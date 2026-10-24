import { Fragment, useEffect, useRef, useState } from 'react';
import TradingItemDetail from './TradingItemDetail';
import {
    fetchJson, formatIsk, formatIskShort, formatNumber, formatPercent, loadStored, saveStored,
    Spinner, TradingFees, TradingSettings, TradingUrls, typeIconUrl,
} from './tradingShared';

interface StationRow {
    typeId: number;
    itemName: string;
    buyBest: number;
    sellBest: number;
    profitPerUnit: number;
    margin: number;
    avgVolume7d: number;
    avgVolume30d: number;
    avgPrice30d: number | null;
    tradedDays30d: number;
    priceVolatility30d: number | null;
    buyCompetitors: number;
    sellCompetitors: number;
    competitors: number;
    buyUpdated24h: number;
    sellUpdated24h: number;
    buyUpdated1h: number;
    sellUpdated1h: number;
    buySideShare: number | null;
    fillable: boolean;
    avgLowest7d: number | null;
    avgHighest7d: number | null;
    expectedUnits: number;
    potential: number;
}

interface StationResponse {
    rows: StationRow[];
    total: number;
    pageSize: number;
    fees: TradingFees;
}

interface StationFilters {
    search: string;
    minVolume: string;
    minMargin: string;
    maxBuyPrice: string;
    minTradedDays: string;
    sort: string;
    direction: 'asc' | 'desc';
}

const FILTER_STORAGE_KEY = 'wh-trading-station-filters';
const DEFAULT_FILTERS: StationFilters = {
    search: '',
    minVolume: '',
    minMargin: '',
    maxBuyPrice: '',
    minTradedDays: '20',
    sort: 'potential',
    direction: 'desc',
};
// Typing in a filter only queries once the input rests
const FILTER_DEBOUNCE_MS = 400;

interface StationTradingTabProps {
    urls: TradingUrls;
    settings: TradingSettings;
    typeIconTemplate: string;
    onOpenMarket: (typeId: number) => void;
}

