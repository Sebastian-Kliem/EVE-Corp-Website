import { useCallback, useEffect, useState } from 'react';
import MyOrdersTab, { MyOrdersData } from './MyOrdersTab';
import TradingPerformanceTab from './TradingPerformanceTab';
import { fetchJson, loadStored, saveStored, TradingUrls } from './tradingShared';
import { TradingCharacterField, TradingContextState, TradingFeesField, TradingNoticeBanner, TradingSettingsPanel } from './TradingSettingsPanel';
import { useTradingPage } from './useTradingPage';

interface TradingOrdersPageProps {
    urls: TradingUrls;
    typeIconUrl: string;
}

type TabKey = 'orders' | 'performance';

const TAB_STORAGE_KEY = 'wh-trading-orders-tab';
// Reading the order checks is a cheap database query, the checks themselves change every 10 to 15 minutes
const ORDER_REFRESH_MS = 60000;

export default function TradingOrdersPage({ urls, typeIconUrl }: TradingOrdersPageProps) {
    const [ordersReloadCount, setOrdersReloadCount] = useState(0);
    const page = useTradingPage(urls, () => setOrdersReloadCount(count => count + 1));
    const [activeTab, setActiveTab] = useState<TabKey>(() => _loadTab());
    // Tabs mount on first visit and stay mounted to keep their state
    const [visitedTabs, setVisitedTabs] = useState<Record<TabKey, boolean>>({ orders: false, performance: false, [activeTab]: true } as Record<TabKey, boolean>);
    const [myOrders, setMyOrders] = useState<MyOrdersData | null>(null);
    const [myOrdersError, setMyOrdersError] = useState<string | null>(null);
    const { salesTaxOverride, brokerFeeOverride } = page.settings;

    const loadMyOrders = useCallback(() => {
        const query = new URLSearchParams({
            salesTax: salesTaxOverride.replace(',', '.'),
            brokerFee: brokerFeeOverride.replace(',', '.'),
        });
        fetchJson<MyOrdersData>(`${urls.myOrders}?${query.toString()}`)
            .then(data => {
                setMyOrders(data);
                setMyOrdersError(null);
            })
            .catch((e: Error) => setMyOrdersError(e.message));
    }, [urls.myOrders, salesTaxOverride, brokerFeeOverride]);

    // Small request for the badge, refreshed while the page is visible
    useEffect(() => {
        loadMyOrders();
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                loadMyOrders();
            }
        }, ORDER_REFRESH_MS);
        return () => window.clearInterval(timer);
    }, [loadMyOrders, ordersReloadCount]);

    const switchTab = (tab: TabKey) => {
        setActiveTab(tab);
        setVisitedTabs(previous => ({ ...previous, [tab]: true }));
        saveStored(TAB_STORAGE_KEY, tab);
    };

    if (!page.context) {
        return <TradingContextState context={page.context} error={page.contextError} />;
    }

    const outbidCount = myOrders?.orders.filter(order => order.status === 'outbid').length ?? 0;

    return (
        <div className="flex flex-col gap-4">
            <TradingSettingsPanel>
                <TradingCharacterField
                    context={page.context}
                    saving={page.savingCharacter}
                    hint="Seine Orders werden überwacht, Steuern aus seinen Skills."
                    onChange={page.changeTradingCharacter}
                />
                <TradingFeesField context={page.context} settings={page.settings} onChange={page.updateSetting} />
            </TradingSettingsPanel>

            <TradingNoticeBanner notice={page.notice} />

            <div className="flex gap-2 border-b border-white/10">
                <TabButton
                    label="Meine Orders"
                    badge={outbidCount > 0 ? String(outbidCount) : null}
                    isActive={activeTab === 'orders'}
                    onClick={() => switchTab('orders')}
                />
                <TabButton label="Handels-Performance" isActive={activeTab === 'performance'} onClick={() => switchTab('performance')} />
            </div>

            {visitedTabs.orders && (
                <div className={activeTab === 'orders' ? '' : 'hidden'}>
                    <MyOrdersTab data={myOrders} error={myOrdersError} typeIconTemplate={typeIconUrl} lastOpened={page.lastOpened} onOpenMarket={page.openMarket} onNotice={page.showNotice} />
                </div>
            )}
            {visitedTabs.performance && (
                <div className={activeTab === 'performance' ? '' : 'hidden'}>
                    <TradingPerformanceTab urls={urls} context={page.context} typeIconTemplate={typeIconUrl} lastOpened={page.lastOpened} onOpenMarket={page.openMarket} />
                </div>
            )}
        </div>
    );
}

function _loadTab(): TabKey {
    const stored = loadStored<string>(TAB_STORAGE_KEY, 'orders');
    return stored === 'performance' ? 'performance' : 'orders';
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
