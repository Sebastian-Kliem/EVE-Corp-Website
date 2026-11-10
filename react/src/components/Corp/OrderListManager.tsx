import React, { useState, useMemo, useEffect, useCallback } from 'react';
import JaniceAppraisal from './JaniceAppraisal';
import OrderWorkflowGuide from './OrderWorkflowGuide';
import DeliveryPackingList from './DeliveryPackingList';
import { formatThousands, formatIsk, roundIsk } from '../../utils/numberFormat';
import OrderNote from './OrderNote';
import OwnedStockBadge, { OwnedStock, findOwnedStock } from './OwnedStockBadge';
import OrderAmountsList from './OrderAmountsList';
import OrderProgress from './OrderProgress';
import { DeliveryStatus, DELIVERY_STATUS_LABELS, DELIVERY_STATUS_STYLES, getCustomerStatus, hasOpenOwnDelivery, isDelivered } from './deliveryStatus';

export interface OrderItem {
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
    deliveryStatus: DeliveryStatus | null;
    deliveredAt: string | null;
}

export interface Order {
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
    createdAt: string;
    updatedAt: string;
    fulfilledAt: string | null;
    user: {
        id: number;
        displayName: string;
    };
    fulfiller: {
        id: number;
        displayName: string;
    } | null;
    fulfillers?: {
        id: number;
        displayName: string;
    }[];
    items: OrderItem[];
    fulfilledItemCount: number;
    deliveredItemCount: number;
    receivedItemCount: number;
    totalItemCount: number;
    isFullyFulfilled: boolean;
    isFullyReceived: boolean;
}

// Current Jita value of an open order, loaded after the page as it needs ESI requests
interface LivePrice {
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
    ownedStock?: OwnedStock;
}

// Buy and sell sections share one list, colors keep them apart
const ORDER_SECTIONS = {
    BUY: {
        title: 'Kaufgesuche',
        subtitle: 'Buy Orders',
        headingClass: 'text-eve-primary',
        lineClass: 'bg-eve-primary/30',
        accentClass: 'border-l-eve-primary/60',
    },
    SELL: {
        title: 'Verkaufsangebote',
        subtitle: 'Sell Orders',
        headingClass: 'text-emerald-300',
        lineClass: 'bg-emerald-500/30',
        accentClass: 'border-l-emerald-400/60',
    },
} as const;

function filterOrdersBySearch(orders: Order[], searchQuery: string): Order[] {
    const query = searchQuery.trim().toLowerCase();
    if (!query) {
        return orders;
    }
    const matchingOrders: Order[] = [];
    for (const order of orders) {
        const matchesTitle = order.title.toLowerCase().includes(query);
        const matchesUser = order.user.displayName.toLowerCase().includes(query);
        const matchesNote = order.note.toLowerCase().includes(query);
        const matchesItems = order.items.some(item => item.name.toLowerCase().includes(query));
        if (matchesTitle || matchesUser || matchesNote || matchesItems) {
            matchingOrders.push(order);
        }
    }
    return matchingOrders;
}

