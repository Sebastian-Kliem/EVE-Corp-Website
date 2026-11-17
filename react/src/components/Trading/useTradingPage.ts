import { useCallback, useEffect, useState } from 'react';
import {
    DEFAULT_SETTINGS, fetchJson, LastOpenedItem, loadStored, postJson, saveStored, TradingContext, TradingSettings, TradingUrls,
} from './tradingShared';

const SETTINGS_STORAGE_KEY = 'wh-trading-settings';
const LAST_OPENED_STORAGE_KEY = 'wh-trading-last-opened';
const NOTICE_DURATION_MS = 5000;

export interface TradingNotice {
    message: string;
    isError: boolean;
}

/**
 * State shared by all trading pages: context, browser settings, the saved trading character and the market window.
 */
export function useTradingPage(urls: TradingUrls, onCharacterChanged: () => void = () => undefined) {
    const [context, setContext] = useState<TradingContext | null>(null);
    const [contextError, setContextError] = useState<string | null>(null);
    const [settings, setSettings] = useState<TradingSettings>(() => loadStored(SETTINGS_STORAGE_KEY, DEFAULT_SETTINGS));
    const [notice, setNotice] = useState<TradingNotice | null>(null);
    const [savingCharacter, setSavingCharacter] = useState(false);
    const [lastOpened, setLastOpened] = useState<LastOpenedItem | null>(() => loadStored<LastOpenedItem | null>(LAST_OPENED_STORAGE_KEY, null));

    const loadContext = useCallback(() => {
        fetchJson<TradingContext>(urls.context)
            .then(data => {
                setContext(data);
                setContextError(null);
            })
            .catch((e: Error) => setContextError(e.message));
    }, [urls.context]);

    useEffect(() => {
        loadContext();
    }, [loadContext]);

    useEffect(() => {
        saveStored(SETTINGS_STORAGE_KEY, settings);
    }, [settings]);

    useEffect(() => {
        if (!notice) {
            return;
        }
        const timer = window.setTimeout(() => setNotice(null), NOTICE_DURATION_MS);
        return () => window.clearTimeout(timer);
    }, [notice]);

    const showNotice = (message: string, isError = false) => setNotice({ message, isError });

    const openMarket = async (typeId: number, orderId: string | null = null) => {
        // Marked before the request so the row stays findable when switching back from the game
        const opened = { typeId, orderId, openedAt: Date.now() };
        setLastOpened(opened);
        saveStored(LAST_OPENED_STORAGE_KEY, opened);
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
            onCharacterChanged();
        } catch (e) {
            showNotice((e as Error).message, true);
        } finally {
            setSavingCharacter(false);
        }
    };

    const updateSetting = (key: keyof TradingSettings, value: string | number) => {
        setSettings(previous => ({ ...previous, [key]: value }));
    };

    return {
        context, contextError, settings, updateSetting, notice, showNotice,
        savingCharacter, changeTradingCharacter, lastOpened, openMarket,
    };
}
