import { ReactNode, useEffect, useState } from 'react';
import {
    formatAge, formatIsk, formatNumber, formatPercent, formatPriceForClipboard, isLastOpened, LAST_OPENED_ROW_CLASS,
    LastOpenedItem, loadStored, saveStored, typeIconUrl,
} from './tradingShared';

// Larger jumps are flagged, the competitor may have placed a bait order
const LARGE_PRICE_CHANGE = 0.2;

export interface TradingOrder {
    orderId: string;
    typeId: number;
    itemName: string;
    locationId: string;
    locationName: string;
    isBuy: boolean;
    price: number;
    volumeRemain: number;
    volumeTotal: number;
    issued: string | null;
    status: 'outbid' | 'best' | 'gone' | 'unchecked';
    bestCompetitorPrice: number | null;
    competitorsAhead: number;
    competitorVolumeAhead: number;
    suggestedPrice: number | null;
    checkedAt: string | null;
    oppositeBestPrice: number | null;
    priceChange: number | null;
    marginAfter: number | null;
}

export interface MyOrdersData {
    orders: TradingOrder[];
    characterName: string | null;
}

interface CopiedPrice {
    price: number;
    copiedAt: number;
}

const COPIED_STORAGE_KEY = 'wh-trading-copied-prices';
// A copied price older than this no longer marks the order as being adjusted
const COPIED_MAX_AGE_MS = 2 * 60 * 60 * 1000;

// Location IDs of the market cards the user folded away
const COLLAPSED_STORAGE_KEY = 'wh-trading-collapsed-markets';

const STATUS_GROUPS: { status: TradingOrder['status']; title: string; hint: string }[] = [
    { status: 'outbid', title: 'Handlungsbedarf', hint: 'Klick kopiert den neuen Preis und öffnet das Marktfenster im Spiel.' },
    { status: 'best', title: 'Beste Order', hint: '' },
    { status: 'gone', title: 'Nicht mehr im Orderbuch', hint: 'Seit dem letzten Abgleich erfüllt, storniert oder abgelaufen; verschwindet mit dem nächsten Order-Sync des Charakters.' },
    { status: 'unchecked', title: 'Nicht geprüft', hint: 'Nur Orders an den fünf Handelsdrehkreuzen werden mit dem Orderbuch abgeglichen.' },
];

// Buy orders get overbid, sell orders get undercut
const SIDES = [
    { key: 'sell', isBuy: false, title: 'Verkaufsorders', outbidLabel: 'unterboten' },
    { key: 'buy', isBuy: true, title: 'Kauforders', outbidLabel: 'überboten' },
];

interface MyOrdersTabProps {
    data: MyOrdersData | null;
    error: string | null;
    typeIconTemplate: string;
    lastOpened: LastOpenedItem | null;
    onOpenMarket: (typeId: number, orderId?: string | null) => Promise<void>;
    onNotice: (message: string, isError?: boolean) => void;
}

