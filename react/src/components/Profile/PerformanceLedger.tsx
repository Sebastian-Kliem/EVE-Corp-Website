import React, { useState, useEffect, useMemo } from 'react';

interface CharacterListEntry {
    id: number;
    name: string;
    accountGroup: string;
    accountName: string;
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

export default function PerformanceLedger({ charactersList, apiDataUrl, imagePaths }: PerformanceLedgerProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [ledgerData, setLedgerData] = useState<Record<string, DailyPerformance>>({});
    
    // Filters state
    const [selectedDateRange, setSelectedDateRange] = useState<number>(30); // 7, 30, 90 days
    const [selectedCharacters, setSelectedCharacters] = useState<string[]>([]);
    const [selectedCategories, setSelectedCategories] = useState<string[]>(['gas', 'ore_ice', 'blue_loot', 'abyss_loot', 'hacking_salvage', 'wallet_rewards', 'other']);
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [expandedDates, setExpandedDates] = useState<Record<string, boolean>>({});

    // Ertragsjournal refresh & detail states
    const [refreshTrigger, setRefreshTrigger] = useState<number>(0);
    const [expandedItemKey, setExpandedItemKey] = useState<string | null>(null);

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
                chars.add(d.character);
            });
        });
        return Array.from(chars).sort();
    }, [ledgerData]);

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
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - selectedDateRange);
        const cutoffStr = cutoffDate.toISOString().substring(0, 10);

        const result: DailyPerformance[] = [];

        Object.entries(ledgerData).forEach(([dateStr, day]) => {
            // Apply Date Range filter
            if (selectedDateRange > 0 && dateStr < cutoffStr) {
                return;
            }

            // Filter details
            const filteredDetails = day.details.filter(item => {
                // Character check
                if (!selectedCharacters.includes(item.character)) {
                    return false;
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
    }, [ledgerData, selectedDateRange, selectedCharacters, selectedCategories, searchTerm]);

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
        <div className="perf-ledger-container">
            <style>{`
                .perf-ledger-container {
                    color: var(--theme-text);
                    font-family: inherit;
                }
                .perf-grid {
                    display: grid;
                    grid-template-columns: 300px 1fr;
                    gap: 1.5rem;
                }
                @media (max-width: 1024px) {
                    .perf-grid {
                        grid-template-columns: 1fr;
                    }
                }
                .filter-panel {
                    background: rgba(13, 19, 32, 0.7);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    padding: 1.25rem;
                    align-self: start;
                }
                .filter-section {
                    margin-bottom: 1.5rem;
                }
                .filter-title {
                    font-size: 0.85rem;
                    text-transform: uppercase;
                    color: var(--theme-text-muted);
                    font-weight: 700;
                    margin-bottom: 0.75rem;
                    letter-spacing: 0.05em;
                }
                .filter-row {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    margin-bottom: 0.5rem;
                    font-size: 0.9rem;
                    cursor: pointer;
                }
                .filter-row input {
                    cursor: pointer;
                }
                .btn-group {
                    display: flex;
                    border-radius: 4px;
                    overflow: hidden;
                    border: 1px solid var(--theme-card-border);
                }
                .btn-group-btn {
                    flex: 1;
                    background: rgba(20, 27, 43, 0.6);
                    border: none;
                    color: var(--theme-text);
                    padding: 0.5rem;
                    font-size: 0.85rem;
                    cursor: pointer;
                    transition: background 0.2s;
                    text-align: center;
                }
                .btn-group-btn:not(:last-child) {
                    border-right: 1px solid var(--theme-card-border);
                }
                .btn-group-btn.is-active {
                    background: var(--theme-primary);
                    color: #000;
                    font-weight: 600;
                }
                .search-input {
                    width: 100%;
                    background: rgba(10, 15, 25, 0.9);
                    border: 1px solid var(--theme-card-border);
                    color: var(--theme-text);
                    padding: 0.5rem 0.75rem;
                    border-radius: 4px;
                    font-size: 0.9rem;
                }
                .search-input:focus {
                    border-color: var(--theme-primary);
                    outline: none;
                }
                .stats-panel {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 1rem;
                    margin-bottom: 1.5rem;
                }
                .stat-box {
                    background: rgba(13, 19, 32, 0.6);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    padding: 1rem;
                    text-align: left;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                }
                .stat-label {
                    font-size: 0.8rem;
                    color: var(--theme-text-muted);
                    margin-bottom: 0.25rem;
                }
                .stat-val {
                    font-size: 1.15rem;
                    font-weight: 700;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .category-indicator {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    display: inline-block;
                    margin-right: 0.5rem;
                }
                .day-list {
                    display: flex;
                    flex-direction: column;
                    gap: 1rem;
                }
                .day-card {
                    background: rgba(13, 19, 32, 0.5);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    overflow: hidden;
                    transition: border-color 0.2s;
                }
                .day-card:hover {
                    border-color: rgba(0, 240, 255, 0.4);
                }
                .day-header {
                    padding: 1rem 1.25rem;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    cursor: pointer;
                    background: rgba(20, 27, 43, 0.4);
                    user-select: none;
                }
                .day-title {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                }
                .day-date {
                    font-size: 1.1rem;
                    font-weight: 700;
                }
                .day-total {
                    font-size: 1.1rem;
                    font-weight: 700;
                    color: var(--theme-primary);
                }
                .day-body {
                    padding: 1.25rem;
                    border-top: 1px solid var(--theme-card-border);
                    background: rgba(10, 15, 25, 0.2);
                }
                .day-breakdown {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.75rem;
                    margin-bottom: 1.25rem;
                    padding-bottom: 1rem;
                    border-bottom: 1px dashed var(--theme-card-border);
                }
                .breakdown-badge {
                    background: rgba(20, 27, 43, 0.8);
                    border: 1px solid var(--theme-card-border);
                    padding: 0.35rem 0.65rem;
                    border-radius: 4px;
                    font-size: 0.8rem;
                    display: flex;
                    align-items: center;
                }
                .item-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 0.9rem;
                }
                .item-table th {
                    text-align: left;
                    color: var(--theme-text-muted);
                    font-weight: 600;
                    padding: 0.5rem 0.75rem;
                    border-bottom: 1px solid var(--theme-card-border);
                }
                .item-table td {
                    padding: 0.65rem 0.75rem;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                    vertical-align: middle;
                }
                .item-icon-wrapper {
                    width: 32px;
                    height: 32px;
                    border-radius: 4px;
                    overflow: hidden;
                    background: #111;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border: 1px solid rgba(255, 255, 255, 0.1);
                }
                .item-icon-wrapper img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .badge-cat {
                    font-size: 0.75rem;
                    padding: 0.15rem 0.4rem;
                    border-radius: 3px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .text-right {
                    text-align: right;
                }
                .item-row-hover:hover {
                    background: rgba(255, 255, 255, 0.04) !important;
                }
            `}</style>

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

            <div className="perf-grid">
                {/* Left filter panel */}
                <div className="filter-panel">
                    <div className="filter-section">
                        <div className="filter-title">Zeitraum</div>
                        <div className="btn-group">
                            <button 
                                className={`btn-group-btn ${selectedDateRange === 7 ? 'is-active' : ''}`}
                                onClick={() => setSelectedDateRange(7)}
                            >
                                7 Tage
                            </button>
                            <button 
                                className={`btn-group-btn ${selectedDateRange === 30 ? 'is-active' : ''}`}
                                onClick={() => setSelectedDateRange(30)}
                            >
                                30 Tage
                            </button>
                            <button 
                                className={`btn-group-btn ${selectedDateRange === 90 ? 'is-active' : ''}`}
                                onClick={() => setSelectedDateRange(90)}
                            >
                                90 Tage
                            </button>
                        </div>
                    </div>

                    <div className="filter-section">
                        <div className="filter-title">Suche Gegenstand</div>
                        <input 
                            type="text" 
                            className="search-input" 
                            placeholder="z.B. Fullerite..." 
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>

                    {availableCharacters.length > 0 && (
                        <div className="filter-section">
                            <div className="filter-title">Charaktere</div>
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
                    )}

                    <div className="filter-section">
                        <div className="filter-title">Kategorien</div>
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

                {/* Right ledger list */}
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
                                day: 'numeric'
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
                                             <div style={{ overflowX: 'auto' }}>
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
                                                             const itemKey = `${day.date}_${item.typeId}`;
                                                             const isExpanded = expandedItemKey === itemKey;
 
                                                             return (
                                                                 <React.Fragment key={idx}>
                                                                     <tr 
                                                                         onClick={() => !item.isWallet && setExpandedItemKey(isExpanded ? null : itemKey)}
                                                                         style={{ cursor: !item.isWallet ? 'pointer' : 'default' }}
                                                                         className={!item.isWallet ? 'item-row-hover' : ''}
                                                                     >
                                                                         <td className="has-text-centered" style={{ verticalAlign: 'middle', color: '#6a737d', fontSize: '0.75rem' }}>
                                                                             {!item.isWallet ? (isExpanded ? '▼' : '▶') : ''}
                                                                         </td>
                                                                         <td>
                                                                             {!item.isWallet && (
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
                                                                             {formatNumber(item.quantity)}
                                                                         </td>
                                                                         <td className="text-right" style={{ fontFamily: 'monospace' }}>
                                                                             {item.price > 0 ? formatISK(item.price) : '-'}
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
