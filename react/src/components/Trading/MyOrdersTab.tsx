import { useEffect, useState } from 'react';
import {
    formatAge, formatIsk, formatNumber, formatPercent, formatPriceForClipboard, loadStored, saveStored, typeIconUrl,
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

interface MyOrdersTabProps {
    data: MyOrdersData | null;
    error: string | null;
    typeIconTemplate: string;
    onOpenMarket: (typeId: number) => Promise<void>;
    onNotice: (message: string, isError?: boolean) => void;
}

export default function MyOrdersTab({ data, error, typeIconTemplate, onOpenMarket, onNotice }: MyOrdersTabProps) {
    const [copiedPrices, setCopiedPrices] = useState<Record<string, CopiedPrice>>(() => _loadCopiedPrices());
    const [now, setNow] = useState(Date.now());

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
        await onOpenMarket(order.typeId);
    };

    const groups: { status: TradingOrder['status']; title: string; hint: string }[] = [
        { status: 'outbid', title: 'Handlungsbedarf', hint: 'Klick kopiert den neuen Preis und öffnet das Marktfenster im Spiel.' },
        { status: 'best', title: 'Beste Order', hint: '' },
        { status: 'gone', title: 'Nicht mehr im Orderbuch', hint: 'Seit dem letzten Abgleich erfüllt, storniert oder abgelaufen; verschwindet mit dem nächsten Order-Sync des Charakters.' },
        { status: 'unchecked', title: 'Nicht geprüft', hint: 'Nur Orders an den fünf Handelsdrehkreuzen werden mit dem Orderbuch abgeglichen.' },
    ];

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs text-eve-muted">
                Orders von <span className="text-white">{data.characterName}</span>. Der Abgleich läuft mit jedem Abzug der Handelsdrehkreuze (Jita alle 10, die anderen alle 15 Minuten);
                neue Orders erscheinen nach dem nächsten Order-Sync des Charakters (ESI-Cache bis 20 Minuten).
            </p>
            {groups.map(group => {
                const orders = data.orders.filter(order => order.status === group.status);
                if (orders.length === 0) {
                    return null;
                }
                return (
                    <div key={group.status} className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
                        <div className="px-4 py-2.5 border-b border-white/10 flex items-center justify-between gap-4 flex-wrap">
                            <h3 className={`text-xs font-bold uppercase tracking-wider ${group.status === 'outbid' ? 'text-amber-400' : 'text-eve-muted'}`}>
                                {group.title} ({orders.length})
                            </h3>
                            {group.hint && <span className="text-[11px] text-eve-muted">{group.hint}</span>}
                        </div>
                        <table className="w-full text-xs">
                            <tbody>
                                {orders.map(order => (
                                    <OrderRow
                                        key={order.orderId}
                                        order={order}
                                        copied={_activeCopy(copiedPrices[order.orderId], order, now)}
                                        now={now}
                                        typeIconTemplate={typeIconTemplate}
                                        onClick={() => handleOrderClick(order)}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                );
            })}
            {data.orders.length === 0 && (
                <div className="bg-eve-card border border-eve-border rounded-lg p-6 text-center text-eve-muted text-sm">Keine aktiven Orders.</div>
            )}
        </div>
    );
}

function OrderRow({ order, copied, now, typeIconTemplate, onClick }: { order: TradingOrder; copied: CopiedPrice | null; now: number; typeIconTemplate: string; onClick: () => void }) {
    return (
        <tr onClick={onClick} className="border-b border-white/5 cursor-pointer hover:bg-white/5">
            <td className="p-2.5">
                <div className="flex items-center gap-2">
                    <img src={typeIconUrl(typeIconTemplate, order.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                    <div>
                        <div className="text-white">{order.itemName}</div>
                        <div className="text-[10px] text-eve-muted">{order.locationName}</div>
                    </div>
                </div>
            </td>
            <td className={`p-2.5 font-semibold ${order.isBuy ? 'text-emerald-400' : 'text-rose-400'}`}>{order.isBuy ? 'Kauf' : 'Verkauf'}</td>
            <td className="p-2.5 text-right tabular-nums">
                <div className="text-white">{formatIsk(order.price)}</div>
                <div className="text-[10px] text-eve-muted">{formatNumber(order.volumeRemain)} / {formatNumber(order.volumeTotal)} Stk.</div>
            </td>
            <td className="p-2.5 text-right tabular-nums">
                {order.bestCompetitorPrice !== null ? (
                    <>
                        <div>{formatIsk(order.bestCompetitorPrice)}</div>
                        <div className="text-[10px] text-eve-muted">bester Konkurrent</div>
                    </>
                ) : order.status === 'best' ? (
                    <span className="text-eve-muted">keine Konkurrenz</span>
                ) : null}
            </td>
            <td className="p-2.5 text-right tabular-nums">
                {order.status === 'outbid' && (
                    <div className="text-[11px] text-amber-400">
                        {order.competitorsAhead} Orders vor dir ({formatNumber(order.competitorVolumeAhead)} Stk.)
                    </div>
                )}
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
