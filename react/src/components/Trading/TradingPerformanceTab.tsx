import { ReactNode, useEffect, useMemo, useState } from 'react';
import { TradingPosition } from './TradingPerformanceDetail';
import TradingItemTable, { PerformanceItem } from './TradingItemTable';
import {
    fetchJson, formatIskShort, formatNumber, formatPercent, LastOpenedItem, loadStored, saveStored, Spinner,
    TradingContext, TradingUrls,
} from './tradingShared';

interface PerformanceSummary {
    revenue: number;
    cost: number;
    grossProfit: number;
    salesTax: number;
    brokerFees: number;
    brokerFeesItems: number;
    brokerFeesBuyShare: number;
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

interface PerformanceData {
    summary: PerformanceSummary;
    items: PerformanceItem[];
    positions: TradingPosition[];
    dataStart: string | null;
}

type PeriodKey = 'thisMonth' | 'lastMonth' | 'last7' | 'last30' | 'last90' | 'all';

const PERIODS: { key: PeriodKey; label: string }[] = [
    { key: 'thisMonth', label: 'Dieser Monat' },
    { key: 'lastMonth', label: 'Letzter Monat' },
    { key: 'last7', label: '7 Tage' },
    { key: 'last30', label: '30 Tage' },
    { key: 'last90', label: '90 Tage' },
    { key: 'all', label: 'Gesamt' },
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
    const detailQuery = useMemo(() => {
        const range = _periodRange(filter.period);
        return { from: range.from, to: range.to, characterId: filter.characterId };
    }, [filter]);

    useEffect(() => {
        saveStored(FILTER_STORAGE_KEY, filter);
        setLoading(true);
        fetchJson<PerformanceData>(`${urls.performance}?${new URLSearchParams(detailQuery).toString()}`)
            .then(result => {
                setData(result);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [urls.performance, filter, detailQuery]);

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
                        Realisierter Gewinn aus Marktverkäufen von Items, die vorher am Markt gekauft wurden (über alle Charaktere, ein Kauf mit Charakter A deckt also auch einen Verkauf mit Charakter B).
                        Jeder Verkauf wird gegen die jüngsten Einkäufe gerechnet (LIFO), so zeigt sich die Marge pro Flip. In Klammern steht, wie viel aus Einkäufen noch da ist.
                        Marktverkäufe ohne vorherigen Kauf (Loot, Produktion, Altbestand) zählen nicht, PLEX bleibt ganz außen vor (auch per Echtgeld kaufbar). Verträge zählen nicht, weder als Kauf noch als Verkauf.
                        Verkaufssteuer wird pro Verkauf zugeordnet. Broker-Gebühren über den Zeitstempel der Order oder geschätzt über den Orderwert. Gebühren werden auf die gekauften bzw. eingestellten Stück verteilt und zählen erst, wenn diese verkauft werden; so belastet eine große, oft angepasste Order nicht nur den ersten Verkauf.
                        {data.dataStart && ` Transaktionen liegen ab ${new Date(data.dataStart).toLocaleDateString('de-DE')} vor, ältere Einkäufe fehlen in der Rechnung.`}
                    </p>
                    <TradingItemTable
                        items={data.items}
                        positions={data.positions}
                        detailUrl={urls.performanceItem}
                        detailQuery={detailQuery}
                        typeIconTemplate={typeIconTemplate}
                        lastOpened={lastOpened}
                        onOpenMarket={onOpenMarket}
                    />
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
                <div title="Anteil der Kauf- und Verkaufsgebühren für die verkauften Stück / Buchungen, die keiner Order zuordenbar sind. Gebühren für noch nicht verkaufte Stück zählen erst beim Verkauf.">
                    Broker: {formatIskShort(summary.brokerFeesItems)} auf Verkäufe (davon {formatIskShort(summary.brokerFeesBuyShare)} Kauf), {formatIskShort(summary.brokerFeesUnassigned)} unbekannt
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
