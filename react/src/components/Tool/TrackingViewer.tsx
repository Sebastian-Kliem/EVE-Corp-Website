import React, { useState, useEffect } from 'react';

interface TrackingListItem {
    id: number;
    typeId: number;
    typeName: string;
}

interface TrackingList {
    id: number;
    name: string;
    description: string | null;
    isGlobal: boolean;
    isTemplate: boolean;
    items: TrackingListItem[];
}

interface CharacterSeries {
    name: string;
    data: number[];
}

interface ItemBreakdown {
    typeId: number;
    typeName: string;
    quantity: number;
    value: number;
}

interface AnalyticsData {
    listName: string;
    dates: string[];
    characters: CharacterSeries[];
    itemBreakdown: ItemBreakdown[];
    totalValue: number;
}

interface TrackingViewerProps {
    jwtToken: string;
}

export default function TrackingViewer(_props: TrackingViewerProps) {
    const [lists, setLists] = useState<TrackingList[]>([]);
    const [loadingLists, setLoadingLists] = useState<boolean>(true);
    const [listError, setListError] = useState<string | null>(null);
    const [expandedListId, setExpandedListId] = useState<number | null>(null);

    useEffect(() => {
        setLoadingLists(true);
        fetch('/corp/tracking/api/lists')
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Laden der Listen.');
                return res.json();
            })
            .then((data: TrackingList[]) => {
                setLists(data);
                if (data.length > 0) {
                    setExpandedListId(data[0].id);
                }
                setLoadingLists(false);
            })
            .catch(err => {
                setListError(err.message);
                setLoadingLists(false);
            });
    }, []);

    const toggleExpand = (listId: number) => {
        if (expandedListId === listId) {
            setExpandedListId(null);
        } else {
            setExpandedListId(listId);
        }
    };

    return (
        <div style={{ maxWidth: '900px', margin: '0 auto' }}>
            <style>{`
                .collapsible-card {
                    background: rgba(20, 27, 43, 0.4);
                    border: 1px solid var(--theme-card-border, #333);
                    border-radius: 8px;
                    margin-bottom: 1rem;
                    overflow: hidden;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }
                .collapsible-card:hover {
                    border-color: var(--theme-primary, #00f0ff);
                }
                .collapsible-header {
                    padding: 1.25rem;
                    cursor: pointer;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    background: rgba(0, 0, 0, 0.15);
                    user-select: none;
                }
                .collapsible-header-title {
                    color: #fff;
                    font-weight: bold;
                    font-size: 1.1rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .collapsible-body {
                    padding: 1.5rem;
                    border-top: 1px solid rgba(255,255,255,0.05);
                    background: rgba(0, 0, 0, 0.05);
                }
                .tag-template-view {
                    background: rgba(0, 240, 255, 0.1);
                    color: #00f0ff;
                    font-size: 0.7rem;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-weight: normal;
                }
                .tag-private-view {
                    background: rgba(0, 255, 170, 0.1);
                    color: #00ffaa;
                    font-size: 0.7rem;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-weight: normal;
                }
                .caret-icon {
                    font-size: 0.8rem;
                    transition: transform 0.2s;
                    color: var(--theme-text-muted);
                }
                .caret-icon.is-expanded {
                    transform: rotate(180deg);
                    color: var(--theme-primary);
                }
            `}</style>

            {loadingLists ? (
                <div className="box has-text-centered p-5">
                    <span className="loader" style={{ display: 'inline-block', width: '2rem', height: '2rem', border: '3px solid var(--theme-primary)', borderRadius: '50%', borderTopColor: 'transparent', animation: 'spin 1s linear infinite' }}></span>
                    <p className="mt-3">Tracking-Listen werden geladen...</p>
                </div>
            ) : listError ? (
                <div className="notification is-danger">{listError}</div>
            ) : lists.length === 0 ? (
                <div className="box has-text-centered p-5">
                    <p className="text-muted mb-3">Du hast noch keine Tracking-Listen eingerichtet.</p>
                    <a href="/profile" className="button is-primary is-small">
                        ⚙️ Tracking-Listen in meinem Profil verwalten
                    </a>
                </div>
            ) : (
                <div>
                    {lists.map(list => (
                        <div key={list.id} className="collapsible-card">
                            <div 
                                className="collapsible-header"
                                onClick={() => toggleExpand(list.id)}
                            >
                                <div>
                                    <span className="collapsible-header-title">
                                        {list.name}
                                        {list.isTemplate ? (
                                            <span className="tag-template-view">System-Vorlage</span>
                                        ) : (
                                            <span className="tag-private-view">Persönlich</span>
                                        )}
                                    </span>
                                    {list.description && (
                                        <p className="is-size-7 text-muted mt-1" style={{ margin: 0 }}>{list.description}</p>
                                    )}
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                    <span className="is-size-7 text-muted">{list.items.length} Items</span>
                                    <span className={`caret-icon ${expandedListId === list.id ? 'is-expanded' : ''}`}>▼</span>
                                </div>
                            </div>

                            {expandedListId === list.id && (
                                <div className="collapsible-body">
                                    <ListAnalyticsViewer listId={list.id} />
                                </div>
                            )}
                        </div>
                    ))}
                    
                    <div className="has-text-centered mt-5">
                        <a href="/profile" className="button is-dark is-small">
                            ⚙️ Tracking-Listen im Profil verwalten
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}

/* Inner component to handle loading and rendering of selected list data */
interface ListAnalyticsViewerProps {
    listId: number;
}

type TimeSelection = '3h' | '6h' | '12h' | '24h' | '7d' | '14d' | '30d' | 'single';

function ListAnalyticsViewer({ listId }: ListAnalyticsViewerProps) {
    const [analytics, setAnalytics] = useState<AnalyticsData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    
    // Time control states
    const [timeSelect, setTimeSelect] = useState<TimeSelection>('30d');
    
    // Date picker state for 'single'
    const todayStr = new Date().toISOString().substring(0, 10);
    const [singleDate, setSingleDate] = useState<string>(todayStr);

    // Refresh and active expanded item states
    const [refreshTrigger, setRefreshTrigger] = useState<number>(0);
    const [activeItemTypeId, setActiveItemTypeId] = useState<number | null>(null);

    useEffect(() => {
        setLoading(true);
        setError(null);

        // Build request URL based on timeSelect
        let url = `/corp/tracking/api/data?listId=${listId}`;
        
        if (timeSelect.endsWith('h')) {
            const hours = parseInt(timeSelect);
            url += `&rangeType=hours&hours=${hours}`;
        } else if (timeSelect.endsWith('d')) {
            const days = parseInt(timeSelect);
            url += `&rangeType=days&days=${days}`;
        } else if (timeSelect === 'single') {
            url += `&rangeType=single_date&date=${singleDate}`;
        }

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Abrufen der Auswertung.');
                return res.json();
            })
            .then((data: AnalyticsData) => {
                setAnalytics(data);
                setLoading(false);
            })
            .catch(err => {
                setError(err.message);
                setLoading(false);
            });
    }, [listId, timeSelect, singleDate, refreshTrigger]);

    const formatISK = (val: number): string => {
        return new Intl.NumberFormat('de-DE', {
            style: 'decimal',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(val) + ' ISK';
    };

    const formatNumber = (val: number): string => {
        return new Intl.NumberFormat('de-DE').format(val);
    };

    if (loading) {
        return (
            <div className="has-text-centered p-5">
                <span className="loader" style={{ display: 'inline-block', width: '1.5rem', height: '1.5rem', border: '2px solid var(--theme-primary)', borderRadius: '50%', borderTopColor: 'transparent', animation: 'spin 1s linear infinite' }}></span>
                <p className="mt-2 is-size-7">Berechne Zuwächse...</p>
            </div>
        );
    }

    if (error) {
        return <div className="notification is-danger is-light is-size-7">{error}</div>;
    }

    if (!analytics) return null;

    // Calculate dynamic values for character share chart
    const charSummary = analytics.characters.map(char => {
        const totalCharVal = char.data.reduce((sum, val) => sum + val, 0);
        return {
            name: char.name,
            value: totalCharVal
        };
    }).sort((a, b) => b.value - a.value);

    const totalCalculatedValue = charSummary.reduce((sum, char) => sum + char.value, 0);

    // Calculate max past date for HTML5 date input (last 30 days)
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
    const minDateStr = thirtyDaysAgo.toISOString().substring(0, 10);

    return (
        <div>
            <style>{`
                .viewer-stats-panel {
                    background: rgba(0, 0, 0, 0.15);
                    border: 1px solid var(--theme-card-border, #333);
                    border-radius: 8px;
                    padding: 1.25rem;
                    margin-bottom: 1rem;
                }
                .viewer-share-bar {
                    display: flex;
                    height: 20px;
                    border-radius: 10px;
                    overflow: hidden;
                    background: rgba(255, 255, 255, 0.03);
                    margin: 1rem 0;
                }
                .viewer-share-bar-segment {
                    height: 100%;
                    transition: width 0.3s;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.7rem;
                    font-weight: bold;
                    color: #000;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    padding: 0 4px;
                }
                .viewer-progress-bar {
                    background: rgba(255, 255, 255, 0.05);
                    height: 5px;
                    border-radius: 3px;
                    overflow: hidden;
                    margin-top: 4px;
                }
                .viewer-progress-fill {
                    background: var(--theme-primary, #00f0ff);
                    height: 100%;
                    border-radius: 3px;
                }
                .time-control-flex {
                    display: flex;
                    justify-content: flex-end;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 1.25rem;
                    flex-wrap: wrap;
                }
                .time-select-container {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                }
                .item-row-hover:hover {
                    background: rgba(255, 255, 255, 0.04) !important;
                }
            `}</style>

            {/* Time range and single date selectors */}
            <div className="time-control-flex">
                {timeSelect === 'single' && (
                    <div className="time-select-container">
                        <span className="is-size-7 text-muted">Datum:</span>
                        <input 
                            type="date" 
                            className="input is-small input-dark-prof" 
                            value={singleDate} 
                            onChange={(e) => setSingleDate(e.target.value)}
                            max={todayStr}
                            min={minDateStr}
                            style={{ width: 'auto' }}
                        />
                    </div>
                )}
                
                <div className="time-select-container">
                    <span className="is-size-7 text-muted">Auswahl:</span>
                    <div className="select is-small">
                        <select 
                            value={timeSelect} 
                            onChange={(e) => setTimeSelect(e.target.value as TimeSelection)}
                            style={{ background: 'rgba(0,0,0,0.3)', color: '#fff', borderColor: '#444' }}
                        >
                            <optgroup label="Stunden">
                                <option value="3h">Letzte 3 Stunden</option>
                                <option value="6h">Letzte 6 Stunden</option>
                                <option value="12h">Letzte 12 Stunden</option>
                                <option value="24h">Letzte 24 Stunden</option>
                            </optgroup>
                            <optgroup label="Tage (max. 30)">
                                <option value="7d">Letzte 7 Tage</option>
                                <option value="14d">Letzte 14 Tage</option>
                                <option value="30d">Letzte 30 Tage (Gesamt)</option>
                            </optgroup>
                            <optgroup label="Einzelner Tag">
                                <option value="single">Spezifischer Tag...</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
            </div>

            {/* Summary statistics */}
            <div className="viewer-stats-panel">
                <p className="subtitle is-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>
                    {timeSelect === 'single' 
                        ? `Wert der Zuwächse am ${singleDate}` 
                        : timeSelect.endsWith('h') 
                            ? `Wert der Zuwächse (Letzte ${timeSelect.replace('h', '')} Std.)` 
                            : `Wert der Zuwächse (Letzte ${timeSelect.replace('d', '')} Tage)`}
                </p>
                <h3 className="title is-4 mb-2" style={{ color: 'var(--theme-primary, #00f0ff)' }}>
                    {formatISK(totalCalculatedValue)}
                </h3>

                {charSummary.length > 0 ? (
                    <div>
                        <div className="viewer-share-bar">
                            {(() => {
                                const colors = ['#00f0ff', '#00ffaa', '#ffbb00', '#ff00aa', '#9900ff', '#3388ff'];
                                return charSummary.map((char, index) => {
                                    const sharePercent = totalCalculatedValue > 0 ? (char.value / totalCalculatedValue) * 100 : 0;
                                    if (sharePercent < 1) return null;
                                    return (
                                        <div 
                                            key={char.name}
                                            className="viewer-share-bar-segment"
                                            style={{ 
                                                width: `${sharePercent}%`, 
                                                backgroundColor: colors[index % colors.length] 
                                            }}
                                            title={`${char.name}: ${formatISK(char.value)} (${sharePercent.toFixed(1)}%)`}
                                        >
                                            {sharePercent > 10 ? char.name : ''}
                                        </div>
                                    );
                                });
                            })()}
                        </div>

                        {/* Legend */}
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '6px' }}>
                            {(() => {
                                const colors = ['#00f0ff', '#00ffaa', '#ffbb00', '#ff00aa', '#9900ff', '#3388ff'];
                                return charSummary.map((char, index) => (
                                    <div key={char.name} style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '0.8rem' }}>
                                        <span style={{ display: 'inline-block', width: '10px', height: '10px', borderRadius: '2px', backgroundColor: colors[index % colors.length] }}></span>
                                        <span className="has-text-weight-semibold">{char.name}:</span>
                                        <span className="text-muted">{formatISK(char.value)}</span>
                                    </div>
                                ));
                            })()}
                        </div>
                    </div>
                ) : (
                    <p className="text-muted is-size-7 mt-2">Keine Zuwächse für deine Charaktere in diesem Zeitraum verzeichnet.</p>
                )}
            </div>

            {/* Item breakdown table */}
            <div className="viewer-stats-panel" style={{ marginBottom: 0 }}>
                <h4 className="title is-6 mb-3" style={{ color: '#fff' }}>📦 Erzielter Loot & Wert</h4>
                {analytics.itemBreakdown.length === 0 ? (
                    <p className="text-muted is-size-7">Keine Gegenstände im gewählten Zeitraum gelootet.</p>
                ) : (
                    <div className="table-container">
                        <table className="table is-striped is-fullwidth" style={{ background: 'transparent' }}>
                            <thead>
                                <tr>
                                    <th className="is-size-7" style={{ width: '40px' }}></th>
                                    <th className="is-size-7">Gegenstand</th>
                                    <th className="has-text-right is-size-7">Menge</th>
                                    <th className="has-text-right is-size-7">Gesamtwert</th>
                                </tr>
                            </thead>
                            <tbody>
                                {analytics.itemBreakdown.map(item => {
                                    const percent = totalCalculatedValue > 0 ? (item.value / totalCalculatedValue) * 100 : 0;
                                    const isExpanded = activeItemTypeId === item.typeId;
                                    return (
                                        <React.Fragment key={item.typeId}>
                                            <tr 
                                                style={{ borderBottom: '1px solid rgba(255,255,255,0.03)', cursor: 'pointer' }}
                                                onClick={() => setActiveItemTypeId(isExpanded ? null : item.typeId)}
                                                className="item-row-hover"
                                            >
                                                <td className="has-text-centered" style={{ verticalAlign: 'middle', color: '#6a737d' }}>
                                                    {isExpanded ? '▼' : '▶'}
                                                </td>
                                                <td>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                                                        <img 
                                                            src={`https://images.evetech.net/types/${item.typeId}/icon?size=32`} 
                                                            alt="" 
                                                            style={{ width: '20px', height: '20px', borderRadius: '4px' }}
                                                            loading="lazy"
                                                        />
                                                        <div>
                                                            <span className="has-text-weight-semibold" style={{ fontSize: '0.85rem' }}>{item.typeName}</span>
                                                            <div className="viewer-progress-bar" style={{ width: '80px' }}>
                                                                <div className="viewer-progress-fill" style={{ width: `${percent}%` }}></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="has-text-right has-text-weight-semibold is-size-7" style={{ verticalAlign: 'middle' }}>
                                                    {formatNumber(item.quantity)}
                                                </td>
                                                <td className="has-text-right has-text-weight-bold is-size-7" style={{ color: 'var(--theme-primary, #00f0ff)', verticalAlign: 'middle' }}>
                                                    {formatISK(item.value)}
                                                </td>
                                            </tr>
                                            {isExpanded && (
                                                <tr>
                                                    <td colSpan={4} style={{ background: 'rgba(0, 0, 0, 0.25)', borderTop: 'none', borderBottom: '1px solid rgba(255,255,255,0.05)', padding: '12px' }}>
                                                        <ItemChangesDetails 
                                                            listId={listId}
                                                            typeId={item.typeId}
                                                            timeSelect={timeSelect}
                                                            singleDate={singleDate}
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
                )}
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
    listId: number;
    typeId: number;
    timeSelect: TimeSelection;
    singleDate: string;
    formatNumber: (val: number) => string;
    onDeleteSuccess: () => void;
}

function ItemChangesDetails({ listId, typeId, timeSelect, singleDate, formatNumber, onDeleteSuccess }: ItemChangesDetailsProps) {
    const [changes, setChanges] = React.useState<ChangeEntry[]>([]);
    const [loading, setLoading] = React.useState<boolean>(true);
    const [error, setError] = React.useState<string | null>(null);
    const [deletingId, setDeletingId] = React.useState<number | null>(null);

    const loadChanges = () => {
        setLoading(true);
        let url = `/corp/tracking/api/changes?listId=${listId}&typeId=${typeId}`;
        if (timeSelect.endsWith('h')) {
            url += `&rangeType=hours&hours=${parseInt(timeSelect)}`;
        } else if (timeSelect.endsWith('d')) {
            url += `&rangeType=days&days=${parseInt(timeSelect)}`;
        } else if (timeSelect === 'single') {
            url += `&rangeType=single_date&date=${singleDate}`;
        }

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
    }, [listId, typeId, timeSelect, singleDate]);

    const handleDelete = (id: number, characterName: string, quantity: number, loggedAt: string) => {
        if (!confirm(`Möchtest du die Zuwachs-Buchung über ${formatNumber(quantity)} Einheiten von ${characterName} am ${loggedAt} wirklich löschen?\nDies korrigiert die Statistik dauerhaft.`)) {
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
        return <div className="is-size-7 text-muted">Lade Einzelbuchungen...</div>;
    }

    if (error) {
        return <div className="is-size-7 has-text-danger">{error}</div>;
    }

    if (changes.length === 0) {
        return <div className="is-size-7 text-muted">Keine Einzelbuchungen (Zuwächse) in diesem Zeitraum vorhanden.</div>;
    }

    return (
        <div>
            <h5 className="is-size-7 has-text-weight-bold mb-2" style={{ color: '#fff' }}>Detaillierte Einzelbuchungen (Zuwächse):</h5>
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
