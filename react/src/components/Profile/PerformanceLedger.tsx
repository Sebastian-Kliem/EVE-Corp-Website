import React, { useState, useEffect, useMemo } from 'react';

interface CharacterListEntry {
    id: number;
    name: string;
    accountGroup: string;
    accountName: string;
    tags?: string[];
}

interface PerformanceDetail {
    character: string;
    category: string;
    typeName: string;
    quantity: number;
    price: number;
    totalValue: number;
    isWallet: boolean;
    typeId: number;
    manualEntryId?: number;
}

interface DailySummary {
    totalValue: number;
    byCategory: {
        gas: number;
        ore_ice: number;
        blue_loot: number;
        abyss_loot: number;
        hacking_salvage: number;
        wallet_rewards: number;
        other: number;
    };
}

interface DailyPerformance {
    date: string;
    summary: DailySummary;
    details: PerformanceDetail[];
}

interface PerformanceLedgerProps {
    charactersList: CharacterListEntry[];
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
    omegaAccountCount: number;
}

const CATEGORY_NAMES: Record<string, string> = {
    gas: 'Gas-Ernte',
    ore_ice: 'Erz & Eis',
    blue_loot: 'Blue Loot (Sleeper)',
    abyss_loot: 'Abyss-Loot',
    hacking_salvage: 'Hacking & Salvaging',
    wallet_rewards: 'Belohnungen & Bounties',
    other: 'Sonstiges'
};

const CATEGORY_COLORS: Record<string, string> = {
    gas: '#00f0ff',
    ore_ice: '#ffaa00',
    blue_loot: '#0066ff',
    abyss_loot: '#ff0055',
    hacking_salvage: '#a800ff',
    wallet_rewards: '#00ff88',
    other: '#888888'
};

const OMEGA_COST_ISK = 2500000000; // 2.5 Billion ISK