export default function MyOrdersTab({ data, error, typeIconTemplate, lastOpened, onOpenMarket, onNotice }: MyOrdersTabProps) {
    const [copiedPrices, setCopiedPrices] = useState<Record<string, CopiedPrice>>(() => _loadCopiedPrices());
    const [now, setNow] = useState(Date.now());
    const [collapsedMarkets, setCollapsedMarkets] = useState<string[]>(() => _loadCollapsedMarkets());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30000);
        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        saveStored(COPIED_STORAGE_KEY, copiedPrices);
    }, [copiedPrices]);

    if (error) {
        return <div className="bg-eve-card border border-red-500/30 rounded-lg p-4 text-sm text-red-400">{error}</div>;
    }
    if (!data) {
        return <div className="bg-eve-card border border-eve-border rounded-lg p-6 text-center text-eve-muted text-sm">Lade Orders...</div>;
    }
    if (data.characterName === null) {
        return <div className="bg-eve-card border border-eve-border rounded-lg p-6 text-center text-eve-muted text-sm">Wähle oben einen Handels-Charakter.</div>;
    }

    const handleOrderClick = async (order: TradingOrder) => {
        // Copy first, browsers only allow clipboard access right after the click
        if (order.suggestedPrice !== null) {
            try {
                await navigator.clipboard.writeText(formatPriceForClipboard(order.suggestedPrice));
                setCopiedPrices(previous => ({ ...previous, [order.orderId]: { price: order.suggestedPrice as number, copiedAt: Date.now() } }));
                onNotice(`${formatIsk(order.suggestedPrice)} kopiert, Marktfenster für ${order.itemName} wird geöffnet.`);
            } catch (e) {
                onNotice('Zwischenablage nicht verfügbar, Preis bitte von Hand übernehmen.', true);
            }
        }
        await onOpenMarket(order.typeId, order.orderId);
    };

    const toggleMarket = (locationId: string) => {
        setCollapsedMarkets(previous => {
            const next = previous.includes(locationId) ? previous.filter(id => id !== locationId) : [...previous, locationId];
            saveStored(COLLAPSED_STORAGE_KEY, next);
            return next;
        });
    };

    const renderRow = (order: TradingOrder) => (
        <OrderRow
            key={order.orderId}
            order={order}
            copied={_activeCopy(copiedPrices[order.orderId], order, now)}
            isLastOpened={isLastOpened(lastOpened, order.typeId, order.orderId)}
            now={now}
            typeIconTemplate={typeIconTemplate}
            onClick={() => handleOrderClick(order)}
        />
    );

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs text-eve-muted">
                Orders von <span className="text-white">{data.characterName}</span>. Der Abgleich läuft mit jedem Abzug der Handelsdrehkreuze (Jita alle 10, die anderen alle 15 Minuten);
                neue Orders erscheinen nach dem nächsten Order-Sync des Charakters (ESI-Cache bis 20 Minuten).
            </p>
            <StatusSummary orders={data.orders} />
            {_groupByMarket(data.orders).map(market => (
                <MarketCard
                    key={market.locationId}
                    market={market}
                    isCollapsed={collapsedMarkets.includes(market.locationId)}
                    onToggle={() => toggleMarket(market.locationId)}
                    renderRow={renderRow}
                />
            ))}
            {data.orders.length === 0 && (
                <div className="bg-eve-card border border-eve-border rounded-lg p-6 text-center text-eve-muted text-sm">Keine aktiven Orders.</div>
            )}
        </div>
    );
}

interface MarketGroup {
    locationId: string;
    locationName: string;
    orders: TradingOrder[];
}

