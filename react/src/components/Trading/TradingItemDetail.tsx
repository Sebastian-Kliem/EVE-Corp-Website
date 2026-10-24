import { useEffect, useState } from 'react';
import { fetchJson, formatAge, formatIsk, formatIskShort, formatNumber, Spinner, TradingUrls } from './tradingShared';

interface HistoryDay {
    date: string;
    average: number;
    highest: number;
    lowest: number;
    volume: number;
    orderCount: number;
}

interface BookOrder {
    price: number;
    volumeRemain: number;
    issued: string;
    isOwn: boolean;
}

interface ItemDetailData {
    typeId: number;
    itemName: string;
    history: HistoryDay[];
    book: { buy: BookOrder[]; sell: BookOrder[]; buyCount: number; sellCount: number } | null;
    bookError: string | null;
}

interface TradingItemDetailProps {
    urls: TradingUrls;
    stationId: number;
    typeId: number;
    onOpenMarket: (typeId: number) => void;
}

const CHART_WIDTH = 720;
const CHART_HEIGHT = 200;
const VOLUME_HEIGHT = 50;

export default function TradingItemDetail({ urls, stationId, typeId, onOpenMarket }: TradingItemDetailProps) {
    const [data, setData] = useState<ItemDetailData | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setError(null);
        fetchJson<ItemDetailData>(`${urls.item}?stationId=${stationId}&typeId=${typeId}`, { signal: controller.signal })
            .then(setData)
            .catch((e: Error) => {
                if (e.name !== 'AbortError') {
                    setError(e.message);
                }
            });
        return () => controller.abort();
    }, [urls.item, stationId, typeId]);

    if (error) {
        return <div className="p-4 text-sm text-red-400">{error}</div>;
    }
    if (!data) {
        return (
            <div className="p-4 flex items-center gap-2 text-xs text-eve-muted">
                <Spinner /> Lade Verlauf und Orderbuch...
            </div>
        );
    }

    return (
        <div className="p-4 flex flex-col gap-4 bg-black/20">
            <div className="flex items-center justify-between gap-4 flex-wrap">
                <h3 className="text-sm font-semibold text-white">{data.itemName}</h3>
                <button
                    type="button"
                    onClick={() => onOpenMarket(typeId)}
                    className="bg-eve-primary/10 border border-eve-primary hover:bg-eve-primary hover:text-black text-eve-primary text-xs font-semibold py-1.5 px-3 rounded cursor-pointer transition-all"
                >
                    Im Spiel öffnen
                </button>
            </div>
            <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div className="xl:col-span-2">
                    <HistoryChart history={data.history} />
                </div>
                <div className="grid grid-cols-2 xl:grid-cols-1 gap-3">
                    {data.book ? (
                        <>
                            <BookTable title="Verkauf" orders={data.book.sell} total={data.book.sellCount} colorClass="text-rose-400" />
                            <BookTable title="Kauf" orders={data.book.buy} total={data.book.buyCount} colorClass="text-emerald-400" />
                        </>
                    ) : (
                        <div className="text-xs text-red-400">{data.bookError}</div>
                    )}
                </div>
            </div>
        </div>
    );
}

