import { ReactNode } from 'react';
import { TradingNotice } from './useTradingPage';
import { formatAge, formatPercent, Spinner, TradingContext, TradingSettings } from './tradingShared';

const INPUT_CLASS = 'bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary';

export function TradingSettingsPanel({ children }: { children: ReactNode }) {
    return (
        <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            {children}
        </div>
    );
}

interface CharacterFieldProps {
    context: TradingContext;
    saving: boolean;
    hint: string;
    onChange: (characterId: string) => void;
}

export function TradingCharacterField({ context, saving, hint, onChange }: CharacterFieldProps) {
    return (
        <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
            Handels-Charakter
            <div className="flex items-center gap-2">
                <select
                    value={context.tradingCharacterId ?? ''}
                    disabled={saving}
                    onChange={event => onChange(event.target.value)}
                    className={`flex-1 ${INPUT_CLASS}`}
                >
                    <option value="">Kein Charakter gewählt</option>
                    {context.characters.map(character => (
                        <option key={character.id} value={character.id}>{character.name}</option>
                    ))}
                </select>
                {saving && <Spinner />}
            </div>
            <span>{hint}</span>
        </label>
    );
}

interface SettingsFieldProps {
    context: TradingContext;
    settings: TradingSettings;
    onChange: (key: keyof TradingSettings, value: string | number) => void;
}

export function TradingHubField({ context, settings, onChange }: SettingsFieldProps) {
    const selectedHub = context.hubs.find(hub => hub.stationId === settings.stationId) ?? context.hubs[0];

    return (
        <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
            Handelsdrehkreuz
            <select
                value={selectedHub.stationId}
                onChange={event => onChange('stationId', parseInt(event.target.value, 10))}
                className={INPUT_CLASS}
            >
                {context.hubs.map(hub => (
                    <option key={hub.stationId} value={hub.stationId}>{hub.name}</option>
                ))}
            </select>
            <span>Orderbuch {formatAge(selectedHub.fetchedAt)}, Historie {formatAge(context.statsComputedAt)}</span>
        </label>
    );
}

export function TradingCapitalField({ settings, onChange }: Omit<SettingsFieldProps, 'context'>) {
    return (
        <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
            Kapital pro Item
            <input
                type="text"
                value={settings.capital}
                placeholder="z. B. 500m, leer = unbegrenzt"
                onChange={event => onChange('capital', event.target.value)}
                className={INPUT_CLASS}
            />
            <span>Begrenzt die Stückzahl im Potenzial auf Kapital ÷ Kaufpreis, damit teure Items nicht unrealistisch weit oben landen.</span>
        </label>
    );
}

export function TradingFeesField({ context, settings, onChange }: SettingsFieldProps) {
    return (
        <div className="flex flex-col gap-1 text-[11px] text-eve-muted">
            Gebühren
            <div className="grid grid-cols-2 gap-2">
                <input
                    type="text"
                    value={settings.salesTaxOverride}
                    placeholder={`Steuer ${formatPercent(context.fees.salesTax, 2)}`}
                    onChange={event => onChange('salesTaxOverride', event.target.value)}
                    className={INPUT_CLASS}
                />
                <input
                    type="text"
                    value={settings.brokerFeeOverride}
                    placeholder={`Broker ${formatPercent(context.fees.brokerFee, 2)}`}
                    onChange={event => onChange('brokerFeeOverride', event.target.value)}
                    className={INPUT_CLASS}
                />
            </div>
            <span>
                Aus Accounting {context.fees.accountingLevel} und Broker Relations {context.fees.brokerRelationsLevel}, ohne Standings.
                Eigener Wert in Prozent überschreibt.
            </span>
        </div>
    );
}

export function TradingNoticeBanner({ notice }: { notice: TradingNotice | null }) {
    if (!notice) {
        return null;
    }

    return (
        <div className={`rounded-lg px-4 py-2 text-xs border ${notice.isError ? 'border-red-500/30 text-red-400 bg-red-500/5' : 'border-eve-primary/30 text-eve-primary bg-eve-primary/5'}`}>
            {notice.message}
        </div>
    );
}

// Rendered instead of the page while the context is missing, null once it is loaded
export function TradingContextState({ context, error }: { context: TradingContext | null; error: string | null }) {
    if (error) {
        return <div className="bg-eve-card border border-red-500/30 rounded-lg p-4 text-sm text-red-400">{error}</div>;
    }
    if (!context) {
        return <div className="flex items-center gap-2 text-eve-muted text-sm"><Spinner /> Lade Handels-Tool...</div>;
    }
    return null;
}
