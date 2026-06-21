import React, { useState, useEffect, useMemo } from 'react';

interface CharacterListEntry {
    id: number;
    name: string;
    hasToken: boolean;
}

interface IndustryJob {
    jobId: string;
    installerId: number;
    blueprintId: string;
    blueprintTypeId: number;
    blueprintName: string;
    blueprintLocationName: string;
    outputLocationName: string;
    productTypeId: number | null;
    productName: string | null;
    activityId: number;
    runs: number;
    successfulRuns: number | null;
    duration: number;
    startDate: string;
    endDate: string;
    pauseDate: string | null;
    completedDate: string | null;
    status: string;
    cost: string | null;
    probability: number | null;
    licenceLimit: number | null;
}

interface CharacterData {
    id: number;
    name: string;
    jobs: IndustryJob[];
    lastUpdate: string | null;
    error: string | null;
}

interface IndustryJobsOverviewProps {
    charactersList: CharacterListEntry[];
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
}

const ACTIVITY_NAMES: Record<number, string> = {
    1: 'Produktion',
    3: 'Zeitforschung (TE)',
    4: 'Materialforschung (ME)',
    5: 'Kopieren',
    7: 'Erfindung',
    8: 'Erfindung (Reverse Engineering)',
    9: 'Reaktionen'
};

const ACTIVITY_COLORS: Record<number, string> = {
    1: '#00f0ff', // Cyan for Manufacturing
    3: '#ffbb00', // Yellow for TE Research
    4: '#ff8800', // Orange for ME Research
    5: '#cc00ff', // Purple for Copying
    7: '#00ffaa', // Green for Invention
    8: '#00ffaa', // Green for Reverse Engineering
    9: '#ff0055'  // Pink/Red for Reactions
};

