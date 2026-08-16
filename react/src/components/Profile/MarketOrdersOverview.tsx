import React, { useState, useEffect, useMemo, useRef } from 'react';

interface MarketOrder {
    order_id: string;
    price: number;
    volume_total: number;
    volume_remain: number;
    is_buy: boolean;
    range: string;
    min_volume: number;
    is_own: boolean;
    character_name: string | null;
}

interface MarketOrderInfo {
    order_id: string;
    type_id: number;
    item_name: string;
    price: number;
    volume_total: number;
    volume_remain: number;
    is_buy: boolean;
    location_id: string;
    location_name: string;
    system_name: string;
    range: string;
    min_volume: number;
    is_outbid: boolean;
}

interface CharacterGroup {
    character_id: number;
    character_name: string;
    orders: MarketOrderInfo[];
}

interface MarketOrdersOverviewProps {
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
}

interface OrderBookDetails {
    buy_orders: MarketOrder[];
    sell_orders: MarketOrder[];
}

export default function MarketOrdersOverview({ apiDataUrl, imagePaths }: MarketOrdersOverviewProps) {
    const [data, setData] = useState<CharacterGroup[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);

    // Collapsible states
    const [openCharacters, setOpenCharacters] = useState<Record<number, boolean>>({});
    const [openOrders, setOpenOrders] = useState<Record<string, boolean>>({});
    const [openSellSections, setOpenSellSections] = useState<Record<number, boolean>>({});
    const [openBuySections, setOpenBuySections] = useState<Record<number, boolean>>({});

    // Competitor details states grouped by character ID and item key (typeId_locationId)
    const [characterDetails, setCharacterDetails] = useState<Record<number, Record<string, OrderBookDetails>>>({});
    const [loadingCharDetails, setLoadingCharDetails] = useState<Record<number, boolean>>({});
    const [errorCharDetails, setErrorCharDetails] = useState<Record<number, string | null>>({});

    const fetchCharDetails = async (charGroup: CharacterGroup) => {
        const charId = charGroup.character_id;
        setLoadingCharDetails(prev => ({ ...prev, [charId]: true }));
        setErrorCharDetails(prev => ({ ...prev, [charId]: null }));

        try {
            const fetchPromises = charGroup.orders.map(async (order) => {
                try {
                    const detailsUrl = `/personal/market/details?type_id=${order.type_id}&location_id=${order.location_id}`;
                    const response = await fetch(detailsUrl);
                    if (!response.ok) {
                        return { key: `${order.type_id}_${order.location_id}`, data: null };
                    }
                    const json = await response.json();
                    return { key: `${order.type_id}_${order.location_id}`, data: json };
                } catch (e) {
                    return { key: `${order.type_id}_${order.location_id}`, data: null };
                }
            });

            const results = await Promise.all(fetchPromises);
            const detailsMap: Record<string, OrderBookDetails> = {};
            
            results.forEach(res => {
                if (res.data) {
                    detailsMap[res.key] = res.data;
                }
            });

            setCharacterDetails(prev => ({
                ...prev,
                [charId]: detailsMap
            }));
        } catch (e: any) {
            setErrorCharDetails(prev => ({ ...prev, [charId]: 'Fehler beim Laden einiger Marktstände.' }));
        } finally {
            setLoadingCharDetails(prev => ({ ...prev, [charId]: false }));
        }
    };

    const fetchData = async () => {
        setLoading(true);
        setError(null);
        setCharacterDetails({});
        setLoadingCharDetails({});
        setErrorCharDetails({});
        try {
            const response = await fetch(apiDataUrl);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const json = await response.json();
            setData(json);

            // Auto-select first character and load their details
            if (json.length > 0) {
                const firstCharId = json[0].character_id;
                setOpenCharacters({ [firstCharId]: true });
                fetchCharDetails(json[0]);
            } else {
                setOpenCharacters({});
            }
        } catch (e: any) {
            setError(e.message || 'Fehler beim Laden der Marktdaten.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, [apiDataUrl]);

    const toggleCharacter = (charId: number) => {
        const isOpen = !openCharacters[charId];
        setOpenCharacters(prev => ({
            ...prev,
            [charId]: isOpen
        }));

        if (isOpen) {
            const group = data.find(g => g.character_id === charId);
            if (group) {
                fetchCharDetails(group);
            }
        }
    };

    const toggleSellSection = (charId: number) => {
        setOpenSellSections(prev => ({
            ...prev,
            [charId]: prev[charId] !== false ? false : true
        }));
    };

    const toggleBuySection = (charId: number) => {
        setOpenBuySections(prev => ({
            ...prev,
            [charId]: prev[charId] !== false ? false : true
        }));
    };

    const toggleOrder = (orderId: string) => {
        setOpenOrders(prev => ({
            ...prev,
            [orderId]: !prev[orderId]
        }));
    };

    const getCharacterPortraitUrl = (charId: number) => {
        return imagePaths.characters.replace('12345', String(charId));
    };

    const getItemIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', String(typeId));
    };

    const formatIsk = (amount: number): string => {
        return new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' ISK';
    };



    return (
        <div className="w-full max-w-4xl mx-auto flex flex-col gap-4">
            
            {/* Sync Header button */}
            <div className="flex justify-end mb-2">
                <button
                    onClick={fetchData}
                    disabled={loading}
                    className="bg-eve-primary/10 border border-eve-primary hover:bg-eve-primary hover:text-black text-eve-primary font-semibold text-xs py-2 px-4 rounded cursor-pointer transition-all flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {loading ? (
                        <svg className="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                    ) : (
                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.253 8H18" />
                        </svg>
                    )}
                    <span>Aufträge aktualisieren</span>
                </button>
            </div>

            {loading && data.length === 0 ? (
                <div className="bg-eve-card border border-eve-border shadow-eve p-12 rounded-lg flex flex-col items-center justify-center gap-4">
                    <svg className="animate-spin h-8 w-8 text-eve-primary" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                    <p className="text-eve-muted text-sm">Lade deine Charaktere und Marktaufträge...</p>
                </div>
            ) : error ? (
                <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg text-center text-red-400">
                    {error}
                </div>
            ) : data.length === 0 ? (
                <div className="bg-eve-card border border-eve-border shadow-eve p-12 rounded-lg text-center text-eve-muted">
                    Keine aktiven Marktaufträge auf deinen Charakteren gefunden.
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {data.map(charGroup => {
                        const isCharOpen = !!openCharacters[charGroup.character_id];
                        const isSellOpen = openSellSections[charGroup.character_id] !== false;
                        const isBuyOpen = openBuySections[charGroup.character_id] !== false;
                        
                        // Separate sell and buy orders
                        const sellOrders = charGroup.orders.filter(o => !o.is_buy);
                        const buyOrders = charGroup.orders.filter(o => o.is_buy);

                        // Precalculated warning checks
                        const charOutbidCount = charGroup.orders.filter(o => o.is_outbid).length;
                        const sellOutbidCount = sellOrders.filter(o => o.is_outbid).length;
                        const buyOutbidCount = buyOrders.filter(o => o.is_outbid).length;

                        return (
                            <div 
                                key={charGroup.character_id}
                                className="bg-eve-card border border-eve-border/60 shadow-eve rounded-lg overflow-hidden transition-all duration-300"
                            >
                                {/* Character Header Card */}
                                <div
                                    onClick={() => toggleCharacter(charGroup.character_id)}
                                    className="w-full text-left p-4 flex items-center justify-between gap-4 cursor-pointer bg-transparent outline-none select-none"
                                >
                                    <div className="flex items-center gap-3">
                                        <img
                                            src={getCharacterPortraitUrl(charGroup.character_id)}
                                            alt={charGroup.character_name}
                                            className="w-12 h-12 rounded-full border border-eve-border shadow"
                                            onError={(e) => {
                                                (e.target as HTMLImageElement).src = '/assets/images/default_avatar.png';
                                            }}
                                        />
                                        <div>
                                            <div className="flex items-center gap-2.5">
                                                <h2 className="text-lg font-bold text-white leading-tight">
                                                    {charGroup.character_name}
                                                </h2>
                                                {charOutbidCount > 0 && (
                                                    <span className="bg-amber-500/10 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500/20 whitespace-nowrap">
                                                        ⚠️ {charOutbidCount}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-xs text-eve-muted mt-0.5">
                                                {charGroup.orders.length} aktive Aufträge
                                                {sellOrders.length > 0 && ` (${sellOrders.length} Verkauf)`}
                                                {buyOrders.length > 0 && ` (${buyOrders.length} Kauf)`}
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div className="flex items-center gap-3">
                                        {loadingCharDetails[charGroup.character_id] && (
                                            <svg className="animate-spin h-4 w-4 text-eve-primary" viewBox="0 0 24 24" fill="none">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                        )}
                                        <div className="text-eve-muted transition-transform duration-200">
                                            <svg 
                                                className={`h-5 w-5 transform transition-transform ${isCharOpen ? 'rotate-180 text-eve-primary' : ''}`} 
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                {/* Character Body: List of Orders grouped into Sell / Buy */}
                                {isCharOpen && (
                                    <div className="border-t border-eve-border/20 p-4 bg-black/10 flex flex-col gap-6">
                                        
                                        {/* SELLS SECTION */}
                                        <div className="flex flex-col gap-2">
                                            <div 
                                                onClick={() => toggleSellSection(charGroup.character_id)}
                                                className="flex items-center justify-between cursor-pointer select-none p-2.5 rounded bg-black/20 hover:bg-black/30 border border-white/5 transition-all"
                                            >
                                                <h3 className="text-xs font-bold text-rose-400 uppercase tracking-wider flex items-center gap-2 flex-wrap">
                                                    <span className="w-2 h-2 rounded-full bg-rose-500"></span>
                                                    <span>Verkaufsaufträge ({sellOrders.length})</span>
                                                    {sellOutbidCount > 0 && (
                                                        <span className="bg-amber-500/10 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500/20 whitespace-nowrap">
                                                            ⚠️ {sellOutbidCount}
                                                        </span>
                                                    )}
                                                </h3>
                                                <svg 
                                                    className={`h-4 w-4 text-eve-muted transform transition-transform ${isSellOpen ? 'rotate-180 text-rose-400' : ''}`} 
                                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                >
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </div>
                                            
                                            {isSellOpen && (
                                                <div className="mt-1">
                                                    {sellOrders.length === 0 ? (
                                                        <div className="text-xs text-eve-muted italic p-3 bg-black/20 rounded border border-white/5">
                                                            Keine aktiven Verkaufsaufträge.
                                                        </div>
                                                    ) : (
                                                        <div className="flex flex-col gap-3">
                                                            {sellOrders.map(order => (
                                                                <OrderAccordionRow
                                                                    key={order.order_id}
                                                                    order={order}
                                                                    isOpen={!!openOrders[order.order_id]}
                                                                    toggleOrder={toggleOrder}
                                                                    getItemIconUrl={getItemIconUrl}
                                                                    formatIsk={formatIsk}
                                                                    details={characterDetails[charGroup.character_id]?.[`${order.type_id}_${order.location_id}`]}
                                                                />
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {/* BUYS SECTION */}
                                        <div className="flex flex-col gap-2">
                                            <div 
                                                onClick={() => toggleBuySection(charGroup.character_id)}
                                                className="flex items-center justify-between cursor-pointer select-none p-2.5 rounded bg-black/20 hover:bg-black/30 border border-white/5 transition-all"
                                            >
                                                <h3 className="text-xs font-bold text-green-400 uppercase tracking-wider flex items-center gap-2 flex-wrap">
                                                    <span className="w-2 h-2 rounded-full bg-green-500"></span>
                                                    <span>Kaufaufträge ({buyOrders.length})</span>
                                                    {buyOutbidCount > 0 && (
                                                        <span className="bg-amber-500/10 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500/20 whitespace-nowrap">
                                                            ⚠️ {buyOutbidCount}
                                                        </span>
                                                    )}
                                                </h3>
                                                <svg 
                                                    className={`h-4 w-4 text-eve-muted transform transition-transform ${isBuyOpen ? 'rotate-180 text-green-400' : ''}`} 
                                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                >
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </div>
                                            
                                            {isBuyOpen && (
                                                <div className="mt-1">
                                                    {buyOrders.length === 0 ? (
                                                        <div className="text-xs text-eve-muted italic p-3 bg-black/20 rounded border border-white/5">
                                                            Keine aktiven Kaufaufträge.
                                                        </div>
                                                    ) : (
                                                        <div className="flex flex-col gap-3">
                                                            {buyOrders.map(order => (
                                                                <OrderAccordionRow
                                                                    key={order.order_id}
                                                                    order={order}
                                                                    isOpen={!!openOrders[order.order_id]}
                                                                    toggleOrder={toggleOrder}
                                                                    getItemIconUrl={getItemIconUrl}
                                                                    formatIsk={formatIsk}
                                                                    details={characterDetails[charGroup.character_id]?.[`${order.type_id}_${order.location_id}`]}
                                                                />
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
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

interface OrderAccordionRowProps {
    order: MarketOrderInfo;
    isOpen: boolean;
    toggleOrder: (orderId: string) => void;
    getItemIconUrl: (typeId: number) => string;
    formatIsk: (amount: number) => string;
    details: OrderBookDetails | undefined;
}

function OrderAccordionRow({ order, isOpen, toggleOrder, getItemIconUrl, formatIsk, details }: OrderAccordionRowProps) {
    const pct = (order.volume_remain / order.volume_total) * 100;
    
    const statusLabel = order.is_outbid ? '⚠️' : '✅';
    const statusColorClass = order.is_outbid 
        ? 'bg-amber-500/10 border-amber-500/20 text-amber-400 font-bold' 
        : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400 font-bold';

    return (
        <div className="bg-black/30 border border-eve-border/40 hover:border-eve-primary/30 rounded-lg overflow-hidden transition-all">
            {/* Header */}
            <div
                onClick={() => toggleOrder(order.order_id)}
                className="w-full text-left p-3 flex flex-wrap items-center justify-between gap-4 cursor-pointer bg-transparent outline-none select-none"
            >
                <div className="flex items-center gap-3 min-w-[200px] flex-1">
                    <img
                        src={getItemIconUrl(order.type_id)}
                        alt={order.item_name}
                        className="w-10 h-10 rounded border border-eve-border/40"
                        onError={(e) => {
                            (e.target as HTMLImageElement).src = '/assets/images/default_item.png';
                        }}
                    />
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-semibold text-white truncate text-sm">
                                {order.item_name}
                            </span>
                            <span className={`text-[9px] px-1.5 py-0.5 rounded border uppercase tracking-wider ${statusColorClass}`}>
                                {statusLabel}
                            </span>
                        </div>
                        <div className="text-xs font-bold text-eve-primary mt-0.5">
                            {formatIsk(order.price)}
                        </div>
                    </div>
                </div>

                {/* Quantity Progress */}
                <div className="flex flex-col gap-1 w-[130px] flex-shrink-0">
                    <div className="flex justify-between text-[10px] font-mono">
                        <span className="text-white">{order.volume_remain.toLocaleString()}</span>
                        <span className="text-eve-muted">/ {order.volume_total.toLocaleString()}</span>
                    </div>
                    <div className="w-full h-1 bg-black/40 rounded-full overflow-hidden">
                        <div 
                            className={`h-full ${order.is_buy ? 'bg-green-500/80' : 'bg-rose-500/80'}`} 
                            style={{ width: `${pct}%` }}
                        ></div>
                    </div>
                </div>

                {/* Location Summary */}
                <div className="text-xs text-eve-muted max-w-[220px] truncate text-right hidden md:block">
                    <div>{order.system_name}</div>
                    <div className="truncate text-[10px] text-eve-muted/70">{order.location_name}</div>
                </div>

                {/* Arrow */}
                <div className="text-eve-muted flex-shrink-0">
                    <svg 
                        className={`h-4 w-4 transform transition-transform ${isOpen ? 'rotate-180 text-eve-primary' : ''}`} 
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </div>

            {/* Expanded details */}
            {isOpen && (
                <OrderDetailPane
                    details={details}
                    ownOrderId={order.order_id}
                    ownPrice={order.price}
                    isBuy={order.is_buy}
                    formatIsk={formatIsk}
                />
            )}
        </div>
    );
}

interface OrderDetailPaneProps {
    details: OrderBookDetails | undefined;
    ownOrderId: string;
    ownPrice: number;
    isBuy: boolean;
    formatIsk: (amount: number) => string;
}

function OrderDetailPane({ details, ownOrderId, ownPrice, isBuy, formatIsk }: OrderDetailPaneProps) {
    const bookContainerRef = useRef<HTMLDivElement>(null);
    const ownRowRef = useRef<HTMLTableRowElement>(null);

    // Analyze competitor status for the detailed view
    const analyzed = useMemo(() => {
        if (!details) return null;

        // Find lowest competitor sell price
        const competitorSells = details.sell_orders.filter(o => !o.is_own);
        const lowestCompetitorSell = competitorSells.length > 0 
            ? Math.min(...competitorSells.map(o => o.price)) 
            : null;

        // Find highest competitor buy price
        const competitorBuys = details.buy_orders.filter(o => !o.is_own);
        const highestCompetitorBuy = competitorBuys.length > 0 
            ? Math.max(...competitorBuys.map(o => o.price)) 
            : null;

        let ownOrderOutbid = false;

        // Analyze sells
        const sell_orders = details.sell_orders.map(o => {
            let isOutbid = false;
            if (o.is_own) {
                if (lowestCompetitorSell !== null && o.price > lowestCompetitorSell) {
                    isOutbid = true;
                    if (o.order_id === ownOrderId) ownOrderOutbid = true;
                }
            }
            return { ...o, isOutbid };
        });

        // Analyze buys
        const buy_orders = details.buy_orders.map(o => {
            let isOutbid = false;
            if (o.is_own) {
                if (highestCompetitorBuy !== null && o.price < highestCompetitorBuy) {
                    isOutbid = true;
                    if (o.order_id === ownOrderId) ownOrderOutbid = true;
                }
            }
            return { ...o, isOutbid };
        });

        return {
            sell_orders,
            buy_orders,
            ownOrderOutbid
        };
    }, [details, ownOrderId]);

    // Center scrollbar on own order
    useEffect(() => {
        if (details) {
            const timer = setTimeout(() => {
                const container = bookContainerRef.current;
                const row = ownRowRef.current;
                if (container && row) {
                    container.scrollTop = row.offsetTop - (container.clientHeight / 2) + (row.clientHeight / 2);
                }
            }, 80);
            return () => clearTimeout(timer);
        }
    }, [details]);

    if (!details) {
        return (
            <div className="border-t border-eve-border/30 bg-black/40 p-6 rounded-b-lg flex items-center justify-center gap-3 text-xs text-eve-muted">
                Warte auf Abschluss der Datenabfrage...
            </div>
        );
    }

    if (!analyzed) {
        return (
            <div className="border-t border-eve-border/30 bg-black/40 p-4 rounded-b-lg text-center text-xs text-eve-muted">
                Keine Marktdaten verfügbar.
            </div>
        );
    }

    return (
        <div className="border-t border-eve-border/30 bg-black/50 p-4 rounded-b-lg flex flex-col gap-4">
            
            {/* Status Alert Badge */}
            <div className="flex items-center justify-between border-b border-white/5 pb-2">
                <span className="text-xs font-semibold text-eve-muted uppercase tracking-wider">
                    Marktstand an dieser Station
                </span>
                
                {analyzed.ownOrderOutbid ? (
                    <span className="bg-amber-500/10 text-amber-400 text-xs font-bold px-2.5 py-0.5 rounded border border-amber-500/20 flex items-center gap-1.5">
                        <span>⚠️ Nachbessern nötig!</span>
                        <span className="font-normal text-[10px] text-amber-400/80">
                            (Du wurdest {isBuy ? 'überboten' : 'unterboten'})
                        </span>
                    </span>
                ) : (
                    <span className="bg-emerald-500/20 text-emerald-400 text-xs font-bold px-2.5 py-0.5 rounded border border-emerald-500/30 flex items-center gap-1.5">
                        <span>✅ Bester Preis</span>
                        <span className="font-normal text-[10px] text-emerald-400/80">
                            (Dein Auftrag steht ganz oben)
                        </span>
                    </span>
                )}
            </div>

            {/* Sell and Buy books stacked vertically */}
            <div className="flex flex-col gap-4">
                
                {/* SELL BOOK */}
                <div className="w-full border border-white/5 bg-black/25 rounded-lg overflow-hidden">
                    <div className="bg-black/40 px-3 py-1.5 border-b border-white/5 flex items-center justify-between text-[11px] font-bold text-white">
                        <span className="flex items-center gap-1.5">
                            <span className="w-2 h-2 rounded-full bg-rose-500"></span>
                            <span>Verkäufer (Sell Orders)</span>
                        </span>
                        <span className="text-[10px] text-eve-muted">{analyzed.sell_orders.length} Einträge</span>
                    </div>

                    <div className="max-h-[180px] overflow-y-auto" ref={!isBuy ? bookContainerRef : undefined}>
                        <table className="w-full text-left text-[11px] border-collapse">
                            <thead>
                                <tr className="border-b border-white/5 text-eve-muted bg-white/[0.01] text-[10px] uppercase font-semibold">
                                    <th className="p-2 w-1/2 text-right pr-4">Menge</th>
                                    <th className="p-2 w-1/2 text-left pl-4">Preis</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5 font-mono">
                                {analyzed.sell_orders.length === 0 ? (
                                    <tr>
                                        <td colSpan={2} className="p-4 text-center text-eve-muted italic">Keine Sell-Orders.</td>
                                    </tr>
                                ) : (
                                    analyzed.sell_orders.map(order => (
                                        <tr 
                                            key={order.order_id}
                                            ref={order.order_id === ownOrderId ? ownRowRef : undefined}
                                            className={`transition-colors ${
                                                order.is_own 
                                                    ? 'bg-eve-primary/5 text-eve-primary font-bold' 
                                                    : 'text-white/90 hover:bg-white/[0.01]'
                                            }`}
                                        >
                                            <td className="p-2 text-right pr-4">
                                                {order.volume_remain.toLocaleString()}
                                            </td>
                                            <td className="p-2 text-left pl-4">
                                                <div className="flex items-center justify-start gap-1.5">
                                                    <span className={order.is_own ? 'text-eve-primary font-bold' : 'text-white'}>
                                                        {formatIsk(order.price)}
                                                    </span>
                                                    {order.is_own && <span className="text-[9px] font-normal text-eve-primary/70">(Du)</span>}
                                                    {order.isOutbid && <span className="text-[9px] text-amber-400 font-bold">⚠️</span>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* BUY BOOK */}
                <div className="w-full border border-white/5 bg-black/25 rounded-lg overflow-hidden">
                    <div className="bg-black/40 px-3 py-1.5 border-b border-white/5 flex items-center justify-between text-[11px] font-bold text-white">
                        <span className="flex items-center gap-1.5">
                            <span className="w-2 h-2 rounded-full bg-green-500"></span>
                            <span>Käufer (Buy Orders)</span>
                        </span>
                        <span className="text-[10px] text-eve-muted">{analyzed.buy_orders.length} Einträge</span>
                    </div>

                    <div className="max-h-[180px] overflow-y-auto" ref={isBuy ? bookContainerRef : undefined}>
                        <table className="w-full text-left text-[11px] border-collapse">
                            <thead>
                                <tr className="border-b border-white/5 text-eve-muted bg-white/[0.01] text-[10px] uppercase font-semibold">
                                    <th className="p-2 w-1/2 text-right pr-4">Menge</th>
                                    <th className="p-2 w-1/2 text-left pl-4">Preis</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5 font-mono">
                                {analyzed.buy_orders.length === 0 ? (
                                    <tr>
                                        <td colSpan={2} className="p-4 text-center text-eve-muted italic">Keine Buy-Orders.</td>
                                    </tr>
                                ) : (
                                    analyzed.buy_orders.map(order => (
                                        <tr 
                                            key={order.order_id}
                                            ref={order.order_id === ownOrderId ? ownRowRef : undefined}
                                            className={`transition-colors ${
                                                order.is_own 
                                                    ? 'bg-eve-primary/5 text-eve-primary font-bold' 
                                                    : 'text-white/90 hover:bg-white/[0.01]'
                                            }`}
                                        >
                                            <td className="p-2 text-right pr-4">
                                                {order.volume_remain.toLocaleString()}
                                            </td>
                                            <td className="p-2 text-left pl-4">
                                                <div className="flex items-center justify-start gap-1.5">
                                                    <span className={order.is_own ? 'text-eve-primary font-bold' : 'text-white'}>
                                                        {formatIsk(order.price)}
                                                    </span>
                                                    {order.is_own && <span className="text-[9px] font-normal text-eve-primary/70">(Du)</span>}
                                                    {order.isOutbid && <span className="text-[9px] text-amber-400 font-bold">⚠️</span>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    );
}
