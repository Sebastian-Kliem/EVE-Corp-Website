import { useEffect, useState } from 'react';
import { fetchJson, formatIsk, formatIskShort, formatNumber, formatPercent, Spinner } from './tradingShared';

export interface TradingPosition {
    typeId: number;
    itemName: string;
    quantity: number;
    cost: number;
    unitCost: number;
    jitaSell: number | null;
    jitaBuy: number | null;
    value: number | null;
    unrealizedProfit: number | null;
    oldestAcquiredAt: string;
}

interface DetailEntry {
    date: string;
    characterId: number;
    isBuy: boolean;
    isContract: boolean;
    quantity: number;
    unitPrice: number;
    matchedQuantity?: number;
    unitCost?: number | null;
    salesTax?: number;
    profit?: number | null;
}

interface ItemDetailData {
    detail: { typeId: number; itemName: string; entries: DetailEntry[] };
    position: TradingPosition | null;
    characterNames: Record<string, string>;
}

// Long histories (daily flips) are cut, the summary still covers the whole period
const MAX_VISIBLE_ENTRIES = 100;

interface ItemDetailProps {
    url: string;
    query: Record<string, string>;
    typeId: number;
    onOpenMarket: (typeId: number) => Promise<void>;
}

// Purchases and sales of one item in the period, with the stock that is still left
export default function ItemDetail({ url, query, typeId, onOpenMarket }: ItemDetailProps) {
    const [data, setData] = useState<ItemDetailData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const queryString = new URLSearchParams({ ...query, typeId: String(typeId) }).toString();

    useEffect(() => {
        fetchJson<ItemDetailData>(`${url}?${queryString}`)
            .then(result => {
                setData(result);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));
    }, [url, queryString]);

    if (error) {
        return <div className="text-xs text-red-400 p-3">{error}</div>;
    }
    if (!data) {
        return <div className="flex items-center gap-2 text-xs text-eve-muted p-3"><Spinner /> Lade Details...</div>;
    }

    const stats = _periodStats(data.detail.entries);
    const visibleEntries = data.detail.entries.slice(0, MAX_VISIBLE_ENTRIES);

    return (
        <div className="flex flex-col gap-3 p-3 bg-black/20">
            <div className="flex flex-wrap items-start gap-3">
                <Stat label="Ø Einkauf im Zeitraum" value={stats.averageBuy !== null ? formatIsk(stats.averageBuy) : '-'} hint={`${formatNumber(stats.boughtQuantity)} Stk.`} />
                <Stat label="Ø Verkauf im Zeitraum" value={stats.averageSell !== null ? formatIsk(stats.averageSell) : '-'} hint={`${formatNumber(stats.soldQuantity)} Stk.`} />
                <Stat
                    label="Bestand"
                    value={data.position ? `${formatNumber(data.position.quantity)} Stk.` : 'kein Bestand'}
                    hint={data.position ? `Einstand ${formatIsk(data.position.unitCost)} pro Stk., ältester Kauf ${new Date(data.position.oldestAcquiredAt).toLocaleDateString('de-DE')}` : ''}
                />
                {data.position && data.position.unrealizedProfit !== null && (
                    <Stat
                        label="Bestand zu Jita-Sell"
                        value={formatIskShort(data.position.value ?? 0)}
                        valueClass={data.position.unrealizedProfit >= 0 ? 'text-emerald-400' : 'text-rose-400'}
                        hint={`${data.position.unrealizedProfit >= 0 ? '+' : ''}${formatIskShort(data.position.unrealizedProfit)} gegenüber Einstand`}
                    />
                )}
                <button
                    type="button"
                    onClick={() => onOpenMarket(typeId)}
                    className="ml-auto px-3 py-1.5 text-xs rounded border border-eve-primary/40 text-eve-primary hover:bg-eve-primary/10 cursor-pointer"
                >
                    Marktfenster im Spiel öffnen
                </button>
            </div>

            <table className="w-full text-[11px]">
                <thead>
                    <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                        <th className="py-1.5 px-2 text-left font-normal">Datum</th>
                        <th className="py-1.5 px-2 text-left font-normal">Charakter</th>
                        <th className="py-1.5 px-2 text-left font-normal">Art</th>
                        <th className="py-1.5 px-2 text-right font-normal">Menge</th>
                        <th className="py-1.5 px-2 text-right font-normal">Preis</th>
                        <th className="py-1.5 px-2 text-right font-normal">Einstand</th>
                        <th className="py-1.5 px-2 text-right font-normal">Gewinn</th>
                    </tr>
                </thead>
                <tbody>
                    {visibleEntries.map((entry, index) => (
                        <EntryRow key={index} entry={entry} characterName={data.characterNames[String(entry.characterId)] ?? ''} />
                    ))}
                    {data.detail.entries.length === 0 && (
                        <tr><td colSpan={7} className="py-3 text-center text-eve-muted">Keine Käufe oder Verkäufe im Zeitraum.</td></tr>
                    )}
                </tbody>
            </table>
            {data.detail.entries.length > MAX_VISIBLE_ENTRIES && (
                <div className="text-[10px] text-eve-muted">Die neuesten {MAX_VISIBLE_ENTRIES} von {formatNumber(data.detail.entries.length)} Buchungen.</div>
            )}
        </div>
    );
}