export default function PerformanceLedger({ charactersList, apiDataUrl, imagePaths, omegaAccountCount }: PerformanceLedgerProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [ledgerData, setLedgerData] = useState<Record<string, DailyPerformance>>({});

    // Calculate dynamic Omega goal based on manually set Omega accounts
    const omegaGoal = useMemo(() => {
        const count = omegaAccountCount > 0 ? omegaAccountCount : 1;
        return count * OMEGA_COST_ISK;
    }, [omegaAccountCount]);

    // Calculate sum of earnings in current calendar month from ledgerData
    const currentMonthEarnings = useMemo(() => {
        const today = new Date();
        const currentYear = today.getUTCFullYear();
        const currentMonth = today.getUTCMonth() + 1; // 1-12
        
        let total = 0;
        Object.entries(ledgerData).forEach(([dateStr, day]) => {
            const [yearStr, monthStr] = dateStr.split('-');
            const year = parseInt(yearStr, 10);
            const month = parseInt(monthStr, 10);
            
            if (year === currentYear && month === currentMonth) {
                total += day.summary.totalValue;
            }
        });
        return total;
    }, [ledgerData]);

    const currentMonthName = useMemo(() => {
        const monthNames = [
            'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
        ];
        const today = new Date();
        return `${monthNames[today.getUTCMonth()]} ${today.getUTCFullYear()}`;
    }, []);

    // Filters state
    const [selectedDateRange, setSelectedDateRange] = useState<string>('today'); // 'today', 'yesterday', '7', '30', '90'
    const [selectedCharacters, setSelectedCharacters] = useState<string[]>([]);
    const [selectedCategories, setSelectedCategories] = useState<string[]>(['gas', 'ore_ice', 'blue_loot', 'abyss_loot', 'hacking_salvage', 'wallet_rewards', 'other']);
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [expandedDates, setExpandedDates] = useState<Record<string, boolean>>({});
    const [selectedTag, setSelectedTag] = useState<string>('all');

    // Collect all unique tags
    const allTags = useMemo(() => {
        const tags = new Set<string>();
        charactersList.forEach(c => {
            if (c.tags) {
                c.tags.forEach(t => tags.add(t));
            }
        });
        return Array.from(tags).sort();
    }, [charactersList]);

    // Ertragsjournal refresh & detail states
    const [refreshTrigger, setRefreshTrigger] = useState<number>(0);
    const [expandedItemKey, setExpandedItemKey] = useState<string | null>(null);

    // Manual entry states
    const [manualDate, setManualDate] = useState<string>(() => {
        const today = new Date();
        const year = today.getUTCFullYear();
        const month = String(today.getUTCMonth() + 1).padStart(2, '0');
        const day = String(today.getUTCDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    });
    const [manualCharId, setManualCharId] = useState<string>('');
    const [manualCategory, setManualCategory] = useState<string>('other');
    const [manualDescription, setManualDescription] = useState<string>('');
    const [manualAmount, setManualAmount] = useState<string>('');
    const [manualLoading, setManualLoading] = useState<boolean>(false);
    const [manualError, setManualError] = useState<string | null>(null);

    const handleAddManualEntry = (e: React.FormEvent) => {
        e.preventDefault();
        if (!manualDescription.trim()) {
            setManualError('Beschreibung fehlt.');
            return;
        }
        const amt = parseFloat(manualAmount);
        if (isNaN(amt) || amt <= 0) {
            setManualError('Betrag muss größer als 0 sein.');
            return;
        }
        setManualLoading(true);
        setManualError(null);

        fetch('/dashboard/performance/manual', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                date: manualDate,
                category: manualCategory,
                description: manualDescription,
                amount: amt,
                characterId: manualCharId ? parseInt(manualCharId, 10) : null
            })
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(data => {
                    throw new Error(data.error || 'Fehler beim Speichern.');
                });
            }
            return res.json();
        })
        .then(() => {
            setManualDescription('');
            setManualAmount('');
            setManualLoading(false);
            setRefreshTrigger(prev => prev + 1);
        })
        .catch(err => {
            setManualError(err.message);
            setManualLoading(false);
        });
    };

    const handleDeleteManualEntry = (id: number, description: string, amount: number) => {
        if (!confirm(`Möchtest du die manuelle Buchung "${description}" über ${formatISK(amount)} wirklich löschen?`)) {
            return;
        }

        fetch(`/dashboard/performance/manual/${id}`, {
            method: 'DELETE'
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(data => {
                    throw new Error(data.error || 'Fehler beim Löschen.');
                });
            }
            return res.json();
        })
        .then(() => {
            setRefreshTrigger(prev => prev + 1);
        })
        .catch(err => {
            alert(err.message);
        });
    };

    // Fetch data
    useEffect(() => {
        setLoading(true);
        fetch(apiDataUrl)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Fehler beim Abrufen der Performance-Daten.');
                }
                return res.json();
            })
            .then((data: Record<string, DailyPerformance>) => {
                setLedgerData(data);

                // Expand the first date by default
                const dates = Object.keys(data);
                if (dates.length > 0) {
                    setExpandedDates({ [dates[0]]: true });
                }

                // Collect all characters from data to select them by default
                const chars = new Set<string>();
                Object.values(data).forEach(day => {
                    day.details.forEach(d => {
                        chars.add(d.character);
                    });
                });
                setSelectedCharacters(Array.from(chars));

                setLoading(false);
            })
            .catch((err) => {
                setError(err.message || 'Ein unbekannter Fehler ist aufgetreten.');
                setLoading(false);
            });
    }, [apiDataUrl, refreshTrigger]);

    // Available characters in current data
    const availableCharacters = useMemo(() => {
        const chars = new Set<string>();
        Object.values(ledgerData).forEach(day => {
            day.details.forEach(d => {
                if (selectedTag === 'all') {
                    chars.add(d.character);
                } else {
                    const charObj = charactersList.find(c => c.name === d.character);
                    if (charObj && charObj.tags && charObj.tags.includes(selectedTag)) {
                        chars.add(d.character);
                    }
                }
            });
        });
        return Array.from(chars).sort();
    }, [ledgerData, selectedTag, charactersList]);

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

    const toggleDateExpand = (date: string) => {
        setExpandedDates(prev => ({
            ...prev,
            [date]: !prev[date]
        }));
    };

    const toggleCharacterFilter = (char: string) => {
        setSelectedCharacters(prev =>
            prev.includes(char) ? prev.filter(c => c !== char) : [...prev, char]
        );
    };

    const toggleCategoryFilter = (cat: string) => {
        setSelectedCategories(prev =>
            prev.includes(cat) ? prev.filter(c => c !== cat) : [...prev, cat]
        );
    };

    // Filter and reconstruct daily records based on active filters
    const filteredLedger = useMemo(() => {
        const today = new Date();
        const formatDateStr = (d: Date) => {
            const year = d.getUTCFullYear();
            const month = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        const todayStr = formatDateStr(today);

        const yesterday = new Date(today.getTime() - 24 * 60 * 60 * 1000);
        const yesterdayStr = formatDateStr(yesterday);

        const result: DailyPerformance[] = [];

        Object.entries(ledgerData).forEach(([dateStr, day]) => {
            // Apply Date Range filter
            if (selectedDateRange === 'today') {
                if (dateStr !== todayStr) {
                    return;
                }
            } else if (selectedDateRange === 'yesterday') {
                if (dateStr !== yesterdayStr) {
                    return;
                }
            } else {
                const daysLimit = parseInt(selectedDateRange, 10);
                const cutoffDate = new Date();
                cutoffDate.setUTCDate(cutoffDate.getUTCDate() - daysLimit);
                const cutoffStr = formatDateStr(cutoffDate);
                if (dateStr < cutoffStr) {
                    return;
                }
            }

            // Filter details
            const filteredDetails = day.details.filter(item => {
                // Character check
                if (!selectedCharacters.includes(item.character)) {
                    return false;
                }
                // Tag check
                if (selectedTag !== 'all') {
                    const charObj = charactersList.find(c => c.name === item.character);
                    if (!charObj || !charObj.tags || !charObj.tags.includes(selectedTag)) {
                        return false;
                    }
                }
                // Category check
                if (!selectedCategories.includes(item.category)) {
                    return false;
                }
                // Search term check
                if (searchTerm && !item.typeName.toLowerCase().includes(searchTerm.toLowerCase())) {
                    return false;
                }
                return true;
            });

            if (filteredDetails.length === 0) {
                return;
            }

            // Recalculate summary for filtered details
            const summary: DailySummary = {
                totalValue: 0.0,
                byCategory: {
                    gas: 0.0,
                    ore_ice: 0.0,
                    blue_loot: 0.0,
                    abyss_loot: 0.0,
                    hacking_salvage: 0.0,
                    wallet_rewards: 0.0,
                    other: 0.0
                }
            };

            filteredDetails.forEach(item => {
                const cat = item.category as keyof DailySummary['byCategory'];
                if (summary.byCategory[cat] !== undefined) {
                    summary.byCategory[cat] += item.totalValue;
                } else {
                    summary.byCategory.other += item.totalValue;
                }
                summary.totalValue += item.totalValue;
            });

            result.push({
                date: dateStr,
                summary,
                details: filteredDetails
            });
        });

        return result;
    }, [ledgerData, selectedDateRange, selectedCharacters, selectedCategories, searchTerm, selectedTag, charactersList]);

    // Overall summary across the filtered ledger
    const totalEarnings = useMemo(() => {
        let total = 0.0;
        const byCat = {
            gas: 0.0,
            ore_ice: 0.0,
            blue_loot: 0.0,
            abyss_loot: 0.0,
            hacking_salvage: 0.0,
            wallet_rewards: 0.0,
            other: 0.0
        };

        filteredLedger.forEach(day => {
            total += day.summary.totalValue;
            Object.keys(byCat).forEach(cat => {
                const c = cat as keyof typeof byCat;
                byCat[c] += day.summary.byCategory[c];
            });
        });

        return { total, byCat };
    }, [filteredLedger]);

    if (loading) {
        return (
            <div className="box has-text-centered p-5">
                <span className="loader" style={{ display: 'inline-block', width: '2rem', height: '2rem', border: '3px solid var(--theme-primary)', borderRadius: '50%', borderTopColor: 'transparent', animation: 'spin 1s linear infinite' }}></span>
                <p className="mt-3">Performance-Daten werden berechnet...</p>
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
        <div className="perf-ledger-container">
            <div className="stats-panel">
                <div className="stat-box" style={{ borderLeft: '4px solid var(--theme-primary)' }}>
                    <span className="stat-label">Gesamtertrag (Netto)</span>
                    <span className="stat-val" style={{ color: 'var(--theme-primary)' }}>{formatISK(totalEarnings.total)}</span>
                </div>
                {Object.entries(totalEarnings.byCat).map(([cat, val]) => {
                    if (val === 0) return null;
                    return (
                        <div key={cat} className="stat-box" style={{ borderLeft: `4px solid ${CATEGORY_COLORS[cat]}` }}>
                            <span className="stat-label">{CATEGORY_NAMES[cat]}</span>
                            <span className="stat-val">{formatISK(val)}</span>
                        </div>
                    );
                })}
            </div>

            {/* Omega Target Widget */}
            {(() => {
                const percent = Math.min(100, Math.max(0, (currentMonthEarnings / omegaGoal) * 100));
                const isNegative = currentMonthEarnings < 0;

                return (
                    <div className="box omega-tracker-box mb-4" style={{
                        background: 'rgba(13, 19, 32, 0.7)',
                        border: '1px solid var(--theme-card-border)',
                        borderRadius: '8px',
                        padding: '1.25rem',
                        marginBottom: '1.5rem'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '15px' }}>
                            <div style={{ flex: '1 1 300px' }}>
                                <h3 className="title is-6 mb-2" style={{ color: 'var(--theme-primary)', display: 'flex', alignItems: 'center', gap: '8px', margin: 0 }}>
                                    🎯 Omega-Ziel Tracker ({currentMonthName})
                                </h3>
                                <p className="is-size-7 has-text-grey-light mb-0">
                                    {omegaAccountCount > 0 ? (
                                        <span>Fortschritt für deine <strong>{omegaAccountCount} Omega-Accounts</strong> (Ziel: <strong>{formatISK(omegaGoal)}</strong>).</span>
                                    ) : (
                                        <span>Vergleiche deine Erträge dieses Monats mit den Kosten für einen Omega-Account (<strong>{formatISK(OMEGA_COST_ISK)}</strong>).</span>
                                    )}
                                </p>
                            </div>
                            
                            <div style={{ flex: '1 1 300px', display: 'flex', flexDirection: 'column', gap: '5px' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span className="is-size-7" style={{ color: '#ccc' }}>
                                        Erwirtschaftet: <strong style={{ color: isNegative ? '#f14668' : '#fff' }}>{formatISK(currentMonthEarnings)}</strong>
                                    </span>
                                    <span className={`is-size-7`} style={{ fontWeight: 'bold', color: isNegative ? '#f14668' : percent >= 100 ? '#00ffaa' : 'var(--theme-primary)' }}>
                                        {isNegative ? '0%' : `${percent.toFixed(1)}%`}
                                    </span>
                                </div>
                                
                                {/* Progress Bar Container */}
                                <div style={{
                                    width: '100%', 
                                    height: '14px', 
                                    backgroundColor: 'rgba(0, 0, 0, 0.4)', 
                                    borderRadius: '7px', 
                                    overflow: 'hidden',
                                    border: '1px solid var(--theme-card-border)',
                                    position: 'relative'
                                }}>
                                    <div style={{
                                        width: `${isNegative ? 0 : percent}%`,
                                        height: '100%',
                                        background: percent >= 100 
                                            ? 'linear-gradient(90deg, #00b37a 0%, #00ffaa 100%)' 
                                            : 'linear-gradient(90deg, #0284c7 0%, var(--theme-primary) 100%)',
                                        borderRadius: '7px',
                                        transition: 'width 0.5s ease-out',
                                        boxShadow: percent >= 100 ? '0 0 8px rgba(0, 255, 170, 0.4)' : '0 0 8px rgba(0, 240, 255, 0.3)'
                                    }}></div>
                                </div>
                            </div>
                            
                            <div style={{ flex: '1 1 100%', borderTop: '1px solid rgba(255,255,255,0.05)', paddingTop: '10px', marginTop: '5px' }}>
                                {percent >= 100 ? (
                                    <div className="is-size-7" style={{ color: '#00ffaa', display: 'flex', alignItems: 'center', gap: '5px' }}>
                                        <span>🎉</span> <strong>Omega gesichert!</strong> Du hast diesen Monat genug erwirtschaftet, um dein Omega-Abonnement zu decken.
                                    </div>
                                ) : isNegative ? (
                                    <div className="is-size-7" style={{ color: '#f14668', display: 'flex', alignItems: 'center', gap: '5px' }}>
                                        <span>⚠️</span> <strong>Verlustmonat!</strong> Du bist diesen Monat im Minus.
                                    </div>
                                ) : (
                                    <div className="is-size-7" style={{ display: 'flex', alignItems: 'center', gap: '5px', color: 'var(--theme-text-muted)' }}>
                                        <span>⏳</span> Noch <strong>{formatISK(omegaGoal - currentMonthEarnings)}</strong> benötigt, um das Omega-Ziel zu erreichen.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Top filter panel */}
            <div className="filter-panel-top mb-4">
                <div className="filter-grid-top">
                    <div className="filter-column">
                        <div className="filter-title">Zeitraum</div>
                        <select
                            className="select-input"
                            value={selectedDateRange}
                            onChange={(e) => setSelectedDateRange(e.target.value)}
                        >
                            <option value="today" style={{ background: '#101525' }}>Heute</option>
                            <option value="yesterday" style={{ background: '#101525' }}>Gestern</option>
                            <option value="7" style={{ background: '#101525' }}>7 Tage</option>
                            <option value="30" style={{ background: '#101525' }}>30 Tage</option>
                            <option value="90" style={{ background: '#101525' }}>90 Tage</option>
                        </select>
                    </div>

                    {allTags.length > 0 && (
                        <div className="filter-column">
                            <div className="filter-title">Tag-Filter</div>
                            <select
                                className="select-input"
                                value={selectedTag}
                                onChange={(e) => setSelectedTag(e.target.value)}
                            >
                                <option value="all" style={{ background: '#101525' }}>Alle Tags</option>
                                {allTags.map(tag => (
                                    <option key={tag} value={tag} style={{ background: '#101525' }}>{tag}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="filter-column">
                        <div className="filter-title">Suche Gegenstand</div>
                        <input
                            type="text"
                            className="ledger-input"
                            placeholder="z.B. Fullerite..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>

                    {availableCharacters.length > 0 && (
                        <div className="filter-column filter-column-wide">
                            <div className="filter-title">Charaktere</div>
                            <div className="filter-checkbox-group">
                                {availableCharacters.map(char => (
                                    <label key={char} className="filter-row">
                                        <input
                                            type="checkbox"
                                            checked={selectedCharacters.includes(char)}
                                            onChange={() => toggleCharacterFilter(char)}
                                        />
                                        <span>{char}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="filter-column filter-column-wide">
                        <div className="filter-title">Kategorien</div>
                        <div className="filter-checkbox-group">
                            {Object.entries(CATEGORY_NAMES).map(([cat, name]) => (
                                <label key={cat} className="filter-row">
                                    <input
                                        type="checkbox"
                                        checked={selectedCategories.includes(cat)}
                                        onChange={() => toggleCategoryFilter(cat)}
                                    />
                                    <span className="category-indicator" style={{ backgroundColor: CATEGORY_COLORS[cat] }}></span>
                                    <span>{name}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Ledger list */}
            <div className="day-list">
                {filteredLedger.length === 0 ? (
                    <div className="box has-text-centered p-5">
                        <p className="text-muted">Keine Ertragsdatensätze für die gewählten Filter gefunden.</p>
                    </div>
                ) : (
                    filteredLedger.map(day => {
                        const isExpanded = !!expandedDates[day.date];
                        const dateObj = new Date(day.date);
                        const formattedDate = dateObj.toLocaleDateString('de-DE', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            timeZone: 'UTC'
                        });

                        return (
                            <div key={day.date} className="day-card">
                                <div className="day-header" onClick={() => toggleDateExpand(day.date)}>
                                    <div className="day-title">
                                        <span style={{ fontSize: '0.8rem', color: 'var(--theme-text-muted)' }}>
                                            {isExpanded ? '▼' : '▶'}
                                        </span>
                                        <span className="day-date">{formattedDate}</span>
                                    </div>
                                    <span className="day-total">{formatISK(day.summary.totalValue)}</span>
                                </div>

                                {isExpanded && (
                                    <div className="day-body">
                                        {/* Category breakdown */}
                                        <div className="day-breakdown">
                                            {Object.entries(day.summary.byCategory).map(([cat, val]) => {
                                                if (val === 0) return null;
                                                return (
                                                    <div key={cat} className="breakdown-badge">
                                                        <span className="category-indicator" style={{ backgroundColor: CATEGORY_COLORS[cat] }}></span>
                                                        <span>{CATEGORY_NAMES[cat]}: <strong>{formatISK(val)}</strong></span>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {/* Details Table */}
                                        <div className="item-table-wrapper">
                                            <table className="item-table">
                                                <thead>
                                                    <tr>
                                                        <th style={{ width: '30px' }}></th>
                                                        <th style={{ width: '40px' }}></th>
                                                        <th>Gegenstand / Aktivität</th>
                                                        <th>Kategorie</th>
                                                        <th>Charakter</th>
                                                        <th className="text-right">Menge</th>
                                                        <th className="text-right">Jita-Preis</th>
                                                        <th className="text-right">Gesamtwert</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {day.details.map((item, idx) => {
                                                        const itemKey = item.manualEntryId ? `manual_${item.manualEntryId}` : `${day.date}_${item.typeId}_${idx}`;
                                                        const isExpanded = expandedItemKey === itemKey;

                                                        return (
                                                            <React.Fragment key={idx}>
                                                                <tr
                                                                    onClick={() => !item.isWallet && !item.manualEntryId && setExpandedItemKey(isExpanded ? null : itemKey)}
                                                                    style={{ cursor: (!item.isWallet && !item.manualEntryId) ? 'pointer' : 'default' }}
                                                                    className={(!item.isWallet && !item.manualEntryId) ? 'item-row-hover' : ''}
                                                                >
                                                                    <td className="has-text-centered" style={{ verticalAlign: 'middle', color: '#6a737d', fontSize: '0.75rem' }}>
                                                                        {item.manualEntryId ? (
                                                                            <button 
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    handleDeleteManualEntry(item.manualEntryId!, item.typeName, item.totalValue);
                                                                                }}
                                                                                style={{
                                                                                    background: 'none',
                                                                                    border: 'none',
                                                                                    color: '#ff4444',
                                                                                    cursor: 'pointer',
                                                                                    padding: 0,
                                                                                    fontSize: '0.9rem'
                                                                                }}
                                                                                title="Manuelle Buchung löschen"
                                                                            >
                                                                                🗑️
                                                                            </button>
                                                                        ) : (
                                                                            !item.isWallet ? (isExpanded ? '▼' : '▶') : ''
                                                                        )}
                                                                    </td>
                                                                    <td>
                                                                        {!item.isWallet && !item.manualEntryId && (
                                                                            <div className="item-icon-wrapper">
                                                                                <img
                                                                                    src={imagePaths.types.replace('12345', item.typeId.toString())}
                                                                                    onError={(e) => {
                                                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${item.typeId}/icon`;
                                                                                    }}
                                                                                    alt=""
                                                                                />
                                                                            </div>
                                                                        )}
                                                                        {item.manualEntryId && (
                                                                            <div className="item-icon-wrapper" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '1.1rem', background: 'rgba(255, 255, 255, 0.03)' }}>
                                                                                ✍️
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td style={{ fontWeight: 600 }}>{item.typeName}</td>
                                                                    <td>
                                                                        <span
                                                                            className="badge-cat"
                                                                            style={{
                                                                                backgroundColor: `${CATEGORY_COLORS[item.category]}20`,
                                                                                color: CATEGORY_COLORS[item.category],
                                                                                border: `1px solid ${CATEGORY_COLORS[item.category]}40`
                                                                            }}
                                                                        >
                                                                            {CATEGORY_NAMES[item.category] || item.category}
                                                                        </span>
                                                                    </td>
                                                                    <td>{item.character}</td>
                                                                    <td className="text-right" style={{ fontFamily: 'monospace' }}>
                                                                        {item.manualEntryId ? '-' : formatNumber(item.quantity)}
                                                                    </td>
                                                                    <td className="text-right" style={{ fontFamily: 'monospace' }}>
                                                                        {item.price > 0 && !item.manualEntryId ? formatISK(item.price) : '-'}
                                                                    </td>
                                                                    <td className="text-right" style={{ fontWeight: 700, color: item.totalValue > 0 ? 'var(--theme-text)' : 'inherit', fontFamily: 'monospace' }}>
                                                                        {formatISK(item.totalValue)}
                                                                    </td>
                                                                </tr>
                                                                {isExpanded && !item.isWallet && (
                                                                    <tr>
                                                                        <td colSpan={8} style={{ background: 'rgba(0, 0, 0, 0.25)', borderTop: 'none', borderBottom: '1px solid rgba(255,255,255,0.05)', padding: '12px' }}>
                                                                            <ItemChangesDetails
                                                                                dateStr={day.date}
                                                                                typeId={item.typeId}
                                                                                formatNumber={formatNumber}
                                                                                onDeleteSuccess={() => setRefreshTrigger(prev => prev + 1)}
                                                                            />
                                                                        </td>
                                                                    </tr>
                                                                )}
                                                            </React.Fragment>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>

            {/* Bottom manual entry card */}
            <div className="manual-entry-panel">
                <div className="filter-title" style={{ fontSize: '1rem', marginBottom: '1.25rem', borderBottom: '1px solid var(--theme-card-border)', paddingBottom: '0.5rem' }}>
                    ✍️ Manuelle Buchung
                </div>
                <form onSubmit={handleAddManualEntry}>
                    <div className="manual-form-grid">
                        <div className="manual-form-group">
                            <label htmlFor="manual-date" className="manual-form-label">Datum</label>
                            <input 
                                id="manual-date"
                                name="date"
                                type="date" 
                                className="ledger-input" 
                                value={manualDate} 
                                onChange={(e) => setManualDate(e.target.value)}
                                required 
                            />
                        </div>
                        <div className="manual-form-group">
                            <label htmlFor="manual-character" className="manual-form-label">Charakter</label>
                            <select 
                                id="manual-character"
                                name="characterId"
                                className="select-input" 
                                value={manualCharId} 
                                onChange={(e) => setManualCharId(e.target.value)}
                            >
                                <option value="" style={{ background: '#101525' }}>Keiner / Allgemein</option>
                                {charactersList.map(char => (
                                    <option key={char.id} value={char.id} style={{ background: '#101525' }}>{char.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="manual-form-group">
                            <label htmlFor="manual-category" className="manual-form-label">Kategorie</label>
                            <select 
                                id="manual-category"
                                name="category"
                                className="select-input" 
                                value={manualCategory} 
                                onChange={(e) => setManualCategory(e.target.value)}
                            >
                                {Object.entries(CATEGORY_NAMES).map(([cat, name]) => (
                                    <option key={cat} value={cat} style={{ background: '#101525' }}>{name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="manual-form-group">
                            <label htmlFor="manual-description" className="manual-form-label">Beschreibung</label>
                            <input 
                                id="manual-description"
                                name="description"
                                type="text" 
                                className="ledger-input" 
                                placeholder="z.B. Skill-Injektor..." 
                                value={manualDescription}
                                onChange={(e) => setManualDescription(e.target.value)}
                                required 
                            />
                        </div>
                        <div className="manual-form-group">
                            <label htmlFor="manual-amount" className="manual-form-label">Betrag (ISK)</label>
                            <input 
                                id="manual-amount"
                                name="amount"
                                type="number" 
                                className="ledger-input" 
                                placeholder="Betrag in ISK" 
                                value={manualAmount}
                                onChange={(e) => setManualAmount(e.target.value)}
                                required 
                            />
                        </div>
                        <div className="manual-form-group manual-action-group">
                            <button 
                                type="submit" 
                                className="manual-submit-btn"
                                disabled={manualLoading}
                            >
                                {manualLoading ? 'Speichert...' : 'Eintragen'}
                            </button>
                        </div>
                    </div>
                    {manualError && <div className="manual-error-msg mt-2">{manualError}</div>}
                </form>
            </div>
        </div>
    );
}

interface ChangeEntry {
    id: number;
    characterName: string;
    quantity: number;
    loggedAt: string;
}

interface ItemChangesDetailsProps {
    dateStr: string;
    typeId: number;
    formatNumber: (val: number) => string;
    onDeleteSuccess: () => void;
}

function ItemChangesDetails({ dateStr, typeId, formatNumber, onDeleteSuccess }: ItemChangesDetailsProps) {
    const [changes, setChanges] = React.useState<ChangeEntry[]>([]);
    const [loading, setLoading] = React.useState<boolean>(true);
    const [error, setError] = React.useState<string | null>(null);
    const [deletingId, setDeletingId] = React.useState<number | null>(null);

    const loadChanges = () => {
        setLoading(true);
        const url = `/dashboard/tracking/api/changes?typeId=${typeId}&rangeType=single_date&date=${dateStr}`;

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Laden der Buchungen.');
                return res.json();
            })
            .then((data: ChangeEntry[]) => {
                setChanges(data);
                setLoading(false);
            })
            .catch(err => {
                setError(err.message);
                setLoading(false);
            });
    };

    React.useEffect(() => {
        loadChanges();
    }, [dateStr, typeId]);

    const handleDelete = (id: number, characterName: string, quantity: number, loggedAt: string) => {
        if (!confirm(`Möchtest du die Zuwachs-Buchung über ${formatNumber(quantity)} Einheiten von ${characterName} am ${loggedAt} wirklich löschen?\nDies korrigiert das Ertragsjournal dauerhaft.`)) {
            return;
        }

        setDeletingId(id);
        fetch(`/dashboard/tracking/api/changes/${id}`, {
            method: 'DELETE'
        })
        .then(res => {
            if (!res.ok) throw new Error('Fehler beim Löschen des Eintrags.');
            return res.json();
        })
        .then(() => {
            setChanges(prev => prev.filter(c => c.id !== id));
            setDeletingId(null);
            onDeleteSuccess();
        })
        .catch(err => {
            alert(err.message);
            setDeletingId(null);
        });
    };

    if (loading) {
        return <div className="is-size-7 text-muted">Lade Einzelbuchungen...</div>;
    }

    if (error) {
        return <div className="is-size-7 has-text-danger">{error}</div>;
    }

    if (changes.length === 0) {
        return <div className="is-size-7 text-muted">Keine Einzelbuchungen (Zuwächse) für diesen Tag vorhanden.</div>;
    }

    return (
        <div>
            <h5 className="is-size-7 has-text-weight-bold mb-2" style={{ color: '#fff' }}>Detaillierte Einzelbuchungen (Zuwächse) für diesen Tag:</h5>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', maxHeight: '180px', overflowY: 'auto' }}>
                {changes.map(c => (
                    <div
                        key={c.id}
                        style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            background: 'rgba(255, 255, 255, 0.02)',
                            padding: '6px 10px',
                            borderRadius: '4px',
                            border: '1px solid rgba(255,255,255,0.05)'
                        }}
                    >
                        <span className="is-size-7" style={{ color: '#ccc' }}>
                            <strong style={{ color: '#00ffaa' }}>{c.characterName}</strong>: {c.quantity > 0 ? '+' : ''}{formatNumber(c.quantity)} Stk.
                            <span style={{ color: '#7a7a7a', marginLeft: '8px', fontSize: '0.7rem' }}>({c.loggedAt})</span>
                        </span>
                        <button
                            className="button is-danger is-small p-1"
                            style={{ height: '22px', fontSize: '0.7rem', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                            onClick={() => handleDelete(c.id, c.characterName, c.quantity, c.loggedAt)}
                            disabled={deletingId === c.id}
                        >
                            {deletingId === c.id ? '...' : '❌ Löschen'}
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}
