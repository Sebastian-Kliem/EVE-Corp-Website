export interface TradingUrls {
    context: string;
    settings: string;
    station: string;
    item: string;
    myOrders: string;
    openMarket: string;
}

export interface TradingFees {
    salesTax: number;
    brokerFee: number;
    accountingLevel?: number;
    brokerRelationsLevel?: number;
}

export interface TradingHub {
    stationId: number;
    name: string;
    fetchedAt: string | null;
}

export interface TradingContext {
    characters: { id: string; name: string }[];
    tradingCharacterId: string | null;
    fees: Required<TradingFees>;
    hubs: TradingHub[];
    statsComputedAt: string | null;
}

// Shared by all tabs, kept in the browser only
export interface TradingSettings {
    stationId: number;
    capital: string;
    salesTaxOverride: string;
    brokerFeeOverride: string;
}

export const DEFAULT_SETTINGS: TradingSettings = {
    stationId: 60003760,
    capital: '',
    salesTaxOverride: '',
    brokerFeeOverride: '',
};

export function loadStored<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) {
            return fallback;
        }
        const parsed = JSON.parse(raw);
        return typeof fallback === 'object' && fallback !== null ? { ...fallback, ...parsed } : parsed;
    } catch (e) {
        return fallback;
    }
}

export function saveStored(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        // Storage can be blocked, the page works without it
    }
}

export async function fetchJson<T>(url: string, init?: RequestInit): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin', ...init });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(data?.error || `Anfrage fehlgeschlagen (${response.status}).`);
    }
    return data as T;
}

export function postJson<T>(url: string, body: unknown): Promise<T> {
    return fetchJson<T>(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}

const numberFormatters: Record<number, Intl.NumberFormat> = {};

export function formatNumber(value: number, fractionDigits = 0): string {
    if (!numberFormatters[fractionDigits]) {
        numberFormatters[fractionDigits] = new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        });
    }
    return numberFormatters[fractionDigits].format(value);
}

// Short ISK amounts for dense tables: 1,23 Mrd, 45,6 Mio, 12,3 Tsd
export function formatIskShort(value: number): string {
    const absolute = Math.abs(value);
    if (absolute >= 1e12) {
        return formatNumber(value / 1e12, 2) + ' Bio';
    }
    if (absolute >= 1e9) {
        return formatNumber(value / 1e9, 2) + ' Mrd';
    }
    if (absolute >= 1e6) {
        return formatNumber(value / 1e6, 2) + ' Mio';
    }
    if (absolute >= 1e4) {
        return formatNumber(value / 1e3, 1) + ' Tsd';
    }
    return formatNumber(value, 2);
}

export function formatIsk(value: number): string {
    return formatNumber(value, value >= 1000 ? 0 : 2) + ' ISK';
}

export function formatPercent(value: number, fractionDigits = 1): string {
    return formatNumber(value * 100, fractionDigits) + ' %';
}

export function formatAge(isoDate: string | null, now: number = Date.now()): string {
    if (!isoDate) {
        return 'nie';
    }
    const minutes = Math.max(0, Math.round((now - new Date(isoDate).getTime()) / 60000));
    if (minutes < 1) {
        return 'gerade eben';
    }
    if (minutes < 60) {
        return `vor ${minutes} min`;
    }
    const hours = Math.round(minutes / 60);
    return hours < 48 ? `vor ${hours} h` : `vor ${Math.round(hours / 24)} Tagen`;
}

// EVE reads the plain number, grouping separators would break the price field
export function formatPriceForClipboard(price: number): string {
    return price >= 1000 ? Math.round(price).toString() : price.toFixed(2);
}

export function typeIconUrl(template: string, typeId: number): string {
    return template.replace('12345', String(typeId));
}

export function Spinner({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={`animate-spin text-eve-primary ${className}`} viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
    );
}