export default function IndustryJobsOverview({ charactersList, apiDataUrl, imagePaths }: IndustryJobsOverviewProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [charactersData, setCharactersData] = useState<CharacterData[]>([]);
    
    // Filters
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [filterActivity, setFilterActivity] = useState<string>('all');
    
    // Refresh tick for countdowns
    const [nowTime, setNowTime] = useState<number>(Date.now());

    useEffect(() => {
        const timer = setInterval(() => {
            setNowTime(Date.now());
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    // Fetch data
    useEffect(() => {
        setLoading(true);
        fetch(apiDataUrl)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Fehler beim Laden der Industriedaten.');
                }
                return res.json();
            })
            .then((data: { characters: CharacterData[] }) => {
                setCharactersData(data.characters || []);
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

    const formatDuration = (ms: number): string => {
        if (ms <= 0) return 'Bereit zum Liefern';
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (days > 0) {
            return `${days}d ${hours % 24}h ${minutes % 60}m`;
        }
        if (hours > 0) {
            return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
        }
        if (minutes > 0) {
            return `${minutes}m ${seconds % 60}s`;
        }
        return `${seconds}s`;
    };

    // Activity Priority mapping
    const activityPriority: Record<number, number> = {
        1: 1, // Produktion
        9: 2, // Reaktionen
        7: 3, // Erfindung (Invention)
        8: 4, // Erfindung (Reverse Engineering)
        5: 5, // Kopieren
        3: 6, // Zeitforschung (TE)
        4: 7  // Materialforschung (ME)
    };

    // Summary statistics for active jobs
    const stats = useMemo(() => {
        let totalActive = 0;
        let totalCostActive = 0;
        let totalRunsActive = 0;

        charactersData.forEach((char) => {
            if (char.jobs) {
                char.jobs.forEach((job) => {
                    if (job.status === 'active') {
                        totalActive++;
                        totalCostActive += parseFloat(job.cost || '0');
                        totalRunsActive += job.runs;
                    }
                });
            }
        });

        return {
            totalActive,
            totalCostActive,
            totalRunsActive
        };
    }, [charactersData]);

    if (loading) {
        return (
            <div className="box has-text-centered p-5">
                <span className="loader" style={{ display: 'inline-block', width: '2rem', height: '2rem', border: '3px solid var(--theme-primary)', borderRadius: '50%', borderTopColor: 'transparent', animation: 'spin 1s linear infinite' }}></span>
                <p className="mt-3">Industriedaten werden geladen...</p>
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
            <style>{`
                .char-industry-box {
                    background: rgba(20, 27, 43, 0.45);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    margin-bottom: 2rem;
                    overflow: hidden;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
                }
                .char-industry-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    background: rgba(10, 15, 25, 0.75);
                    padding: 0.85rem 1.25rem;
                    border-bottom: 1px solid var(--theme-card-border);
                    flex-wrap: wrap;
                    gap: 0.75rem;
                }
                .char-profile-section {
                    display: flex;
                    align-items: center;
                    gap: 0.85rem;
                }
                .char-profile-section img {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    border: 2px solid var(--theme-primary);
                }
                .char-profile-name {
                    font-weight: 700;
                    font-size: 1.15rem;
                    color: #fff;
                    margin: 0;
                }
                .char-sync-time {
                    font-size: 0.75rem;
                    color: var(--theme-text-muted);
                    margin: 0;
                }
                .char-jobs-count-badge {
                    background: rgba(0, 240, 255, 0.1);
                    border: 1px solid var(--theme-primary);
                    color: var(--theme-primary);
                    font-weight: bold;
                    font-size: 0.85rem;
                    padding: 0.25rem 0.6rem;
                    border-radius: 4px;
                }
                .char-industry-body {
                    padding: 1.25rem;
                }
                .collapsible-activity-group {
                    margin-bottom: 1rem;
                    border: 1px solid rgba(255, 255, 255, 0.08);
                    border-radius: 6px;
                    overflow: hidden;
                    background: rgba(10, 15, 25, 0.2);
                }
                .collapsible-activity-group:last-child {
                    margin-bottom: 0;
                }
                .collapsible-activity-group summary {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 0.75rem 1rem;
                    background: rgba(20, 27, 43, 0.65);
                    cursor: pointer;
                    font-weight: bold;
                    color: #fff;
                    user-select: none;
                    list-style: none; /* Hide default caret */
                    transition: background 0.2s ease;
                }
                .collapsible-activity-group summary::-webkit-details-marker {
                    display: none; /* Hide default caret on Safari */
                }
                .collapsible-activity-group summary:hover {
                    background: rgba(20, 27, 43, 0.85);
                }
                .group-title-side {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }
                .group-title-indicator {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                }
                .group-stats-side {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    font-size: 0.8rem;
                    color: var(--theme-text-muted);
                }
                .group-jobs-list {
                    padding: 0.85rem;
                    border-top: 1px solid rgba(255, 255, 255, 0.05);
                }
                .job-item-row {
                    background: rgba(10, 15, 25, 0.3);
                    border: 1px solid rgba(255, 255, 255, 0.04);
                    border-radius: 6px;
                    padding: 0.85rem;
                    margin-bottom: 0.75rem;
                    display: flex;
                    flex-direction: column;
                    gap: 0.75rem;
                    transition: border-color 0.2s ease;
                }
                .job-item-row:hover {
                    border-color: rgba(0, 240, 255, 0.25);
                }
                .job-item-row:last-child {
                    margin-bottom: 0;
                }
                .job-row-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                }
                .item-info {
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                }
                .item-icon-img {
                    width: 36px;
                    height: 36px;
                    border-radius: 4px;
                    border: 1px solid var(--theme-card-border);
                }
                .item-title {
                    font-weight: bold;
                    color: #fff;
                    margin: 0;
                    font-size: 0.95rem;
                }
                .item-subtitle {
                    font-size: 0.75rem;
                    color: var(--theme-text-muted);
                    margin: 0;
                }
                .bp-ref {
                    color: #bbb;
                }
                .job-meta-line {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 1.5rem;
                    font-size: 0.8rem;
                    color: #ccc;
                    background: rgba(0, 0, 0, 0.15);
                    padding: 0.5rem 0.75rem;
                    border-radius: 4px;
                }
                .meta-item {
                    display: flex;
                    gap: 0.35rem;
                }
                .meta-label {
                    color: var(--theme-text-muted);
                }
                .meta-value {
                    font-weight: 600;
                }
                .progress-bar-wrapper {
                    background: rgba(255, 255, 255, 0.08);
                    height: 6px;
                    border-radius: 3px;
                    overflow: hidden;
                }
                .progress-bar-fill {
                    height: 100%;
                    border-radius: 3px;
                    transition: width 0.5s ease;
                }
                .progress-texts {
                    display: flex;
                    justify-content: space-between;
                    font-size: 0.75rem;
                    font-weight: 500;
                }
                .progress-percent {
                    color: #eee;
                }
                .progress-countdown {
                    color: var(--theme-primary);
                }
                .summary-card {
                    background: rgba(20, 27, 43, 0.5);
                    border: 1px solid var(--theme-card-border);
                    border-radius: 8px;
                    padding: 1.25rem;
                    margin-bottom: 1.5rem;
                }
            `}</style>

            {/* Aggregated Stats Cards */}
            <div className="columns mb-4">
                <div className="column">
                    <div className="summary-card mb-0">
                        <p className="subtitle is-6 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Aktive Aufträge</p>
                        <p className="title is-3 mb-0" style={{ color: 'var(--theme-primary)' }}>
                            {stats.totalActive}
                        </p>
                    </div>
                </div>
                <div className="column">
                    <div className="summary-card mb-0">
                        <p className="subtitle is-6 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Kosten aktiver Aufträge</p>
                        <p className="title is-3 mb-0">
                            {formatISK(stats.totalCostActive)}
                        </p>
                    </div>
                </div>
                <div className="column">
                    <div className="summary-card mb-0">
                        <p className="subtitle is-6 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Durchgänge gesamt</p>
                        <p className="title is-3 mb-0">
                            {stats.totalRunsActive}
                        </p>
                    </div>
                </div>
            </div>

            {/* Global Search & Filters */}
            <div className="box mb-4">
                <div className="columns">
                    <div className="column is-8">
                        <input 
                            type="text"
                            className="input input-dark"
                            placeholder="Nach Gegenstand oder Blueprint suchen..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>
                    <div className="column is-4">
                        <div className="select is-fullwidth">
                            <select 
                                value={filterActivity}
                                onChange={(e) => setFilterActivity(e.target.value)}
                                className="input-dark"
                                style={{ background: '#101525', color: '#fff', border: '1px solid var(--theme-card-border)' }}
                            >
                                <option value="all">Alle Aktivitäten</option>
                                <option value="1">Produktion</option>
                                <option value="9">Reaktionen</option>
                                <option value="7">Erfindung</option>
                                <option value="8">Erfindung (Reverse Engineering)</option>
                                <option value="5">Kopieren</option>
                                <option value="3">Zeitforschung (TE)</option>
                                <option value="4">Materialforschung (ME)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {/* Characters list */}
            {charactersData.map((char) => {
                const lastSync = char.lastUpdate ? new Date(char.lastUpdate).toLocaleString('de-DE') : 'Nie';

                // Process character jobs
                const activeJobs = (char.jobs || []).filter(j => j.status === 'active');
                
                // Apply global search & filters
                const filteredJobs = activeJobs.filter(job => {
                    const matchesSearch = 
                        job.blueprintName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                        (job.productName && job.productName.toLowerCase().includes(searchTerm.toLowerCase()));
                    
                    const matchesActivity = 
                        filterActivity === 'all' || 
                        job.activityId.toString() === filterActivity;

                    return matchesSearch && matchesActivity;
                });

                // Group filtered jobs by activity type
                const groupedMap: Record<number, IndustryJob[]> = {};
                filteredJobs.forEach(job => {
                    if (!groupedMap[job.activityId]) {
                        groupedMap[job.activityId] = [];
                    }
                    groupedMap[job.activityId].push(job);
                });

                // Sort grouped activities by priority
                const sortedGroups = Object.entries(groupedMap)
                    .map(([activityIdStr, jobs]) => {
                        const activityId = parseInt(activityIdStr);
                        // Sort jobs in group by end date
                        const sortedJobs = jobs.sort((a, b) => new Date(a.endDate).getTime() - new Date(b.endDate).getTime());
                        return { activityId, jobs: sortedJobs };
                    })
                    .sort((a, b) => {
                        const priorityA = activityPriority[a.activityId] || 99;
                        const priorityB = activityPriority[b.activityId] || 99;
                        return priorityA - priorityB;
                    });

                return (
                    <div key={char.id} className="char-industry-box">
                        <div className="char-industry-header">
                            <div className="char-profile-section">
                                <img src={getCharacterPortraitUrl(char.id)} alt={char.name} />
                                <div>
                                    <h3 className="char-profile-name">{char.name}</h3>
                                    <p className="char-sync-time">Sync: {lastSync}</p>
                                </div>
                            </div>
                            <div className="char-jobs-count-badge">
                                {activeJobs.length} aktive Aufträge
                            </div>
                        </div>

                        <div className="char-industry-body">
                            {char.error ? (
                                <div className="notification is-danger p-3 mb-0" style={{ background: 'rgba(255, 68, 68, 0.12)', border: '1px solid #ff4444', color: '#ff8888', borderRadius: '6px' }}>
                                    {char.error}
                                </div>
                            ) : filteredJobs.length === 0 ? (
                                <div className="has-text-centered p-4" style={{ color: 'var(--theme-text-muted)' }}>
                                    Keine aktiven Aufträge gefunden.
                                </div>
                            ) : (
                                <div>
                                    {sortedGroups.map(({ activityId, jobs }) => {
                                        // Calculate counts in group: running vs ready/finished
                                        let runningCount = 0;
                                        let readyCount = 0;

                                        jobs.forEach(job => {
                                            const endMs = new Date(job.endDate).getTime();
                                            const timeRemaining = endMs - nowTime;
                                            if (timeRemaining > 0) {
                                                runningCount++;
                                            } else {
                                                readyCount++;
                                            }
                                        });

                                        const groupColor = ACTIVITY_COLORS[activityId] || '#fff';
                                        const groupName = ACTIVITY_NAMES[activityId] || 'Unbekannt';

                                        // Render collapse/details for this group
                                        return (
                                            <details key={activityId} className="collapsible-activity-group">
                                                <summary>
                                                    <div className="group-title-side">
                                                        <span>{groupName}</span>
                                                    </div>
                                                    <div className="group-stats-side">
                                                        {runningCount > 0 && <span style={{ color: '#00f0ff' }}>{runningCount} laufend</span>}
                                                        {runningCount > 0 && readyCount > 0 && <span style={{ color: 'var(--theme-text-muted)', margin: '0 4px' }}>|</span>}
                                                        {readyCount > 0 && <span style={{ color: '#00ffaa' }}>{readyCount} bereit</span>}
                                                        <span style={{ fontSize: '0.6rem', marginLeft: '0.4rem' }}>▼</span>
                                                    </div>
                                                </summary>

                                                <div className="group-jobs-list">
                                                    {jobs.map((job) => {
                                                        const startMs = new Date(job.startDate).getTime();
                                                        const endMs = new Date(job.endDate).getTime();
                                                        const totalDuration = endMs - startMs;
                                                        const elapsed = nowTime - startMs;
                                                        
                                                        const percent = Math.min(100, Math.max(0, (elapsed / totalDuration) * 100));
                                                        const timeRemaining = Math.max(0, endMs - nowTime);

                                                        const isResearch = [3, 4, 5, 7, 8].includes(job.activityId);
                                                        const iconTypeId = isResearch ? job.blueprintTypeId : (job.productTypeId || job.blueprintTypeId);
                                                        const iconName = isResearch ? job.blueprintName : (job.productName || job.blueprintName);

                                                        return (
                                                            <div key={job.jobId} className="job-item-row">
                                                                <div className="job-row-header">
                                                                    <div className="item-info">
                                                                        <img 
                                                                            src={getItemIconUrl(iconTypeId)} 
                                                                            alt={iconName} 
                                                                            className="item-icon-img" 
                                                                        />
                                                                        <div>
                                                                            <h4 className="item-title">{iconName}</h4>
                                                                            <p className="item-subtitle">
                                                                                {isResearch ? 'Forschung an: ' : 'Hergestellt aus: '} 
                                                                                <span className="bp-ref">{job.blueprintName}</span>
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div className="job-meta-line">
                                                                    <div className="meta-item">
                                                                        <span className="meta-label">Runs:</span>
                                                                        <span className="meta-value">{job.runs}</span>
                                                                    </div>
                                                                    <div className="meta-item" style={{ flexGrow: 1, minWidth: '150px' }}>
                                                                        <span className="meta-label">Standort:</span>
                                                                        <span className="meta-value" title={job.blueprintLocationName}>{job.blueprintLocationName}</span>
                                                                    </div>
                                                                    <div className="meta-item">
                                                                        <span className="meta-label">Kosten:</span>
                                                                        <span className="meta-value">{formatISK(parseFloat(job.cost || '0'))}</span>
                                                                    </div>
                                                                </div>

                                                                <div className="progress-container">
                                                                    <div className="progress-bar-wrapper">
                                                                        <div 
                                                                            className="progress-bar-fill" 
                                                                            style={{ 
                                                                                width: `${percent}%`,
                                                                                backgroundColor: groupColor
                                                                            }}
                                                                        ></div>
                                                                    </div>
                                                                    <div className="progress-texts mt-1">
                                                                        <span className="progress-percent">{percent.toFixed(1)}%</span>
                                                                        <span className="progress-countdown" style={{ color: timeRemaining > 0 ? 'var(--theme-primary)' : '#00ffaa' }}>
                                                                            {formatDuration(timeRemaining)}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </details>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
