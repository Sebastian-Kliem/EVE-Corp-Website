import React, {useState, useEffect, useMemo} from 'react';
import { cleanItemSearch } from '../../utils/itemSearch';

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
                    if (t !== 'Copy-Char') {
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
            <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6 text-center py-12">
                <span className="inline-block w-8 h-8 border-3 border-eve-primary rounded-full border-t-transparent animate-spin"></span>
                <p className="mt-3 text-eve-muted">Industriedaten werden geladen...</p>
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
            {/* Aggregated Stats Cards */}
            <div className="flex flex-wrap gap-6 mb-6">
                <div className="flex-1 min-w-[200px]">
                    <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                        <p className="text-xs text-eve-muted mb-1">Aktive Aufträge</p>
                        <p className="text-2xl font-bold text-eve-primary mb-0">
                            {stats.totalActive}
                        </p>
                    </div>
                </div>
                <div className="flex-1 min-w-[200px]">
                    <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                        <p className="text-xs text-eve-muted mb-1">Kosten aktiver Aufträge</p>
                        <p className="text-2xl font-bold text-white mb-0">
                            {formatISK(stats.totalCostActive)}
                        </p>
                    </div>
                </div>
                <div className="flex-1 min-w-[200px]">
                    <div className="bg-[#141b2b80] border border-eve-border rounded-lg p-5">
                        <p className="text-xs text-eve-muted mb-1">Durchgänge gesamt</p>
                        <p className="text-2xl font-bold text-white mb-0">
                            {stats.totalRunsActive}
                        </p>
                    </div>
                </div>
            </div>

            {/* Global Search & Filters */}
            <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                <div className="flex flex-wrap gap-4 mb-4">
                    <div className="flex-1 min-w-[280px]">
                        <input
                            type="text"
                            className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                            placeholder="Nach Gegenstand oder Blueprint suchen..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(cleanItemSearch(e.target.value))}
                        />
                    </div>
                    <div className="w-full md:w-1/4 min-w-[200px]">
                        <div className="relative w-full">
                            <select
                                value={filterActivity}
                                onChange={(e) => setFilterActivity(e.target.value)}
                                className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
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

                <div className="flex flex-wrap gap-4 mb-4">
                    <div className="flex-1 min-w-[200px]">
                        <div className="relative w-full">
                            <select
                                value={inputCostMode}
                                onChange={(e) => setInputCostMode(e.target.value as 'buy' | 'sell')}
                                className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                                title="Materialberechnung nach"
                            >
                                <option value="buy">Material: Jita Buy (Gefarmt)</option>
                                <option value="sell">Material: Jita Sell (Sofortkauf)</option>
                            </select>
                        </div>
                    </div>
                    <div className="flex-1 min-w-[200px]">
                        <div className="relative w-full">
                            <select
                                value={outputValueMode}
                                onChange={(e) => setOutputValueMode(e.target.value as 'buy' | 'sell')}
                                className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                                title="Ergebnisberechnung nach"
                            >
                                <option value="buy">Ergebnis: Jita Buy (Sofortverkauf)</option>
                                <option value="sell">Ergebnis: Jita Sell (Verkaufsorder)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap gap-4 items-center">
                    <div className="w-full mt-1 flex flex-wrap gap-6 items-center">
                        <button
                            className="inline-flex items-center justify-center border border-eve-border hover:border-eve-primary text-eve-text hover:text-eve-primary rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 cursor-pointer gap-1.5"
                            onClick={() => setOnlyKopiererMode(prev => !prev)}
                            title="Weise deinen Charakteren das Tag 'Copy-Char' in den Einstellungen zu, damit sie hier gruppiert werden."
                            style={{
                                background: onlyKopiererMode ? 'var(--theme-primary)' : 'rgba(16, 21, 37, 0.45)',
                                color: onlyKopiererMode ? '#000' : '#ccc',
                            }}
                        >
                            <span>📂</span>
                            <span>{onlyKopiererMode ? 'Alle Charaktere anzeigen' : 'Nur Kopier-Alt-Chars (komprimiert)'}</span>
                        </button>

                        {!onlyKopiererMode && (
                            <label className="flex items-center gap-2 cursor-pointer text-xs text-eve-muted select-none">
                                <input
                                    type="checkbox"
                                    checked={hideEmptyCharacters}
                                    onChange={(e) => setHideEmptyCharacters(e.target.checked)}
                                    className="accent-eve-primary"
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
                    const isKopierer = charObj && charObj.tags && charObj.tags.includes('Copy-Char');

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
                            <div key={char.id} className="bg-[#141b2b73] border border-eve-border rounded-lg mb-4 p-3 px-4 flex items-center justify-between flex-wrap gap-4 shadow-eve">
                                {/* Left Side: Portrait & Name */}
                                <div className="flex items-center gap-3 min-w-[220px]">
                                    <img
                                        src={getCharacterPortraitUrl(char.id)}
                                        alt={char.name}
                                        className="w-8 h-8 rounded-full border-2 border-eve-primary"
                                    />
                                    <div>
                                        <h4 className="font-bold text-white m-0 text-sm">{char.name}</h4>
                                        <p className="text-[10px] text-eve-muted m-0">Sync: {lastSync}</p>
                                    </div>
                                </div>

                                {/* Middle Side: Jobs Summary */}
                                <div className="flex-grow flex flex-wrap gap-2 items-center">
                                    {char.error ? (
                                        <span className="text-xs text-rose-400">⚠️ {char.error}</span>
                                    ) : activeJobs.length === 0 ? (
                                        <span className="text-[10px] font-bold text-amber-400 bg-amber-500/10 px-2 py-1 rounded border border-amber-500/20">
                                            ⚠️ Keine aktiven Jobs (Bereit!)
                                        </span>
                                    ) : (
                                        <div className="flex flex-wrap gap-1.5">
                                            {activeJobs.map(job => {
                                                const endMs = new Date(job.endDate).getTime();
                                                const timeRemaining = endMs - nowTime;
                                                const isReady = timeRemaining <= 0;
                                                const activityColor = ACTIVITY_COLORS[job.activityId] || '#fff';

                                                return (
                                                    <div
                                                        key={job.jobId}
                                                        className={`bg-black/30 border rounded px-2 py-0.5 text-xs flex items-center gap-1.5 ${isReady ? 'border-emerald-500/40 text-emerald-400' : 'border-white/5 text-[#ccc]'}`}
                                                    >
                                                        <span 
                                                            className="w-1.5 h-1.5 rounded-full inline-block"
                                                            style={{ background: activityColor }}
                                                        ></span>
                                                        <span className="font-bold">{job.blueprintName}</span>
                                                        <span className="text-eve-muted">({job.runs}x)</span>
                                                        <span>-</span>
                                                        <span className={isReady ? 'font-bold' : 'normal'}>
                                                            {isReady ? 'Fertig' : formatDuration(timeRemaining)}
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {/* Right Side: Total Jobs Badge */}
                                <div className={`font-bold text-xs px-2 py-1 rounded min-w-[80px] text-center border ${activeJobs.length > 0 ? 'bg-eve-primary/10 border-eve-primary/30 text-eve-primary' : 'bg-rose-500/10 border-rose-500/20 text-rose-400'}`}>
                                    {activeJobs.length} Aktiv
                                </div>
                            </div>
                        );
                    }

                    // Apply global search & filters
                    const cleanTerm = cleanItemSearch(searchTerm).toLowerCase().trim();
                    const filteredJobs = activeJobs.filter(job => {
                        const matchesSearch = !cleanTerm ||
                            job.blueprintName.toLowerCase().includes(cleanTerm) ||
                            (job.productName && job.productName.toLowerCase().includes(cleanTerm));

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
                        <div key={char.id} className="bg-[#141b2b73] border border-eve-border rounded-lg mb-6 overflow-hidden shadow-eve">
                            <div className="flex items-center justify-between bg-black/30 p-4 border-b border-eve-border flex-wrap gap-3">
                                <div className="flex items-center gap-3">
                                    <img src={getCharacterPortraitUrl(char.id)} alt={char.name} className="w-10 h-10 rounded-full border-2 border-eve-primary"/>
                                    <div>
                                        <h3 className="font-bold text-lg text-white m-0">{char.name}</h3>
                                        <p className="text-xs text-eve-muted m-0">Sync: {lastSync}</p>
                                    </div>
                                </div>
                                <div className="bg-eve-primary/10 border border-eve-primary/30 text-eve-primary font-bold text-xs px-2.5 py-1 rounded">
                                    {activeJobs.length} aktive Aufträge
                                </div>
                            </div>

                            <div className="p-5">
                                {char.error ? (
                                    <div className="py-5 px-6 rounded-lg mb-6 bg-rose-500/10 border border-rose-500/30 text-rose-400 mb-0">
                                        {char.error}
                                    </div>
                                ) : filteredJobs.length === 0 ? (
                                    <div className="text-center p-4 text-eve-muted text-xs">
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
                                                    className="mb-4 border border-white/10 rounded-lg overflow-hidden bg-black/10 last:mb-0 group"
                                                    onToggle={(e) => {
                                                        const target = e.target as HTMLDetailsElement;
                                                        if (target.open) {
                                                            jobs.forEach(job => {
                                                                loadBlueprintFinances(job.blueprintTypeId, job.activityId, job.productTypeId);
                                                            });
                                                        }
                                                    }}
                                                >
                                                    <summary className="flex justify-between items-center p-3 px-4 bg-eve-card cursor-pointer font-bold text-white select-none transition-colors duration-200 hover:bg-black/20 list-none">
                                                        <div className="flex items-center gap-2">
                                                            <span className="w-2 h-2 rounded-full" style={{ backgroundColor: groupColor }}></span>
                                                            <span className="text-sm">{groupName}</span>
                                                        </div>
                                                        <div className="flex items-center gap-2 text-xs text-eve-muted font-normal">
                                                            {runningCount > 0 && <span className="text-eve-primary">{runningCount} laufend</span>}
                                                            {runningCount > 0 && readyCount > 0 && <span className="text-white/20 mx-1">|</span>}
                                                            {readyCount > 0 && <span className="text-emerald-400">{readyCount} bereit</span>}
                                                            <span className="text-[10px] text-eve-primary transition-transform duration-200 group-open:rotate-180 ml-1">▼</span>
                                                        </div>
                                                    </summary>

                                                    <div className="p-4 border-t border-white/5 flex flex-col gap-3 bg-[#0a0f1d]/20">
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
                                                                <div key={job.jobId} className="bg-[#0a0f1d]/20 border border-white/5 rounded-lg p-4 flex flex-col gap-3 transition-colors duration-200 hover:border-eve-primary/30">
                                                                    <div className="flex items-center justify-between flex-wrap gap-2">
                                                                        <div className="flex items-center gap-3">
                                                                            <img
                                                                                src={getItemIconUrl(iconTypeId)}
                                                                                alt={iconName}
                                                                                className="w-9 h-9 rounded border border-eve-border"
                                                                            />
                                                                            <div>
                                                                                <h4 className="font-bold text-white m-0 text-sm">{iconName}</h4>
                                                                                <p className="text-xs text-eve-muted m-0">
                                                                                    {isResearch ? 'Forschung an: ' : 'Hergestellt aus: '}
                                                                                    <span className="text-[#bbb]">{job.blueprintName}</span>
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <div className="flex flex-wrap gap-6 text-xs text-[#ccc] bg-black/25 p-2 px-3 rounded">
                                                                        <div className="flex gap-1">
                                                                            <span className="text-eve-muted">Runs:</span>
                                                                            <span className="font-semibold">{job.runs}</span>
                                                                        </div>
                                                                        <div className="flex gap-1 flex-grow min-w-[150px]">
                                                                            <span className="text-eve-muted">Standort:</span>
                                                                            <span className="font-semibold truncate" title={job.blueprintLocationName}>{job.blueprintLocationName}</span>
                                                                        </div>
                                                                    </div>

                                                                    {finances.loading ? (
                                                                        <div className="flex justify-center items-center text-xs p-2.5 px-3 bg-black/15 rounded border border-white/5 text-eve-muted">
                                                                            <span className="inline-block w-4 h-4 border-2 border-eve-primary rounded-full border-t-transparent animate-spin mr-2"></span>
                                                                            Kalkulation wird berechnet...
                                                                        </div>
                                                                    ) : finances.hasData ? (
                                                                        <div className="flex justify-between text-xs p-2.5 px-3 bg-black/15 rounded border border-white/5 flex-wrap gap-2">
                                                                            <div>
                                                                                <span className="text-eve-muted">Materialien ({inputCostMode === 'buy' ? 'Jita Buy' : 'Jita Sell'}): </span>
                                                                                <span className="font-bold text-[#eee]">{formatISK(finances.totalMaterialCost)}</span>
                                                                            </div>
                                                                            <div>
                                                                                <span className="text-eve-muted">Job-Kosten: </span>
                                                                                <span className="font-bold text-[#eee]">{formatISK(finances.jobCost)}</span>
                                                                            </div>
                                                                            {!isResearch && (
                                                                                <>
                                                                                    <div>
                                                                                        <span className="text-eve-muted">Ergebnis ({outputValueMode === 'buy' ? 'Jita Buy' : 'Jita Sell'}): </span>
                                                                                        <span className={`font-bold ${finances.totalProductValue > 0 ? 'text-eve-primary' : 'text-[#eee]'}`}>{formatISK(finances.totalProductValue)}</span>
                                                                                    </div>
                                                                                    <div>
                                                                                        <span className="text-eve-muted">Gewinn: </span>
                                                                                        <span className={`font-bold ${finances.profit >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                                                                                            {finances.profit >= 0 ? '+' : ''}{formatISK(finances.profit)} ({finances.profit >= 0 ? '+' : ''}{finances.profitPercent.toFixed(1)}%)
                                                                                        </span>
                                                                                    </div>
                                                                                </>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="flex justify-center text-xs p-2.5 px-3 bg-black/15 rounded border border-white/5 text-eve-muted">
                                                                            <a
                                                                                href="#"
                                                                                onClick={(e) => {
                                                                                    e.preventDefault();
                                                                                    loadBlueprintFinances(job.blueprintTypeId, job.activityId, job.productTypeId);
                                                                                }}
                                                                                className="text-eve-primary underline"
                                                                            >
                                                                                Gewinn & Materialwerte laden
                                                                            </a>
                                                                        </div>
                                                                    )}

                                                                    {finances.materials.length > 0 && (
                                                                        <details className="mt-0.5 group/details">
                                                                            <summary className="text-xs text-eve-primary cursor-pointer select-none outline-none flex items-center gap-1.5">
                                                                                <span>Material-Details anzeigen</span>
                                                                                <span className="text-[9px] transition-transform duration-200 group-open/details:rotate-180">▼</span>
                                                                            </summary>
                                                                            <div className="mt-2 bg-black/20 p-3 rounded max-h-[150px] overflow-y-auto flex flex-col gap-1.5 border border-white/5">
                                                                                {finances.materials.map(m => {
                                                                                    const price = marketPrices[m.typeId]?.[inputCostMode] ?? 0;
                                                                                    const totalVal = price * m.quantity * job.runs;
                                                                                    return (
                                                                                        <div key={m.typeId} className="flex justify-between text-xs text-[#ccc]">
                                                                                            <span>{m.quantity * job.runs}x {m.name}</span>
                                                                                            <span className="font-mono">{formatISK(totalVal)}</span>
                                                                                        </div>
                                                                                    );
                                                                                })}
                                                                            </div>
                                                                        </details>
                                                                    )}

                                                                    <div className="mt-2">
                                                                        <div className="bg-white/10 h-1.5 rounded-full overflow-hidden">
                                                                            <div
                                                                                className="h-full rounded-full transition-all duration-500"
                                                                                style={{
                                                                                    width: `${percent}%`,
                                                                                    backgroundColor: groupColor
                                                                                }}
                                                                            ></div>
                                                                        </div>
                                                                        <div className="flex justify-between text-xs font-semibold mt-1">
                                                                            <span className="text-white/90">{percent.toFixed(1)}%</span>
                                                                            <span className={`font-bold ${timeRemaining > 0 ? 'text-eve-primary' : 'text-emerald-400'}`}>
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
