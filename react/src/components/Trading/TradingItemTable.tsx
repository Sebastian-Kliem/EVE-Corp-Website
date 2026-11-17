import { Fragment, useMemo, useState } from 'react';
import ItemDetail, { TradingPosition } from './TradingPerformanceDetail';
import {
    formatIskShort, formatNumber, formatPercent, isLastOpened, LAST_OPENED_ROW_CLASS, LastOpenedBadge,
    LastOpenedItem, typeIconUrl,
} from './tradingShared';

export interface PerformanceItem {
    typeId: number;
    itemName: string;
    quantity: number;
    revenue: number;
    cost: number;
    salesTax: number;
    brokerFees: number;
    profit: number;
    margin: number | null;
    averageHoldingDays: number | null;
    sales: number;
}

type SortKey = 'quantity' | 'revenue' | 'profit' | 'margin' | 'averageHoldingDays';

const SORT_COLUMNS: { key: SortKey; label: string }[] = [
    { key: 'quantity', label: 'Menge' },
    { key: 'revenue', label: 'Umsatz' },
    { key: 'profit', label: 'Gewinn' },
    { key: 'margin', label: 'Marge' },
    { key: 'averageHoldingDays', label: 'Haltedauer' },
];

interface TradingItemTableProps {
    items: PerformanceItem[];
    positions: TradingPosition[];
    detailUrl: string;
    detailQuery: Record<string, string>;
    typeIconTemplate: string;
    lastOpened: LastOpenedItem | null;
    onOpenMarket: (typeId: number) => Promise<void>;
}

// Items sold in the period with their realised result, remaining stock only as a hint
export default function TradingItemTable({ items, positions, detailUrl, detailQuery, typeIconTemplate, lastOpened, onOpenMarket }: TradingItemTableProps) {
    const [sort, setSort] = useState<{ key: SortKey; descending: boolean }>({ key: 'profit', descending: true });
    const [expandedTypeId, setExpandedTypeId] = useState<number | null>(null);

    const stockByType = useMemo(() => {
        const stock = new Map<number, number>();
        for (const position of positions) {
            stock.set(position.typeId, position.quantity);
        }
        return stock;
    }, [positions]);

    const sortedItems = useMemo(() => {
        const sorted = [...items];
        sorted.sort((first, second) => {
            const difference = (first[sort.key] ?? -Infinity) - (second[sort.key] ?? -Infinity);
            return sort.descending ? -difference : difference;
        });
        return sorted;
    }, [items, sort]);

    const toggleSort = (key: SortKey) => {
        setSort(previous => ({ key, descending: previous.key === key ? !previous.descending : true }));
    };

    return (
        <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                        <th className="p-2.5 text-left font-normal">Verkaufte Items ({sortedItems.length})</th>
                        {SORT_COLUMNS.map(column => (
                            <th
                                key={column.key}
                                onClick={() => toggleSort(column.key)}
                                className={`p-2.5 text-right font-normal cursor-pointer select-none hover:text-white ${sort.key === column.key ? 'text-white' : ''}`}
                            >
                                {column.label}{sort.key === column.key ? (sort.descending ? ' ▼' : ' ▲') : ''}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sortedItems.map(item => {
                        const isExpanded = expandedTypeId === item.typeId;
                        return (
                            <Fragment key={item.typeId}>
                                <ItemRow
                                    item={item}
                                    stockQuantity={stockByType.get(item.typeId) ?? null}
                                    isExpanded={isExpanded}
                                    isLastOpened={isLastOpened(lastOpened, item.typeId)}
                                    typeIconTemplate={typeIconTemplate}
                                    onClick={() => setExpandedTypeId(isExpanded ? null : item.typeId)}
                                />
                                {isExpanded && (
                                    <tr className="border-b border-white/5">
                                        <td colSpan={SORT_COLUMNS.length + 1} className="p-0">
                                            <ItemDetail url={detailUrl} query={detailQuery} typeId={item.typeId} onOpenMarket={onOpenMarket} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                    {sortedItems.length === 0 && (
                        <tr>
                            <td colSpan={SORT_COLUMNS.length + 1} className="p-6 text-center text-eve-muted">Keine Handelsverkäufe im Zeitraum.</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

interface ItemRowProps {
    item: PerformanceItem;
    stockQuantity: number | null;
    isExpanded: boolean;
    isLastOpened: boolean;
    typeIconTemplate: string;
    onClick: () => void;
}

function ItemRow({ item, stockQuantity, isExpanded, isLastOpened, typeIconTemplate, onClick }: ItemRowProps) {
    return (
        <tr onClick={onClick} title="Käufe und Verkäufe anzeigen" className={`border-b border-white/5 cursor-pointer hover:bg-white/5 ${isLastOpened ? LAST_OPENED_ROW_CLASS : ''}`}>
            <td className="p-2.5">
                <div className="flex items-center gap-2">
                    <span className={`text-eve-muted text-[10px] transition-transform ${isExpanded ? 'rotate-90' : ''}`}>▶</span>
                    <img src={typeIconUrl(typeIconTemplate, item.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                    <div>
                        <div className="text-white flex items-center gap-2">{item.itemName}{isLastOpened && <LastOpenedBadge />}</div>
                        <div className="text-[10px] text-eve-muted">{formatNumber(item.sales)} Verkäufe</div>
                    </div>
                </div>
            </td>
            <td className="p-2.5 text-right tabular-nums">
                {formatNumber(item.quantity)}
                {stockQuantity !== null && <span className="text-eve-muted" title="Aus Einkäufen noch vorhanden"> ({formatNumber(stockQuantity)} da)</span>}
            </td>
            <td className="p-2.5 text-right tabular-nums">
                <div className="text-white">{formatIskShort(item.revenue)}</div>
                <div className="text-[10px] text-eve-muted">Einstand {formatIskShort(item.cost)}</div>
            </td>
            <td className={`p-2.5 text-right tabular-nums font-semibold ${item.profit >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                {formatIskShort(item.profit)}
                <div className="text-[10px] text-eve-muted font-normal">Steuer {formatIskShort(item.salesTax)}, Broker {formatIskShort(item.brokerFees)}</div>
            </td>
            <td className="p-2.5 text-right tabular-nums">{item.margin !== null ? formatPercent(item.margin) : ''}</td>
            <td className="p-2.5 text-right tabular-nums text-eve-muted">
                {item.averageHoldingDays !== null ? `${formatNumber(item.averageHoldingDays, 1)} Tage` : ''}
            </td>
        </tr>
    );
}
