import React, { useState, useEffect, useMemo } from 'react';
import { cleanItemSearch } from '../../utils/itemSearch';
import { formatThousands, parseThousands } from '../../utils/numberFormat';

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
        ship_losses: number;
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
    ship_losses: 'Schiffsverluste',
    other: 'Sonstiges'
};

const CATEGORY_COLORS: Record<string, string> = {
    gas: '#00f0ff',
    ore_ice: '#ffaa00',
    blue_loot: '#0066ff',
    abyss_loot: '#ff0055',
    hacking_salvage: '#a800ff',
    wallet_rewards: '#00ff88',
    ship_losses: '#f14668',
    other: '#888888'
};

const OMEGA_COST_ISK = 2500000000; // 2.5 Billion ISK

export default function PerformanceLedger({ charactersList, apiDataUrl, imagePaths, omegaAccountCount }: PerformanceLedgerProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [ledgerData, setLedgerData] = useState<Record<string, DailyPerformance>>({});
    const [exclusions, setExclusions] = useState<any[]>([]);

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
    const [selectedCategories, setSelectedCategories] = useState<string[]>(['gas', 'ore_ice', 'blue_loot', 'abyss_loot', 'hacking_salvage', 'wallet_rewards', 'ship_losses', 'other']);
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
        const amt = parseThousands(manualAmount);
        if (isNaN(amt) || amt === 0) {
            setManualError('Betrag darf nicht 0 sein.');
            return;
        }
        setManualLoading(true);
        setManualError(null);

        fetch('/personal/performance/manual', {
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

        fetch(`/personal/performance/manual/${id}`, {
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

    const handleExcludeEntry = (date: string, item: PerformanceDetail) => {
        if (!confirm(`Möchtest du den Eintrag "${item.typeName}" am ${date} wirklich ausblenden?`)) {
            return;
        }

        fetch('/personal/performance/exclude', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                date: date,
                category: item.category,
                typeName: item.typeName,
                characterName: item.character,
                amount: item.totalValue
            })
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(data => {
                    throw new Error(data.error || 'Fehler beim Ausblenden.');
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

    const handleRemoveExclusion = (id: number) => {
        fetch(`/personal/performance/exclude/${id}`, {
            method: 'DELETE'
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(data => {
                    throw new Error(data.error || 'Fehler beim Einblenden.');
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
            .then((data: { ledger: Record<string, DailyPerformance>; exclusions: any[] }) => {
                setLedgerData(data.ledger || {});
                setExclusions(data.exclusions || []);

                // Expand the first date by default
                const dates = Object.keys(data.ledger || {});
                if (dates.length > 0) {
                    setExpandedDates({ [dates[0]]: true });
                }

                // Collect all characters from data to select them by default
                const chars = new Set<string>();
                Object.values(data.ledger || {}).forEach(day => {
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
                const cleanSearch = cleanItemSearch(searchTerm).toLowerCase().trim();
                return !(cleanSearch && !item.typeName.toLowerCase().includes(cleanSearch));

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
                    ship_losses: 0.0,
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

    // 30-day average earnings computed over the last 30 calendar days (or less if the ledger is newer than 30 days)
    const average30Days = useMemo(() => {
        const today = new Date();
        const formatDateStr = (d: Date) => {
            const year = d.getUTCFullYear();
            const month = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        const cutoffDate = new Date();
        cutoffDate.setUTCDate(cutoffDate.getUTCDate() - 30);
        const cutoffStr = formatDateStr(cutoffDate);

        // Find the oldest date in the entire ledgerData to calculate the start point of recordings
        const allDates = Object.keys(ledgerData).sort();
        let daysToDivide = 30;

        if (allDates.length > 0) {
            const oldestDateStr = allDates[0];
            const oldestDate = new Date(oldestDateStr);
            const todayReset = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
            const oldestReset = new Date(Date.UTC(oldestDate.getUTCFullYear(), oldestDate.getUTCMonth(), oldestDate.getUTCDate()));
            
            const diffTime = Math.abs(todayReset.getTime() - oldestReset.getTime());
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // Include today
            
            daysToDivide = Math.min(30, diffDays);
        }

        // Ensure we divide by at least 1
        daysToDivide = Math.max(1, daysToDivide);

        let sum = 0.0;

        Object.entries(ledgerData).forEach(([dateStr, day]) => {
            if (dateStr < cutoffStr) {
                return;
            }

            const filteredDetails = day.details.filter(item => {
                if (!selectedCharacters.includes(item.character)) {
                    return false;
                }
                if (selectedTag !== 'all') {
                    const charObj = charactersList.find(c => c.name === item.character);
                    if (!charObj || !charObj.tags || !charObj.tags.includes(selectedTag)) {
                        return false;
                    }
                }
                if (!selectedCategories.includes(item.category)) {
                    return false;
                }
                const cleanSearch = cleanItemSearch(searchTerm).toLowerCase().trim();
                return !(cleanSearch && !item.typeName.toLowerCase().includes(cleanSearch));
            });

            filteredDetails.forEach(item => {
                sum += item.totalValue;
            });
        });

        return sum / daysToDivide;
    }, [ledgerData, selectedCharacters, selectedCategories, searchTerm, selectedTag, charactersList]);

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
            ship_losses: 0.0,
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
            <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6 text-center py-12">
                <span className="inline-block w-8 h-8 border-3 border-eve-primary rounded-full border-t-transparent animate-spin"></span>
                <p className="mt-3 text-eve-muted">Performance-Daten werden berechnet...</p>
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
        <div className="perf-ledger-container">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div className="bg-[#0d132099] border border-eve-border rounded-lg p-4 flex flex-col justify-between" style={{ borderLeft: '4px solid var(--theme-primary)' }}>
                    <span className="text-xs text-eve-muted mb-1">Gesamtertrag (Netto)</span>
                    <span className="text-lg font-bold truncate" style={{ color: 'var(--theme-primary)' }}>{formatISK(totalEarnings.total)}</span>
                </div>
                <div className="bg-[#0d132099] border border-eve-border rounded-lg p-4 flex flex-col justify-between" style={{ borderLeft: '4px solid #3ab0ff' }}>
                    <span className="text-xs text-eve-muted mb-1">Ø Tagesgewinn (30 Tage)</span>
                    <span className="text-lg font-bold truncate" style={{ color: '#3ab0ff' }}>{formatISK(average30Days)}</span>
                </div>
                {Object.entries(totalEarnings.byCat).map(([cat, val]) => {
                    if (val === 0) return null;
                    return (
                        <div key={cat} className="bg-[#0d132099] border border-eve-border rounded-lg p-4 flex flex-col justify-between" style={{ borderLeft: `4px solid ${CATEGORY_COLORS[cat]}` }}>
                            <span className="text-xs text-eve-muted mb-1">{CATEGORY_NAMES[cat]}</span>
                            <span className="text-lg font-bold truncate">{formatISK(val)}</span>
                        </div>
                    );
                })}
            </div>

            {/* Omega Target Widget */}
            {(() => {
                const percent = Math.min(100, Math.max(0, (currentMonthEarnings / omegaGoal) * 100));
                const isNegative = currentMonthEarnings < 0;

                return (
                    <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg mb-6">
                        <div className="flex justify-between items-center flex-wrap gap-4">
                            <div className="flex-1 min-w-[300px]">
                                <h3 className="text-sm font-semibold mb-2 text-eve-primary flex items-center gap-2 m-0">
                                    🎯 Omega-Ziel Tracker ({currentMonthName})
                                </h3>
                                <p className="text-xs text-eve-muted mb-0">
                                    {omegaAccountCount > 0 ? (
                                        <span>Fortschritt für deine <strong>{omegaAccountCount} Omega-Accounts</strong> (Ziel: <strong>{formatISK(omegaGoal)}</strong>).</span>
                                    ) : (
                                        <span>Vergleiche deine Erträge dieses Monats mit den Kosten für einen Omega-Account (<strong>{formatISK(OMEGA_COST_ISK)}</strong>).</span>
                                    )}
                                </p>
                            </div>

                            <div className="flex-1 min-w-[300px] flex flex-col gap-1">
                                <div className="flex justify-between items-center">
                                    <span className="text-xs text-[#ccc]">
                                        Erwirtschaftet: <strong className={isNegative ? 'text-rose-400' : 'text-white'}>{formatISK(currentMonthEarnings)}</strong>
                                    </span>
                                    <span className={`text-xs font-bold ${isNegative ? 'text-rose-400' : percent >= 100 ? 'text-emerald-400' : 'text-eve-primary'}`}>
                                        {isNegative ? '0%' : `${percent.toFixed(1)}%`}
                                    </span>
                                </div>

                                {/* Progress Bar Container */}
                                <div className="w-full h-3.5 bg-black/40 rounded-full overflow-hidden border border-eve-border relative">
                                    <div 
                                        className="h-full rounded-full transition-all duration-500"
                                        style={{
                                            width: `${isNegative ? 0 : percent}%`,
                                            background: percent >= 100
                                                ? 'linear-gradient(90deg, #00b37a 0%, #00ffaa 100%)'
                                                : 'linear-gradient(90deg, #0284c7 0%, var(--theme-primary) 100%)',
                                            boxShadow: percent >= 100 ? '0 0 8px rgba(0, 255, 170, 0.4)' : '0 0 8px rgba(0, 240, 255, 0.3)'
                                        }}
                                    ></div>
                                </div>
                            </div>

                            <div className="w-full border-t border-white/5 pt-2.5 mt-1">
                                {percent >= 100 ? (
                                    <div className="text-xs text-emerald-400 flex items-center gap-1.5">
                                        <span>🎉</span> <strong>Omega gesichert!</strong> Du hast diesen Monat genug erwirtschaftet, um dein Omega-Abonnement zu decken.
                                    </div>
                                ) : isNegative ? (
                                    <div className="text-xs text-rose-400 flex items-center gap-1.5">
                                        <span>⚠️</span> <strong>Verlustmonat!</strong> Du bist diesen Monat im Minus.
                                    </div>
                                ) : (
                                    <div className="text-xs flex items-center gap-1.5 text-eve-muted">
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
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div className="flex flex-col gap-2">
                        <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider">Zeitraum</div>
                        <select
                            className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm cursor-pointer focus:border-eve-primary focus:outline-none"
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
                        <div className="flex flex-col gap-2">
                            <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider">Tag-Filter</div>
                            <select
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm cursor-pointer focus:border-eve-primary focus:outline-none"
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

                    <div className="flex flex-col gap-2">
                        <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider">Suche Gegenstand</div>
                        <input
                            type="text"
                            className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm focus:border-eve-primary focus:outline-none"
                            placeholder="z.B. Fullerite..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(cleanItemSearch(e.target.value))}
                        />
                    </div>

                    {availableCharacters.length > 0 && (
                        <div className="filter-column filter-column-wide">
                            <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider">Charaktere</div>
                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {availableCharacters.map(char => (
                                    <label key={char} className="flex items-center gap-2 mb-2 text-sm cursor-pointer select-none text-eve-text hover:text-white">
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
                        <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider">Kategorien</div>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            {Object.entries(CATEGORY_NAMES).map(([cat, name]) => (
                                <label key={cat} className="flex items-center gap-2 mb-2 text-sm cursor-pointer select-none text-eve-text hover:text-white">
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
            <div className="flex flex-col gap-4">
                {filteredLedger.length === 0 ? (
                    <div className="box has-text-centered p-5">
                        <p className="text-muted">Keine Ertragsdatensätze für die gewählten Filter gefunden.</p>
                    </div>
                ) : (
                    filteredLedger.map(day => {
                        const isExpanded = expandedDates[day.date];
                        const dateObj = new Date(day.date);
                        const formattedDate = dateObj.toLocaleDateString('de-DE', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            timeZone: 'UTC'
                        });

                        return (
                            <div key={day.date} className="bg-[#0d132080] border border-eve-border rounded-lg overflow-hidden transition-all duration-200 hover:border-eve-primary/40">
                                <div className="p-4 flex justify-between items-center cursor-pointer bg-[#141b2b66] select-none" onClick={() => toggleDateExpand(day.date)}>
                                    <div className="flex items-center gap-4">
                                        <span style={{ fontSize: '0.8rem', color: 'var(--theme-text-muted)' }}>
                                            {isExpanded ? '▼' : '▶'}
                                        </span>
                                        <span className="text-lg font-bold text-white">{formattedDate}</span>
                                    </div>
                                    <span className="text-lg font-bold text-eve-primary">{formatISK(day.summary.totalValue)}</span>
                                </div>

                                {isExpanded && (
                                    <div className="p-5 border-t border-eve-border bg-[#0a0f1933]">
                                        {/* Category breakdown */}
                                        <div className="flex flex-wrap gap-3 mb-5 pb-4 border-b border-dashed border-eve-border">
                                            {Object.entries(day.summary.byCategory).map(([cat, val]) => {
                                                if (val === 0) return null;
                                                return (
                                                    <div key={cat} className="bg-[#141b2bcc] border border-eve-border py-1.5 px-2.5 rounded text-xs flex items-center text-eve-muted">
                                                        <span className="category-indicator" style={{ backgroundColor: CATEGORY_COLORS[cat] }}></span>
                                                        <span>{CATEGORY_NAMES[cat]}: <strong>{formatISK(val)}</strong></span>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {/* Details Table */}
                                        <div className="w-full overflow-x-auto mt-2 rounded">
                                            <table className="w-full border-collapse min-w-[750px]">
                                                <thead>
                                                    <tr className="border-b border-eve-border">
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-left" style={{ width: '45px' }}></th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-left" style={{ width: '45px' }}></th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-left">Gegenstand / Aktivität</th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-left">Kategorie</th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-left">Charakter</th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-right">Menge</th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-right">Jita-Preis</th>
                                                        <th className="p-3 text-xs font-semibold uppercase tracking-wider text-eve-muted text-right">Gesamtwert</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {day.details.map((item, idx) => {
                                                        const itemKey = item.manualEntryId ? `manual_${item.manualEntryId}` : `${day.date}_${item.typeId}_${idx}`;
                                                        const isExpanded = expandedItemKey === itemKey;

                                                        return (
                                                            <React.Fragment key={idx}>
                                                                <tr
                                                                    onClick={() => !item.isWallet && !item.manualEntryId && setExpandedItemKey(isExpanded ? null : itemKey)}
                                                                    className={`group transition-colors duration-150 ${(!item.isWallet && !item.manualEntryId) ? 'hover:bg-white/5 cursor-pointer' : ''}`}
                                                                >
                                                                    <td className="p-3 text-center text-xs text-eve-muted align-middle">
                                                                        {item.manualEntryId ? (
                                                                            <button
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    handleDeleteManualEntry(item.manualEntryId!, item.typeName, item.totalValue);
                                                                                }}
                                                                                className="text-red-700/80 hover:text-red-500 hover:scale-110 transition-all duration-150 bg-transparent border-none p-0 text-sm cursor-pointer inline-flex items-center justify-center"
                                                                                title="Manuelle Buchung löschen"
                                                                            >
                                                                                🗑️
                                                                            </button>
                                                                        ) : (
                                                                            <div className="flex items-center gap-2 justify-center">
                                                                                {!item.isWallet && (
                                                                                    <span className={`inline-block text-[10px] text-eve-muted transition-transform duration-200 ease-out select-none ${isExpanded ? 'rotate-90 text-eve-primary' : ''}`}>▶</span>
                                                                                )}
                                                                                <button
                                                                                    onClick={(e) => {
                                                                                        e.stopPropagation();
                                                                                        handleExcludeEntry(day.date, item);
                                                                                    }}
                                                                                    className="text-eve-muted hover:text-amber-600 hover:scale-110 transition-all duration-150 bg-transparent border-none p-0 text-sm cursor-pointer inline-flex items-center justify-center"
                                                                                    title="Eintrag ausblenden (wird von der Berechnung abgezogen)"
                                                                                >
                                                                                    🗑️
                                                                                </button>
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td className="p-3 align-middle">
                                                                        {!item.isWallet && !item.manualEntryId && (
                                                                            <div className="w-8 h-8 rounded overflow-hidden bg-black/40 flex items-center justify-center border border-white/10 transition-all duration-150 group-hover:border-eve-primary/40 group-hover:scale-105 group-hover:shadow-[0_0_8px_rgba(0,240,255,0.25)]">
                                                                                <img
                                                                                    src={imagePaths.types.replace('12345', item.typeId.toString())}
                                                                                    onError={(e) => {
                                                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${item.typeId}/icon`;
                                                                                    }}
                                                                                    alt=""
                                                                                    className="w-full h-full object-cover"
                                                                                />
                                                                            </div>
                                                                        )}
                                                                        {item.manualEntryId && (
                                                                            <div className="w-8 h-8 rounded overflow-hidden bg-black/40 flex items-center justify-center border border-white/10 text-[1.1rem] bg-white/[0.03] transition-all duration-150 group-hover:border-eve-primary/40 group-hover:scale-105 group-hover:shadow-[0_0_8px_rgba(0,240,255,0.25)]">
                                                                                ✍️
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td className="p-3 font-semibold align-middle text-eve-text text-sm">{item.typeName}</td>
                                                                    <td className="p-3 align-middle text-sm">
                                                                        <span
                                                                            className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase whitespace-nowrap"
                                                                            style={{
                                                                                backgroundColor: `${CATEGORY_COLORS[item.category]}20`,
                                                                                color: CATEGORY_COLORS[item.category],
                                                                                border: `1px solid ${CATEGORY_COLORS[item.category]}40`
                                                                            }}
                                                                        >
                                                                            {CATEGORY_NAMES[item.category] || item.category}
                                                                        </span>
                                                                    </td>
                                                                    <td className="p-3 align-middle text-eve-text text-sm">{item.character}</td>
                                                                    <td className="p-3 align-middle text-right font-mono text-sm text-eve-text">
                                                                        {item.manualEntryId ? '-' : formatNumber(item.quantity)}
                                                                    </td>
                                                                    <td className="p-3 align-middle text-right font-mono text-sm text-eve-text">
                                                                        {item.price > 0 && !item.manualEntryId ? formatISK(item.price) : '-'}
                                                                    </td>
                                                                    <td className="p-3 align-middle text-right font-mono font-bold text-sm text-eve-primary" style={{ color: item.totalValue > 0 ? 'var(--color-eve-primary)' : 'inherit' }}>
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
            <div className="bg-[#0d1320b3] border border-eve-border rounded-lg p-5 mt-8">
                <div className="text-xs uppercase text-eve-muted font-bold mb-3 tracking-wider" style={{ fontSize: '1rem', marginBottom: '1.25rem', borderBottom: '1px solid var(--theme-card-border)', paddingBottom: '0.5rem' }}>
                    ✍️ Manuelle Buchung
                </div>
                <form onSubmit={handleAddManualEntry}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 items-end">
                        <div className="m-0">
                            <label htmlFor="manual-date" className="block text-xs text-eve-muted mb-1 font-semibold">Datum</label>
                            <input
                                id="manual-date"
                                name="date"
                                type="date"
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm focus:border-eve-primary focus:outline-none"
                                value={manualDate}
                                onChange={(e) => setManualDate(e.target.value)}
                                required
                            />
                        </div>
                        <div className="m-0">
                            <label htmlFor="manual-character" className="block text-xs text-eve-muted mb-1 font-semibold">Charakter</label>
                            <select
                                id="manual-character"
                                name="characterId"
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm cursor-pointer focus:border-eve-primary focus:outline-none"
                                value={manualCharId}
                                onChange={(e) => setManualCharId(e.target.value)}
                            >
                                <option value="" style={{ background: '#101525' }}>Keiner / Allgemein</option>
                                {charactersList.map(char => (
                                    <option key={char.id} value={char.id} style={{ background: '#101525' }}>{char.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="m-0">
                            <label htmlFor="manual-category" className="block text-xs text-eve-muted mb-1 font-semibold">Kategorie</label>
                            <select
                                id="manual-category"
                                name="category"
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm cursor-pointer focus:border-eve-primary focus:outline-none"
                                value={manualCategory}
                                onChange={(e) => setManualCategory(e.target.value)}
                            >
                                {Object.entries(CATEGORY_NAMES).map(([cat, name]) => (
                                    <option key={cat} value={cat} style={{ background: '#101525' }}>{name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="m-0">
                            <label htmlFor="manual-description" className="block text-xs text-eve-muted mb-1 font-semibold">Beschreibung</label>
                            <input
                                id="manual-description"
                                name="description"
                                type="text"
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm focus:border-eve-primary focus:outline-none"
                                placeholder="z.B. Skill-Injektor..."
                                value={manualDescription}
                                onChange={(e) => setManualDescription(e.target.value)}
                                required
                            />
                        </div>
                        <div className="m-0">
                            <label htmlFor="manual-amount" className="block text-xs text-eve-muted mb-1 font-semibold">Betrag (ISK)</label>
                            <input
                                id="manual-amount"
                                name="amount"
                                type="text"
                                inputMode="numeric"
                                className="w-full bg-[#0a0f19e6] border border-eve-border text-eve-text p-2 rounded text-sm focus:border-eve-primary focus:outline-none"
                                placeholder="z. B. 100.000.000"
                                value={manualAmount}
                                onChange={(e) => setManualAmount(formatThousands(e.target.value))}
                                required
                            />
                        </div>
                        <div className="manual-form-group manual-action-group">
                            <button
                                type="submit"
                                className="w-full bg-eve-primary text-black border-0 p-2 rounded font-semibold cursor-pointer transition-opacity duration-200 text-sm h-[38px] flex items-center justify-center hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                                disabled={manualLoading}
                            >
                                {manualLoading ? 'Speichert...' : 'Eintragen'}
                            </button>
                        </div>
                    </div>
                    {manualError && <div className="manual-error-msg mt-2">{manualError}</div>}
                </form>
            </div>

            {/* Hidden Entries Exclusions List */}
            {exclusions.length > 0 && (
                <details className="box mt-5" style={{ background: 'rgba(255, 68, 68, 0.02)', borderColor: 'rgba(255, 68, 68, 0.12)' }}>
                    <summary className="text-xs uppercase text-eve-muted font-bold tracking-wider" style={{ color: '#ff6b8b', fontSize: '0.85rem', padding: '1rem 1.25rem' }}>
                        <span>👁️ Ausgeblendete automatische Buchungen ({exclusions.length})</span>
                    </summary>
                    <div className="details-content" style={{ borderTop: '1px solid rgba(255, 68, 68, 0.12)', padding: '1.25rem' }}>
                        <p className="text-xs text-eve-muted mb-3">
                            Diese automatisch erfassten Buchungen wurden ausgeblendet und werden nicht mehr in die Ertragsberechnungen einbezogen.
                        </p>
                        <div className="flex flex-col gap-1.5 max-h-[200px] overflow-y-auto">
                            {exclusions.map(ex => (
                                <div
                                    key={ex.id}
                                    className="flex justify-between items-center bg-black/20 p-2.5 rounded border border-white/5"
                                >
                                    <span className="text-xs text-[#ccc]">
                                        <strong className="text-[#ff6b8b]">{ex.characterName}</strong> ({new Date(ex.date).toLocaleDateString('de-DE')}): {ex.typeName} — <strong style={{ color: ex.amount < 0 ? '#ff4444' : '#00ffaa' }}>{formatISK(ex.amount)}</strong>
                                    </span>
                                    <button
                                        className="inline-flex items-center justify-center border border-transparent rounded bg-eve-primary hover:brightness-110 text-[#060911] font-semibold text-xs px-2.5 py-1 shadow transition-all duration-200 cursor-pointer h-[22px]"
                                        onClick={() => handleRemoveExclusion(ex.id)}
                                    >
                                        🔄 Wieder einblenden
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                </details>
            )}
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
        const url = `/corp/tracking/api/changes?typeId=${typeId}&rangeType=single_date&date=${dateStr}`;

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
        fetch(`/corp/tracking/api/changes/${id}`, {
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
        return <div className="text-xs text-eve-muted">Lade Einzelbuchungen...</div>;
    }

    if (error) {
        return <div className="text-xs text-rose-400">{error}</div>;
    }

    if (changes.length === 0) {
        return <div className="text-xs text-eve-muted">Keine Einzelbuchungen (Zuwächse) für diesen Tag vorhanden.</div>;
    }

    return (
        <div>
            <h5 className="text-xs font-bold mb-2" style={{ color: '#fff' }}>Detaillierte Einzelbuchungen (Zuwächse) für diesen Tag:</h5>
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
                        <span className="text-xs" style={{ color: '#ccc' }}>
                            <strong style={{ color: '#00ffaa' }}>{c.characterName}</strong>: {c.quantity > 0 ? '+' : ''}{formatNumber(c.quantity)} Stk.
                            <span style={{ color: '#7a7a7a', marginLeft: '8px', fontSize: '0.7rem' }}>({c.loggedAt})</span>
                        </span>
                        <button
                            className="inline-flex items-center justify-center border border-red-950/40 rounded bg-red-900/40 hover:bg-red-800/60 text-red-200 font-semibold text-xs px-2 py-1 transition-all duration-300 cursor-pointer"
                            style={{ height: '22px', fontSize: '0.7rem' }}
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