function Stat({ label, value, hint, valueClass = 'text-white' }: { label: string; value: string; hint: string; valueClass?: string }) {
    return (
        <div className="bg-eve-card border border-eve-border rounded px-3 py-2 min-w-[150px]">
            <div className="text-[10px] uppercase tracking-wider text-eve-muted">{label}</div>
            <div className={`text-sm font-semibold tabular-nums ${valueClass}`}>{value}</div>
            {hint && <div className="text-[10px] text-eve-muted">{hint}</div>}
        </div>
    );
}

function EntryRow({ entry, characterName }: { entry: DetailEntry; characterName: string }) {
    const isUnmatched = !entry.isBuy && (entry.matchedQuantity ?? 0) < entry.quantity;

    return (
        <tr className="border-b border-white/5">
            <td className="py-1 px-2 text-eve-muted whitespace-nowrap">{new Date(entry.date).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })}</td>
            <td className="py-1 px-2 text-eve-muted">{characterName}</td>
            <td className={`py-1 px-2 ${entry.isBuy ? 'text-emerald-400' : 'text-rose-400'}`}>
                {entry.isBuy ? (entry.isContract ? 'Kauf (Vertrag)' : 'Kauf') : 'Verkauf'}
            </td>
            <td className="py-1 px-2 text-right tabular-nums" title={isUnmatched ? 'Teilweise ohne Einkauf (Loot, Produktion, Altbestand), dieser Teil zählt nicht' : ''}>
                {formatNumber(entry.quantity)}
                {isUnmatched && <span className="text-amber-400"> ({formatNumber(entry.matchedQuantity ?? 0)} gezählt)</span>}
            </td>
            <td className="py-1 px-2 text-right tabular-nums">{formatIsk(entry.unitPrice)}</td>
            <td className="py-1 px-2 text-right tabular-nums text-eve-muted">{entry.unitCost != null ? formatIsk(entry.unitCost) : ''}</td>
            <td className={`py-1 px-2 text-right tabular-nums ${entry.profit == null ? '' : entry.profit >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                {entry.profit != null ? formatIskShort(entry.profit) : ''}
                {entry.profit != null && entry.unitCost ? <span className="text-eve-muted"> ({formatPercent(entry.unitPrice / entry.unitCost - 1, 0)})</span> : null}
            </td>
        </tr>
    );
}

function _periodStats(entries: DetailEntry[]) {
    let boughtQuantity = 0;
    let boughtValue = 0;
    let soldQuantity = 0;
    let soldValue = 0;
    for (const entry of entries) {
        if (entry.isBuy) {
            boughtQuantity += entry.quantity;
            boughtValue += entry.quantity * entry.unitPrice;
        } else {
            soldQuantity += entry.quantity;
            soldValue += entry.quantity * entry.unitPrice;
        }
    }
    return {
        boughtQuantity,
        soldQuantity,
        averageBuy: boughtQuantity > 0 ? boughtValue / boughtQuantity : null,
        averageSell: soldQuantity > 0 ? soldValue / soldQuantity : null,
    };
}
