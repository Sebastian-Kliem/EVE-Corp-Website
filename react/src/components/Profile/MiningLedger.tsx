import React, { useState, useEffect, useMemo } from 'react';

interface CharacterListEntry {
    id: number;
    name: string;
    hasToken: boolean;
}

interface MiningRecord {
    date: string;
    solarSystemId: number;
    solarSystemName: string;
    typeId: number;
    typeName: string;
    quantity: number;
    price: number;
    value: number;
}

interface CharacterData {
    id: number;
    name: string;
    records: MiningRecord[];
    error: string | null;
}

interface MiningLedgerProps {
    charactersList: CharacterListEntry[];
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
}

export default function MiningLedger({ charactersList, apiDataUrl, imagePaths }: MiningLedgerProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [charactersData, setCharactersData] = useState<CharacterData[]>([]);
    
    // Active tabs
    const [activeMainTab, setActiveMainTab] = useState<'daily' | 'monthly' | 'characters'>('daily');
    const [selectedCharId, setSelectedCharId] = useState<number | null>(null);

    // Get current date and month defaults
    const todayStr = useMemo(() => {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }, []);

    const currentMonthStr = useMemo(() => {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    }, []);

    // Filter selectors
    const [selectedDate, setSelectedDate] = useState<string>(todayStr);
    const [selectedMonth, setSelectedMonth] = useState<string>(currentMonthStr);

    // Fetch data from API
    useEffect(() => {
        setLoading(true);
        fetch(apiDataUrl)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Fehler beim Laden der Bergbaudaten.');
                }
                return res.json();
            })
            .then((data: { characters: CharacterData[] }) => {
                setCharactersData(data.characters || []);
                
                // Select first character by default for character tab
                if (data.characters && data.characters.length > 0) {
                    setSelectedCharId(data.characters[0].id);
                }

                // If there are records, set selectedDate/selectedMonth to the most recent record's date/month
                let newestDate = '';
                data.characters.forEach((char) => {
                    char.records.forEach((rec) => {
                        if (rec.date > newestDate) {
                            newestDate = rec.date;
                        }
                    });
                });

                if (newestDate) {
                    setSelectedDate(newestDate);
                    setSelectedMonth(newestDate.substring(0, 7));
                }

                setLoading(false);
            })
            .catch((err) => {
                setError(err.message || 'Ein unbekannter Fehler ist aufgetreten.');
                setLoading(false);
            });
    }, [apiDataUrl]);

    // Helpers
    const getCharacterPortraitUrl = (charId: number) => {
        return imagePaths.characters.replace('12345', charId.toString());
    };

    const getItemIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', typeId.toString());
    };

    const formatISK = (val: number): string => {
        return new Intl.NumberFormat('de-DE', {
            style: 'decimal',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(val) + ' ISK';
    };

    const formatNumber = (val: number): string => {
        return new Intl.NumberFormat('de-DE').format(val);
    };

    // Calculate aggregated records for a specific filter
    const getFilteredCombinedData = (mode: 'daily' | 'monthly') => {
        const records: { charName: string; charId: number; record: MiningRecord }[] = [];
        
        charactersData.forEach((char) => {
            char.records.forEach((rec) => {
                const isMatch = mode === 'daily' 
                    ? rec.date === selectedDate 
                    : rec.date.startsWith(selectedMonth);
                
                if (isMatch) {
                    records.push({
                        charName: char.name,
                        charId: char.id,
                        record: rec
                    });
                }
            });
        });

        // Sum value per character
        const charSummary: Record<number, { name: string; value: number; quantity: number }> = {};
        // Group by ore type
        const oreSummary: Record<number, { name: string; quantity: number; value: number; typeId: number }> = {};
        
        let totalValue = 0;
        let totalQuantity = 0;

        records.forEach(({ charId, charName, record }) => {
            totalValue += record.value;
            totalQuantity += record.quantity;

            // Character summary
            if (!charSummary[charId]) {
                charSummary[charId] = { name: charName, value: 0, quantity: 0 };
            }
            charSummary[charId].value += record.value;
            charSummary[charId].quantity += record.quantity;

            // Ore summary
            if (!oreSummary[record.typeId]) {
                oreSummary[record.typeId] = {
                    typeId: record.typeId,
                    name: record.typeName,
                    quantity: 0,
                    value: 0
                };
            }
            oreSummary[record.typeId].quantity += record.quantity;
            oreSummary[record.typeId].value += record.value;
        });

        return {
            records,
            charSummary: Object.entries(charSummary).map(([id, info]) => ({
                id: Number(id),
                ...info
            })).sort((a, b) => b.value - a.value),
            oreSummary: Object.values(oreSummary).sort((a, b) => b.value - a.value),
            totalValue,
            totalQuantity
        };
    };

    const dailyCombined = useMemo(() => getFilteredCombinedData('daily'), [charactersData, selectedDate]);
    const monthlyCombined = useMemo(() => getFilteredCombinedData('monthly'), [charactersData, selectedMonth]);

    const activeChar = useMemo(() => {
        return charactersData.find(c => c.id === selectedCharId) || null;
    }, [charactersData, selectedCharId]);

    const activeCharStats = useMemo(() => {
        if (!activeChar) return { totalVal: 0, totalQty: 0, count: 0 };
        let totalVal = 0;
        let totalQty = 0;
        activeChar.records.forEach(r => {
            totalVal += r.value;
            totalQty += r.quantity;
        });
        return {
            totalVal,
            totalQty,
            count: activeChar.records.length
        };
    }, [activeChar]);

    if (loading) {
        return (
            <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6 text-center py-12">
                <span className="inline-block w-8 h-8 border-3 border-eve-primary rounded-full border-t-transparent animate-spin"></span>
                <p className="mt-3 text-eve-muted">Bergbaudaten werden geladen...</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6 text-center py-12 border-rose-500/30">
                <h3 className="text-xl font-semibold mb-2 text-rose-400">Fehler</h3>
                <p className="text-sm text-eve-muted">{error}</p>
            </div>
        );
    }

    return (
        <div>
            {/* Navigation Tabs */}
            <div className="flex border-b border-eve-border mb-6 gap-2">
                <button 
                    className={`bg-transparent border-none text-eve-muted px-4 py-2.5 text-sm cursor-pointer font-medium border-b-2 border-transparent transition-all duration-200 hover:text-eve-primary ${activeMainTab === 'daily' ? '!text-eve-primary !border-eve-primary' : ''}`}
                    onClick={() => setActiveMainTab('daily')}
                >
                    📅 Tageseinkommen
                </button>
                <button 
                    className={`bg-transparent border-none text-eve-muted px-4 py-2.5 text-sm cursor-pointer font-medium border-b-2 border-transparent transition-all duration-200 hover:text-eve-primary ${activeMainTab === 'monthly' ? '!text-eve-primary !border-eve-primary' : ''}`}
                    onClick={() => setActiveMainTab('monthly')}
                >
                    📊 Monatseinkommen
                </button>
                <button 
                    className={`bg-transparent border-none text-eve-muted px-4 py-2.5 text-sm cursor-pointer font-medium border-b-2 border-transparent transition-all duration-200 hover:text-eve-primary ${activeMainTab === 'characters' ? '!text-eve-primary !border-eve-primary' : ''}`}
                    onClick={() => setActiveMainTab('characters')}
                >
                    👤 Charakterübersicht
                </button>
            </div>

            {/* TAB: DAILY INCOME */}
            {activeMainTab === 'daily' && (
                <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                    <div className="flex justify-between items-center flex-wrap gap-4 mb-6">
                        <div>
                            <h2 className="text-xl font-semibold text-white mb-0">Gemeinsames Tageseinkommen</h2>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="text-xs font-semibold text-eve-muted mb-0">Tag auswählen:</label>
                            <input 
                                type="date" 
                                className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300 w-auto" 
                                value={selectedDate}
                                onChange={(e) => setSelectedDate(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-6">
                        {/* Summary Stats */}
                        <div className="w-full md:w-1/3 flex flex-col gap-4">
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <p className="text-xs text-eve-muted mb-2">Gesamtwert am {selectedDate}</p>
                                <h3 className="text-2xl font-bold text-eve-primary">
                                    {formatISK(dailyCombined.totalValue)}
                                </h3>
                                <hr className="border-eve-border my-4" />
                                <p className="text-xs text-eve-muted mb-1">Menge erzielt:</p>
                                <p className="font-bold text-sm text-white">{formatNumber(dailyCombined.totalQuantity)} Einheiten</p>
                            </div>

                            {/* Character share */}
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <h4 className="text-base font-semibold mb-3 text-white">Aufteilung nach Charakter</h4>
                                {dailyCombined.charSummary.length === 0 ? (
                                    <p className="text-xs text-eve-muted">Keine Aktivitäten an diesem Tag.</p>
                                ) : (
                                    <div className="flex flex-col gap-3">
                                        {dailyCombined.charSummary.map((c) => (
                                            <div key={c.id} className="flex justify-between items-center flex-wrap gap-1 min-w-0">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <img 
                                                        src={getCharacterPortraitUrl(c.id)} 
                                                        alt={c.name} 
                                                        className="w-6 h-6 rounded-full border border-eve-border flex-shrink-0"
                                                    />
                                                    <span className="text-sm text-eve-text truncate">
                                                        {c.name}
                                                    </span>
                                                </div>
                                                <span className="whitespace-nowrap font-bold text-sm text-eve-primary ml-auto flex-shrink-0">
                                                    {formatISK(c.value)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Ore Breakdown */}
                        <div className="flex-1 min-w-[280px]">
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <h4 className="text-base font-semibold mb-3 text-white">Abgebautes Erz / Ressourcen</h4>
                                {dailyCombined.oreSummary.length === 0 ? (
                                    <div className="text-center p-5">
                                        <p className="text-xs text-eve-muted">An diesem Tag wurden keine Erze abgebaut.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full border-collapse text-left bg-transparent text-eve-text text-xs">
                                            <thead>
                                                <tr className="border-b border-white/10">
                                                    <th className="font-semibold text-eve-muted p-2">Ressource</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Menge</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Est. Stückpreis</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Gesamtwert</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {dailyCombined.oreSummary.map((ore) => (
                                                    <tr key={ore.typeId} className="border-b border-white/5">
                                                        <td className="p-2 flex items-center">
                                                            <img 
                                                                src={getItemIconUrl(ore.typeId)} 
                                                                alt={ore.name} 
                                                                className="w-6 h-6 inline-block mr-2 rounded" 
                                                            />
                                                            {ore.name}
                                                        </td>
                                                        <td className="p-2 text-right">{formatNumber(ore.quantity)}</td>
                                                        <td className="p-2 text-right">{formatISK(ore.value / ore.quantity)}</td>
                                                        <td className="p-2 text-right font-bold text-eve-primary">
                                                            {formatISK(ore.value)}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB: MONTHLY INCOME */}
            {activeMainTab === 'monthly' && (
                <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                    <div className="flex justify-between items-center flex-wrap gap-4 mb-6">
                        <div>
                            <h2 className="text-xl font-semibold text-white mb-0">Gemeinsames Monatseinkommen</h2>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="text-xs font-semibold text-eve-muted mb-0">Monat auswählen:</label>
                            <input 
                                type="month" 
                                className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300 w-auto" 
                                value={selectedMonth}
                                onChange={(e) => setSelectedMonth(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-6">
                        {/* Summary Stats */}
                        <div className="w-full md:w-1/3 flex flex-col gap-4">
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <p className="text-xs text-eve-muted mb-2">Gesamtwert im Monat: {selectedMonth}</p>
                                <h3 className="text-2xl font-bold text-eve-primary">
                                    {formatISK(monthlyCombined.totalValue)}
                                </h3>
                                <hr className="border-eve-border my-4" />
                                <p className="text-xs text-eve-muted mb-1">Menge erzielt:</p>
                                <p className="font-bold text-sm text-white">{formatNumber(monthlyCombined.totalQuantity)} Einheiten</p>
                            </div>

                            {/* Character share */}
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <h4 className="text-base font-semibold mb-3 text-white">Aufteilung nach Charakter</h4>
                                {monthlyCombined.charSummary.length === 0 ? (
                                    <p className="text-xs text-eve-muted">Keine Aktivitäten in diesem Monat.</p>
                                ) : (
                                    <div className="flex flex-col gap-3">
                                        {monthlyCombined.charSummary.map((c) => (
                                            <div key={c.id} className="flex justify-between items-center flex-wrap gap-1 min-w-0">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <img 
                                                        src={getCharacterPortraitUrl(c.id)} 
                                                        alt={c.name} 
                                                        className="w-6 h-6 rounded-full border border-eve-border flex-shrink-0"
                                                    />
                                                    <span className="text-sm text-eve-text truncate">
                                                        {c.name}
                                                    </span>
                                                </div>
                                                <span className="whitespace-nowrap font-bold text-sm text-eve-primary ml-auto flex-shrink-0">
                                                    {formatISK(c.value)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Ore Breakdown */}
                        <div className="flex-1 min-w-[280px]">
                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                <h4 className="text-base font-semibold mb-3 text-white">Abgebautes Erz / Ressourcen</h4>
                                {monthlyCombined.oreSummary.length === 0 ? (
                                    <div className="text-center p-5">
                                        <p className="text-xs text-eve-muted">In diesem Monat wurden keine Erze abgebaut.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full border-collapse text-left bg-transparent text-eve-text text-xs">
                                            <thead>
                                                <tr className="border-b border-white/10">
                                                    <th className="font-semibold text-eve-muted p-2">Ressource</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Menge</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Est. Stückpreis</th>
                                                    <th className="font-semibold text-eve-muted p-2 text-right">Gesamtwert</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {monthlyCombined.oreSummary.map((ore) => (
                                                    <tr key={ore.typeId} className="border-b border-white/5">
                                                        <td className="p-2 flex items-center">
                                                            <img 
                                                                src={getItemIconUrl(ore.typeId)} 
                                                                alt={ore.name} 
                                                                className="w-6 h-6 inline-block mr-2 rounded" 
                                                            />
                                                            {ore.name}
                                                        </td>
                                                        <td className="p-2 text-right">{formatNumber(ore.quantity)}</td>
                                                        <td className="p-2 text-right">{formatISK(ore.value / ore.quantity)}</td>
                                                        <td className="p-2 text-right font-bold text-eve-primary">
                                                            {formatISK(ore.value)}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB: CHARACTERS */}
            {activeMainTab === 'characters' && (
                <div>
                    {/* Character Cards list */}
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-4 mb-6">
                        {charactersData.map((char) => {
                            let charValue = 0;
                            char.records.forEach(r => charValue += r.value);

                            return (
                                <div 
                                    key={char.id} 
                                    className={`flex items-center gap-4 p-4 rounded-lg border border-eve-border bg-[#141b2b66] cursor-pointer transition-all duration-200 hover:border-eve-primary ${selectedCharId === char.id ? 'bg-eve-primary/10 border-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.2)]' : ''}`}
                                    onClick={() => setSelectedCharId(char.id)}
                                >
                                    <img 
                                        src={getCharacterPortraitUrl(char.id)} 
                                        alt={char.name} 
                                        className={`w-12 h-12 rounded-full border-2 ${selectedCharId === char.id ? 'border-eve-primary' : 'border-eve-border'}`} 
                                    />
                                    <div>
                                        <h4 className="font-bold text-sm text-white mb-0">{char.name}</h4>
                                        {char.error ? (
                                            <span className="text-rose-400 text-xs">⚠️ Fehler</span>
                                        ) : (
                                            <span className="text-eve-muted text-xs">
                                                90d: {formatISK(charValue)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Selected Character Detail */}
                    {activeChar && (
                        <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                            <div className="flex justify-between items-center flex-wrap gap-4 mb-6">
                                <div className="flex items-center gap-3">
                                    <img 
                                        src={getCharacterPortraitUrl(activeChar.id)} 
                                        alt={activeChar.name} 
                                        className="w-12 h-12 rounded-full border-2 border-eve-primary" 
                                    />
                                    <div>
                                        <h2 className="text-xl font-semibold text-white mb-0">{activeChar.name}</h2>
                                        <p className="text-eve-muted text-xs">Bergbautagebuch-Details</p>
                                    </div>
                                </div>
                            </div>

                            {activeChar.error ? (
                                <div className="py-5 px-6 rounded-lg mb-6 bg-rose-500/10 border border-rose-500/30 text-rose-400">
                                    <p className="mb-0">{activeChar.error}</p>
                                </div>
                            ) : (
                                <div>
                                    {/* Stats grid */}
                                    <div className="flex flex-wrap gap-4 mb-6">
                                        <div className="flex-1 min-w-[200px]">
                                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                                <p className="text-xs text-eve-muted mb-1">Gesamtwert (90 Tage)</p>
                                                <p className="text-lg font-bold text-eve-primary mb-0">
                                                    {formatISK(activeCharStats.totalVal)}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex-1 min-w-[200px]">
                                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                                <p className="text-xs text-eve-muted mb-1">Geförderte Erze</p>
                                                <p className="text-lg font-bold text-white mb-0">
                                                    {formatNumber(activeCharStats.totalQty)} <span className="text-xs font-normal text-eve-muted">Einheiten</span>
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex-1 min-w-[200px]">
                                            <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                                                <p className="text-xs text-eve-muted mb-1">Einträge</p>
                                                <p className="text-lg font-bold text-white mb-0">
                                                    {activeCharStats.count} <span className="text-xs font-normal text-eve-muted">Aktivitäten</span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Full Log Table */}
                                    <h4 className="text-base font-semibold mb-3 text-white">Chronologisches Bergbautagebuch</h4>
                                    {activeChar.records.length === 0 ? (
                                        <div className="text-center p-5">
                                            <p className="text-xs text-eve-muted">Keine Bergbaueinträge in den letzten 90 Tagen gefunden.</p>
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full border-collapse text-left bg-transparent text-eve-text text-xs">
                                                <thead>
                                                    <tr className="border-b border-white/10">
                                                        <th className="font-semibold text-eve-muted p-2">Datum</th>
                                                        <th className="font-semibold text-eve-muted p-2">Sonnensystem</th>
                                                        <th className="font-semibold text-eve-muted p-2">Ressource</th>
                                                        <th className="font-semibold text-eve-muted p-2 text-right">Menge</th>
                                                        <th className="font-semibold text-eve-muted p-2 text-right">Einheitspreis</th>
                                                        <th className="font-semibold text-eve-muted p-2 text-right">Gesamtwert</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {activeChar.records.map((rec, idx) => (
                                                        <tr key={idx} className="border-b border-white/5">
                                                            <td className="p-2">{rec.date}</td>
                                                            <td className="p-2">{rec.solarSystemName}</td>
                                                            <td className="p-2 flex items-center">
                                                                <img 
                                                                    src={getItemIconUrl(rec.typeId)} 
                                                                    alt={rec.typeName} 
                                                                    className="w-6 h-6 inline-block mr-2 rounded" 
                                                                />
                                                                {rec.typeName}
                                                            </td>
                                                            <td className="p-2 text-right">{formatNumber(rec.quantity)}</td>
                                                            <td className="p-2 text-right">{formatISK(rec.price)}</td>
                                                            <td className="p-2 text-right font-bold text-eve-primary">
                                                                {formatISK(rec.value)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
