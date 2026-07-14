import React, {useState, useEffect, useMemo} from 'react';

interface CharacterListEntry {
    id: number;
    name: string;
    hasToken: boolean;
    tags?: string[];
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

interface MaterialDetail {
    typeId: number;
    name: string;
    quantity: number;
}

interface ProductDetail {
    typeId: number;
    name: string;
    quantity: number;
}

interface BlueprintInfo {
    materials: MaterialDetail[];
    products: ProductDetail[];
}

interface MarketPrice {
    buy: number | null;
    sell: number | null;
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

export default function IndustryJobsOverview({charactersList, apiDataUrl, imagePaths}: IndustryJobsOverviewProps) {
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [charactersData, setCharactersData] = useState<CharacterData[]>([]);

    // Filters
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [filterActivity, setFilterActivity] = useState<string>('all');
    const [hideEmptyCharacters, setHideEmptyCharacters] = useState<boolean>(true);
    const [selectedTag, setSelectedTag] = useState<string>('all');
    const [onlyKopiererMode, setOnlyKopiererMode] = useState<boolean>(false);

    // Collect unique tags
    const allTags = useMemo(() => {
        const tags = new Set<string>();
        charactersList.forEach(c => {
            if (c.tags) {
                c.tags.forEach(t => {
                    if (t !== 'Kopierer' && t !== 'Kopier-Alt' && t !== 'Copy-Alt' && t !== 'Copy-Char') {
                        tags.add(t);
                    }
                });
            }
        });
        return Array.from(tags).sort();
    }, [charactersList]);
    const [blueprintDetails, setBlueprintDetails] = useState<Record<string, BlueprintInfo>>({});
    const [marketPrices, setMarketPrices] = useState<Record<number, MarketPrice>>({});
    const [loadingBlueprints, setLoadingBlueprints] = useState<Record<string, boolean>>({});
    const [inputCostMode, setInputCostMode] = useState<'buy' | 'sell'>('buy');
    const [outputValueMode, setOutputValueMode] = useState<'buy' | 'sell'>('sell');

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

    // Load finances for all jobs in the background as soon as charactersData is loaded
    useEffect(() => {
        if (charactersData.length === 0) return;

        const uniqueBlueprints = new Map<string, {
            blueprintTypeId: number;
            activityId: number;
            productTypeId: number | null
        }>();
        charactersData.forEach(char => {
            if (char.jobs) {
                char.jobs.forEach(job => {
                    const bpKey = `${job.blueprintTypeId}_${job.activityId}`;
                    if (!uniqueBlueprints.has(bpKey)) {
                        uniqueBlueprints.set(bpKey, {
                            blueprintTypeId: job.blueprintTypeId,
                            activityId: job.activityId,
                            productTypeId: job.productTypeId
                        });
                    }
                });
            }
        });

        uniqueBlueprints.forEach((info) => {
            loadBlueprintFinances(info.blueprintTypeId, info.activityId, info.productTypeId);
        });
    }, [charactersData]);

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

    const loadBlueprintFinances = (blueprintTypeId: number, activityId: number, productTypeId: number | null) => {
        const bpKey = `${blueprintTypeId}_${activityId}`;
        if (blueprintDetails[bpKey] || loadingBlueprints[bpKey]) {
            return;
        }

        setLoadingBlueprints(prev => ({...prev, [bpKey]: true}));

        const url = new URL(apiDataUrl.replace('/data', '/blueprint-finances'), window.location.origin);
        url.searchParams.append('blueprintTypeId', blueprintTypeId.toString());
        url.searchParams.append('activityId', activityId.toString());
        if (productTypeId) {
            url.searchParams.append('productTypeId', productTypeId.toString());
        }

        fetch(url.toString())
            .then(res => {
                if (!res.ok) throw new Error();
                return res.json();
            })
            .then((data: { blueprintDetails: BlueprintInfo; marketPrices: Record<number, MarketPrice> }) => {
                setBlueprintDetails(prev => ({
                    ...prev,
                    [bpKey]: data.blueprintDetails
                }));
                setMarketPrices(prev => ({
                    ...prev,
                    ...data.marketPrices
                }));
                setLoadingBlueprints(prev => ({...prev, [bpKey]: false}));
            })
            .catch(() => {
                setLoadingBlueprints(prev => ({...prev, [bpKey]: false}));
            });
    };

    const calculateJobFinances = (job: IndustryJob) => {
        const bpKey = `${job.blueprintTypeId}_${job.activityId}`;
        const details = blueprintDetails[bpKey];
        const isLoading = loadingBlueprints[bpKey];

        const jobCost = parseFloat(job.cost || '0');

        if (isLoading) {
            return {
                materials: [],
                products: [],
                totalMaterialCost: 0,
                totalProductValue: 0,
                jobCost,
                totalCosts: 0,
                profit: 0,
                profitPercent: 0,
                hasData: false,
                loading: true
            };
        }
        if (!details) {
            return {
                materials: [],
                products: [],
                totalMaterialCost: 0,
                totalProductValue: 0,
                jobCost,
                totalCosts: 0,
                profit: 0,
                profitPercent: 0,
                hasData: false,
                loading: false
            };
        }

        const materials = details.materials || [];
        const products = details.products || [];

        let totalMaterialCost = 0;
        materials.forEach(m => {
            const price = marketPrices[m.typeId]?.[inputCostMode] ?? 0;
            totalMaterialCost += price * m.quantity * job.runs;
        });

        let totalProductValue = 0;
        products.forEach(p => {
            const price = marketPrices[p.typeId]?.[outputValueMode] ?? 0;
            totalProductValue += price * p.quantity * job.runs;
        });

        if (products.length === 0 && job.productTypeId) {
            const price = marketPrices[job.productTypeId]?.[outputValueMode] ?? 0;
            totalProductValue = price * job.runs;
        }

        const totalCosts = totalMaterialCost + jobCost;
        const profit = totalProductValue - totalCosts;
        const profitPercent = totalCosts > 0 ? (profit / totalCosts) * 100 : 0;

        return {
            materials,
            products,
            totalMaterialCost,
            totalProductValue,
            jobCost,
            totalCosts,
            profit,
            profitPercent,
            hasData: materials.length > 0 || totalProductValue > 0,
            loading: false
        };
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
                <span className="loader" style={{
                    display: 'inline-block',
                    width: '2rem',
                    height: '2rem',
                    border: '3px solid var(--theme-primary)',
                    borderRadius: '50%',
                    borderTopColor: 'transparent',
                    animation: 'spin 1s linear infinite'
                }}></span>
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
            <div className="box has-text-centered p-5" style={{borderColor: 'red'}}>
                <h3 className="title is-4" style={{color: '#ff4444'}}>Fehler</h3>
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
                        <p className="subtitle is-6 mb-1" style={{color: 'var(--theme-text-muted)'}}>Aktive Aufträge</p>
                        <p className="title is-3 mb-0" style={{color: 'var(--theme-primary)'}}>
                            {stats.totalActive}
                        </p>
                    </div>
                </div>
                <div className="column">
                    <div className="summary-card mb-0">
                        <p className="subtitle is-6 mb-1" style={{color: 'var(--theme-text-muted)'}}>Kosten aktiver
                            Aufträge</p>
                        <p className="title is-3 mb-0">
                            {formatISK(stats.totalCostActive)}
                        </p>
                    </div>
                </div>
                <div className="column">
                    <div className="summary-card mb-0">
                        <p className="subtitle is-6 mb-1" style={{color: 'var(--theme-text-muted)'}}>Durchgänge
                            gesamt</p>
                        <p className="title is-3 mb-0">
                            {stats.totalRunsActive}
                        </p>
                    </div>
                </div>
            </div>

            {/* Global Search & Filters */}
            <div className="box mb-4">
                <div className="columns is-multiline">
                    <div className="column is-6">
                        <input
                            type="text"
                            className="input input-dark"
                            placeholder="Nach Gegenstand oder Blueprint suchen..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>
                    <div className="column is-2">
                        <div className="select is-fullwidth">
                            <select
                                value={filterActivity}
                                onChange={(e) => setFilterActivity(e.target.value)}
                                className="input-dark"
                                style={{
                                    background: '#101525',
                                    color: '#fff',
                                    border: '1px solid var(--theme-card-border)'
                                }}
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
                <div className="columns is-multiline">
                    <div className="column is-2">
                        <div className="select is-fullwidth">
                            <select
                                value={inputCostMode}
                                onChange={(e) => setInputCostMode(e.target.value as 'buy' | 'sell')}
                                className="input-dark"
                                style={{
                                    background: '#101525',
                                    color: '#fff',
                                    border: '1px solid var(--theme-card-border)',
                                    width: '100%'
                                }}
                                title="Materialberechnung nach"
                            >
                                <option value="buy">Material: Jita Buy (Gefarmt)</option>
                                <option value="sell">Material: Jita Sell (Sofortkauf)</option>
                            </select>
                        </div>
                    </div>
                    <div className="column is-2">
                        <div className="select is-fullwidth">
                            <select
                                value={outputValueMode}
                                onChange={(e) => setOutputValueMode(e.target.value as 'buy' | 'sell')}
                                className="input-dark"
                                style={{
                                    background: '#101525',
                                    color: '#fff',
                                    border: '1px solid var(--theme-card-border)',
                                    width: '100%'
                                }}
                                title="Ergebnisberechnung nach"
                            >
                                <option value="buy">Ergebnis: Jita Buy (Sofortverkauf)</option>
                                <option value="sell">Ergebnis: Jita Sell (Verkaufsorder)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div className="columns is-multiline">
                    <div className="column is-12 mt-1"
                         style={{display: 'flex', flexWrap: 'wrap', gap: '1.5rem', alignItems: 'center'}}>
                        <button
                            className="button is-small"
                            onClick={() => setOnlyKopiererMode(prev => !prev)}
                            style={{
                                background: onlyKopiererMode ? 'var(--theme-primary)' : '#101525',
                                color: onlyKopiererMode ? '#000' : '#ccc',
                                border: '1px solid var(--theme-card-border)',
                                fontWeight: 'bold',
                                cursor: 'pointer',
                                padding: '0.25rem 0.6rem',
                                borderRadius: '4px',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '0.35rem'
                            }}
                        >
                            <span>📂</span>
                            <span>{onlyKopiererMode ? 'Alle Charaktere anzeigen' : 'Nur Kopier-Alt-Chars (komprimiert)'}</span>
                        </button>

                        {!onlyKopiererMode && (
                            <label className="checkbox" style={{
                                color: 'var(--theme-text-muted)',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '0.5rem',
                                cursor: 'pointer',
                                marginBottom: 0
                            }}>
                                <input
                                    type="checkbox"
                                    checked={hideEmptyCharacters}
                                    onChange={(e) => setHideEmptyCharacters(e.target.checked)}
                                    style={{accentColor: 'var(--theme-primary)'}}
                                />
                                <span>Charaktere ohne aktive Industrie-Jobs ausblenden</span>
                            </label>
                        )}
                    </div>
                </div>
            </div>

            {charactersData
                .filter((char) => {
                    const charObj = charactersList.find(c => c.id === char.id);
                    const isKopierer = charObj && charObj.tags && (
                        charObj.tags.includes('Kopierer') ||
                        charObj.tags.includes('Kopier-Alt') ||
                        charObj.tags.includes('Copy-Alt') ||
                        charObj.tags.includes('Copy-Char')
                    );

                    if (onlyKopiererMode) {
                        return isKopierer;
                    }

                    // Tag check
                    if (selectedTag !== 'all') {
                        if (!charObj || !charObj.tags || !charObj.tags.includes(selectedTag)) {
                            return false;
                        }
                    }
                    if (hideEmptyCharacters) {
                        const activeJobsCount = (char.jobs || []).filter(j => j.status === 'active').length;
                        return activeJobsCount > 0;
                    }
                    return true;
                })
                .map((char) => {
                    const lastSync = char.lastUpdate ? new Date(char.lastUpdate).toLocaleString('de-DE') : 'Nie';

                    // Process character jobs
                    const activeJobs = (char.jobs || []).filter(j => j.status === 'active');

                    if (onlyKopiererMode) {
                        return (
                            <div key={char.id} className="char-industry-box-compact" style={{
                                background: 'rgba(20, 27, 43, 0.45)',
                                border: '1px solid var(--theme-card-border)',
                                borderRadius: '8px',
                                marginBottom: '1rem',
                                padding: '0.75rem 1rem',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                flexWrap: 'wrap',
                                gap: '1rem',
                                boxShadow: '0 4px 6px rgba(0, 0, 0, 0.15)'
                            }}>
                                {/* Left Side: Portrait & Name */}
                                <div style={{display: 'flex', alignItems: 'center', gap: '0.75rem', minWidth: '220px'}}>
                                    <img
                                        src={getCharacterPortraitUrl(char.id)}
                                        alt={char.name}
                                        style={{
                                            width: '32px',
                                            height: '32px',
                                            borderRadius: '50%',
                                            border: '2px solid var(--theme-primary)'
                                        }}
                                    />
                                    <div>
                                        <h4 style={{
                                            fontWeight: 'bold',
                                            color: '#fff',
                                            margin: 0,
                                            fontSize: '0.95rem'
                                        }}>{char.name}</h4>
                                        <p style={{
                                            fontSize: '0.7rem',
                                            color: 'var(--theme-text-muted)',
                                            margin: 0
                                        }}>Sync: {lastSync}</p>
                                    </div>
                                </div>

                                {/* Middle Side: Jobs Summary */}
                                <div style={{
                                    flexGrow: 1,
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    gap: '0.5rem',
                                    alignItems: 'center'
                                }}>
                                    {char.error ? (
                                        <span style={{fontSize: '0.8rem', color: '#ff8888'}}>⚠️ {char.error}</span>
                                    ) : activeJobs.length === 0 ? (
                                        <span style={{
                                            fontSize: '0.8rem',
                                            fontWeight: 'bold',
                                            color: '#ffdd57',
                                            background: 'rgba(255, 221, 87, 0.1)',
                                            padding: '0.25rem 0.6rem',
                                            borderRadius: '4px',
                                            border: '1px solid rgba(255, 221, 87, 0.3)'
                                        }}>
                                        ⚠️ Keine aktiven Jobs (Bereit!)
                                    </span>
                                    ) : (
                                        <div style={{display: 'flex', flexWrap: 'wrap', gap: '0.4rem'}}>
                                            {activeJobs.map(job => {
                                                const endMs = new Date(job.endDate).getTime();
                                                const timeRemaining = endMs - nowTime;
                                                const isReady = timeRemaining <= 0;
                                                const activityColor = ACTIVITY_COLORS[job.activityId] || '#fff';

                                                return (
                                                    <div
                                                        key={job.jobId}
                                                        style={{
                                                            background: 'rgba(10, 15, 25, 0.6)',
                                                            border: `1px solid ${isReady ? '#00ffaa' : 'rgba(255, 255, 255, 0.08)'}`,
                                                            borderRadius: '4px',
                                                            padding: '0.2rem 0.5rem',
                                                            fontSize: '0.75rem',
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            gap: '0.35rem',
                                                            color: isReady ? '#00ffaa' : '#ccc'
                                                        }}
                                                    >
                                                    <span style={{
                                                        width: '6px',
                                                        height: '6px',
                                                        borderRadius: '50%',
                                                        background: activityColor,
                                                        display: 'inline-block'
                                                    }}></span>
                                                        <span style={{fontWeight: 'bold'}}>{job.blueprintName}</span>
                                                        <span
                                                            style={{color: 'var(--theme-text-muted)'}}>({job.runs}x)</span>
                                                        <span>-</span>
                                                        <span style={{fontWeight: isReady ? 'bold' : 'normal'}}>
                                                        {isReady ? 'Fertig' : formatDuration(timeRemaining)}
                                                    </span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {/* Right Side: Total Jobs Badge */}
                                <div style={{
                                    background: activeJobs.length > 0 ? 'rgba(0, 240, 255, 0.1)' : 'rgba(255, 68, 68, 0.1)',
                                    border: activeJobs.length > 0 ? '1px solid var(--theme-primary)' : '1px solid #ff4444',
                                    color: activeJobs.length > 0 ? 'var(--theme-primary)' : '#ff4444',
                                    fontWeight: 'bold',
                                    fontSize: '0.8rem',
                                    padding: '0.2rem 0.5rem',
                                    borderRadius: '4px',
                                    minWidth: '80px',
                                    textAlign: 'center'
                                }}>
                                    {activeJobs.length} Aktiv
                                </div>
                            </div>
                        );
                    }

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
                            // Sort jobs in group by end date, putting ready/finished jobs at the top
                            const sortedJobs = jobs.sort((a, b) => {
                                const endA = new Date(a.endDate).getTime();
                                const endB = new Date(b.endDate).getTime();
                                const readyA = endA <= nowTime;
                                const readyB = endB <= nowTime;

                                if (readyA && !readyB) {
                                    return -1;
                                }
                                if (!readyA && readyB) {
                                    return 1;
                                }
                                return endA - endB;
                            });
                            return {activityId, jobs: sortedJobs};
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
                                    <img src={getCharacterPortraitUrl(char.id)} alt={char.name}/>
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
                                    <div className="notification is-danger p-3 mb-0" style={{
                                        background: 'rgba(255, 68, 68, 0.12)',
                                        border: '1px solid #ff4444',
                                        color: '#ff8888',
                                        borderRadius: '6px'
                                    }}>
                                        {char.error}
                                    </div>
                                ) : filteredJobs.length === 0 ? (
                                    <div className="has-text-centered p-4" style={{color: 'var(--theme-text-muted)'}}>
                                        Keine aktiven Aufträge gefunden.
                                    </div>
                                ) : (
                                    <div>
                                        {sortedGroups.map(({activityId, jobs}) => {
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
                                                <details
                                                    key={activityId}
                                                    className="collapsible-activity-group"
                                                    onToggle={(e) => {
                                                        const target = e.target as HTMLDetailsElement;
                                                        if (target.open) {
                                                            jobs.forEach(job => {
                                                                loadBlueprintFinances(job.blueprintTypeId, job.activityId, job.productTypeId);
                                                            });
                                                        }
                                                    }}
                                                >
                                                    <summary>
                                                        <div className="group-title-side">
                                                            <span>{groupName}</span>
                                                        </div>
                                                        <div className="group-stats-side">
                                                            {runningCount > 0 && <span
                                                                style={{color: '#00f0ff'}}>{runningCount} laufend</span>}
                                                            {runningCount > 0 && readyCount > 0 && <span style={{
                                                                color: 'var(--theme-text-muted)',
                                                                margin: '0 4px'
                                                            }}>|</span>}
                                                            {readyCount > 0 && <span
                                                                style={{color: '#00ffaa'}}>{readyCount} bereit</span>}
                                                            <span style={{
                                                                fontSize: '0.6rem',
                                                                marginLeft: '0.4rem'
                                                            }}>▼</span>
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
                                                            const finances = calculateJobFinances(job);

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
                                                                                    <span
                                                                                        className="bp-ref">{job.blueprintName}</span>
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <div className="job-meta-line">
                                                                        <div className="meta-item">
                                                                            <span className="meta-label">Runs:</span>
                                                                            <span
                                                                                className="meta-value">{job.runs}</span>
                                                                        </div>
                                                                        <div className="meta-item"
                                                                             style={{flexGrow: 1, minWidth: '150px'}}>
                                                                            <span
                                                                                className="meta-label">Standort:</span>
                                                                            <span className="meta-value"
                                                                                  title={job.blueprintLocationName}>{job.blueprintLocationName}</span>
                                                                        </div>
                                                                    </div>

                                                                    {finances.loading ? (
                                                                        <div className="job-calculation-line" style={{
                                                                            display: 'flex',
                                                                            justifyContent: 'center',
                                                                            alignItems: 'center',
                                                                            fontSize: '0.8rem',
                                                                            padding: '0.5rem 0.75rem',
                                                                            background: 'rgba(0,0,0,0.15)',
                                                                            borderRadius: '4px',
                                                                            border: '1px solid rgba(255,255,255,0.03)',
                                                                            color: 'var(--theme-text-muted)'
                                                                        }}>
                                                                            <span className="loader" style={{
                                                                                display: 'inline-block',
                                                                                width: '1rem',
                                                                                height: '1rem',
                                                                                border: '2px solid var(--theme-primary)',
                                                                                borderRadius: '50%',
                                                                                borderTopColor: 'transparent',
                                                                                animation: 'spin 1s linear infinite',
                                                                                marginRight: '0.5rem'
                                                                            }}></span>
                                                                            Kalkulation wird berechnet...
                                                                        </div>
                                                                    ) : finances.hasData ? (
                                                                        <div className="job-calculation-line" style={{
                                                                            display: 'flex',
                                                                            justifyContent: 'space-between',
                                                                            fontSize: '0.8rem',
                                                                            padding: '0.5rem 0.75rem',
                                                                            background: 'rgba(0,0,0,0.15)',
                                                                            borderRadius: '4px',
                                                                            border: '1px solid rgba(255,255,255,0.03)',
                                                                            flexWrap: 'wrap',
                                                                            gap: '0.5rem'
                                                                        }}>
                                                                            <div>
                                                                                <span
                                                                                    style={{color: 'var(--theme-text-muted)'}}>Materialien ({inputCostMode === 'buy' ? 'Jita Buy' : 'Jita Sell'}): </span>
                                                                                <span style={{
                                                                                    fontWeight: 'bold',
                                                                                    color: '#eee'
                                                                                }}>{formatISK(finances.totalMaterialCost)}</span>
                                                                            </div>
                                                                            <div>
                                                                                <span
                                                                                    style={{color: 'var(--theme-text-muted)'}}>Job-Kosten: </span>
                                                                                <span style={{
                                                                                    fontWeight: 'bold',
                                                                                    color: '#eee'
                                                                                }}>{formatISK(finances.jobCost)}</span>
                                                                            </div>
                                                                            {!isResearch && (
                                                                                <>
                                                                                    <div>
                                                                                        <span
                                                                                            style={{color: 'var(--theme-text-muted)'}}>Ergebnis ({outputValueMode === 'buy' ? 'Jita Buy' : 'Jita Sell'}): </span>
                                                                                        <span style={{
                                                                                            fontWeight: 'bold',
                                                                                            color: finances.totalProductValue > 0 ? '#00f0ff' : '#eee'
                                                                                        }}>{formatISK(finances.totalProductValue)}</span>
                                                                                    </div>
                                                                                    <div>
                                                                                        <span
                                                                                            style={{color: 'var(--theme-text-muted)'}}>Gewinn: </span>
                                                                                        <span style={{
                                                                                            fontWeight: 'bold',
                                                                                            color: finances.profit >= 0 ? '#00ffaa' : '#ff4444'
                                                                                        }}>
                                                                                        {finances.profit >= 0 ? '+' : ''}{formatISK(finances.profit)} ({finances.profit >= 0 ? '+' : ''}{finances.profitPercent.toFixed(1)}%)
                                                                                    </span>
                                                                                    </div>
                                                                                </>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="job-calculation-line" style={{
                                                                            display: 'flex',
                                                                            justifyContent: 'center',
                                                                            fontSize: '0.8rem',
                                                                            padding: '0.5rem 0.75rem',
                                                                            background: 'rgba(0,0,0,0.15)',
                                                                            borderRadius: '4px',
                                                                            border: '1px solid rgba(255,255,255,0.03)',
                                                                            color: 'var(--theme-text-muted)'
                                                                        }}>
                                                                            <a
                                                                                href="#"
                                                                                onClick={(e) => {
                                                                                    e.preventDefault();
                                                                                    loadBlueprintFinances(job.blueprintTypeId, job.activityId, job.productTypeId);
                                                                                }}
                                                                                style={{
                                                                                    color: 'var(--theme-primary)',
                                                                                    textDecoration: 'underline'
                                                                                }}
                                                                            >
                                                                                Gewinn & Materialwerte laden
                                                                            </a>
                                                                        </div>
                                                                    )}

                                                                    {finances.materials.length > 0 && (
                                                                        <details style={{marginTop: '0.1rem'}}>
                                                                            <summary style={{
                                                                                fontSize: '0.75rem',
                                                                                color: 'var(--theme-primary)',
                                                                                cursor: 'pointer',
                                                                                userSelect: 'none',
                                                                                outline: 'none'
                                                                            }}>
                                                                                Material-Details anzeigen
                                                                            </summary>
                                                                            <div style={{
                                                                                marginTop: '0.4rem',
                                                                                background: 'rgba(0,0,0,0.2)',
                                                                                padding: '0.6rem',
                                                                                borderRadius: '4px',
                                                                                maxHeight: '150px',
                                                                                overflowY: 'auto',
                                                                                display: 'flex',
                                                                                flexDirection: 'column',
                                                                                gap: '0.35rem',
                                                                                border: '1px solid rgba(255,255,255,0.03)'
                                                                            }}>
                                                                                {finances.materials.map(m => {
                                                                                    const price = marketPrices[m.typeId]?.[inputCostMode] ?? 0;
                                                                                    const totalVal = price * m.quantity * job.runs;
                                                                                    return (
                                                                                        <div key={m.typeId} style={{
                                                                                            display: 'flex',
                                                                                            justifyContent: 'space-between',
                                                                                            fontSize: '0.75rem',
                                                                                            color: '#ccc'
                                                                                        }}>
                                                                                            <span>{m.quantity * job.runs}x {m.name}</span>
                                                                                            <span
                                                                                                style={{fontFamily: 'monospace'}}>{formatISK(totalVal)}</span>
                                                                                        </div>
                                                                                    );
                                                                                })}
                                                                            </div>
                                                                        </details>
                                                                    )}

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
                                                                            <span
                                                                                className="progress-percent">{percent.toFixed(1)}%</span>
                                                                            <span className="progress-countdown"
                                                                                  style={{color: timeRemaining > 0 ? 'var(--theme-primary)' : '#00ffaa'}}>
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
