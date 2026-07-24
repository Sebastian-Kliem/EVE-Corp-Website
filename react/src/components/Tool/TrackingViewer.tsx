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
        <div className="w-full max-w-[900px] mx-auto">
            {loadingLists ? (
                <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg text-center">
                    <span className="inline-block w-8 h-8 border-3 border-eve-primary rounded-full border-t-transparent animate-spin"></span>
                    <p className="mt-3 text-xs text-eve-muted">Tracking-Listen werden geladen...</p>
                </div>
            ) : listError ? (
                <div className="p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm">{listError}</div>
            ) : lists.length === 0 ? (
                <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg text-center">
                    <p className="text-eve-muted text-xs mb-3">Du hast noch keine Tracking-Listen eingerichtet.</p>
                    <a href="/profile" className="inline-flex items-center justify-center border border-transparent rounded bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold text-xs px-2.5 py-1.5 shadow-eve transition-all duration-300 cursor-pointer">
                        ⚙️ Tracking-Listen in meinem Profil verwalten
                    </a>
                </div>
            ) : (
                <div>
                    {lists.map(list => (
                        <div key={list.id} className="bg-[#141b2b66] border border-eve-border rounded-lg mb-4 overflow-hidden transition-all duration-200 hover:border-eve-primary">
                            <div 
                                className="p-4 cursor-pointer flex justify-between items-center bg-black/15 select-none"
                                onClick={() => toggleExpand(list.id)}
                            >
                                <div>
                                    <span className="text-white font-bold text-sm flex items-center gap-2">
                                        {list.name}
                                        {list.isTemplate ? (
                                            <span className="bg-eve-primary/10 text-eve-primary text-[10px] px-1.5 py-0.5 rounded font-normal">System-Vorlage</span>
                                        ) : (
                                            <span className="bg-emerald-500/10 text-emerald-400 text-[10px] px-1.5 py-0.5 rounded font-normal">Persönlich</span>
                                        )}
                                    </span>
                                    {list.description && (
                                        <p className="text-xs text-eve-muted mt-1">{list.description}</p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-xs text-eve-muted">{list.items.length} Items</span>
                                    <span className={`text-xs transition-transform duration-200 text-eve-muted ${expandedListId === list.id ? 'transform rotate-180 text-eve-primary' : ''}`}>▼</span>
                                </div>
                            </div>
 
                            {expandedListId === list.id && (
                                <div className="p-5 border-t border-white/5 bg-black/5">
                                    <ListAnalyticsViewer listId={list.id} />
                                </div>
                            )}
                        </div>
                    ))}
                    
                    <div className="text-center mt-5">
                        <a href="/profile" className="inline-flex items-center justify-center border border-eve-border hover:border-eve-primary text-eve-text hover:text-eve-primary bg-transparent rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 cursor-pointer">
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
            <div className="text-center p-5">
                <span className="inline-block w-6 h-6 border-2 border-eve-primary rounded-full border-t-transparent animate-spin"></span>
                <p className="mt-2 text-xs text-eve-muted">Berechne Zuwächse...</p>
            </div>
        );
    }

    if (error) {
        return <div className="p-3 rounded text-xs bg-rose-500/10 border border-rose-500/30 text-rose-400">{error}</div>;
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
            {/* Time range and single date selectors */}
            <div className="flex justify-end items-center gap-2.5 mb-5 flex-wrap">
                {timeSelect === 'single' && (
                    <div className="flex items-center gap-1.5">
                        <span className="text-xs text-eve-muted">Datum:</span>
                        <input 
                            type="date" 
                            className="rounded px-2.5 py-1 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-auto" 
                            value={singleDate} 
                            onChange={(e) => setSingleDate(e.target.value)}
                            max={todayStr}
                            min={minDateStr}
                        />
                    </div>
                )}
                
                <div className="flex items-center gap-1.5">
                    <span className="text-xs text-eve-muted">Auswahl:</span>
                    <div>
                        <select 
                            value={timeSelect} 
                            onChange={(e) => setTimeSelect(e.target.value as TimeSelection)}
                            className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                        >
                            <optgroup label="Stunden" style={{ background: '#101525' }}>
                                <option value="3h">Letzte 3 Stunden</option>
                                <option value="6h">Letzte 6 Stunden</option>
                                <option value="12h">Letzte 12 Stunden</option>
                                <option value="24h">Letzte 24 Stunden</option>
                            </optgroup>
                            <optgroup label="Tage (max. 30)" style={{ background: '#101525' }}>
                                <option value="7d">Letzte 7 Tage</option>
                                <option value="14d">Letzte 14 Tage</option>
                                <option value="30d">Letzte 30 Tage (Gesamt)</option>
                            </optgroup>
                            <optgroup label="Einzelner Tag" style={{ background: '#101525' }}>
                                <option value="single">Spezifischer Tag...</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
            </div>

            {/* Summary statistics */}
            <div className="bg-black/15 border border-eve-border rounded-lg p-5 mb-4">
                <p className="text-[10px] text-eve-muted mb-1">
                    {timeSelect === 'single' 
                        ? `Wert der Zuwächse am ${singleDate}` 
                        : timeSelect.endsWith('h') 
                            ? `Wert der Zuwächse (Letzte ${timeSelect.replace('h', '')} Std.)` 
                            : `Wert der Zuwächse (Letzte ${timeSelect.replace('d', '')} Tage)`}
                </p>
                <h3 className="text-xl font-bold text-eve-primary mb-2">
                    {formatISK(totalCalculatedValue)}
                </h3>

                {charSummary.length > 0 ? (
                    <div>
                        <div className="flex h-5 rounded-full overflow-hidden bg-white/3 my-4">
                            {(() => {
                                const colors = ['#00f0ff', '#00ffaa', '#ffbb00', '#ff00aa', '#9900ff', '#3388ff'];
                                return charSummary.map((char, index) => {
                                    const sharePercent = totalCalculatedValue > 0 ? (char.value / totalCalculatedValue) * 100 : 0;
                                    if (sharePercent < 1) return null;
                                    return (
                                        <div 
                                            key={char.name}
                                            className="h-full transition-all duration-300 flex items-center justify-center text-[10px] font-bold text-black overflow-hidden truncate px-1"
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
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-1.5">
                            {(() => {
                                const colors = ['#00f0ff', '#00ffaa', '#ffbb00', '#ff00aa', '#9900ff', '#3388ff'];
                                return charSummary.map((char, index) => (
                                    <div key={char.name} className="flex items-center gap-1.5 text-xs text-[#ccc]">
                                        <span className="inline-block w-2.5 h-2.5 rounded-sm flex-shrink-0" style={{ backgroundColor: colors[index % colors.length] }}></span>
                                        <span className="font-semibold">{char.name}:</span>
                                        <span className="text-eve-muted">{formatISK(char.value)}</span>
                                    </div>
                                ));
                            })()}
                        </div>
                    </div>
                ) : (
                    <p className="text-eve-muted text-xs mt-2">Keine Zuwächse für deine Charaktere in diesem Zeitraum verzeichnet.</p>
                )}
            </div>

            {/* Item breakdown table */}
            <div className="bg-black/15 border border-eve-border rounded-lg p-5">
                <h4 className="text-sm font-semibold text-white mb-3">📦 Erzielter Loot & Wert</h4>
                {analytics.itemBreakdown.length === 0 ? (
                    <p className="text-eve-muted text-xs">Keine Gegenstände im gewählten Zeitraum gelootet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="border-b border-eve-border bg-[#0d121fe6]/50">
                                    <th className="text-left font-semibold text-eve-muted p-2 text-xs w-10"></th>
                                    <th className="text-left font-semibold text-eve-muted p-2 text-xs">Gegenstand</th>
                                    <th className="text-right font-semibold text-eve-muted p-2 text-xs">Menge</th>
                                    <th className="text-right font-semibold text-eve-muted p-2 text-xs">Gesamtwert</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {analytics.itemBreakdown.map(item => {
                                    const percent = totalCalculatedValue > 0 ? (item.value / totalCalculatedValue) * 100 : 0;
                                    const isExpanded = activeItemTypeId === item.typeId;
                                    return (
                                        <React.Fragment key={item.typeId}>
                                            <tr 
                                                className="hover:bg-white/2 cursor-pointer transition-colors"
                                                onClick={() => setActiveItemTypeId(isExpanded ? null : item.typeId)}
                                            >
                                                <td className="text-center p-2 text-xs text-[#6a737d] vertical-middle">
                                                    {isExpanded ? '▼' : '▶'}
                                                </td>
                                                <td className="p-2 text-xs text-[#ccc] vertical-middle">
                                                    <div className="flex items-center gap-1.5">
                                                        <img 
                                                            src={`https://images.evetech.net/types/${item.typeId}/icon?size=32`} 
                                                            alt="" 
                                                            className="w-5 h-5 rounded flex-shrink-0"
                                                            loading="lazy"
                                                        />
                                                        <div>
                                                            <span className="font-semibold text-white">{item.typeName}</span>
                                                            <div className="bg-white/5 h-1 rounded-full overflow-hidden mt-1 w-20">
                                                                <div className="bg-eve-primary h-full rounded-full" style={{ width: `${percent}%` }}></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="p-2 text-xs text-right text-white font-semibold vertical-middle font-mono">
                                                    {formatNumber(item.quantity)}
                                                </td>
                                                <td className="p-2 text-xs text-right text-eve-primary font-bold vertical-middle font-mono">
                                                    {formatISK(item.value)}
                                                </td>
                                            </tr>
                                            {isExpanded && (
                                                <tr>
                                                    <td colSpan={4} className="bg-black/25 p-3 border-b border-white/5">
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
        return <div className="text-xs text-eve-muted">Lade Einzelbuchungen...</div>;
    }

    if (error) {
        return <div className="text-xs text-rose-400">{error}</div>;
    }

    if (changes.length === 0) {
        return <div className="text-xs text-eve-muted">Keine Einzelbuchungen (Zuwächse) in diesem Zeitraum vorhanden.</div>;
    }

    return (
        <div>
            <h5 className="text-xs font-semibold text-white mb-2">Detaillierte Einzelbuchungen (Zuwächse):</h5>
            <div className="flex flex-col gap-1.5 max-h-[180px] overflow-y-auto pr-1">
                {changes.map(c => (
                    <div 
                        key={c.id} 
                        className="flex justify-between items-center bg-white/2 p-2 px-2.5 rounded border border-white/5 hover:border-white/10 transition-colors"
                    >
                        <span className="text-xs text-[#ccc]">
                            <strong className="text-emerald-400">{c.characterName}</strong>: {c.quantity > 0 ? '+' : ''}{formatNumber(c.quantity)} Stk. 
                            <span className="text-white/40 ml-2 text-[10px]">({c.loggedAt})</span>
                        </span>
                        <button 
                            className="inline-flex items-center justify-center border border-transparent rounded bg-rose-600 hover:bg-rose-500 text-white font-semibold text-[10px] px-2 py-1 transition-all duration-200 cursor-pointer"
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
