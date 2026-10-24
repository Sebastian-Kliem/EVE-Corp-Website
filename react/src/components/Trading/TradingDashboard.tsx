import { useCallback, useEffect, useState } from 'react';
import MyOrdersTab, { MyOrdersData } from './MyOrdersTab';
import StationTradingTab from './StationTradingTab';
import {
    DEFAULT_SETTINGS, fetchJson, formatAge, formatPercent, loadStored, postJson, saveStored,
    Spinner, TradingContext, TradingSettings, TradingUrls,
} from './tradingShared';

interface TradingDashboardProps {
    urls: TradingUrls;
    typeIconUrl: string;
}

type TabKey = 'station' | 'orders';

const SETTINGS_STORAGE_KEY = 'wh-trading-settings';
const TAB_STORAGE_KEY = 'wh-trading-tab';
// Reading the order checks is a cheap database query, the checks themselves change every 10 to 15 minutes
const ORDER_REFRESH_MS = 60000;

export default function TradingDashboard({ urls, typeIconUrl }: TradingDashboardProps) {
    const [context, setContext] = useState<TradingContext | null>(null);
    const [contextError, setContextError] = useState<string | null>(null);
    const [settings, setSettings] = useState<TradingSettings>(() => loadStored(SETTINGS_STORAGE_KEY, DEFAULT_SETTINGS));
    const [activeTab, setActiveTab] = useState<TabKey>(() => loadStored<TabKey>(TAB_STORAGE_KEY, 'station'));
    // Tabs mount on first visit and stay mounted to keep their state
    const [visitedTabs, setVisitedTabs] = useState<Record<TabKey, boolean>>({ station: false, orders: false, [activeTab]: true } as Record<TabKey, boolean>);
    const [myOrders, setMyOrders] = useState<MyOrdersData | null>(null);
    const [myOrdersError, setMyOrdersError] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ message: string; isError: boolean } | null>(null);
    const [savingCharacter, setSavingCharacter] = useState(false);

    const loadContext = useCallback(() => {
        fetchJson<TradingContext>(urls.context)
            .then(data => {
                setContext(data);
                setContextError(null);
            })
            .catch((e: Error) => setContextError(e.message));
    }, [urls.context]);

    const loadMyOrders = useCallback(() => {
        const query = new URLSearchParams({
            salesTax: settings.salesTaxOverride.replace(',', '.'),
            brokerFee: settings.brokerFeeOverride.replace(',', '.'),
        });
        fetchJson<MyOrdersData>(`${urls.myOrders}?${query.toString()}`)
            .then(data => {
                setMyOrders(data);
                setMyOrdersError(null);
            })
            .catch((e: Error) => setMyOrdersError(e.message));
    }, [urls.myOrders, settings.salesTaxOverride, settings.brokerFeeOverride]);

    useEffect(() => {
        loadContext();
    }, [loadContext]);

    // Small request for the badge, refreshed while the page is visible
    useEffect(() => {
        loadMyOrders();
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                loadMyOrders();
            }
        }, ORDER_REFRESH_MS);
        return () => window.clearInterval(timer);
    }, [loadMyOrders]);

    useEffect(() => {
        saveStored(SETTINGS_STORAGE_KEY, settings);
    }, [settings]);

    useEffect(() => {
        if (!notice) {
            return;
        }
        const timer = window.setTimeout(() => setNotice(null), 5000);
        return () => window.clearTimeout(timer);
    }, [notice]);

    const switchTab = (tab: TabKey) => {
        setActiveTab(tab);
        setVisitedTabs(previous => ({ ...previous, [tab]: true }));
        saveStored(TAB_STORAGE_KEY, tab);
    };

    const showNotice = (message: string, isError = false) => setNotice({ message, isError });

    const openMarket = async (typeId: number) => {
        try {
            await postJson(urls.openMarket, { typeId });
        } catch (e) {
            showNotice((e as Error).message, true);
        }
    };

    const changeTradingCharacter = async (characterId: string) => {
        setSavingCharacter(true);
        try {
            await postJson(urls.settings, { tradingCharacterId: characterId });
            loadContext();
            loadMyOrders();
        } catch (e) {
            showNotice((e as Error).message, true);
        } finally {
            setSavingCharacter(false);
        }
    };

    const updateSetting = (key: keyof TradingSettings, value: string | number) => {
        setSettings(previous => ({ ...previous, [key]: value }));
    };

    if (contextError) {
        return <div className="bg-eve-card border border-red-500/30 rounded-lg p-4 text-sm text-red-400">{contextError}</div>;
    }
    if (!context) {
        return <div className="flex items-center gap-2 text-eve-muted text-sm"><Spinner /> Lade Handels-Tool...</div>;
    }

    const selectedHub = context.hubs.find(hub => hub.stationId === settings.stationId) ?? context.hubs[0];
    const outbidCount = myOrders?.orders.filter(order => order.status === 'outbid').length ?? 0;

    return (
        <div className="flex flex-col gap-4">
            <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
                    Handels-Charakter
                    <div className="flex items-center gap-2">
                        <select
                            value={context.tradingCharacterId ?? ''}
                            disabled={savingCharacter}
                            onChange={event => changeTradingCharacter(event.target.value)}
                            className="flex-1 bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary"
                        >
                            <option value="">Kein Charakter gewählt</option>
                            {context.characters.map(character => (
                                <option key={character.id} value={character.id}>{character.name}</option>
                            ))}
                        </select>
                        {savingCharacter && <Spinner />}
                    </div>
                    <span>Steuern aus seinen Skills, seine Orders werden überwacht.</span>
                </label>

                <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
                    Handelsdrehkreuz
                    <select
                        value={selectedHub.stationId}
                        onChange={event => updateSetting('stationId', parseInt(event.target.value, 10))}
                        className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary"
                    >
                        {context.hubs.map(hub => (
                            <option key={hub.stationId} value={hub.stationId}>{hub.name}</option>
                        ))}
                    </select>
                    <span>Orderbuch {formatAge(selectedHub.fetchedAt)}, Historie {formatAge(context.statsComputedAt)}</span>
                </label>

                <label className="flex flex-col gap-1 text-[11px] text-eve-muted">
                    Kapital pro Item
                    <input
                        type="text"
                        value={settings.capital}
                        placeholder="z. B. 500m, leer = unbegrenzt"
                        onChange={event => updateSetting('capital', event.target.value)}
                        className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary"
                    />
                    <span>Begrenzt die Stückzahl im Potenzial auf Kapital ÷ Kaufpreis, damit teure Items nicht unrealistisch weit oben landen.</span>
                </label>

                <div className="flex flex-col gap-1 text-[11px] text-eve-muted">
                    Gebühren
                    <div className="grid grid-cols-2 gap-2">
                        <input
                            type="text"
                            value={settings.salesTaxOverride}
                            placeholder={`Steuer ${formatPercent(context.fees.salesTax, 2)}`}
                            onChange={event => updateSetting('salesTaxOverride', event.target.value)}
                            className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary"
                        />
                        <input
                            type="text"
                            value={settings.brokerFeeOverride}
                            placeholder={`Broker ${formatPercent(context.fees.brokerFee, 2)}`}
                            onChange={event => updateSetting('brokerFeeOverride', event.target.value)}
                            className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white outline-none focus:border-eve-primary"
                        />
                    </div>
                    <span>
                        Aus Accounting {context.fees.accountingLevel} und Broker Relations {context.fees.brokerRelationsLevel}, ohne Standings.
                        Eigener Wert in Prozent überschreibt.
                    </span>
                </div>
            </div>

            {notice && (
                <div className={`rounded-lg px-4 py-2 text-xs border ${notice.isError ? 'border-red-500/30 text-red-400 bg-red-500/5' : 'border-eve-primary/30 text-eve-primary bg-eve-primary/5'}`}>
                    {notice.message}
                </div>
            )}

            <div className="flex gap-2 border-b border-white/10">
                <TabButton label="Station-Trading" isActive={activeTab === 'station'} onClick={() => switchTab('station')} />
                <TabButton
                    label="Meine Orders"
                    badge={outbidCount > 0 ? String(outbidCount) : null}
                    isActive={activeTab === 'orders'}
                    onClick={() => switchTab('orders')}
                />
            </div>

            {visitedTabs.station && (
                <div className={activeTab === 'station' ? '' : 'hidden'}>
                    <StationTradingTab urls={urls} settings={settings} typeIconTemplate={typeIconUrl} onOpenMarket={openMarket} />
                </div>
            )}
            {visitedTabs.orders && (
                <div className={activeTab === 'orders' ? '' : 'hidden'}>
                    <MyOrdersTab data={myOrders} error={myOrdersError} typeIconTemplate={typeIconUrl} onOpenMarket={openMarket} onNotice={showNotice} />
                </div>
            )}
        </div>
    );
}

function TabButton({ label, badge = null, isActive, onClick }: { label: string; badge?: string | null; isActive: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`px-4 py-2 text-sm cursor-pointer border-b-2 -mb-px flex items-center gap-2 ${isActive ? 'border-eve-primary text-white' : 'border-transparent text-eve-muted hover:text-white'}`}
        >
            {label}
            {badge && <span className="bg-amber-500/15 text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded border border-amber-500/30">{badge}</span>}
        </button>
    );
}
