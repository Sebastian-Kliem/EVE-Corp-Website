import React, { useState, useMemo } from 'react';
import JaniceAppraisal from './JaniceAppraisal';
import OrderWorkflowGuide from './OrderWorkflowGuide';
import { formatThousands } from '../../utils/numberFormat';

interface OrderItem {
    id: number;
    typeId: number;
    name: string;
    amount: number;
    unitPrice: number;
    totalPrice: number;
    unitVolume: number;
    totalVolume: number;
    slot: string;
    sortOrder: number;
    isFulfilled: boolean;
    fulfiller: {
        id: number;
        displayName: string;
    } | null;
    fulfilledAt: string | null;
    liveUnitPrice: number;
    liveTotalPrice: number;
}

interface Order {
    id: number;
    type: 'BUY' | 'SELL';
    status: 'OPEN' | 'IN_PROGRESS' | 'FULFILLED' | 'CANCELLED';
    title: string;
    isFitting: boolean;
    shipTypeId: number | null;
    percentToJita: number;
    totalPrice: number;
    totalVolume: number;
    note: string;
    contractId: string | null;
    createdAt: string;
    updatedAt: string;
    fulfilledAt: string | null;
    user: {
        id: number;
        displayName: string;
    };
    items: OrderItem[];
    fulfilledItemCount: number;
    totalItemCount: number;
    isFullyFulfilled: boolean;
    liveTotalPrice: number;
    priceDiff: number;
    priceDiffPercent: number;
}

interface DoctrineFit {
    id: number;
    title: string;
    shipName: string;
    shipTypeId: number | null;
    role: string;
    eft: string;
}

interface OrderListManagerProps {
    initialBuyOrders: Order[];
    initialSellOrders: Order[];
    initialArchivedBuyOrders: Order[];
    initialArchivedSellOrders: Order[];
    doctrineFits?: DoctrineFit[];
    currentUserId?: number;
    isOfficer?: boolean;
}

