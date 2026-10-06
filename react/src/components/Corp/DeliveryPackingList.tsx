import React, { useState, useEffect, useMemo } from 'react';
import { formatThousands } from '../../utils/numberFormat';
import OrderNote from './OrderNote';
import OwnedStockBadge, { OwnedStock, findOwnedStock } from './OwnedStockBadge';
import { Order, OrderItem } from './OrderListManager';

interface DeliveryPackingListProps {
    orders: Order[];
    currentUserId?: number;
    ownedStock?: OwnedStock;
    isOfficer?: boolean;
    onCopyUser: (order: Order) => void;
    onCopyAmount: (order: Order) => void;
    onCopyTitle: (order: Order) => void;
    onCopyMultibuy: (order: Order) => void;
    onCopyItemName: (name: string) => void;
    onOpenInGame: (orderId: number) => void;
    onOpenMarketItem: (typeId: number) => void;
    onCompleteOrder: (orderId: number) => void;
    onUnacceptOrder: (orderId: number) => void;
    onFulfillItem: (itemId: number, currentStatus: boolean) => void;
    onGoToActiveOrders: () => void;
    actionLoadingId: number | null;
    itemLoadingId: number | null;
}

export default function DeliveryPackingList({
    orders,
    currentUserId,
    ownedStock,
    isOfficer = false,
    onCopyUser,
    onCopyAmount,
    onCopyTitle,
    onCopyMultibuy,
    onCopyItemName,
    onOpenInGame,
    onOpenMarketItem,
    onCompleteOrder,
    onUnacceptOrder,
    onFulfillItem,
    onGoToActiveOrders,
    actionLoadingId,
    itemLoadingId,
}: DeliveryPackingListProps) {
    const [searchQuery, setSearchQuery] = useState('');
    const [expandedOrderIds, setExpandedOrderIds] = useState<number[]>([]);
    const [checkedItemIds, setCheckedItemIds] = useState<number[]>(() => {
        try {
            const saved = localStorage.getItem(`wh_checked_pack_items_${currentUserId || 0}`);
            return saved ? JSON.parse(saved) : [];
        } catch {
            return [];
        }
    });

    // Save checked items to localStorage
    useEffect(() => {
        try {
            localStorage.setItem(
                `wh_checked_pack_items_${currentUserId || 0}`,
                JSON.stringify(checkedItemIds)
            );
        } catch (e) {
            console.error('Failed to save pack checklist state', e);
        }
    }, [checkedItemIds, currentUserId]);

    // Expand all orders by default on mount or order change
    useEffect(() => {
        setExpandedOrderIds(orders.map(o => o.id));
    }, [orders.length]);

    const toggleExpand = (id: number) => {
        setExpandedOrderIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const toggleCheckItem = (itemId: number) => {
        setCheckedItemIds(prev =>
            prev.includes(itemId) ? prev.filter(id => id !== itemId) : [...prev, itemId]
        );
    };

    const handleCheckAllForOrder = (order: Order) => {
        const orderItemIds = order.items.map(i => i.id);
        const allChecked = orderItemIds.every(id => checkedItemIds.includes(id));
        if (allChecked) {
            setCheckedItemIds(prev => prev.filter(id => !orderItemIds.includes(id)));
        } else {
            setCheckedItemIds(prev => Array.from(new Set([...prev, ...orderItemIds])));
        }
    };

    const handleResetAllChecklists = () => {
        if (confirm('Möchtest du alle Häkchen auf der Packliste zurücksetzen?')) {
            setCheckedItemIds([]);
        }
    };

    // Calculate stack division across all active deliveries
    const sharedItemDemands = useMemo(() => {
        const itemMap = new Map<number, { name: string; totalAmount: number; orders: { orderId: number; user: string; amount: number }[] }>();

        orders.forEach(order => {
            order.items.forEach(item => {
                const isUserItem = !currentUserId || (item.isFulfilled && item.fulfiller?.id === currentUserId) || (order.fulfiller?.id === currentUserId);
                if (!isUserItem) return;

                if (!itemMap.has(item.typeId)) {
                    itemMap.set(item.typeId, {
                        name: item.name,
                        totalAmount: 0,
                        orders: [],
                    });
                }
                const record = itemMap.get(item.typeId)!;
                record.totalAmount += item.amount;
                record.orders.push({
                    orderId: order.id,
                    user: order.user.displayName,
                    amount: item.amount,
                });
            });
        });

        const shared: { typeId: number; name: string; totalAmount: number; orders: { orderId: number; user: string; amount: number }[] }[] = [];
        itemMap.forEach((val, typeId) => {
            if (val.orders.length > 1) {
                shared.push({ typeId, ...val });
            }
        });

        return shared;
    }, [orders, currentUserId]);

    // Overall stats
    const totalISK = useMemo(() => orders.reduce((sum, o) => sum + o.totalPrice, 0), [orders]);
    const totalVolume = useMemo(() => orders.reduce((sum, o) => sum + o.totalVolume, 0), [orders]);
    const totalPositions = useMemo(() => orders.reduce((sum, o) => sum + o.items.length, 0), [orders]);
    const totalPackedPositions = useMemo(() => {
        const allIds = orders.flatMap(o => o.items.map(i => i.id));
        return allIds.filter(id => checkedItemIds.includes(id)).length;
    }, [orders, checkedItemIds]);

    const filteredOrders = useMemo(() => {
        if (!searchQuery.trim()) return orders;
        const q = searchQuery.toLowerCase();
        return orders.filter(order => {
            const matchesUser = order.user.displayName.toLowerCase().includes(q);
            const matchesTitle = order.title.toLowerCase().includes(q);
            const matchesItem = order.items.some(i => i.name.toLowerCase().includes(q));
            return matchesUser || matchesTitle || matchesItem;
        });
    }, [orders, searchQuery]);

    const getSlotBadgeStyle = (slot: string) => {
        switch (slot.toLowerCase()) {
            case 'hull': return 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            case 'high': return 'bg-red-500/15 text-red-300 border-red-500/30';
            case 'med': return 'bg-blue-500/15 text-blue-300 border-blue-500/30';
            case 'low': return 'bg-amber-600/15 text-amber-400 border-amber-600/30';
            case 'rig': return 'bg-purple-500/15 text-purple-300 border-purple-500/30';
            case 'subsystem': return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
            case 'drone': case 'fighter': return 'bg-teal-500/15 text-teal-300 border-teal-500/30';
            case 'charge': return 'bg-orange-500/15 text-orange-300 border-orange-500/30';
            default: return 'bg-slate-500/15 text-slate-300 border-slate-500/30';
        }
    };

    if (orders.length === 0) {
        return (
            <div className="p-8 rounded-lg border border-eve-border bg-eve-card/40 text-center flex flex-col items-center justify-center gap-3">
                <span className="text-3xl">📦</span>
                <h3 className="text-base font-bold text-white">Keine aktiven Lieferungen übernommen</h3>
                <p className="text-xs text-eve-muted max-w-md leading-relaxed">
                    Sobald du in den "Aktiven Aufträgen" eine Bestellung ganz oder teilweise annimmst, erscheint sie hier auf deiner Packliste zum einfachen Sortieren, Verladen und Vertragen.
                </p>
                <button
                    type="button"
                    onClick={onGoToActiveOrders}
                    className="mt-2 px-4 py-2 rounded-lg text-xs font-semibold bg-eve-primary text-black hover:brightness-115 cursor-pointer shadow-eve transition-all"
                >
                    Zu den aktiven Aufträgen wechseln
                </button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {/* Header Summary Card */}
            <div className="p-5 rounded-lg border border-eve-border bg-eve-card/60 shadow-eve">
                <div className="flex flex-wrap items-center justify-between gap-4 mb-4 pb-4 border-b border-eve-border/40">
                    <div>
                        <h2 className="text-lg font-bold text-white flex items-center gap-2">
                            <span>📦 Meine Lieferungen & Packliste</span>
                            <span className="px-2 py-0.5 rounded-full text-xs bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 font-mono">
                                {orders.length} {orders.length === 1 ? 'Auftrag' : 'Aufträge'}
                            </span>
                        </h2>
                        <p className="text-xs text-eve-muted mt-0.5">
                            Klicke auf einen Item-Namen, um ihn direkt für den EVE-Hangarfilter zu kopieren. Nutze die Checkboxen zum Abhaken beim Verladen.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {checkedItemIds.length > 0 && (
                            <button
                                type="button"
                                onClick={handleResetAllChecklists}
                                className="px-3 py-1.5 rounded-lg text-xs bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                            >
                                Häkchen zurücksetzen
                            </button>
                        )}
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Item oder Empfänger filtern..."
                            className="w-56 rounded-lg px-3 py-1.5 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div className="p-3 rounded-lg bg-black/40 border border-white/5">
                        <span className="text-[11px] text-eve-muted block mb-1">Gesamterlös (ISK):</span>
                        <span className="text-base font-bold text-eve-primary font-mono">
                            {formatThousands(totalISK)} ISK
                        </span>
                    </div>

                    <div className="p-3 rounded-lg bg-black/40 border border-white/5">
                        <span className="text-[11px] text-eve-muted block mb-1">Gesamtes Ladevolumen:</span>
                        <span className="text-base font-bold text-slate-200 font-mono">
                            {formatThousands(totalVolume)} m³
                        </span>
                    </div>

                    <div className="p-3 rounded-lg bg-black/40 border border-white/5">
                        <span className="text-[11px] text-eve-muted block mb-1">Empfänger:</span>
                        <span className="text-base font-bold text-slate-200">
                            {new Set(orders.map(o => o.user.displayName)).size} Piloten
                        </span>
                    </div>

                    <div className="p-3 rounded-lg bg-black/40 border border-white/5">
                        <div className="flex justify-between items-center text-[11px] text-eve-muted mb-1">
                            <span>Gepackte Posten:</span>
                            <span className="font-semibold text-white">
                                {totalPackedPositions} / {totalPositions}
                            </span>
                        </div>
                        <div className="w-full bg-black/60 rounded-full h-2 overflow-hidden border border-white/10 mt-1.5">
                            <div
                                className={`h-full transition-all duration-300 ${totalPackedPositions === totalPositions && totalPositions > 0 ? 'bg-emerald-400' : 'bg-eve-primary'}`}
                                style={{ width: `${totalPositions > 0 ? (totalPackedPositions / totalPositions) * 100 : 0}%` }}
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Freight Container Pro-Tip Alert */}
            <div className="p-4 rounded-lg bg-eve-primary/10 border border-eve-primary/30 flex items-start gap-3 text-xs text-slate-300">
                <span className="text-xl flex-shrink-0">💡</span>
                <div className="leading-relaxed">
                    <strong className="text-white block font-semibold mb-0.5">
                        Profi-Tipp für Frachter-Piloten (Null Sortieraufwand am Zielort):
                    </strong>
                    <span>
                        Kaufe in Jita für jeden Auftrag einen günstigen <strong className="text-eve-primary">Freight Container</strong> (z. B. Small oder Standard Freight Container für ~300k ISK).
                        Benenne den Container nach dem Besteller und ziehe die Multibuy-Items direkt hinein.
                        Beim Entladen im Wurmloch musst du dann nichts mehr suchen: Einfach <em>Rechtsklick auf den jeweiligen Container → "Create Contract"</em> an den Empfänger!
                    </span>
                </div>
            </div>

            {/* Stack Division Alert if shared items exist */}
            {sharedItemDemands.length > 0 && (
                <div className="p-4 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs">
                    <div className="flex items-center gap-2 font-bold mb-2 text-amber-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <span>Stack-Teilung beachten (Mehrfach-Bedarf über mehrere Aufträge):</span>
                    </div>
                    <p className="text-[11px] text-amber-300/80 mb-2.5">
                        Folgende Gegenstände wurden von mehreren Piloten bestellt. Achte beim Erstellen der Verträge darauf, nicht den gesamten Stack an einen Empfänger zu vertragen:
                    </p>
                    <div className="space-y-2">
                        {sharedItemDemands.map(item => (
                            <div key={item.typeId} className="p-2 rounded bg-black/40 border border-amber-500/20 flex flex-wrap items-center gap-2 text-xs">
                                <button
                                    type="button"
                                    onClick={() => onCopyItemName(item.name)}
                                    className="font-bold text-white hover:text-eve-primary flex items-center gap-1 cursor-pointer bg-white/5 px-2 py-0.5 rounded border border-white/10"
                                    title="Name für EVE-Filter kopieren"
                                >
                                    <span>{item.name}</span>
                                    <span className="text-[10px] text-eve-primary font-mono">📋 Kopieren</span>
                                </button>
                                <span className="text-slate-300 font-semibold font-mono">
                                    Gesamt: {formatThousands(item.totalAmount)} Stk.
                                </span>
                                <span className="text-eve-muted">→ Aufteilung:</span>
                                {item.orders.map((o, idx) => (
                                    <span key={idx} className="px-2 py-0.5 rounded bg-amber-500/15 border border-amber-500/30 text-[11px] text-amber-200">
                                        <strong>{formatThousands(o.amount)}x</strong> für <span className="text-white font-semibold">{o.user}</span> (Order #{o.orderId})
                                    </span>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Delivery Orders List */}
            <div className="flex flex-col gap-5">
                {filteredOrders.map(order => {
                    const isExpanded = expandedOrderIds.includes(order.id);
                    const orderItemIds = order.items.map(i => i.id);
                    const packedCount = order.items.filter(i => checkedItemIds.includes(i.id)).length;
                    const isFullyPacked = packedCount === order.items.length && order.items.length > 0;
                    const canManage = isOfficer || (currentUserId && order.user.id === currentUserId);

                    return (
                        <div
                            key={order.id}
                            className={`rounded-lg border bg-eve-card/50 shadow-eve overflow-hidden transition-all ${
                                isFullyPacked ? 'border-emerald-500/40 bg-emerald-950/10' : 'border-eve-border'
                            }`}
                        >
                            {/* Order Header */}
                            <div
                                onClick={() => toggleExpand(order.id)}
                                className="p-4 bg-black/30 hover:bg-white/5 cursor-pointer flex flex-wrap items-center justify-between gap-4 transition-colors"
                            >
                                <div className="flex items-center gap-3 min-w-[220px]">
                                    {order.shipTypeId ? (
                                        <img
                                            src={`/eve/image/types/${order.shipTypeId}/render?size=64`}
                                            alt={order.title}
                                            className="w-10 h-10 rounded border border-white/10 bg-black/40 object-contain"
                                            loading="lazy"
                                        />
                                    ) : (
                                        <div className="w-10 h-10 rounded border border-white/10 bg-black/40 flex items-center justify-center text-xs font-bold text-eve-primary">
                                            #{order.id}
                                        </div>
                                    )}

                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="text-sm font-bold text-white">{order.title}</h3>
                                            <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-eve-primary/15 border border-eve-primary/30 text-eve-primary">
                                                Ziel: {order.user.displayName}
                                            </span>
                                            {isFullyPacked && (
                                                <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1">
                                                    ✓ Bereit zum Vertragen
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-[11px] text-eve-muted mt-0.5">
                                            Order #{order.id} • {order.items.length} Posten • {formatThousands(order.totalVolume)} m³
                                        </p>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-4">
                                    {/* Packed Counter */}
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-eve-muted">Packstatus:</span>
                                        <span className={`px-2 py-0.5 rounded text-xs font-mono font-bold border ${
                                            isFullyPacked
                                                ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/50'
                                                : 'bg-black/50 text-slate-300 border-white/10'
                                        }`}>
                                            {packedCount} / {order.items.length} gepackt
                                        </span>
                                    </div>

                                    <div className="text-right">
                                        <span className="text-[10px] text-eve-muted block">Zu fordern (ISK):</span>
                                        <span className="text-sm font-bold text-eve-primary font-mono">
                                            {formatThousands(order.totalPrice)} ISK
                                        </span>
                                    </div>

                                    <div className={`text-eve-primary transition-transform duration-300 ${isExpanded ? 'rotate-180' : ''}`}>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </div>
                                </div>
                                <OrderNote note={order.note} />
                            </div>

                            {/* Expanded Content: Contract Toolbar & Checklist */}
                            {isExpanded && (
                                <div className="p-4 border-t border-eve-border/60 bg-black/40">
                                    {/* Contract Copy Toolbar */}
                                    <div className="p-3 mb-4 rounded-lg bg-black/60 border border-eve-border/50 flex flex-wrap items-center justify-between gap-3 text-xs">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-eve-muted font-semibold mr-1">Vertragshilfe:</span>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onCopyUser(order);
                                                }}
                                                className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:border-eve-primary/50 text-slate-200 hover:text-white cursor-pointer font-semibold flex items-center gap-1.5"
                                                title="In EVE in das Feld 'Private to' einfügen"
                                            >
                                                <span>👤 Empfänger:</span>
                                                <strong className="text-eve-primary">{order.user.displayName}</strong>
                                                <span className="text-[10px] text-eve-muted">📋</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onCopyAmount(order);
                                                }}
                                                className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:border-eve-primary/50 text-slate-200 hover:text-white cursor-pointer font-semibold flex items-center gap-1.5"
                                                title="In EVE in das Feld 'I will receive' einfügen"
                                            >
                                                <span>💰 Betrag:</span>
                                                <strong className="text-eve-primary">{formatThousands(order.totalPrice)} ISK</strong>
                                                <span className="text-[10px] text-eve-muted">📋</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onCopyTitle(order);
                                                }}
                                                className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:border-eve-primary/50 text-slate-200 hover:text-white cursor-pointer flex items-center gap-1.5"
                                                title="In EVE als Vertragstitel einfügen (für Auto-Sync wichtig)"
                                            >
                                                <span>Titel:</span>
                                                <span className="text-slate-300 font-mono">WH-Order #{order.id}</span>
                                                <span className="text-[10px] text-eve-muted">📋</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onCopyMultibuy(order);
                                                }}
                                                className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                            >
                                                Multibuy kopieren
                                            </button>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleCheckAllForOrder(order);
                                                }}
                                                className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                            >
                                                {isFullyPacked ? 'Häkchen aufheben' : 'Alle abhaken'}
                                            </button>

                                            {order.contractId && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onOpenInGame(order.id);
                                                    }}
                                                    className="px-3 py-1 rounded bg-eve-primary/15 border border-eve-primary/50 text-eve-primary hover:brightness-115 cursor-pointer font-semibold"
                                                >
                                                    Im EVE-Client öffnen
                                                </button>
                                            )}

                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onCompleteOrder(order.id);
                                                }}
                                                disabled={actionLoadingId === order.id}
                                                className="px-3 py-1 rounded bg-emerald-500/20 border border-emerald-500/60 text-emerald-200 hover:bg-emerald-500/30 cursor-pointer font-semibold shadow-[0_0_10px_rgba(16,185,129,0.2)]"
                                                title="Schließt die gesamte Bestellung ab und archiviert sie"
                                            >
                                                Bestellung abschließen
                                            </button>

                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onUnacceptOrder(order.id);
                                                }}
                                                disabled={actionLoadingId === order.id}
                                                className="px-2.5 py-1 rounded bg-black/40 border border-white/20 text-slate-300 hover:text-white cursor-pointer text-xs"
                                                title="Gibt die übernommenen Posten wieder frei"
                                            >
                                                Freigeben
                                            </button>
                                        </div>
                                    </div>

                                    {/* Checklist Items Table */}
                                    <div className="overflow-x-auto border border-eve-border/60 rounded-lg">
                                        <table className="w-full border-collapse text-xs">
                                            <thead>
                                                <tr className="border-b border-eve-border/60 bg-black/50 text-eve-muted text-left">
                                                    <th className="p-2.5 w-10 text-center">Gepackt</th>
                                                    <th className="p-2.5 w-8"></th>
                                                    <th className="p-2.5">Gegenstand (Klick = Name kopieren)</th>
                                                    <th className="p-2.5">Slot / Kategorie</th>
                                                    <th className="p-2.5 text-right">Menge</th>
                                                    <th className="p-2.5 text-right">Volumen</th>
                                                    <th className="p-2.5 text-right">Wert (ISK)</th>
                                                    <th className="p-2.5 text-center">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-white/5 bg-black/20">
                                                {order.items.map(item => {
                                                    const isChecked = checkedItemIds.includes(item.id);
                                                    return (
                                                        <tr
                                                            key={item.id}
                                                            onClick={() => toggleCheckItem(item.id)}
                                                            className={`cursor-pointer transition-colors ${
                                                                isChecked
                                                                    ? 'bg-emerald-950/20 text-slate-400'
                                                                    : 'hover:bg-white/5 text-slate-200'
                                                            }`}
                                                        >
                                                            {/* Checkbox */}
                                                            <td className="p-2 text-center" onClick={(e) => e.stopPropagation()}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={isChecked}
                                                                    onChange={() => toggleCheckItem(item.id)}
                                                                    className="w-4 h-4 rounded border-eve-border text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500"
                                                                />
                                                            </td>

                                                            {/* Icon */}
                                                            <td className="p-2">
                                                                <img
                                                                    src={`/eve/image/types/${item.typeId}/icon?size=64`}
                                                                    alt={item.name}
                                                                    className="w-7 h-7 rounded border border-white/10 bg-black/40 object-contain"
                                                                    loading="lazy"
                                                                    onError={(e) => {
                                                                        (e.target as HTMLImageElement).src = '/assets/images/fallback_item.png';
                                                                    }}
                                                                />
                                                            </td>

                                                            {/* Item Name with Click-to-Copy */}
                                                            <td className="p-2 font-medium" onClick={(e) => e.stopPropagation()}>
                                                                <div className="flex items-center gap-2">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => onCopyItemName(item.name)}
                                                                        className={`flex items-center gap-1.5 px-2 py-0.5 rounded text-left transition-colors cursor-pointer group ${
                                                                            isChecked
                                                                                ? 'line-through text-slate-400 hover:text-white hover:bg-white/5'
                                                                                : 'text-white hover:text-eve-primary hover:bg-eve-primary/10'
                                                                        }`}
                                                                        title="Klicke zum Kopieren (für EVE Hangar-Filter)"
                                                                    >
                                                                        <span className="font-semibold">{item.name}</span>
                                                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-eve-primary opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                                                                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                                                        </svg>
                                                                    </button>

                                                                    <button
                                                                        type="button"
                                                                        onClick={() => onOpenMarketItem(item.typeId)}
                                                                        title="Markt im Spiel öffnen"
                                                                        className="text-eve-muted hover:text-eve-primary text-[10px]"
                                                                    >
                                                                        [Markt]
                                                                    </button>
                                                                    <OwnedStockBadge stock={findOwnedStock(ownedStock, item.typeId)} requiredAmount={item.amount} />
                                                                </div>
                                                            </td>

                                                            {/* Slot */}
                                                            <td className="p-2">
                                                                <span className={`px-2 py-0.5 rounded text-[10px] font-semibold border ${getSlotBadgeStyle(item.slot)}`}>
                                                                    {item.slot.toUpperCase()}
                                                                </span>
                                                            </td>

                                                            {/* Amount */}
                                                            <td className="p-2 text-right font-mono font-bold text-white text-xs">
                                                                {formatThousands(item.amount)}
                                                            </td>

                                                            {/* Volume */}
                                                            <td className="p-2 text-right font-mono text-eve-muted">
                                                                {formatThousands(item.totalVolume)} m³
                                                            </td>

                                                            {/* Total Price */}
                                                            <td className="p-2 text-right font-mono font-semibold text-eve-primary">
                                                                {formatThousands(item.totalPrice)}
                                                            </td>

                                                            {/* Status */}
                                                            <td className="p-2 text-center" onClick={(e) => e.stopPropagation()}>
                                                                {item.isFulfilled ? (
                                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 border border-emerald-500/30 text-emerald-300">
                                                                        ✓ Erfüllt {item.fulfiller ? `(${item.fulfiller.displayName})` : ''}
                                                                    </span>
                                                                ) : (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => onFulfillItem(item.id, false)}
                                                                        disabled={itemLoadingId === item.id}
                                                                        className="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer"
                                                                    >
                                                                        Annehmen
                                                                    </button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