export default function OrderListManager({
    initialBuyOrders = [],
    initialSellOrders = [],
    initialArchivedBuyOrders = [],
    initialArchivedSellOrders = [],
    doctrineFits = [],
    currentUserId,
    isOfficer = false,
    ownedStock,
}: OrderListManagerProps) {
    const [viewMode, setViewMode] = useState<'active' | 'deliveries' | 'archived'>('active');
    const [searchQuery, setSearchQuery] = useState('');
    const [isCreateOpen, setIsCreateOpen] = useState(true);

    // Orders state
    const [buyOrders, setBuyOrders] = useState<Order[]>(initialBuyOrders);
    const [sellOrders, setSellOrders] = useState<Order[]>(initialSellOrders);
    const [archivedBuyOrders, setArchivedBuyOrders] = useState<Order[]>(initialArchivedBuyOrders);
    const [archivedSellOrders, setArchivedSellOrders] = useState<Order[]>(initialArchivedSellOrders);

    const [expandedOrderIds, setExpandedOrderIds] = useState<number[]>([]);
    const [collapsedSections, setCollapsedSections] = useState<Record<'BUY' | 'SELL', boolean>>({ BUY: false, SELL: false });
    const [feedback, setFeedback] = useState<string | null>(null);
    const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);
    const [itemLoadingId, setItemLoadingId] = useState<number | null>(null);
    const [livePrices, setLivePrices] = useState<Record<number, LivePrice>>({});
    // Partial amount per open item the current user wants to take
    const [takeAmounts, setTakeAmounts] = useState<Record<number, number>>({});

    const loadLivePrices = useCallback(() => {
        fetch('/api/orders/live-prices')
            .then(res => (res.ok ? res.json() : {}))
            .then(data => setLivePrices(Array.isArray(data) ? {} : data))
            .catch(() => setLivePrices({}));
    }, []);

    useEffect(() => {
        loadLivePrices();
    }, [loadLivePrices]);

    const toggleExpand = (id: number) => {
        setExpandedOrderIds(prev =>
            prev.includes(id) ? prev.filter(item => item !== id) : [...prev, id]
        );
    };

    const showFeedbackMessage = (msg: string) => {
        setFeedback(msg);
        setTimeout(() => setFeedback(null), 4000);
    };

    const handleCopyItemName = (name: string) => {
        navigator.clipboard.writeText(name);
        showFeedbackMessage(`"${name}" kopiert (bereit für EVE-Suchfilter).`);
    };

    const myDeliveries = useMemo(() => {
        const allActive = [...buyOrders, ...sellOrders];
        return allActive.filter(order => {
            if (order.status === 'FULFILLED' || order.status === 'CANCELLED') {
                return false;
            }
            if (!currentUserId) {
                return order.status === 'IN_PROGRESS' || order.fulfilledItemCount > 0;
            }
            // Order leaves the tab once all own items are delivered
            return hasOpenOwnDelivery(order, currentUserId);
        });
    }, [buyOrders, sellOrders, currentUserId]);

    const filteredBuyOrders = useMemo(
        () => filterOrdersBySearch(viewMode === 'archived' ? archivedBuyOrders : buyOrders, searchQuery),
        [viewMode, buyOrders, archivedBuyOrders, searchQuery]
    );

    const filteredSellOrders = useMemo(
        () => filterOrdersBySearch(viewMode === 'archived' ? archivedSellOrders : sellOrders, searchQuery),
        [viewMode, sellOrders, archivedSellOrders, searchQuery]
    );

    const handleOrderCreated = (newOrder: Order) => {
        if (newOrder.type === 'BUY') {
            setBuyOrders(prev => [newOrder, ...prev]);
        } else {
            setSellOrders(prev => [newOrder, ...prev]);
        }
        setViewMode('active');
        setExpandedOrderIds(prev => [...prev, newOrder.id]);
        loadLivePrices();
        showFeedbackMessage(`Bestellung #${newOrder.id} "${newOrder.title}" erfolgreich aufgegeben!`);
    };

    const handleFulfillItem = (itemId: number, currentStatus: boolean, amount?: number) => {
        setItemLoadingId(itemId);
        fetch(`/api/orders/items/${itemId}/fulfill`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(amount !== undefined ? { status: !currentStatus, amount } : { status: !currentStatus }),
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Aktualisieren der Position.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                setTakeAmounts(prev => {
                    const next = { ...prev };
                    delete next[itemId];
                    return next;
                });
                showFeedbackMessage(
                    !currentStatus
                        ? 'Position übernommen.'
                        : 'Position wieder freigegeben.'
                );
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Erfüllen der Position.');
            })
            .finally(() => {
                setItemLoadingId(null);
            });
    };

    const handleSetItemDeliveryStatus = (itemId: number, status: DeliveryStatus) => {
        setItemLoadingId(itemId);
        fetch(`/api/orders/items/${itemId}/delivery-status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Setzen des Lieferstatus.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage(`Lieferstatus: ${DELIVERY_STATUS_LABELS[status]}.`);
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Setzen des Lieferstatus.');
            })
            .finally(() => {
                setItemLoadingId(null);
            });
    };

    const handleSetOrderDeliveryStatus = (orderId: number, status: DeliveryStatus) => {
        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/delivery-status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Setzen des Lieferstatus.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage(`${data.changedCount} Posten auf "${DELIVERY_STATUS_LABELS[status]}" gesetzt.`);
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Setzen des Lieferstatus.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleAcceptOrder = (orderId: number) => {
        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/accept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Annehmen der Bestellung.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Bestellung erfolgreich angenommen. Sie ist nun "In Bearbeitung".');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Annehmen.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleUnacceptOrder = (orderId: number) => {
        if (!confirm('Möchtest du die Annahme dieser Bestellung wirklich zurückziehen?')) return;

        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/unaccept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Zurückziehen der Annahme.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Annahme zurückgezogen. Bestellung ist wieder offen.');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Zurückziehen.');
            })
            .finally(() => {
                setActionLoadingId(null);
            });
    };

    const handleCompleteOrder = (order: Order) => {
        const question = order.isFullyReceived
            ? 'Bestellung archivieren? Sie wird ins Archiv verschoben.'
            : `Erst ${order.receivedItemCount} von ${order.totalItemCount} Posten sind als erhalten markiert. Trotzdem archivieren?`;
        if (!confirm(question)) return;

        const orderId = order.id;
        setActionLoadingId(orderId);
        fetch(`/api/orders/${orderId}/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Archivieren der Bestellung.');
                }
                return res.json();
            })
            .then(data => {
                updateOrderInState(data.order);
                showFeedbackMessage('Bestellung archiviert.');
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Archivieren.');
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
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Stornieren der Bestellung.');
                }
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
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Wiedereröffnen der Bestellung.');
                }
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
        _copyMultibuyLines(targetItems);
        showFeedbackMessage(`Multibuy-Liste für Order #${order.id} kopiert.`);
    };

    const _copyMultibuyLines = (items: OrderItem[]) => {
        const lines = items.map(i => `${i.name} ${i.amount}`);
        navigator.clipboard.writeText(lines.join('\n'));
    };

    const handleCopyShareMultibuy = (items: OrderItem[], label: string) => {
        _copyMultibuyLines(items);
        showFeedbackMessage(`Multibuy-Liste (${label}) kopiert.`);
    };

    const handleCopyShareAmount = (amount: number, label: string) => {
        navigator.clipboard.writeText(roundIsk(amount).toString());
        showFeedbackMessage(`${label}: ${formatIsk(amount)} ISK kopiert.`);
    };

    const handleCopyAmount = (order: Order) => {
        navigator.clipboard.writeText(roundIsk(order.totalPrice).toString());
        showFeedbackMessage(`Betrag ${formatIsk(order.totalPrice)} ISK kopiert.`);
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

    const renderOrderCard = (order: Order) => {
        const isExpanded = expandedOrderIds.includes(order.id);
        const isActiveOrder = order.status !== 'CANCELLED' && order.status !== 'FULFILLED';
        const canManageOrder = isOfficer || (currentUserId && order.user.id === currentUserId);
        const hasUnreceivedTakenItems = order.items.some(i => i.isFulfilled && i.deliveryStatus !== 'RECEIVED');

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
                    <div className="flex items-center gap-3 min-w-55">
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
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-sm font-bold text-white">{order.title}</h3>
                                {getStatusBadge(order.status)}
                                {isActiveOrder && order.isFullyReceived && (
                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold border bg-emerald-500/20 text-emerald-200 border-emerald-500/50">
                                        Vollständig erhalten, bereit zum Archivieren
                                    </span>
                                )}
                                {order.fulfillers && order.fulfillers.length > 0 ? (
                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold border bg-blue-500/15 text-blue-300 border-blue-500/30">
                                        Wird bedient von: {order.fulfillers.map(f => f.displayName).join(', ')}
                                    </span>
                                ) : order.fulfiller ? (
                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold border bg-blue-500/15 text-blue-300 border-blue-500/30">
                                        Wird bedient von: {order.fulfiller.displayName}
                                    </span>
                                ) : null}
                            </div>
                            <p className="text-[11px] text-eve-muted mt-0.5">
                                Von <span className="text-slate-300 font-semibold">{order.user.displayName}</span> am {order.createdAt}
                                {order.fulfillers && order.fulfillers.length > 0 ? (
                                    <span className="ml-2">
                                        • Bearbeiter: <span className="text-blue-300 font-medium">{order.fulfillers.map(f => f.displayName).join(', ')}</span>
                                    </span>
                                ) : order.fulfiller ? (
                                    <span className="ml-2">
                                        • Bearbeiter: <span className="text-blue-300 font-medium">{order.fulfiller.displayName}</span>
                                    </span>
                                ) : null}
                            </p>
                        </div>
                    </div>

                    {/* Progress & Stats */}
                    <div className="flex flex-wrap items-center gap-6">
                        <OrderProgress order={order} />

                        <div className="text-right">
                            <span className="text-[10px] text-eve-muted block">Gesamtwert ({order.percentToJita}%):</span>
                            <span className="text-sm font-bold text-eve-primary">
                                {formatIsk(order.totalPrice)} ISK
                            </span>
                            {order.status === 'OPEN' && livePrices[order.id] && livePrices[order.id].priceDiff !== 0 && (
                                <span className={`text-[10px] block ${livePrices[order.id].priceDiffPercent > 0 ? 'text-amber-400' : 'text-emerald-400'}`}>
                                    Markt: {formatIsk(livePrices[order.id].liveTotalPrice)} ({livePrices[order.id].priceDiffPercent > 0 ? '+' : ''}{livePrices[order.id].priceDiffPercent}%)
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
                    <OrderNote note={order.note} />
                </div>

                {/* Expanded Content */}
                {isExpanded && (
                    <div className="p-4 border-t border-eve-border/60 bg-black/30">
                        {/* Action Bar with all amounts */}
                        <div className="mb-4 rounded-lg bg-black/40 border border-eve-border/40 text-xs overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-3 p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-eve-muted font-semibold mr-1">Kopierhilfe:</span>
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
                                    {/* Mass Button: Take all remaining open positions at once */}
                                    {order.status !== 'CANCELLED' && order.status !== 'FULFILLED' && order.fulfilledItemCount < order.totalItemCount && (
                                        <button
                                            type="button"
                                            onClick={() => handleAcceptOrder(order.id)}
                                            disabled={actionLoadingId === order.id}
                                            className="px-3 py-1 rounded bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer font-semibold"
                                            title="Nimmt alle noch offenen Positionen dieser Bestellung auf einmal an"
                                        >
                                            {order.fulfilledItemCount > 0 ? 'Restliche Posten annehmen' : 'Alle Posten annehmen'}
                                        </button>
                                    )}

                                    {/* Receipt: customer confirms all taken items at once */}
                                    {isActiveOrder && canManageOrder && hasUnreceivedTakenItems && (
                                        <button
                                            type="button"
                                            onClick={() => handleSetOrderDeliveryStatus(order.id, 'RECEIVED')}
                                            disabled={actionLoadingId === order.id}
                                            className="px-3 py-1 rounded bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer font-semibold"
                                            title="Bestätigt den Empfang aller übernommenen Posten"
                                        >
                                            Alle übernommenen Posten erhalten
                                        </button>
                                    )}

                                    {/* Archive: only the customer or officers */}
                                    {isActiveOrder && canManageOrder && (
                                        <button
                                            type="button"
                                            onClick={() => handleCompleteOrder(order)}
                                            disabled={actionLoadingId === order.id}
                                            className={`px-3 py-1 rounded cursor-pointer font-semibold ${
                                                order.isFullyReceived
                                                    ? 'bg-emerald-500/20 border border-emerald-500/60 text-emerald-200 hover:bg-emerald-500/30 shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                                                    : 'bg-[#0a0f1d] border border-white/10 text-slate-300 hover:text-white hover:bg-white/5'
                                            }`}
                                            title="Verschiebt die Bestellung ins Archiv"
                                        >
                                            Archivieren
                                        </button>
                                    )}

                                    {/* Release items: Fulfiller, creator or officer can cancel acceptance / release back to OPEN */}
                                    {order.status !== 'CANCELLED' && order.status !== 'FULFILLED' && order.fulfilledItemCount > 0 && (
                                        canManageOrder ||
                                        (order.fulfiller && currentUserId === order.fulfiller.id) ||
                                        (currentUserId && order.items.some(i => i.isFulfilled && i.fulfiller?.id === currentUserId))
                                    ) && (
                                        <button
                                            type="button"
                                            onClick={() => handleUnacceptOrder(order.id)}
                                            disabled={actionLoadingId === order.id}
                                            className="px-2.5 py-1 rounded bg-black/40 border border-white/20 text-slate-300 hover:text-white hover:border-white/40 cursor-pointer"
                                            title="Gibt deine übernommenen Positionen wieder frei"
                                        >
                                            Posten freigeben
                                        </button>
                                    )}

                                    {/* Stornieren: OPEN or IN_PROGRESS */}
                                    {(order.status === 'OPEN' || order.status === 'IN_PROGRESS') && canManageOrder && (
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
                            <OrderAmountsList
                                order={order}
                                currentUserId={currentUserId ?? null}
                                onCopyAmount={handleCopyShareAmount}
                                onCopyMultibuy={handleCopyShareMultibuy}
                            />
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
                                        <th className="p-2.5 text-center">Status / Lieferant</th>
                                        {isActiveOrder && (
                                            <th className="p-2.5 text-center">Aktion</th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/5 bg-black/20">
                                    {order.items.map(item => {
                                        const isItemFulfiller = currentUserId && item.fulfiller?.id === currentUserId;
                                        const canReleaseItem = (canManageOrder || isItemFulfiller) && !isDelivered(item);
                                        const customerStatus = getCustomerStatus(item);
                                        const takeAmount = takeAmounts[item.id] ?? item.amount;

                                        return (
                                            <tr key={item.id} className={`hover:bg-white/5 transition-colors ${item.isFulfilled ? 'bg-emerald-950/10' : ''}`}>
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
                                                    <div className="flex items-center gap-1.5 group">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopyItemName(item.name)}
                                                            title="Klicken zum Kopieren (für EVE Inventar-Filter)"
                                                            className="text-left font-medium text-white hover:text-amber-300 transition-colors inline-flex items-center gap-1.5 cursor-pointer text-xs"
                                                        >
                                                            <span>{item.name}</span>
                                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 text-white/30 group-hover:text-amber-400 opacity-60 group-hover:opacity-100 transition-opacity shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenMarketItem(item.typeId)}
                                                            title="Markt im Spiel öffnen"
                                                            className="text-eve-muted hover:text-eve-primary cursor-pointer text-[10px]"
                                                        >
                                                            [Markt]
                                                        </button>
                                                        <OwnedStockBadge stock={findOwnedStock(ownedStock, item.typeId)} requiredAmount={item.amount} />
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
                                                    {formatIsk(item.totalPrice)}
                                                </td>
                                                <td className="p-2 text-center">
                                                    {customerStatus ? (
                                                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold border ${DELIVERY_STATUS_STYLES[customerStatus]}`}>
                                                            {DELIVERY_STATUS_LABELS[customerStatus]} {item.fulfiller ? `(${item.fulfiller.displayName})` : ''}
                                                        </span>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded text-[10px] font-medium bg-white/5 border border-white/10 text-eve-muted">
                                                            Offen
                                                        </span>
                                                    )}
                                                </td>
                                                {isActiveOrder && (
                                                    <td className="p-2 text-center">
                                                        {!item.isFulfilled ? (
                                                            <div className="inline-flex items-center gap-1.5">
                                                                {item.amount > 1 && (
                                                                    <input
                                                                        type="number"
                                                                        min={1}
                                                                        max={item.amount}
                                                                        value={takeAmount}
                                                                        onChange={(e) => setTakeAmounts(prev => ({ ...prev, [item.id]: Math.min(item.amount, Math.max(1, parseInt(e.target.value, 10) || 1)) }))}
                                                                        className="w-20 rounded px-1.5 py-0.5 text-[11px] text-right font-mono border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                                                                        title="Menge, die du übernimmst (Rest bleibt offen)"
                                                                    />
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleFulfillItem(item.id, item.isFulfilled, takeAmount < item.amount ? takeAmount : undefined)}
                                                                    disabled={itemLoadingId === item.id}
                                                                    className="px-2.5 py-1 rounded text-[11px] font-semibold bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer disabled:opacity-50"
                                                                    title="Diesen Posten (oder die eingegebene Teilmenge) übernehmen"
                                                                >
                                                                    {itemLoadingId === item.id ? '...' : 'Annehmen'}
                                                                </button>
                                                            </div>
                                                        ) : canManageOrder && item.deliveryStatus !== 'RECEIVED' ? (
                                                            <div className="inline-flex items-center gap-1.5">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleSetItemDeliveryStatus(item.id, 'RECEIVED')}
                                                                    disabled={itemLoadingId === item.id}
                                                                    className="px-2.5 py-1 rounded text-[11px] font-semibold bg-emerald-500/15 border border-emerald-500/50 text-emerald-300 hover:brightness-115 cursor-pointer disabled:opacity-50"
                                                                    title="Empfang dieses Postens bestätigen"
                                                                >
                                                                    {itemLoadingId === item.id ? '...' : 'Erhalten'}
                                                                </button>
                                                                {canReleaseItem && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleFulfillItem(item.id, item.isFulfilled)}
                                                                        disabled={itemLoadingId === item.id}
                                                                        className="px-2.5 py-1 rounded text-[11px] font-medium bg-black/40 border border-white/20 text-slate-300 hover:text-white hover:border-white/40 cursor-pointer disabled:opacity-50"
                                                                        title="Diesen Posten wieder freigeben"
                                                                    >
                                                                        Freigeben
                                                                    </button>
                                                                )}
                                                            </div>
                                                        ) : canManageOrder ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleSetItemDeliveryStatus(item.id, 'DELIVERED')}
                                                                disabled={itemLoadingId === item.id}
                                                                className="px-2.5 py-1 rounded text-[11px] font-medium bg-black/40 border border-white/20 text-slate-300 hover:text-white hover:border-white/40 cursor-pointer disabled:opacity-50"
                                                                title="Empfangsbestätigung zurücknehmen"
                                                            >
                                                                {itemLoadingId === item.id ? '...' : 'Nicht erhalten'}
                                                            </button>
                                                        ) : canReleaseItem ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleFulfillItem(item.id, item.isFulfilled)}
                                                                disabled={itemLoadingId === item.id}
                                                                className="px-2.5 py-1 rounded text-[11px] font-medium bg-black/40 border border-white/20 text-slate-300 hover:text-white hover:border-white/40 cursor-pointer disabled:opacity-50"
                                                                title="Diesen Posten wieder freigeben"
                                                            >
                                                                {itemLoadingId === item.id ? '...' : 'Freigeben'}
                                                            </button>
                                                        ) : (
                                                            <span className="text-[10px] text-eve-muted italic">
                                                                Belegt
                                                            </span>
                                                        )}
                                                    </td>
                                                )}
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
    };

    const toggleSection = (type: 'BUY' | 'SELL') => {
        setCollapsedSections(prev => ({ ...prev, [type]: !prev[type] }));
    };

    const renderOrderSection = (type: 'BUY' | 'SELL', orders: Order[]) => {
        const section = ORDER_SECTIONS[type];
        const isCollapsed = collapsedSections[type];
        return (
            <section className={`border-l-4 ${section.accentClass} pl-4`}>
                <button
                    type="button"
                    onClick={() => toggleSection(type)}
                    aria-expanded={!isCollapsed}
                    className="w-full flex items-center gap-3 py-1 cursor-pointer group"
                >
                    <h2 className={`text-xs font-bold uppercase tracking-wider ${section.headingClass}`}>{section.title}</h2>
                    <span className="text-[11px] text-eve-muted group-hover:text-slate-300">{section.subtitle} ({orders.length})</span>
                    <div className={`flex-1 h-px ${section.lineClass}`} />
                    <span className={`${section.headingClass} transition-transform duration-300 ${isCollapsed ? '-rotate-90' : ''}`}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                </button>
                {!isCollapsed && (
                    <div className="mt-3">
                        {orders.length === 0 ? (
                            <div className="p-4 rounded-lg border border-eve-border bg-eve-card/40 text-center text-eve-muted text-xs">
                                Keine {viewMode === 'active' ? 'aktiven' : 'archivierten'} {section.title} gefunden.
                            </div>
                        ) : (
                            <div className="flex flex-col gap-4">
                                {orders.map(order => renderOrderCard(order))}
                            </div>
                        )}
                    </div>
                )}
            </section>
        );
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
                        <span className="text-sm font-semibold text-white">Janice-Rechner &amp; neue Bestellung</span>
                        <span className="text-xs text-eve-muted">(EFT-Fit, Hangar-Liste oder Multibuy bewerten und als Order aufgeben)</span>
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
                            onOrderCreated={handleOrderCreated}
                            embedded={true}
                        />
                    </div>
                )}
            </div>

            {/* View Mode & Filters */}
            <div className="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-eve-border/40">
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
                            onClick={() => setViewMode('deliveries')}
                            className={`px-3 py-1 rounded text-xs font-semibold transition-all cursor-pointer flex items-center gap-1.5 ${
                                viewMode === 'deliveries'
                                    ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                                    : 'text-eve-muted hover:text-white'
                            }`}
                        >
                            <span>📦 Meine Lieferungen</span>
                            {myDeliveries.length > 0 && (
                                <span className={`px-1.5 py-0.2 rounded-full text-[10px] font-bold ${viewMode === 'deliveries' ? 'bg-emerald-400 text-black' : 'bg-white/10 text-slate-300'}`}>
                                    {myDeliveries.length}
                                </span>
                            )}
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

                    {viewMode !== 'deliveries' && (
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Auftrag, Item oder Member suchen..."
                            className="w-56 rounded-lg px-3 py-1.5 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                        />
                    )}
                </div>
            </div>

            {/* View Mode Content */}
            {viewMode === 'deliveries' ? (
                <DeliveryPackingList
                    orders={myDeliveries}
                    currentUserId={currentUserId}
                    ownedStock={ownedStock}
                    onCopyUser={handleCopyUser}
                    onCopyAmount={handleCopyAmount}
                    onCopyTitle={handleCopyTitle}
                    onCopyMultibuy={handleCopyMultibuy}
                    onCopyItemName={handleCopyItemName}
                    onOpenMarketItem={handleOpenMarketItem}
                    onUnacceptOrder={handleUnacceptOrder}
                    onFulfillItem={handleFulfillItem}
                    onSetItemDeliveryStatus={handleSetItemDeliveryStatus}
                    onSetOrderDeliveryStatus={handleSetOrderDeliveryStatus}
                    onGoToActiveOrders={() => setViewMode('active')}
                    actionLoadingId={actionLoadingId}
                    itemLoadingId={itemLoadingId}
                />
            ) : (
                <div className="flex flex-col gap-8">
                    {renderOrderSection('BUY', filteredBuyOrders)}
                    {renderOrderSection('SELL', filteredSellOrders)}
                </div>
            )}
        </div>
    );
}