export default function OrderListManager({
    initialBuyOrders = [],
    initialSellOrders = [],
    initialArchivedBuyOrders = [],
    initialArchivedSellOrders = [],
    doctrineFits = [],
    currentUserId,
    isOfficer = false,
}: OrderListManagerProps) {
    const [selectedTab, setSelectedTab] = useState<'BUY' | 'SELL'>('BUY');
    const [viewMode, setViewMode] = useState<'active' | 'archived'>('active');
    const [searchQuery, setSearchQuery] = useState('');
    const [isCreateOpen, setIsCreateOpen] = useState(false);

    // Orders state
    const [buyOrders, setBuyOrders] = useState<Order[]>(initialBuyOrders);
    const [sellOrders, setSellOrders] = useState<Order[]>(initialSellOrders);
    const [archivedBuyOrders, setArchivedBuyOrders] = useState<Order[]>(initialArchivedBuyOrders);
    const [archivedSellOrders, setArchivedSellOrders] = useState<Order[]>(initialArchivedSellOrders);

    const [expandedOrderIds, setExpandedOrderIds] = useState<number[]>([]);
    const [feedback, setFeedback] = useState<string | null>(null);
    const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);

    const toggleExpand = (id: number) => {
        setExpandedOrderIds(prev =>
            prev.includes(id) ? prev.filter(item => item !== id) : [...prev, id]
        );
    };

    const showFeedbackMessage = (msg: string) => {
        setFeedback(msg);
        setTimeout(() => setFeedback(null), 4000);
    };

    const currentOrders = useMemo(() => {
        if (selectedTab === 'BUY') {
            return viewMode === 'active' ? buyOrders : archivedBuyOrders;
        }
        return viewMode === 'active' ? sellOrders : archivedSellOrders;
    }, [selectedTab, viewMode, buyOrders, sellOrders, archivedBuyOrders, archivedSellOrders]);

    const filteredOrders = useMemo(() => {
        if (!searchQuery.trim()) return currentOrders;
        const q = searchQuery.toLowerCase();
        return currentOrders.filter(order => {
            const matchesTitle = order.title.toLowerCase().includes(q);
            const matchesUser = order.user.displayName.toLowerCase().includes(q);
            const matchesNote = order.note.toLowerCase().includes(q);
            const matchesItems = order.items.some(i => i.name.toLowerCase().includes(q));
            return matchesTitle || matchesUser || matchesNote || matchesItems;
        });
    }, [currentOrders, searchQuery]);

    const handleOrderCreated = (newOrder: Order) => {
        setIsCreateOpen(false);
        if (newOrder.type === 'BUY') {
            setBuyOrders(prev => [newOrder, ...prev]);
            setSelectedTab('BUY');
        } else {
            setSellOrders(prev => [newOrder, ...prev]);
            setSelectedTab('SELL');
        }
        setViewMode('active');
        setExpandedOrderIds(prev => [...prev, newOrder.id]);
        showFeedbackMessage(`Bestellung #${newOrder.id} "${newOrder.title}" erfolgreich aufgegeben!`);
    };

    const handleFulfillItem = (itemId: number, currentStatus: boolean) => {
        fetch(`/api/orders/items/${itemId}/fulfill`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: !currentStatus }),
        })
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Aktualisieren der Position.');
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Erfüllen der Position.');
            });
    };

    const handleFulfillAll = (orderId: number) => {
        if (!confirm('Möchtest du wirklich alle offenen Positionen dieser Bestellung als erledigt markieren?')) return;

        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/fulfill-all`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Abschließen der Bestellung.');
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Bestellung vollständig abgeschlossen.');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Abschließen.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleCancelOrder = (orderId: number) => {
        if (!confirm('Möchtest du diesen Auftrag wirklich stornieren?')) return;

        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/cancel`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Stornieren der Bestellung.');
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Auftrag wurde storniert.');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Stornieren.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleReopenOrder = (orderId: number) => {
        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/reopen`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Wiedereröffnen der Bestellung.');
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Auftrag wiedereröffnet.');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Wiedereröffnen.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleOpenInGame = (orderId: number) => {
        fetch(`/api/orders/${orderId}/open-in-game`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                } else {
                    showFeedbackMessage('Vertragsfenster im EVE-Client geöffnet.');
                }
            })
            .catch(err => {
                alert('Fehler beim Senden des ESI UI-Befehls: ' + err.message);
            });
    };

    const handleOpenMarketItem = (typeId: number) => {
        fetch('/api/orders/open-market-item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ typeId }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                } else {
                    showFeedbackMessage('Marktfenster im EVE-Client geöffnet.');
                }
            })
            .catch(err => {
                console.error(err);
            });
    };

    const updateOrderInState = (updatedOrder: Order) => {
        const isArchived = updatedOrder.status === 'FULFILLED' || updatedOrder.status === 'CANCELLED';

        if (updatedOrder.type === 'BUY') {
            if (isArchived) {
                setBuyOrders(prev => prev.filter(o => o.id !== updatedOrder.id));
                setArchivedBuyOrders(prev => [updatedOrder, ...prev.filter(o => o.id !== updatedOrder.id)]);
            } else {
                setArchivedBuyOrders(prev => prev.filter(o => o.id !== updatedOrder.id));
                setBuyOrders(prev => prev.map(o => o.id === updatedOrder.id ? updatedOrder : o));
            }
        } else {
            if (isArchived) {
                setSellOrders(prev => prev.filter(o => o.id !== updatedOrder.id));
                setArchivedSellOrders(prev => [updatedOrder, ...prev.filter(o => o.id !== updatedOrder.id)]);
            } else {
                setArchivedSellOrders(prev => prev.filter(o => o.id !== updatedOrder.id));
                setSellOrders(prev => prev.map(o => o.id === updatedOrder.id ? updatedOrder : o));
            }
        }
    };

    const handleCopyMultibuy = (order: Order) => {
        const unfulfilledItems = order.items.filter(i => !i.isFulfilled);
        const targetItems = unfulfilledItems.length > 0 ? unfulfilledItems : order.items;
        const lines = targetItems.map(i => `${i.name} ${i.amount}`);
        navigator.clipboard.writeText(lines.join('\n'));
        showFeedbackMessage(`Multibuy-Liste für Order #${order.id} kopiert.`);
    };

    const handleCopyAmount = (order: Order) => {
        navigator.clipboard.writeText(order.totalPrice.toString());
        showFeedbackMessage(`Betrag ${formatThousands(order.totalPrice)} ISK kopiert.`);
    };

    const handleCopyTitle = (order: Order) => {
        const title = `WH-Order #${order.id}`;
        navigator.clipboard.writeText(title);
        showFeedbackMessage(`Vertragstitel "${title}" kopiert.`);
    };

    const handleCopyUser = (order: Order) => {
        navigator.clipboard.writeText(order.user.displayName);
        showFeedbackMessage(`Charakter "${order.user.displayName}" kopiert.`);
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'OPEN':
                return <span className="px-2.5 py-1 rounded text-[11px] font-semibold border bg-eve-primary/15 text-eve-primary border-eve-primary/40">Offen</span>;
            case 'IN_PROGRESS':
                return <span className="px-2.5 py-1 rounded text-[11px] font-semibold border bg-blue-500/15 text-blue-300 border-blue-500/40">In Bearbeitung</span>;
            case 'FULFILLED':
                return <span className="px-2.5 py-1 rounded text-[11px] font-semibold border bg-emerald-500/15 text-emerald-300 border-emerald-500/40">Erfüllt</span>;
            case 'CANCELLED':
                return <span className="px-2.5 py-1 rounded text-[11px] font-semibold border bg-red-500/15 text-red-300 border-red-500/40">Storniert</span>;
            default:
                return <span className="px-2.5 py-1 rounded text-[11px] font-semibold border bg-slate-500/15 text-slate-300 border-slate-500/40">{status}</span>;
        }
    };

    const getSlotBadgeStyle = (slot: string) => {
        switch (slot) {
            case 'hull':
                return 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            case 'high':
                return 'bg-red-500/15 text-red-300 border-red-500/30';
            case 'med':
                return 'bg-blue-500/15 text-blue-300 border-blue-500/30';
            case 'low':
                return 'bg-amber-600/15 text-amber-400 border-amber-600/30';
            case 'rig':
                return 'bg-purple-500/15 text-purple-300 border-purple-500/30';
            case 'subsystem':
                return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
            case 'drone':
            case 'fighter':
                return 'bg-teal-500/15 text-teal-300 border-teal-500/30';
            case 'charge':
                return 'bg-orange-500/15 text-orange-300 border-orange-500/30';
            default:
                return 'bg-slate-500/15 text-slate-300 border-slate-500/30';
        }
    };

    return (
        <div className="w-full">
            <OrderWorkflowGuide />

            {feedback && (
                <div className="mb-4 p-3 rounded-lg bg-emerald-500/15 border border-emerald-500/40 text-emerald-300 text-xs flex items-center justify-between animate-fade-in">
                    <span>{feedback}</span>
                    <button type="button" onClick={() => setFeedback(null)} className="text-emerald-400 hover:text-white">x</button>
                </div>
            )}

            {/* Create Order Accordion */}
            <div className="mb-6 rounded-lg border border-eve-border bg-eve-card/40 shadow-eve overflow-hidden">
                <button
                    type="button"
                    onClick={() => setIsCreateOpen(!isCreateOpen)}
                    className="w-full flex items-center justify-between p-4 bg-black/20 hover:bg-white/5 text-left transition-colors cursor-pointer"
                >
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-white">Neue Bestellung / Angebot erstellen</span>
                        <span className="text-xs text-eve-muted">(EFT-Fit, Hangar-Liste oder Multibuy)</span>
                    </div>
                    <div className={`text-eve-primary transition-transform duration-300 ${isCreateOpen ? 'rotate-180' : ''}`}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </div>
                </button>

                {isCreateOpen && (
                    <div className="p-5 border-t border-eve-border/60 bg-black/30">
                        <JaniceAppraisal
                            doctrineFits={doctrineFits}
                            defaultType={selectedTab}
                            onOrderCreated={handleOrderCreated}
                            embedded={true}
                        />
                    </div>
                )}
            </div>

            {/* Main Tabs & Filters */}
            <div className="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-eve-border/40">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setSelectedTab('BUY')}
                        className={`px-4 py-2 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                            selectedTab === 'BUY'
                                ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.2)]'
                                : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'
                        }`}
                    >
                        Kaufgesuche (Buy Orders) ({buyOrders.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setSelectedTab('SELL')}
                        className={`px-4 py-2 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                            selectedTab === 'SELL'
                                ? 'bg-emerald-500/15 border-emerald-500/60 text-emerald-300 shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                                : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'
                        }`}
                    >
                        Verkaufsangebote (Sell Orders) ({sellOrders.length})
                    </button>
                </div>

                <div className="flex items-center gap-3">
                    <div className="flex items-center rounded-lg border border-white/10 bg-[#0a0f1d] p-1">
                        <button
                            type="button"
                            onClick={() => setViewMode('active')}
                            className={`px-3 py-1 rounded text-xs font-semibold transition-all cursor-pointer ${
                                viewMode === 'active'
                                    ? 'bg-eve-primary/20 text-eve-primary border border-eve-primary/30'
                                    : 'text-eve-muted hover:text-white'
                            }`}
                        >
                            Aktive Aufträge
                        </button>
                        <button
                            type="button"
                            onClick={() => setViewMode('archived')}
                            className={`px-3 py-1 rounded text-xs font-semibold transition-all cursor-pointer ${
                                viewMode === 'archived'
                                    ? 'bg-eve-primary/20 text-eve-primary border border-eve-primary/30'
                                    : 'text-eve-muted hover:text-white'
                            }`}
                        >
                            Archiv & Historie
                        </button>
                    </div>

                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Auftrag, Item oder Member suchen..."
                        className="w-56 rounded-lg px-3 py-1.5 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                    />
                </div>
            </div>

            {/* Orders List */}
            {filteredOrders.length === 0 ? (
                <div className="p-8 rounded-lg border border-eve-border bg-eve-card/40 text-center text-eve-muted text-xs">
                    Keine {viewMode === 'active' ? 'aktiven' : 'archivierten'} {selectedTab === 'BUY' ? 'Kaufaufträge' : 'Verkaufsangebote'} gefunden.
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {filteredOrders.map(order => {
                        const isExpanded = expandedOrderIds.includes(order.id);
                        const progressPercent = order.totalItemCount > 0 ? (order.fulfilledItemCount / order.totalItemCount) * 100 : 0;
                        const canManageOrder = isOfficer || (currentUserId && order.user.id === currentUserId);

                        return (
                            <div
                                key={order.id}
                                className="rounded-lg border border-eve-border bg-eve-card/40 shadow-eve overflow-hidden transition-all"
                            >
                                {/* Card Header */}
                                <div
                                    onClick={() => toggleExpand(order.id)}
                                    className="p-4 bg-black/20 hover:bg-white/5 cursor-pointer flex flex-wrap items-center justify-between gap-4 transition-colors"
                                >
                                    <div className="flex items-center gap-3 min-w-[220px]">
                                        {order.shipTypeId ? (
                                            <img
                                                src={`/eve/image/types/${order.shipTypeId}/render?size=64`}
                                                alt={order.title}
                                                className="w-10 h-10 rounded border border-white/10 bg-black/40 object-contain"
                                                loading="lazy"
                                                onError={(e) => {
                                                    (e.target as HTMLImageElement).src = '/assets/images/fallback_item.png';
                                                }}
                                            />
                                        ) : (
                                            <div className="w-10 h-10 rounded border border-white/10 bg-black/40 flex items-center justify-center text-xs font-bold text-eve-primary">
                                                #{order.id}
                                            </div>
                                        )}

                                        <div>
                                            <div className="flex items-center gap-2">
                                                <h3 className="text-sm font-bold text-white">{order.title}</h3>
                                                {getStatusBadge(order.status)}
                                            </div>
                                            <p className="text-[11px] text-eve-muted mt-0.5">
                                                Von <span className="text-slate-300 font-semibold">{order.user.displayName}</span> am {order.createdAt}
                                                {order.note && <span className="ml-2 italic text-slate-400">"{order.note}"</span>}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Progress & Stats */}
                                    <div className="flex flex-wrap items-center gap-6">
                                        <div className="min-w-[120px]">
                                            <div className="flex justify-between text-[10px] text-eve-muted mb-1">
                                                <span>Erfüllt:</span>
                                                <span className="font-semibold text-slate-300">
                                                    {order.fulfilledItemCount} / {order.totalItemCount} Posten
                                                </span>
                                            </div>
                                            <div className="w-full bg-black/50 rounded-full h-1.5 overflow-hidden border border-white/10">
                                                <div
                                                    className="bg-eve-primary h-full transition-all duration-300"
                                                    style={{ width: `${progressPercent}%` }}
                                                ></div>
                                            </div>
                                        </div>

                                        <div className="text-right">
                                            <span className="text-[10px] text-eve-muted block">Gesamtwert ({order.percentToJita}%):</span>
                                            <span className="text-sm font-bold text-eve-primary">
                                                {formatThousands(order.totalPrice)} ISK
                                            </span>
                                            {order.status === 'OPEN' && order.priceDiff !== 0 && (
                                                <span className={`text-[10px] block ${order.priceDiffPercent > 0 ? 'text-amber-400' : 'text-emerald-400'}`}>
                                                    Markt: {formatThousands(order.liveTotalPrice)} ({order.priceDiffPercent > 0 ? '+' : ''}{order.priceDiffPercent}%)
                                                </span>
                                            )}
                                        </div>

                                        <div className="text-right">
                                            <span className="text-[10px] text-eve-muted block">Volumen:</span>
                                            <span className="text-xs font-semibold text-slate-300">
                                                {formatThousands(order.totalVolume)} m³
                                            </span>
                                        </div>

                                        <div className={`text-eve-primary transition-transform duration-300 ${isExpanded ? 'rotate-180' : ''}`}>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                {/* Expanded Content */}
                                {isExpanded && (
                                    <div className="p-4 border-t border-eve-border/60 bg-black/30">
                                        {/* Action Bar */}
                                        <div className="flex flex-wrap items-center justify-between gap-3 mb-4 p-3 rounded-lg bg-black/40 border border-eve-border/40 text-xs">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-eve-muted font-semibold mr-1">Kopierhilfe:</span>
                                                <button
                                                    type="button"
                                                    onClick={() => handleCopyMultibuy(order)}
                                                    className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                                >
                                                    Multibuy kopieren
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleCopyAmount(order)}
                                                    className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                                >
                                                    Betrag ({formatThousands(order.totalPrice)} ISK)
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleCopyTitle(order)}
                                                    className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                                >
                                                    Titel (WH-Order #{order.id})
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleCopyUser(order)}
                                                    className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                                >
                                                    Ziel: {order.user.displayName}
                                                </button>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                {order.contractId && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenInGame(order.id)}
                                                        className="px-3 py-1 rounded bg-eve-primary/15 border border-eve-primary/50 text-eve-primary hover:brightness-115 cursor-pointer font-semibold"
                                                    >
                                                        Im EVE-Client öffnen
                                                    </button>
                                                )}

                                                {order.status !== 'FULFILLED' && order.status !== 'CANCELLED' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleFulfillAll(order.id)}
                                                        disabled={actionLoadingId === order.id}
                                                        className="px-3 py-1 rounded bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer font-semibold"
                                                    >
                                                        Gesamte Bestellung erfüllen
                                                    </button>
                                                )}

                                                {order.status === 'OPEN' && canManageOrder && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCancelOrder(order.id)}
                                                        disabled={actionLoadingId === order.id}
                                                        className="px-2.5 py-1 rounded bg-red-500/15 border border-red-500/40 text-red-300 hover:brightness-115 cursor-pointer"
                                                    >
                                                        Stornieren
                                                    </button>
                                                )}

                                                {(order.status === 'CANCELLED' || order.status === 'FULFILLED') && canManageOrder && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleReopenOrder(order.id)}
                                                        disabled={actionLoadingId === order.id}
                                                        className="px-2.5 py-1 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer"
                                                    >
                                                        Wiedereröffnen
                                                    </button>
                                                )}
                                            </div>
                                        </div>

                                        {/* Items Table */}
                                        <div className="overflow-x-auto border border-eve-border/60 rounded-lg">
                                            <table className="w-full border-collapse text-xs">
                                                <thead>
                                                    <tr className="border-b border-eve-border/60 bg-black/40 text-eve-muted text-left">
                                                        <th className="p-2.5 w-8"></th>
                                                        <th className="p-2.5">Gegenstand</th>
                                                        <th className="p-2.5">Slot / Kategorie</th>
                                                        <th className="p-2.5 text-right">Menge</th>
                                                        <th className="p-2.5 text-right">Volumen</th>
                                                        <th className="p-2.5 text-right">Stückpreis (vereinbart)</th>
                                                        <th className="p-2.5 text-right">Gesamt (ISK)</th>
                                                        <th className="p-2.5 text-center">Status / Erfüller</th>
                                                        <th className="p-2.5 text-right">Aktion</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5 bg-black/20">
                                                    {order.items.map(item => (
                                                        <tr key={item.id} className={`hover:bg-white/5 transition-colors ${item.isFulfilled ? 'opacity-60 bg-emerald-950/10' : ''}`}>
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
                                                            <td className="p-2 font-medium text-white">
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>{item.name}</span>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleOpenMarketItem(item.typeId)}
                                                                        title="Markt im Spiel öffnen"
                                                                        className="text-eve-muted hover:text-eve-primary cursor-pointer text-[10px]"
                                                                    >
                                                                        [Markt]
                                                                    </button>
                                                                </div>
                                                            </td>
                                                            <td className="p-2">
                                                                <span className={`px-2 py-0.5 rounded text-[10px] font-semibold border ${getSlotBadgeStyle(item.slot)}`}>
                                                                    {item.slot.toUpperCase()}
                                                                </span>
                                                            </td>
                                                            <td className="p-2 text-right font-mono text-slate-300">
                                                                {formatThousands(item.amount)}
                                                            </td>
                                                            <td className="p-2 text-right font-mono text-eve-muted">
                                                                {formatThousands(item.totalVolume)} m³
                                                            </td>
                                                            <td className="p-2 text-right font-mono text-eve-muted">
                                                                {formatThousands(item.unitPrice)}
                                                            </td>
                                                            <td className="p-2 text-right font-mono font-semibold text-eve-primary">
                                                                {formatThousands(item.totalPrice)}
                                                            </td>
                                                            <td className="p-2 text-center">
                                                                {item.isFulfilled ? (
                                                                    <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 border border-emerald-500/30 text-emerald-300">
                                                                        Erfüllt {item.fulfiller ? `(${item.fulfiller.displayName})` : ''}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-[10px] text-eve-muted">Offen</span>
                                                                )}
                                                            </td>
                                                            <td className="p-2 text-right">
                                                                {order.status !== 'CANCELLED' && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleFulfillItem(item.id, item.isFulfilled)}
                                                                        className={`px-2 py-1 rounded text-[10px] font-semibold border transition-all cursor-pointer ${
                                                                            item.isFulfilled
                                                                                ? 'bg-black/40 border-white/10 text-eve-muted hover:text-white'
                                                                                : 'bg-emerald-500/15 border-emerald-500/50 text-emerald-300 hover:brightness-115'
                                                                        }`}
                                                                    >
                                                                        {item.isFulfilled ? 'Freigeben' : 'Abhaken'}
                                                                    </button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