export default function StationTradingTab({ urls, settings, typeIconTemplate, onOpenMarket }: StationTradingTabProps) {
    const [filters, setFilters] = useState<StationFilters>(() => loadStored(FILTER_STORAGE_KEY, DEFAULT_FILTERS));
    const [page, setPage] = useState(1);
    const [data, setData] = useState<StationResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [openTypeId, setOpenTypeId] = useState<number | null>(null);
    const [showExplanation, setShowExplanation] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        saveStored(FILTER_STORAGE_KEY, filters);
    }, [filters]);

    useEffect(() => {
        const query = new URLSearchParams({
            stationId: String(settings.stationId),
            capital: String(parseShortNumber(settings.capital)),
            salesTax: settings.salesTaxOverride.replace(',', '.'),
            brokerFee: settings.brokerFeeOverride.replace(',', '.'),
            search: filters.search,
            minVolume: String(parseShortNumber(filters.minVolume)),
            minMargin: filters.minMargin.replace(',', '.'),
            maxBuyPrice: String(parseShortNumber(filters.maxBuyPrice)),
            minTradedDays: filters.minTradedDays,
            sort: filters.sort,
            direction: filters.direction,
            page: String(page),
        });

        const timer = window.setTimeout(() => {
            // A newer query replaces a running one
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;
            setLoading(true);
            fetchJson<StationResponse>(`${urls.station}?${query.toString()}`, { signal: controller.signal })
                .then(response => {
                    setData(response);
                    setError(null);
                    setLoading(false);
                })
                .catch((e: Error) => {
                    if (e.name !== 'AbortError') {
                        setError(e.message);
                        setLoading(false);
                    }
                });
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [urls.station, settings, filters, page]);

    const updateFilter = (key: keyof StationFilters, value: string) => {
        setFilters(previous => ({ ...previous, [key]: value }));
        setPage(1);
    };

    const toggleSort = (column: string) => {
        setFilters(previous => ({
            ...previous,
            sort: column,
            direction: previous.sort === column && previous.direction === 'desc' ? 'asc' : 'desc',
        }));
        setPage(1);
    };

    const pageCount = data ? Math.max(1, Math.ceil(data.total / data.pageSize)) : 1;

    return (
        <div className="flex flex-col gap-4">
            <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg p-4 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                <FilterInput label="Item suchen" value={filters.search} placeholder="z. B. Gyrostabilizer" onChange={value => updateFilter('search', value)} />
                <FilterInput label="Min. Umsatz/Tag (Stk.)" value={filters.minVolume} placeholder="z. B. 50" onChange={value => updateFilter('minVolume', value)} />
                <FilterInput label="Min. Marge (%)" value={filters.minMargin} placeholder="z. B. 10" onChange={value => updateFilter('minMargin', value)} />
                <FilterInput label="Max. Kaufpreis" value={filters.maxBuyPrice} placeholder="z. B. 500m" onChange={value => updateFilter('maxBuyPrice', value)} />
                <FilterInput label="Min. Handelstage (von 30)" value={filters.minTradedDays} placeholder="20" onChange={value => updateFilter('minTradedDays', value)} />
                <div className="flex items-end gap-2">
                    <button
                        type="button"
                        onClick={() => { setFilters(DEFAULT_FILTERS); setPage(1); }}
                        className="text-xs text-eve-muted hover:text-white border border-white/10 rounded py-2 px-3 cursor-pointer"
                    >
                        Filter zurücksetzen
                    </button>
                    {loading && <Spinner />}
                </div>
            </div>

            <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg">
                <button
                    type="button"
                    onClick={() => setShowExplanation(!showExplanation)}
                    className="w-full text-left px-4 py-2.5 text-xs text-eve-primary cursor-pointer flex items-center justify-between"
                >
                    <span>Wie wird das Potenzial berechnet?</span>
                    <span>{showExplanation ? 'ausblenden' : 'anzeigen'}</span>
                </button>
                {showExplanation && <PotentialExplanation fees={data?.fees ?? null} />}
            </div>

            {error && <div className="bg-eve-card border border-red-500/30 rounded-lg p-4 text-sm text-red-400">{error}</div>}

            <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr className="text-eve-muted border-b border-white/10">
                            <th className="text-left font-semibold p-2.5">Item</th>
                            <SortHeader label="Kauf" column="buy_best" filters={filters} onSort={toggleSort} />
                            <th className="text-right font-semibold p-2.5">Verkauf</th>
                            <SortHeader label="Gewinn/Stk." column="profit_per_unit" filters={filters} onSort={toggleSort} />
                            <SortHeader label="Marge" column="margin" filters={filters} onSort={toggleSort} />
                            <SortHeader label="Umsatz/Tag" column="avg_volume7d" filters={filters} onSort={toggleSort} title="Durchschnitt der letzten 7 Tage in der Region" />
                            <SortHeader label="Konkurrenz" column="competitors" filters={filters} onSort={toggleSort} title="Orders bis 5 % vom Bestpreis, Kauf / Verkauf" />
                            <SortHeader label="Anpassungen 24h" column="activity" filters={filters} onSort={toggleSort} title="Orders nahe am Bestpreis, die in den letzten 24 Stunden neu eingestellt oder angepasst wurden" />
                            <SortHeader label="Potenzial/Tag" column="potential" filters={filters} onSort={toggleSort} title="Geschätzter Gewinn pro Tag, Erklärung über der Tabelle" />
                        </tr>
                    </thead>
                    <tbody>
                        {data?.rows.map(row => (
                            <Fragment key={row.typeId}>
                                <tr
                                    onClick={() => setOpenTypeId(openTypeId === row.typeId ? null : row.typeId)}
                                    className={`border-b border-white/5 cursor-pointer hover:bg-white/5 ${openTypeId === row.typeId ? 'bg-white/5' : ''}`}
                                >
                                    <td className="p-2.5">
                                        <div className="flex items-center gap-2">
                                            <img src={typeIconUrl(typeIconTemplate, row.typeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                                            <span className="text-white">{row.itemName}</span>
                                        </div>
                                    </td>
                                    <td className="p-2.5 text-right tabular-nums text-emerald-400">{formatIskShort(row.buyBest)}</td>
                                    <td className="p-2.5 text-right tabular-nums text-rose-400">{formatIskShort(row.sellBest)}</td>
                                    <td className="p-2.5 text-right tabular-nums text-white">{formatIskShort(row.profitPerUnit)}</td>
                                    <td className="p-2.5 text-right tabular-nums">{formatPercent(row.margin)}</td>
                                    <td className="p-2.5 text-right tabular-nums">{formatNumber(row.avgVolume7d, row.avgVolume7d < 10 ? 1 : 0)}</td>
                                    <td className="p-2.5 text-right tabular-nums">{row.buyCompetitors} / {row.sellCompetitors}</td>
                                    <td className="p-2.5 text-right tabular-nums" title={`Letzte Stunde: ${row.buyUpdated1h} Kauf / ${row.sellUpdated1h} Verkauf`}>
                                        {row.buyUpdated24h} / {row.sellUpdated24h}
                                    </td>
                                    <td className="p-2.5 text-right tabular-nums font-semibold text-eve-primary" title={buildPotentialTooltip(row)}>
                                        {row.fillable ? formatIskShort(row.potential) : <span className="text-amber-400 font-normal">nicht gefüllt</span>}
                                    </td>
                                </tr>
                                {openTypeId === row.typeId && (
                                    <tr className="border-b border-white/10">
                                        <td colSpan={9} className="p-0">
                                            <TradingItemDetail urls={urls} stationId={settings.stationId} typeId={row.typeId} onOpenMarket={onOpenMarket} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                        {data && data.rows.length === 0 && (
                            <tr>
                                <td colSpan={9} className="p-6 text-center text-eve-muted">Keine Items mit diesen Filtern.</td>
                            </tr>
                        )}
                        {!data && !error && (
                            <tr>
                                <td colSpan={9} className="p-6">
                                    <div className="flex items-center justify-center gap-2 text-eve-muted"><Spinner /> Lade Marktdaten...</div>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {data && data.total > 0 && (
                <div className="flex items-center justify-between text-xs text-eve-muted">
                    <span>{formatNumber(data.total)} Items</span>
                    <div className="flex items-center gap-2">
                        <PageButton label="Zurück" disabled={page <= 1} onClick={() => setPage(page - 1)} />
                        <span>Seite {page} von {pageCount}</span>
                        <PageButton label="Weiter" disabled={page >= pageCount} onClick={() => setPage(page + 1)} />
                    </div>
                </div>
            )}
        </div>
    );
}

// Accepts 1.5m, 2b, 300k and German separators
export function parseShortNumber(input: string): number {
    const cleaned = input.trim().toLowerCase().replace(/\s/g, '');
    if (cleaned === '') {
        return 0;
    }
    const match = cleaned.match(/^([\d.,]+)(k|m|b|mio|mrd|tsd)?$/);
    if (!match) {
        return 0;
    }
    let numberPart = match[1];
    if (numberPart.includes(',')) {
        numberPart = numberPart.replace(/\./g, '').replace(',', '.');
    } else if ((numberPart.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(numberPart) && !match[2]) {
        numberPart = numberPart.replace(/\./g, '');
    }
    const multipliers: Record<string, number> = { k: 1e3, tsd: 1e3, m: 1e6, mio: 1e6, b: 1e9, mrd: 1e9 };
    const value = parseFloat(numberPart) * (match[2] ? multipliers[match[2]] : 1);
    return isNaN(value) ? 0 : value;
}

function buildPotentialTooltip(row: StationRow): string {
    if (!row.fillable) {
        return `Kauf- oder Verkaufspreis liegt außerhalb der Tagestiefs/-hochs der letzten 7 Tage (Ø Tief ${formatIsk(row.avgLowest7d ?? 0)}, Ø Hoch ${formatIsk(row.avgHighest7d ?? 0)}). Zu diesen Preisen wurde nicht gehandelt.`;
    }
    const share = row.buySideShare ?? 0;
    return [
        `Gewinn/Stk.: ${formatIsk(row.profitPerUnit)}`,
        `Umsatz/Tag (Ø 7 Tage): ${formatNumber(row.avgVolume7d, 1)}`,
        `davon an Kauforders: ${formatPercent(share, 0)}, an Verkaufsorders: ${formatPercent(1 - share, 0)}`,
        `Konkurrenz: ${row.competitors} Orders, dein Anteil 1/${row.competitors + 1}`,
        `Erwartete Stückzahl/Tag: ${formatNumber(row.expectedUnits, 2)}`,
        `Potenzial: ${formatIsk(row.potential)}`,
    ].join('\n');
}

function PotentialExplanation({ fees }: { fees: TradingFees | null }) {
    return (
        <div className="px-4 pb-4 text-xs text-slate-300 leading-relaxed flex flex-col gap-2">
            <p><span className="text-white font-semibold">Potenzial/Tag = Gewinn pro Stück × erwartete Stückzahl pro Tag</span></p>
            <p>
                <span className="text-white">Gewinn pro Stück</span> = Verkaufspreis × (1 − Verkaufssteuer − Broker-Gebühr) − Kaufpreis × (1 + Broker-Gebühr).
                Es zählen die aktuell besten Preise an der Station; die Broker-Gebühr fällt für Kauf- und Verkaufsorder an.
                {fees && <> Aktuell: Verkaufssteuer {formatPercent(fees.salesTax, 2)}, Broker-Gebühr {formatPercent(fees.brokerFee, 2)}.</>}
                {' '}Gebühren für spätere Preisanpassungen sind nicht enthalten.
            </p>
            <p>
                <span className="text-white">Erwartete Stückzahl</span> = Umsatz pro Tag (Ø 7 Tage) × kleinerer Anteil von Kauf- und Verkaufsseite ÷ (Konkurrenz + 1).
                Der Durchschnittspreis der Historie zeigt, wie viel des Umsatzes an Kauforders ging (Ø nahe am Kaufpreis) und wie viel an Verkaufsorders.
                Für einen Handelszyklus braucht es beide Seiten, deshalb zählt der kleinere Anteil.
                Konkurrenz sind alle Orders bis 5 % vom Bestpreis auf der volleren Seite. Mit Kapital wird die Stückzahl auf Kapital ÷ Kaufpreis begrenzt.
            </p>
            <p>
                <span className="text-white">Nicht gefüllt</span>: Der beste Kaufpreis liegt deutlich unter den Tagestiefs oder der beste Verkaufspreis deutlich über den Tageshochs der letzten 7 Tage.
                Zu diesen Preisen wurde zuletzt nicht gehandelt, das Potenzial ist dann 0.
            </p>
            <p className="text-eve-muted">
                Das ist eine grobe Schätzung: Die Konkurrenz teilt sich den Umsatz nicht gleichmäßig, und wer seltener nachzieht, verkauft weniger.
                Die Spalte Anpassungen 24h zeigt, wie aktiv die Konkurrenz ihre Orders nachzieht.
            </p>
        </div>
    );
}

function FilterInput({ label, value, placeholder, onChange }: { label: string; value: string; placeholder: string; onChange: (value: string) => void }) {
    return (
        <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
            {label}
            <input
                type="text"
                value={value}
                placeholder={placeholder}
                onChange={event => onChange(event.target.value)}
                className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white focus:border-eve-primary outline-none"
            />
        </label>
    );
}

function SortHeader({ label, column, filters, onSort, title }: { label: string; column: string; filters: StationFilters; onSort: (column: string) => void; title?: string }) {
    const isActive = filters.sort === column;
    return (
        <th className="text-right font-semibold p-2.5 whitespace-nowrap" title={title}>
            <button type="button" onClick={() => onSort(column)} className={`cursor-pointer hover:text-white ${isActive ? 'text-eve-primary' : ''}`}>
                {label}{isActive ? (filters.direction === 'desc' ? ' ↓' : ' ↑') : ''}
            </button>
        </th>
    );
}

function PageButton({ label, disabled, onClick }: { label: string; disabled: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="border border-white/10 rounded px-3 py-1 hover:text-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
        >
            {label}
        </button>
    );
}
