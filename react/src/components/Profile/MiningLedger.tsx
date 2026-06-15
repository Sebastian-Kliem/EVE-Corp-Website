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
            <div className="box has-text-centered p-5">
                <span className="loader" style={{ display: 'inline-block', width: '2rem', height: '2rem', border: '3px solid var(--theme-primary)', borderRadius: '50%', borderTopColor: 'transparent', animation: 'spin 1s linear infinite' }}></span>
                <p className="mt-3">Bergbaudaten werden geladen...</p>
                <style>{`
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                `}</style>
            </div>
        );
    }

    if (error) {
        return (
            <div className="box has-text-centered p-5" style={{ borderColor: 'red' }}>
                <h3 className="title is-4" style={{ color: '#ff4444' }}>Fehler</h3>
                <p className="subtitle is-6">{error}</p>
            </div>
        );
    }

    return (
        <div>
            {/* Custom Tab styling */}
            <style>{`
                .mining-tabs {
                    display: flex;
                    border-bottom: 2px solid var(--theme-card-border);
                    margin-bottom: 1.5rem;
                    gap: 0.5rem;
                }
                .mining-tab-btn {
                    background: transparent;
                    border: none;
                    color: var(--theme-text-muted);
                    padding: 0.75rem 1.25rem;
                    font-size: 1rem;
                    cursor: pointer;
                    font-weight: 500;
                    border-bottom: 3px solid transparent;
                    transition: all 0.2s ease;
                }
                .mining-tab-btn:hover {
                    color: var(--theme-primary);
                }
                .mining-tab-btn.is-active {
                    color: var(--theme-primary);
                    border-bottom-color: var(--theme-primary);
                }
                .summary-card {
                    background: rgba(20, 27, 43, 0.5);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    padding: 1.5rem;
                    margin-bottom: 1.5rem;
                }
                .char-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 1rem;
                    margin-bottom: 1.5rem;
                }
                .char-card-selector {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    padding: 1rem;
                    border-radius: 8px;
                    border: 1px solid var(--theme-card-border);
                    background: rgba(20, 27, 43, 0.4);
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                .char-card-selector:hover {
                    border-color: var(--theme-primary);
                }
                .char-card-selector.is-active {
                    background: rgba(0, 240, 255, 0.08);
                    border-color: var(--theme-primary);
                    box-shadow: 0 0 10px rgba(0, 240, 255, 0.2);
                }
                .char-portrait {
                    width: 48px;
                    height: 48px;
                    border-radius: 50%;
                    border: 2px solid var(--theme-card-border);
                }
                .char-card-selector.is-active .char-portrait {
                    border-color: var(--theme-primary);
                }
                .item-icon {
                    width: 24px;
                    height: 24px;
                    vertical-align: middle;
                    margin-right: 0.5rem;
                    border-radius: 4px;
                }
            `}</style>

            {/* Navigation Tabs */}
            <div className="mining-tabs">
                <button 
                    className={`mining-tab-btn ${activeMainTab === 'daily' ? 'is-active' : ''}`}
                    onClick={() => setActiveMainTab('daily')}
                >
                    📅 Tageseinkommen
                </button>
                <button 
                    className={`mining-tab-btn ${activeMainTab === 'monthly' ? 'is-active' : ''}`}
                    onClick={() => setActiveMainTab('monthly')}
                >
                    📊 Monatseinkommen
                </button>
                <button 
                    className={`mining-tab-btn ${activeMainTab === 'characters' ? 'is-active' : ''}`}
                    onClick={() => setActiveMainTab('characters')}
                >
                    👤 Charakterübersicht
                </button>
            </div>

            {/* TAB: DAILY INCOME */}
            {activeMainTab === 'daily' && (
                <div className="box">
                    <div className="level mb-4">
                        <div className="level-left">
                            <h2 className="title is-4 mb-0">Gemeinsames Tageseinkommen</h2>
                        </div>
                        <div className="level-right">
                            <label className="label mr-2 mb-0" style={{ color: 'var(--theme-text-muted)' }}>Tag auswählen:</label>
                            <input 
                                type="date" 
                                className="input input-dark" 
                                style={{ width: 'auto' }}
                                value={selectedDate}
                                onChange={(e) => setSelectedDate(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="columns">
                        {/* Summary Stats */}
                        <div className="column is-one-third">
                            <div className="summary-card">
                                <p className="subtitle is-6 mb-2">Gesamtwert am {selectedDate}</p>
                                <h3 className="title is-3" style={{ color: 'var(--theme-primary)' }}>
                                    {formatISK(dailyCombined.totalValue)}
                                </h3>
                                <hr style={{ borderColor: 'var(--theme-card-border)', margin: '1rem 0' }} />
                                <p className="subtitle is-6 mb-1">Menge erzielt:</p>
                                <p className="has-text-weight-bold">{formatNumber(dailyCombined.totalQuantity)} Einheiten</p>
                            </div>

                            {/* Character share */}
                            <div className="summary-card">
                                <h4 className="title is-5 mb-3">Aufteilung nach Charakter</h4>
                                {dailyCombined.charSummary.length === 0 ? (
                                    <p className="text-muted">Keine Aktivitäten an diesem Tag.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                                        {dailyCombined.charSummary.map((c) => (
                                            <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.25rem 0.5rem', minWidth: 0 }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', minWidth: 0 }}>
                                                    <img 
                                                        src={getCharacterPortraitUrl(c.id)} 
                                                        alt={c.name} 
                                                        style={{ width: '24px', height: '24px', borderRadius: '50%', flexShrink: 0 }} 
                                                    />
                                                    <span style={{ fontSize: '0.95rem' }}>
                                                        {c.name}
                                                    </span>
                                                </div>
                                                <span style={{ whiteSpace: 'nowrap', fontWeight: 'bold', fontSize: '0.95rem', color: 'var(--theme-primary)', marginLeft: 'auto', flexShrink: 0 }}>
                                                    {formatISK(c.value)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Ore Breakdown */}
                        <div className="column is-two-thirds">
                            <div className="summary-card">
                                <h4 className="title is-5 mb-3">Abgebautes Erz / Ressourcen</h4>
                                {dailyCombined.oreSummary.length === 0 ? (
                                    <div className="has-text-centered p-5">
                                        <p className="text-muted">An diesem Tag wurden keine Erze abgebaut.</p>
                                    </div>
                                ) : (
                                    <div className="table-container">
                                        <table className="table is-striped is-fullwidth">
                                            <thead>
                                                <tr>
                                                    <th>Ressource</th>
                                                    <th className="has-text-right">Menge</th>
                                                    <th className="has-text-right">Est. Stückpreis</th>
                                                    <th className="has-text-right">Gesamtwert</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {dailyCombined.oreSummary.map((ore) => (
                                                    <tr key={ore.typeId}>
                                                        <td>
                                                            <img 
                                                                src={getItemIconUrl(ore.typeId)} 
                                                                alt={ore.name} 
                                                                className="item-icon" 
                                                            />
                                                            {ore.name}
                                                        </td>
                                                        <td className="has-text-right">{formatNumber(ore.quantity)}</td>
                                                        <td className="has-text-right">{formatISK(ore.value / ore.quantity)}</td>
                                                        <td className="has-text-right has-text-weight-bold" style={{ color: 'var(--theme-primary)' }}>
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
                <div className="box">
                    <div className="level mb-4">
                        <div className="level-left">
                            <h2 className="title is-4 mb-0">Gemeinsames Monatseinkommen</h2>
                        </div>
                        <div className="level-right">
                            <label className="label mr-2 mb-0" style={{ color: 'var(--theme-text-muted)' }}>Monat auswählen:</label>
                            <input 
                                type="month" 
                                className="input input-dark" 
                                style={{ width: 'auto' }}
                                value={selectedMonth}
                                onChange={(e) => setSelectedMonth(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="columns">
                        {/* Summary Stats */}
                        <div className="column is-one-third">
                            <div className="summary-card">
                                <p className="subtitle is-6 mb-2">Gesamtwert im Monat: {selectedMonth}</p>
                                <h3 className="title is-3" style={{ color: 'var(--theme-primary)' }}>
                                    {formatISK(monthlyCombined.totalValue)}
                                </h3>
                                <hr style={{ borderColor: 'var(--theme-card-border)', margin: '1rem 0' }} />
                                <p className="subtitle is-6 mb-1">Menge erzielt:</p>
                                <p className="has-text-weight-bold">{formatNumber(monthlyCombined.totalQuantity)} Einheiten</p>
                            </div>

                            {/* Character share */}
                            <div className="summary-card">
                                <h4 className="title is-5 mb-3">Aufteilung nach Charakter</h4>
                                {monthlyCombined.charSummary.length === 0 ? (
                                    <p className="text-muted">Keine Aktivitäten in diesem Monat.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                                        {monthlyCombined.charSummary.map((c) => (
                                            <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.25rem 0.5rem', minWidth: 0 }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', minWidth: 0 }}>
                                                    <img 
                                                        src={getCharacterPortraitUrl(c.id)} 
                                                        alt={c.name} 
                                                        style={{ width: '24px', height: '24px', borderRadius: '50%', flexShrink: 0 }} 
                                                    />
                                                    <span style={{ fontSize: '0.95rem' }}>
                                                        {c.name}
                                                    </span>
                                                </div>
                                                <span style={{ whiteSpace: 'nowrap', fontWeight: 'bold', fontSize: '0.95rem', color: 'var(--theme-primary)', marginLeft: 'auto', flexShrink: 0 }}>
                                                    {formatISK(c.value)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Ore Breakdown */}
                        <div className="column is-two-thirds">
                            <div className="summary-card">
                                <h4 className="title is-5 mb-3">Abgebautes Erz / Ressourcen</h4>
                                {monthlyCombined.oreSummary.length === 0 ? (
                                    <div className="has-text-centered p-5">
                                        <p className="text-muted">In diesem Monat wurden keine Erze abgebaut.</p>
                                    </div>
                                ) : (
                                    <div className="table-container">
                                        <table className="table is-striped is-fullwidth">
                                            <thead>
                                                <tr>
                                                    <th>Ressource</th>
                                                    <th className="has-text-right">Menge</th>
                                                    <th className="has-text-right">Est. Stückpreis</th>
                                                    <th className="has-text-right">Gesamtwert</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {monthlyCombined.oreSummary.map((ore) => (
                                                    <tr key={ore.typeId}>
                                                        <td>
                                                            <img 
                                                                src={getItemIconUrl(ore.typeId)} 
                                                                alt={ore.name} 
                                                                className="item-icon" 
                                                            />
                                                            {ore.name}
                                                        </td>
                                                        <td className="has-text-right">{formatNumber(ore.quantity)}</td>
                                                        <td className="has-text-right">{formatISK(ore.value / ore.quantity)}</td>
                                                        <td className="has-text-right has-text-weight-bold" style={{ color: 'var(--theme-primary)' }}>
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
                    <div className="char-grid">
                        {charactersData.map((char) => {
                            let charValue = 0;
                            char.records.forEach(r => charValue += r.value);

                            return (
                                <div 
                                    key={char.id} 
                                    className={`char-card-selector ${selectedCharId === char.id ? 'is-active' : ''}`}
                                    onClick={() => setSelectedCharId(char.id)}
                                >
                                    <img 
                                        src={getCharacterPortraitUrl(char.id)} 
                                        alt={char.name} 
                                        className="char-portrait" 
                                    />
                                    <div>
                                        <h4 className="has-text-weight-bold mb-0">{char.name}</h4>
                                        {char.error ? (
                                            <span style={{ color: '#ff4444', fontSize: '0.8rem' }}>⚠️ Fehler</span>
                                        ) : (
                                            <span className="text-muted" style={{ fontSize: '0.85rem' }}>
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
                        <div className="box">
                            <div className="level mb-4">
                                <div className="level-left">
                                    <img 
                                        src={getCharacterPortraitUrl(activeChar.id)} 
                                        alt={activeChar.name} 
                                        style={{ width: '48px', height: '48px', borderRadius: '50%', border: '2px solid var(--theme-primary)' }} 
                                    />
                                    <div>
                                        <h2 className="title is-4 mb-0">{activeChar.name}</h2>
                                        <p className="text-muted" style={{ fontSize: '0.9rem' }}>Bergbautagebuch-Details</p>
                                    </div>
                                </div>
                            </div>

                            {activeChar.error ? (
                                <div className="notification is-danger" style={{ background: 'rgba(255, 68, 68, 0.15)', border: '1px solid #ff4444', borderRadius: '8px', padding: '1rem', color: '#ff8888' }}>
                                    <p className="mb-0">{activeChar.error}</p>
                                </div>
                            ) : (
                                <div>
                                    {/* Stats grid */}
                                    <div className="columns mb-4">
                                        <div className="column">
                                            <div className="summary-card mb-0">
                                                <p className="subtitle is-6 mb-1">Gesamtwert (90 Tage)</p>
                                                <p className="title is-4 mb-0" style={{ color: 'var(--theme-primary)' }}>
                                                    {formatISK(activeCharStats.totalVal)}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="column">
                                            <div className="summary-card mb-0">
                                                <p className="subtitle is-6 mb-1">Geförderte Erze</p>
                                                <p className="title is-4 mb-0">
                                                    {formatNumber(activeCharStats.totalQty)} <span className="is-size-6 font-weight-normal text-muted">Einheiten</span>
                                                </p>
                                            </div>
                                        </div>
                                        <div className="column">
                                            <div className="summary-card mb-0">
                                                <p className="subtitle is-6 mb-1">Einträge</p>
                                                <p className="title is-4 mb-0">
                                                    {activeCharStats.count} <span className="is-size-6 font-weight-normal text-muted">Aktivitäten</span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Full Log Table */}
                                    <h4 className="title is-5 mb-3">Chronologisches Bergbautagebuch</h4>
                                    {activeChar.records.length === 0 ? (
                                        <div className="has-text-centered p-5">
                                            <p className="text-muted">Keine Bergbaueinträge in den letzten 90 Tagen gefunden.</p>
                                        </div>
                                    ) : (
                                        <div className="table-container">
                                            <table className="table is-striped is-fullwidth">
                                                <thead>
                                                    <tr>
                                                        <th>Datum</th>
                                                        <th>Sonnensystem</th>
                                                        <th>Ressource</th>
                                                        <th className="has-text-right">Menge</th>
                                                        <th className="has-text-right">Einheitspreis</th>
                                                        <th className="has-text-right">Gesamtwert</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {activeChar.records.map((rec, idx) => (
                                                        <tr key={idx}>
                                                            <td>{rec.date}</td>
                                                            <td>{rec.solarSystemName}</td>
                                                            <td>
                                                                <img 
                                                                    src={getItemIconUrl(rec.typeId)} 
                                                                    alt={rec.typeName} 
                                                                    className="item-icon" 
                                                                />
                                                                {rec.typeName}
                                                            </td>
                                                            <td className="has-text-right">{formatNumber(rec.quantity)}</td>
                                                            <td className="has-text-right">{formatISK(rec.price)}</td>
                                                            <td className="has-text-right has-text-weight-bold" style={{ color: 'var(--theme-primary)' }}>
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