function HistoryChart({ history }: { history: HistoryDay[] }) {
    const [hoverIndex, setHoverIndex] = useState<number | null>(null);

    if (history.length < 2) {
        return <div className="text-xs text-eve-muted">Zu wenig Historie für einen Verlauf.</div>;
    }

    // Daily extremes of thin markets can be absurd, the scale follows the 5th to 95th percentile
    const prices: number[] = [];
    for (const day of history) {
        prices.push(day.lowest, day.highest, day.average);
    }
    prices.sort((first, second) => first - second);
    const minPrice = prices[Math.floor(prices.length * 0.05)];
    const maxPrice = prices[Math.ceil(prices.length * 0.95) - 1];
    const priceRange = Math.max(maxPrice - minPrice, maxPrice * 0.01, 0.01);

    let maxVolume = 1;
    for (const day of history) {
        maxVolume = Math.max(maxVolume, day.volume);
    }

    const step = CHART_WIDTH / history.length;
    const toY = (price: number) => {
        const clamped = Math.min(Math.max(price, minPrice), maxPrice);
        return 8 + (1 - (clamped - minPrice) / priceRange) * (CHART_HEIGHT - 16);
    };
    const buildLine = (key: 'average' | 'highest' | 'lowest') => {
        let path = '';
        history.forEach((day, index) => {
            path += `${index === 0 ? 'M' : 'L'}${(index + 0.5) * step},${toY(day[key])}`;
        });
        return path;
    };

    const hovered = hoverIndex !== null ? history[hoverIndex] : history[history.length - 1];

    return (
        <div>
            <div className="text-[11px] text-eve-muted mb-1 flex gap-4 flex-wrap">
                <span>{hovered.date}</span>
                <span>Ø <span className="text-white">{formatIsk(hovered.average)}</span></span>
                <span>Tief <span className="text-emerald-400">{formatIskShort(hovered.lowest)}</span></span>
                <span>Hoch <span className="text-rose-400">{formatIskShort(hovered.highest)}</span></span>
                <span>Umsatz <span className="text-white">{formatNumber(hovered.volume)}</span></span>
            </div>
            <svg
                viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT + VOLUME_HEIGHT}`}
                className="w-full h-auto"
                onMouseLeave={() => setHoverIndex(null)}
            >
                {history.map((day, index) => {
                    const barHeight = (day.volume / maxVolume) * (VOLUME_HEIGHT - 4);
                    return (
                        <rect
                            key={day.date}
                            x={index * step + 1}
                            y={CHART_HEIGHT + VOLUME_HEIGHT - barHeight}
                            width={Math.max(step - 2, 1)}
                            height={barHeight}
                            className={index === hoverIndex ? 'fill-eve-primary/60' : 'fill-eve-primary/25'}
                        />
                    );
                })}
                <path d={buildLine('highest')} className="stroke-rose-400/60 fill-none" strokeWidth="1" />
                <path d={buildLine('lowest')} className="stroke-emerald-400/60 fill-none" strokeWidth="1" />
                <path d={buildLine('average')} className="stroke-white fill-none" strokeWidth="1.5" />
                {history.map((day, index) => (
                    <rect
                        key={`hover-${day.date}`}
                        x={index * step}
                        y={0}
                        width={step}
                        height={CHART_HEIGHT + VOLUME_HEIGHT}
                        fill="transparent"
                        onMouseEnter={() => setHoverIndex(index)}
                    />
                ))}
            </svg>
            <div className="text-[10px] text-eve-muted mt-1">
                {history.length} Tage, Region des Handelsdrehkreuzes. Linien: Tageshoch, Durchschnitt, Tagestief; Balken: Umsatz.
            </div>
        </div>
    );
}

function BookTable({ title, orders, total, colorClass }: { title: string; orders: BookOrder[]; total: number; colorClass: string }) {
    return (
        <div>
            <div className={`text-[11px] font-bold uppercase tracking-wider mb-1 ${colorClass}`}>
                {title} ({total} Orders an der Station)
            </div>
            <table className="w-full text-[11px]">
                <tbody>
                    {orders.map((order, index) => (
                        <tr key={index} className={order.isOwn ? 'text-eve-primary font-semibold' : 'text-slate-300'}>
                            <td className="py-0.5 pr-2 text-right tabular-nums">{formatIsk(order.price)}</td>
                            <td className="py-0.5 pr-2 text-right tabular-nums">{formatNumber(order.volumeRemain)}</td>
                            <td className="py-0.5 text-right text-eve-muted">{order.isOwn ? 'eigene' : formatAge(order.issued)}</td>
                        </tr>
                    ))}
                    {orders.length === 0 && (
                        <tr>
                            <td className="py-0.5 text-eve-muted">Keine Orders.</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