// One collapsible card per market, outbid orders stay highlighted in the header while collapsed
function MarketCard({ market, isCollapsed, onToggle, renderRow }: { market: MarketGroup; isCollapsed: boolean; onToggle: () => void; renderRow: (order: TradingOrder) => ReactNode }) {
    let outbidCount = 0;
    let buyCount = 0;
    for (const order of market.orders) {
        if (order.status === 'outbid') {
            outbidCount++;
        }
        if (order.isBuy) {
            buyCount++;
        }
    }

    return (
        <div className={`bg-eve-card border shadow-eve rounded-lg overflow-x-auto ${outbidCount > 0 ? 'border-amber-500/40' : 'border-eve-border'}`}>
            <button
                type="button"
                onClick={onToggle}
                className="w-full px-4 py-3 flex items-center justify-between gap-4 flex-wrap text-left cursor-pointer hover:bg-white/5"
            >
                <div className="flex items-center gap-3">
                    <span className={`text-xs text-eve-primary transition-transform duration-200 ${isCollapsed ? '-rotate-90' : ''}`}>▼</span>
                    <h3 className="text-sm font-semibold text-white">{market.locationName}</h3>
                    {outbidCount > 0 && (
                        <span className="bg-amber-500/15 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500/30">{outbidCount} überboten</span>
                    )}
                </div>
                <span className="text-[11px] text-eve-muted tabular-nums">
                    {market.orders.length} Orders · <span className="text-emerald-400">{buyCount} Kauf</span> · <span className="text-rose-400">{market.orders.length - buyCount} Verkauf</span>
                </span>
            </button>
            {!isCollapsed && STATUS_GROUPS.map(group => {
                const orders = market.orders.filter(order => order.status === group.status);
                if (orders.length === 0) {
                    return null;
                }
                return (
                    <div key={group.status} className="border-t border-white/10">
                        <div className="px-4 py-2 flex items-center justify-between gap-4 flex-wrap bg-black/20">
                            <h4 className={`text-xs font-bold uppercase tracking-wider ${group.status === 'outbid' ? 'text-amber-400' : 'text-eve-muted'}`}>
                                {group.title} ({orders.length})
                            </h4>
                            {group.hint && <span className="text-[11px] text-eve-muted">{group.hint}</span>}
                        </div>
                        {/* Space between the sell and buy table keeps the current side recognisable */}
                        <div className="flex flex-col gap-6 pb-2">
                            {SIDES.map(side => {
                                const sideOrders = orders.filter(order => order.isBuy === side.isBuy);
                                if (sideOrders.length === 0) {
                                    return null;
                                }
                                return (
                                    <table key={side.key} className="w-full text-xs">
                                        <thead>
                                            <tr>
                                                <th colSpan={7} className={`px-2.5 pt-3 pb-1 text-center text-sm font-bold uppercase tracking-wider ${side.isBuy ? 'text-emerald-400' : 'text-rose-400'}`}>
                                                    {side.title} ({sideOrders.length})
                                                </th>
                                            </tr>
                                            <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                                                <th className="p-2.5 text-left font-normal">Item</th>
                                                <th className="p-2.5 text-left font-normal">Status</th>
                                                <th className="p-2.5 text-right font-normal">Mein Preis</th>
                                                <th className="p-2.5 text-right font-normal">Bester Konkurrent</th>
                                                <th className="p-2.5 text-right font-normal">Neuer Preis</th>
                                                <th className="p-2.5 text-right font-normal">Marge</th>
                                                <th className="p-2.5 text-right font-normal">Abgleich</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {sideOrders.map(renderRow)}
                                        </tbody>
                                    </table>
                                );
                            })}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// Markets with more orders first, then by name
function _groupByMarket(orders: TradingOrder[]): MarketGroup[] {
    const groups: Record<string, MarketGroup> = {};
    for (const order of orders) {
        if (!groups[order.locationId]) {
            groups[order.locationId] = { locationId: order.locationId, locationName: order.locationName, orders: [] };
        }
        groups[order.locationId].orders.push(order);
    }
    return Object.values(groups).sort((first, second) => second.orders.length - first.orders.length || first.locationName.localeCompare(second.locationName, 'de'));
}

// Outbid and best counts per side at a glance
function StatusSummary({ orders }: { orders: TradingOrder[] }) {
    if (orders.length === 0) {
        return null;
    }
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {SIDES.map(side => {
                let outbidCount = 0;
                let bestCount = 0;
                for (const order of orders) {
                    if (order.isBuy !== side.isBuy) {
                        continue;
                    }
                    if (order.status === 'outbid') {
                        outbidCount++;
                    } else if (order.status === 'best') {
                        bestCount++;
                    }
                }
                return (
                    <div key={side.key} className="bg-eve-card border border-eve-border rounded-lg px-4 py-3 flex items-center justify-between gap-4">
                        <span className={`text-xs font-bold uppercase tracking-wider ${side.isBuy ? 'text-emerald-400' : 'text-rose-400'}`}>{side.title}</span>
                        <div className="flex gap-4 text-xs tabular-nums">
                            <span className={outbidCount > 0 ? 'text-amber-400 font-semibold' : 'text-eve-muted'}>{outbidCount} {side.outbidLabel}</span>
                            <span className="text-eve-muted">{bestCount} vorn</span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function OrderRow({ order, copied, isLastOpened, now, typeIconTemplate, onClick }: { order: TradingOrder; copied: CopiedPrice | null; isLastOpened: boolean; now: number; typeIconTemplate: string; onClick: () => void }) {
    return (
        <tr onClick={onClick} className={`border-b border-white/5 cursor-pointer hover:bg-white/5 ${isLastOpened ? LAST_OPENED_ROW_CLASS : ''}`}>
            <td className="p-2.5">
                <div className="flex items-center gap-2">
                    <img src={typeIconUrl(typeIconTemplate, order.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                    <div className="text-white">{order.itemName}</div>
                </div>
            </td>
            <td className="p-2.5">
                <StatusBadge order={order} />
            </td>
            <td className="p-2.5 text-right tabular-nums">
                <div className="text-white">{formatIsk(order.price)}</div>
                <div className="text-[10px] text-eve-muted">{formatNumber(order.volumeRemain)} / {formatNumber(order.volumeTotal)} Stk.</div>
            </td>
            <td className="p-2.5 text-right tabular-nums">
                {order.bestCompetitorPrice !== null ? (
                    <>
                        <div className={order.status === 'outbid' ? 'text-amber-400' : ''}>{formatIsk(order.bestCompetitorPrice)}</div>
                        <div className="text-[10px] text-eve-muted">{_competitorGapText(order)}</div>
                    </>
                ) : order.status === 'best' ? (
                    <span className="text-eve-muted">keine Konkurrenz</span>
                ) : null}
            </td>
            <td className="p-2.5 text-right tabular-nums">
                {order.suggestedPrice !== null && (
                    <>
                        <div className="text-eve-primary font-semibold">{formatIsk(order.suggestedPrice)}</div>
                        <div className="text-[10px] text-eve-muted">
                            {copied ? `kopiert ${formatAge(new Date(copied.copiedAt).toISOString(), now)}, wartet auf Abgleich` : 'neuer Preis'}
                        </div>
                        {order.priceChange !== null && Math.abs(order.priceChange) >= LARGE_PRICE_CHANGE && (
                            <div className="text-[10px] text-amber-400">Sprung um {formatPercent(order.priceChange, 0)}, Konkurrenz prüfen</div>
                        )}
                    </>
                )}
            </td>
            <td className="p-2.5 text-right tabular-nums" title={order.oppositeBestPrice !== null ? `Beste ${order.isBuy ? 'Verkaufs' : 'Kauf'}order an der Station: ${formatIsk(order.oppositeBestPrice)}` : ''}>
                {order.marginAfter !== null && (
                    <>
                        <div className={order.marginAfter < 0 ? 'text-red-400 font-semibold' : 'text-white'}>{formatPercent(order.marginAfter)}</div>
                        <div className="text-[10px] text-eve-muted">{order.suggestedPrice !== null ? 'Marge danach' : 'Marge'}</div>
                    </>
                )}
            </td>
            <td className="p-2.5 text-right text-[10px] text-eve-muted whitespace-nowrap">
                {order.checkedAt ? `Abgleich ${formatAge(order.checkedAt, now)}` : ''}
            </td>
        </tr>
    );
}

function StatusBadge({ order }: { order: TradingOrder }) {
    if (order.status === 'outbid') {
        return (
            <>
                <span className="inline-block bg-amber-500/15 text-amber-400 text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border border-amber-500/30">
                    {order.isBuy ? 'überboten' : 'unterboten'}
                </span>
                <div className="text-[10px] text-eve-muted mt-0.5 whitespace-nowrap">
                    {order.competitorsAhead} vor dir ({formatNumber(order.competitorVolumeAhead)} Stk.)
                </div>
            </>
        );
    }
    if (order.status === 'best') {
        return <span className="inline-block bg-emerald-500/15 text-emerald-400 text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border border-emerald-500/30">vorn</span>;
    }
    return <span className="text-[10px] text-eve-muted">{order.status === 'gone' ? 'nicht im Buch' : 'ungeprüft'}</span>;
}

// Distance from the own price to the best competitor, positive means the competitor is ahead
function _competitorGapText(order: TradingOrder): string {
    if (order.bestCompetitorPrice === null || order.price <= 0) {
        return '';
    }
    const gap = order.isBuy ? order.bestCompetitorPrice - order.price : order.price - order.bestCompetitorPrice;
    const gapShare = gap / order.price;
    if (gap > 0) {
        return `${formatIsk(gap)} besser (${formatPercent(gapShare)})`;
    }
    if (gap < 0) {
        return `${formatIsk(-gap)} Vorsprung (${formatPercent(-gapShare)})`;
    }
    return 'gleicher Preis';
}

function _loadCopiedPrices(): Record<string, CopiedPrice> {
    const stored = loadStored<Record<string, CopiedPrice>>(COPIED_STORAGE_KEY, {});
    const fresh: Record<string, CopiedPrice> = {};
    for (const orderId of Object.keys(stored)) {
        if (Date.now() - stored[orderId].copiedAt < COPIED_MAX_AGE_MS) {
            fresh[orderId] = stored[orderId];
        }
    }
    return fresh;
}

function _loadCollapsedMarkets(): string[] {
    // Null fallback, loadStored would merge an array fallback into an object
    const stored = loadStored<unknown>(COLLAPSED_STORAGE_KEY, null);
    return Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string') : [];
}

// A copy counts until a check after it shows the order again
function _activeCopy(copied: CopiedPrice | undefined, order: TradingOrder, now: number): CopiedPrice | null {
    if (!copied || now - copied.copiedAt > COPIED_MAX_AGE_MS) {
        return null;
    }
    if (order.checkedAt && new Date(order.checkedAt).getTime() > copied.copiedAt) {
        return null;
    }
    return copied;
}
