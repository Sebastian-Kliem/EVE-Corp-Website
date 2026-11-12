import { Fragment, useMemo, useState } from 'react';
import ItemDetail, { TradingPosition } from './TradingPerformanceDetail';
import { formatIsk, formatIskShort, formatNumber, typeIconUrl } from './tradingShared';

type PositionSortKey = 'cost' | 'value' | 'unrealizedProfit';

const COLLAPSED_ROW_COUNT = 15;

interface TradingPositionsTableProps {
    positions: TradingPosition[];
    detailUrl: string;
    detailQuery: Record<string, string>;
    typeIconTemplate: string;
    onOpenMarket: (typeId: number) => Promise<void>;
}

// Bought stock that is still owned, valued at the current Jita sell price
export default function TradingPositionsTable({ positions, detailUrl, detailQuery, typeIconTemplate, onOpenMarket }: TradingPositionsTableProps) {
    const [sort, setSort] = useState<{ key: PositionSortKey; descending: boolean }>({ key: 'cost', descending: true });
    const [showAll, setShowAll] = useState(false);
    const [expandedTypeId, setExpandedTypeId] = useState<number | null>(null);

    const sortedPositions = useMemo(() => {
        const sorted = [...positions];
        sorted.sort((first, second) => {
            const difference = (first[sort.key] ?? -Infinity) - (second[sort.key] ?? -Infinity);
            return sort.descending ? -difference : difference;
        });
        return sorted;
    }, [positions, sort]);

    let totalCost = 0;
    let totalValue = 0;
    for (const position of positions) {
        totalCost += position.cost;
        totalValue += position.value ?? position.cost;
    }

    const visiblePositions = showAll ? sortedPositions : sortedPositions.slice(0, COLLAPSED_ROW_COUNT);
    const toggleSort = (key: PositionSortKey) => {
        setSort(previous => ({ key, descending: previous.key === key ? !previous.descending : true }));
    };
    const sortHeader = (key: PositionSortKey, label: string) => (
        <th onClick={() => toggleSort(key)} className={`p-2.5 text-right font-normal cursor-pointer select-none hover:text-white ${sort.key === key ? 'text-white' : ''}`}>
            {label}{sort.key === key ? (sort.descending ? ' ▼' : ' ▲') : ''}
        </th>
    );

    return (
        <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
            <div className="px-4 py-2.5 border-b border-white/10 flex items-center justify-between gap-4 flex-wrap">
                <h3 className="text-xs font-bold uppercase tracking-wider text-eve-muted">Bestand aus Einkäufen ({positions.length})</h3>
                <span className="text-[11px] text-eve-muted">
                    Einstand {formatIskShort(totalCost)}, zu Jita-Sell {formatIskShort(totalValue)}{' '}
                    <span className={totalValue - totalCost >= 0 ? 'text-emerald-400' : 'text-rose-400'}>
                        ({totalValue - totalCost >= 0 ? '+' : ''}{formatIskShort(totalValue - totalCost)})
                    </span>
                </span>
            </div>
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                        <th className="p-2.5 text-left font-normal">Item</th>
                        <th className="p-2.5 text-right font-normal">Menge</th>
                        {sortHeader('cost', 'Einstand')}
                        {sortHeader('value', 'Wert (Jita-Sell)')}
                        {sortHeader('unrealizedProfit', 'Unrealisiert')}
                        <th className="p-2.5 text-right font-normal">Ältester Kauf</th>
                    </tr>
                </thead>
                <tbody>
                    {visiblePositions.map(position => {
                        const isExpanded = expandedTypeId === position.typeId;
                        return (
                            <Fragment key={position.typeId}>
                                <tr onClick={() => setExpandedTypeId(isExpanded ? null : position.typeId)} className="border-b border-white/5 cursor-pointer hover:bg-white/5">
                                    <td className="p-2.5">
                                        <div className="flex items-center gap-2">
                                            <span className={`text-eve-muted text-[10px] transition-transform ${isExpanded ? 'rotate-90' : ''}`}>▶</span>
                                            <img src={typeIconUrl(typeIconTemplate, position.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                                            <span className="text-white">{position.itemName}</span>
                                        </div>
                                    </td>
                                    <td className="p-2.5 text-right tabular-nums">{formatNumber(position.quantity)}</td>
                                    <td className="p-2.5 text-right tabular-nums">
                                        <div className="text-white">{formatIskShort(position.cost)}</div>
                                        <div className="text-[10px] text-eve-muted">{formatIsk(position.unitCost)} / Stk.</div>
                                    </td>
                                    <td className="p-2.5 text-right tabular-nums">
                                        {position.value !== null ? formatIskShort(position.value) : <span className="text-eve-muted">kein Jita-Preis</span>}
                                        {position.jitaSell !== null && <div className="text-[10px] text-eve-muted">{formatIsk(position.jitaSell)} / Stk.</div>}
                                    </td>
                                    <td className={`p-2.5 text-right tabular-nums font-semibold ${position.unrealizedProfit === null ? '' : position.unrealizedProfit >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                                        {position.unrealizedProfit !== null ? formatIskShort(position.unrealizedProfit) : ''}
                                    </td>
                                    <td className="p-2.5 text-right tabular-nums text-eve-muted">{new Date(position.oldestAcquiredAt).toLocaleDateString('de-DE')}</td>
                                </tr>
                                {isExpanded && (
                                    <tr className="border-b border-white/5">
                                        <td colSpan={6} className="p-0">
                                            <ItemDetail url={detailUrl} query={detailQuery} typeId={position.typeId} onOpenMarket={onOpenMarket} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
            {positions.length > COLLAPSED_ROW_COUNT && (
                <button type="button" onClick={() => setShowAll(!showAll)} className="w-full py-2 text-[11px] text-eve-primary hover:bg-white/5 cursor-pointer">
                    {showAll ? 'Weniger anzeigen' : `Alle ${positions.length} Positionen anzeigen`}
                </button>
            )}
        </div>
    );
}
