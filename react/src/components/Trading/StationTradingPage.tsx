import StationTradingTab from './StationTradingTab';
import { TradingUrls } from './tradingShared';
import {
    TradingCapitalField, TradingCharacterField, TradingContextState, TradingFeesField, TradingHubField, TradingNoticeBanner, TradingSettingsPanel,
} from './TradingSettingsPanel';
import { useTradingPage } from './useTradingPage';

interface StationTradingPageProps {
    urls: TradingUrls;
    typeIconUrl: string;
}

export default function StationTradingPage({ urls, typeIconUrl }: StationTradingPageProps) {
    const page = useTradingPage(urls);

    if (!page.context) {
        return <TradingContextState context={page.context} error={page.contextError} />;
    }

    return (
        <div className="flex flex-col gap-4">
            <TradingSettingsPanel>
                <TradingCharacterField
                    context={page.context}
                    saving={page.savingCharacter}
                    hint="Steuern aus seinen Skills, öffnet das Marktfenster im Spiel."
                    onChange={page.changeTradingCharacter}
                />
                <TradingHubField context={page.context} settings={page.settings} onChange={page.updateSetting} />
                <TradingCapitalField settings={page.settings} onChange={page.updateSetting} />
                <TradingFeesField context={page.context} settings={page.settings} onChange={page.updateSetting} />
            </TradingSettingsPanel>

            <TradingNoticeBanner notice={page.notice} />

            <StationTradingTab urls={urls} settings={page.settings} typeIconTemplate={typeIconUrl} lastOpened={page.lastOpened} onOpenMarket={page.openMarket} />
        </div>
    );
}
