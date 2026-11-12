import { ReactNode, useEffect, useMemo, useState } from 'react';
import {
    fetchJson, formatIskShort, formatNumber, formatPercent, isLastOpened, LAST_OPENED_ROW_CLASS, LastOpenedBadge,
    LastOpenedItem, loadStored, saveStored, Spinner, TradingContext, TradingUrls, typeIconUrl,
} from './tradingShared';

interface PerformanceSummary {
    revenue: number;
    cost: number;
    grossProfit: number;
    salesTax: number;
    brokerFees: number;
    brokerFeesItems: number;
    brokerFeesOpenPositions: number;
    brokerFeesUnassigned: number;
    brokerFeesExcluded: number;
    feeBookings: number;
    feeBookingsExact: number;
    feeBookingsEstimated: number;
    netProfit: number;
    margin: number | null;
    sales: number;
    orderActions: number;
}

interface PerformanceItem {
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

interface ContractPosition {
    typeId: number;
    itemName: string;
    quantity: number;
    cost: number;
    costSource: 'purchase' | 'mixed' | 'buyPrice' | 'none';
}

interface PerformanceContract {
    contractId: string;
    date: string;
    mainItemName: string;
    mainTypeId: number;
    revenue: number;
    cost: number;
    profit: number;
    margin: number | null;
    positions: ContractPosition[];
}

interface PerformanceData {
    summary: PerformanceSummary;
    items: PerformanceItem[];
    contracts: PerformanceContract[];
    dataStart: string | null;
}

const COST_SOURCE_LABELS: Record<ContractPosition['costSource'], string> = {
    purchase: 'Einkauf',
    mixed: 'Einkauf + Jita-Buy',
    buyPrice: 'Jita-Buy (Bestand)',
    none: 'kein Preis',
}

type PeriodKey = 'thisMonth' | 'lastMonth' | 'last7' | 'last30' | 'last90' | 'all';
type SortKey = 'profit' | 'revenue' | 'margin' | 'quantity' | 'averageHoldingDays';

const PERIODS: { key: PeriodKey; label: string }[] = [
    { key: 'thisMonth', label: 'Dieser Monat' },
    { key: 'lastMonth', label: 'Letzter Monat' },
    { key: 'last7', label: '7 Tage' },
    { key: 'last30', label: '30 Tage' },
    { key: 'last90', label: '90 Tage' },
    { key: 'all', label: 'Gesamt' },
];

const SORT_COLUMNS: { key: SortKey; label: string }[] = [
    { key: 'quantity', label: 'Menge' },
    { key: 'revenue', label: 'Umsatz' },
    { key: 'profit', label: 'Gewinn' },
    { key: 'margin', label: 'Marge' },
    { key: 'averageHoldingDays', label: 'Haltedauer' },
];

const FILTER_STORAGE_KEY = 'wh-trading-performance-filter';

interface PerformanceFilter {
    period: PeriodKey;
    characterId: string;
}

interface TradingPerformanceTabProps {
    urls: TradingUrls;
    context: TradingContext;
    typeIconTemplate: string;
    lastOpened: LastOpenedItem | null;
    onOpenMarket: (typeId: number) => Promise<void>;
}

export default function TradingPerformanceTab({ urls, context, typeIconTemplate, lastOpened, onOpenMarket }: TradingPerformanceTabProps) {
    const [filter, setFilter] = useState<PerformanceFilter>(() => loadStored<PerformanceFilter>(FILTER_STORAGE_KEY, { period: 'thisMonth', characterId: '' }));
    const [data, setData] = useState<PerformanceData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [sort, setSort] = useState<{ key: SortKey; descending: boolean }>({ key: 'profit', descending: true });

    useEffect(() => {
        saveStored(FILTER_STORAGE_KEY, filter);
        const range = _periodRange(filter.period);
        const query = new URLSearchParams({ from: range.from, to: range.to, characterId: filter.characterId });
        setLoading(true);
        fetchJson<PerformanceData>(`${urls.performance}?${query.toString()}`)
            .then(result => {
                setData(result);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [urls.performance, filter]);

    const sortedItems = useMemo(() => {
        if (!data) {
            return [];
        }
        const items = [...data.items];
        items.sort((first, second) => {
            const difference = (first[sort.key] ?? -Infinity) - (second[sort.key] ?? -Infinity);
            return sort.descending ? -difference : difference;
        });
        return items;
    }, [data, sort]);

    const toggleSort = (key: SortKey) => {
        setSort(previous => ({ key, descending: previous.key === key ? !previous.descending : true }));
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex flex-wrap gap-1">
                    {PERIODS.map(period => (
                        <button
                            key={period.key}
                            type="button"
                            onClick={() => setFilter(previous => ({ ...previous, period: period.key }))}
                            className={`px-3 py-1 text-xs rounded border cursor-pointer ${filter.period === period.key ? 'border-eve-primary text-white bg-eve-primary/10' : 'border-white/10 text-eve-muted hover:text-white'}`}
                        >
                            {period.label}
                        </button>
                    ))}
                </div>
                <select
                    value={filter.characterId}
                    onChange={event => setFilter(previous => ({ ...previous, characterId: event.target.value }))}
                    className="bg-black/30 border border-white/10 rounded px-2 py-1 text-xs text-white outline-none focus:border-eve-primary"
                >
                    <option value="">Alle Charaktere</option>
                    {context.characters.map(character => (
                        <option key={character.id} value={character.id}>{character.name}</option>
                    ))}
                </select>
                {loading && <Spinner />}
            </div>

            {error && <div className="bg-eve-card border border-red-500/30 rounded-lg p-4 text-sm text-red-400">{error}</div>}

            {data && (
                <>
                    <SummaryCards summary={data.summary} />
                    <p className="text-[11px] text-eve-muted">
                        Realisierter Gewinn aus Verkäufen von Items, die vorher gekauft wurden (Markt und Item-Tausch-Verträge mit Fremden, Einstandspreis als gleitender Durchschnitt über alle Charaktere).
                        Marktverkäufe ohne vorherigen Kauf (Loot, Produktion, Altbestand) zählen nicht. Verkaufsverträge zählen als Ganzes, Items darin ohne Einkauf mit dem Jita-Buy-Preis des Verkaufstags.
                        Verkaufssteuer wird pro Verkauf zugeordnet. Broker-Gebühren über den Zeitstempel der Order oder geschätzt über den Orderwert; im Zeitraum gezahlt, auch für noch offene Orders.
                        {data.dataStart && ` Transaktionen liegen ab ${new Date(data.dataStart).toLocaleDateString('de-DE')} vor, ältere Einkäufe fehlen in der Rechnung.`}
                    </p>
                    {data.contracts.length > 0 && <ContractTable contracts={data.contracts} typeIconTemplate={typeIconTemplate} />}
                    <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                                    <th className="p-2.5 text-left font-normal">Marktverkäufe ({sortedItems.length})</th>
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
                                {sortedItems.map(item => (
                                    <ItemRow
                                        key={item.typeId}
                                        item={item}
                                        isLastOpened={isLastOpened(lastOpened, item.typeId)}
                                        typeIconTemplate={typeIconTemplate}
                                        onClick={() => onOpenMarket(item.typeId)}
                                    />
                                ))}
                                {sortedItems.length === 0 && (
                                    <tr>
                                        <td colSpan={SORT_COLUMNS.length + 1} className="p-6 text-center text-eve-muted">Keine Handelsverkäufe im Zeitraum.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </div>
    );
}

function SummaryCards({ summary }: { summary: PerformanceSummary }) {
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <Card label="Netto-Gewinn" valueClass={summary.netProfit >= 0 ? 'text-emerald-400' : 'text-rose-400'} value={formatIskShort(summary.netProfit)}>
                {summary.margin !== null ? `Marge ${formatPercent(summary.margin)} vom Umsatz` : 'Keine Verkäufe'}
            </Card>
            <Card label="Umsatz" value={formatIskShort(summary.revenue)}>
                Einstand {formatIskShort(summary.cost)}, Rohgewinn {formatIskShort(summary.grossProfit)}
            </Card>
            <Card label="Gebühren" valueClass="text-amber-400" value={formatIskShort(summary.salesTax + summary.brokerFees)}>
                <div>Steuer {formatIskShort(summary.salesTax)}, Broker {formatIskShort(summary.brokerFees)}</div>
                <div title="Broker-Gebühren gekaufter Items: verkaufte Items / gekauft, aber im Zeitraum nicht verkauft / keinem Item zuordenbar">
                    Broker: {formatIskShort(summary.brokerFeesItems)} verkauft, {formatIskShort(summary.brokerFeesOpenPositions)} offen, {formatIskShort(summary.brokerFeesUnassigned)} unbekannt
                </div>
                <div title="Gebühren für Orders von Items ohne Einkauf (Loot, Produktion) zählen nicht zum Handel">
                    {formatIskShort(summary.brokerFeesExcluded)} für Loot/Produktion herausgerechnet
                </div>
            </Card>
            <Card label="Aktivität" value={`${formatNumber(summary.sales)} Verkäufe`}>
                <div>{formatNumber(summary.orderActions)} Orders eingestellt oder geändert</div>
                <div title="Gebührenbuchungen: exakt über den Order-Zeitstempel, geschätzt über den Orderwert">
                    Zuordnung: {formatNumber(summary.feeBookingsExact)} exakt, {formatNumber(summary.feeBookingsEstimated)} geschätzt, {formatNumber(summary.feeBookings - summary.feeBookingsExact - summary.feeBookingsEstimated)} offen
                </div>
            </Card>
        </div>
    );
}

function Card({ label, value, valueClass = 'text-white', children }: { label: string; value: string; valueClass?: string; children: ReactNode }) {
    return (
        <div className="bg-eve-card border border-eve-border rounded-lg px-4 py-3">
            <div className="text-[10px] uppercase tracking-wider text-eve-muted">{label}</div>
            <div className={`text-lg font-bold tabular-nums ${valueClass}`}>{value}</div>
            <div className="text-[11px] text-eve-muted">{children}</div>
        </div>
    );
}

function ItemRow({ item, isLastOpened, typeIconTemplate, onClick }: { item: PerformanceItem; isLastOpened: boolean; typeIconTemplate: string; onClick: () => void }) {
    return (
        <tr onClick={onClick} title="Marktfenster im Spiel öffnen" className={`border-b border-white/5 cursor-pointer hover:bg-white/5 ${isLastOpened ? LAST_OPENED_ROW_CLASS : ''}`}>
            <td className="p-2.5">
                <div className="flex items-center gap-2">
                    <img src={typeIconUrl(typeIconTemplate, item.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                    <div>
                        <div className="text-white flex items-center gap-2">{item.itemName}{isLastOpened && <LastOpenedBadge />}</div>
                        <div className="text-[10px] text-eve-muted">
                            {formatNumber(item.sales)} Verkäufe
                        </div>
                    </div>
                </div>
            </td>
            <td className="p-2.5 text-right tabular-nums">{formatNumber(item.quantity)}</td>
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

function ContractTable({ contracts, typeIconTemplate }: { contracts: PerformanceContract[]; typeIconTemplate: string }) {
    const [expandedId, setExpandedId] = useState<string | null>(null);

    return (
        <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-white/10 text-[10px] uppercase tracking-wider text-eve-muted">
                        <th className="p-2.5 text-left font-normal">Verkaufsverträge ({contracts.length})</th>
                        <th className="p-2.5 text-right font-normal">Abgeschlossen</th>
                        <th className="p-2.5 text-right font-normal">Preis</th>
                        <th className="p-2.5 text-right font-normal">Gewinn</th>
                        <th className="p-2.5 text-right font-normal">Marge</th>
                    </tr>
                </thead>
                <tbody>
                    {contracts.map(contract => {
                        const isExpanded = expandedId === contract.contractId;
                        const otherCount = contract.positions.length - 1;
                        return (
                            <ContractRows
                                key={contract.contractId}
                                contract={contract}
                                title={otherCount > 0 ? `${contract.mainItemName} + ${otherCount} Items` : contract.mainItemName}
                                isExpanded={isExpanded}
                                typeIconTemplate={typeIconTemplate}
                                onToggle={() => setExpandedId(isExpanded ? null : contract.contractId)}
                            />
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function ContractRows({ contract, title, isExpanded, typeIconTemplate, onToggle }: { contract: PerformanceContract; title: string; isExpanded: boolean; typeIconTemplate: string; onToggle: () => void }) {
    return (
        <>
            <tr onClick={onToggle} className="border-b border-white/5 cursor-pointer hover:bg-white/5">
                <td className="p-2.5">
                    <div className="flex items-center gap-2">
                        <span className={`text-eve-muted text-[10px] transition-transform ${isExpanded ? 'rotate-90' : ''}`}>▶</span>
                        <img src={typeIconUrl(typeIconTemplate, contract.mainTypeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                        <div className="text-white">{title}</div>
                    </div>
                </td>
                <td className="p-2.5 text-right tabular-nums text-eve-muted">{new Date(contract.date).toLocaleDateString('de-DE')}</td>
                <td className="p-2.5 text-right tabular-nums">
                    <div className="text-white">{formatIskShort(contract.revenue)}</div>
                    <div className="text-[10px] text-eve-muted">Einstand {formatIskShort(contract.cost)}</div>
                </td>
                <td className={`p-2.5 text-right tabular-nums font-semibold ${contract.profit >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{formatIskShort(contract.profit)}</td>
                <td className="p-2.5 text-right tabular-nums">{contract.margin !== null ? formatPercent(contract.margin) : ''}</td>
            </tr>
            {isExpanded && contract.positions.map(position => (
                <tr key={position.typeId} className="border-b border-white/5 bg-black/20">
                    <td className="py-1.5 pl-12 pr-2.5">
                        <div className="flex items-center gap-2">
                            <img src={typeIconUrl(typeIconTemplate, position.typeId)} alt="" className="w-5 h-5 rounded" loading="lazy" />
                            <span className="text-eve-text">{formatNumber(position.quantity)} x {position.itemName}</span>
                        </div>
                    </td>
                    <td className={`py-1.5 px-2.5 text-right text-[10px] ${position.costSource === 'purchase' ? 'text-eve-muted' : 'text-amber-400'}`}>{COST_SOURCE_LABELS[position.costSource]}</td>
                    <td className="py-1.5 px-2.5 text-right tabular-nums text-eve-muted">Einstand {formatIskShort(position.cost)}</td>
                    <td colSpan={2}></td>
                </tr>
            ))}
        </>
    );
}

// Dates in EVE time (UTC), the end date is inclusive
function _periodRange(period: PeriodKey): { from: string; to: string } {
    const today = new Date();
    const year = today.getUTCFullYear();
    const month = today.getUTCMonth();
    const day = today.getUTCDate();
    const toDate = (date: Date) => date.toISOString().slice(0, 10);
    const todayString = toDate(new Date(Date.UTC(year, month, day)));

    switch (period) {
        case 'thisMonth':
            return { from: toDate(new Date(Date.UTC(year, month, 1))), to: todayString };
        case 'lastMonth':
            return { from: toDate(new Date(Date.UTC(year, month - 1, 1))), to: toDate(new Date(Date.UTC(year, month, 0))) };
        case 'last7':
            return { from: toDate(new Date(Date.UTC(year, month, day - 6))), to: todayString };
        case 'last30':
            return { from: toDate(new Date(Date.UTC(year, month, day - 29))), to: todayString };
        case 'last90':
            return { from: toDate(new Date(Date.UTC(year, month, day - 89))), to: todayString };
        default:
            return { from: '2003-05-06', to: todayString };
    }
}
