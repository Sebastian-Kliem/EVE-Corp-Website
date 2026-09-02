import React, { useState, useMemo } from 'react';

interface ActiveJob {
    activityId: number; // 3 = TE, 4 = ME, 5 = Copying
    endDate: string; // ISO string
    runs: number;
    status: string;
}

interface BlueprintData {
    itemId: string;
    typeId: number;
    name: string;
    category: string;
    ownerCharacterName: string;
    ownerUserName: string;
    locationName: string;
    systemName: string;
    isBpo: boolean;
    me: number;
    te: number;
    runs: number;
    quantity: number;
    productId: number;
    activeJob: ActiveJob | null;
}

interface BlueprintVaultProps {
    blueprints: BlueprintData[];
    imagePaths: {
        types: string;
    };
}

const getMeTagClass = (me: number) => {
    const base = "inline-flex items-center justify-center h-[22px] min-w-[52px] px-1.5 text-xs font-bold rounded leading-none";
    if (me === 10) {
        return `${base} bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-[0_0_8px_rgba(16,185,129,0.2)]`;
    }
    if (me === 9) {
        return `${base} bg-amber-500/20 text-amber-400 border border-amber-500/40 shadow-[0_0_8px_rgba(245,158,11,0.2)]`;
    }
    return `${base} bg-black/40 border border-white/10 text-sky-400`;
};

const getTeTagClass = (te: number) => {
    const base = "inline-flex items-center justify-center h-[22px] min-w-[52px] px-1.5 text-xs font-bold rounded leading-none";
    if (te === 20) {
        return `${base} bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-[0_0_8px_rgba(16,185,129,0.2)]`;
    }
    if (te === 18) {
        return `${base} bg-amber-500/20 text-amber-400 border border-amber-500/40 shadow-[0_0_8px_rgba(245,158,11,0.2)]`;
    }
    return `${base} bg-black/40 border border-white/10 text-emerald-400`;
};

export default function BlueprintVault({ blueprints, imagePaths }: BlueprintVaultProps) {
    const [searchQuery, setSearchQuery] = useState('');
    const [filterType, setFilterType] = useState<'all' | 'bpo' | 'bpc' | 'job'>('all');
    const [selectedCategory, setSelectedCategory] = useState<string>('all');
    const [hoveredItemId, setHoveredItemId] = useState<string | null>(null);

    // Collect all unique categories
    const categories = useMemo(() => {
        const cats = new Set<string>();
        blueprints.forEach(bp => {
            if (bp.category) {
                cats.add(bp.category);
            }
        });
        return Array.from(cats).sort();
    }, [blueprints]);

    const filteredBlueprints = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();
        const queryTerms = query ? query.split(/\s+/).filter(Boolean) : [];

        return blueprints.filter((bp) => {
            // Tab filter
            if (filterType === 'bpo' && !bp.isBpo) return false;
            if (filterType === 'bpc' && bp.isBpo) return false;
            if (filterType === 'job' && bp.activeJob === null) return false;

            // Category filter
            if (selectedCategory !== 'all' && bp.category !== selectedCategory) return false;

            // Search filter
            if (queryTerms.length > 0) {
                const activityText = bp.activeJob
                    ? (bp.activeJob.activityId === 4
                        ? 'materialforschung me research'
                        : bp.activeJob.activityId === 3
                        ? 'zeiteffizienz te research'
                        : bp.activeJob.activityId === 5
                        ? 'kopieren copy bpc'
                        : 'forschung job in arbeit')
                    : 'bereit hangar';

                const bpoBpcText = bp.isBpo ? 'original bpo originale' : 'kopie bpc kopien copy';

                const searchableText = [
                    bp.name || '',
                    bp.category || '',
                    bp.ownerCharacterName || '',
                    bp.ownerUserName || '',
                    bp.locationName || '',
                    bp.systemName || '',
                    bpoBpcText,
                    activityText,
                    `me:${bp.me ?? 0}`,
                    `te:${bp.te ?? 0}`,
                    `me ${bp.me ?? 0}`,
                    `te ${bp.te ?? 0}`,
                    `me${bp.me ?? 0}`,
                    `te${bp.te ?? 0}`,
                ].join(' ').toLowerCase();

                const matchesAllTerms = queryTerms.every((term) => searchableText.includes(term));
                if (!matchesAllTerms) {
                    return false;
                }
            }

            return true;
        });
    }, [blueprints, searchQuery, filterType, selectedCategory]);

    const getBlueprintIconUrl = (bp: BlueprintData) => {
        const action = bp.isBpo ? 'bp' : 'bpc';
        // Replace '12345' with the typeId and 'icon' with the blueprint action 'bp' or 'bpc'
        return imagePaths.types
            .replace('12345', bp.typeId.toString())
            .replace('/icon', '/' + action);
    };

    const getProductIconUrl = (bp: BlueprintData) => {
        return imagePaths.types.replace('12345', bp.productId.toString());
    };

    const formatJobDetails = (bp: BlueprintData, job: ActiveJob) => {
        const endDateStr = new Date(job.endDate).toLocaleString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });

        if (job.activityId === 4) {
            const nextMe = Math.min(10, bp.me + job.runs);
            return (
                <div className="inline-flex flex-col items-start px-2.5 py-1.5 rounded text-xs bg-amber-500/15 text-amber-400 border border-amber-500/30 h-auto font-semibold">
                    <span>🔬 Materialforschung</span>
                    <span className="text-[10px] font-normal text-eve-muted">Fertig: {endDateStr}</span>
                    <span className="text-[10px] font-semibold">Ergebnis: ME {nextMe}</span>
                </div>
            );
        }

        if (job.activityId === 3) {
            const nextTe = Math.min(20, bp.te + job.runs * 2);
            return (
                <div className="inline-flex flex-col items-start px-2.5 py-1.5 rounded text-xs bg-sky-500/15 text-sky-400 border border-sky-500/30 h-auto font-semibold">
                    <span>⏳ Zeiteffizienzforschung</span>
                    <span className="text-[10px] font-normal text-eve-muted">Fertig: {endDateStr}</span>
                    <span className="text-[10px] font-semibold">Ergebnis: TE {nextTe}</span>
                </div>
            );
        }

        if (job.activityId === 5) {
            return (
                <div className="inline-flex flex-col items-start px-2.5 py-1.5 rounded text-xs bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 h-auto font-semibold">
                    <span>🖨️ Kopieren</span>
                    <span className="text-[10px] font-normal text-eve-muted">Fertig: {endDateStr}</span>
                    <span className="text-[10px] font-semibold">Ergebnis: {job.runs} Kopien (BPC)</span>
                </div>
            );
        }

        return <span className="inline-flex items-center justify-center px-3 py-1 text-xs font-semibold rounded bg-white/10 text-white border border-white/10">In Arbeit</span>;
    };

    return (
        <div>
            {/* Search and Filters */}
            <div className="flex flex-wrap items-center gap-4 mb-4">
                <div className="flex-1 min-w-[250px]">
                    <p className="text-xs text-eve-muted">Durchsuche alle von Corp-Mitgliedern geteilten Blueprints.</p>
                </div>
                <div className="flex-shrink-0">
                    <div className="mb-0">
                        <div className="relative block">
                            <select
                                value={filterType}
                                onChange={(e) => setFilterType(e.target.value as any)}
                                className="rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                            >
                                <option value="all">Alle Typen ({blueprints.length})</option>
                                <option value="bpo">Originale (BPOs) ({blueprints.filter(b => b.isBpo).length})</option>
                                <option value="bpc">Kopien (BPCs) ({blueprints.filter(b => !b.isBpo).length})</option>
                                <option value="job">In Forschung/Kopie ({blueprints.filter(b => b.activeJob !== null).length})</option>
                            </select>
                        </div>
                    </div>
                </div>
                {categories.length > 0 && (
                    <div className="flex-shrink-0">
                        <div className="mb-0">
                            <div className="relative block">
                                <select
                                    value={selectedCategory}
                                    onChange={(e) => setSelectedCategory(e.target.value)}
                                    className="rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                >
                                    <option value="all">Alle Kategorien</option>
                                    {categories.map(cat => (
                                        <option key={cat} value={cat}>{cat}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>
                )}
                <div className="flex-shrink-0">
                    <div className="mb-0">
                        <div className="relative">
                            <input
                                className="rounded-lg text-xs pl-8 pr-8 py-1.5 w-[250px] border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                                type="text"
                                placeholder="Blueprints suchen..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                            <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-eve-muted pointer-events-none">🔍</span>
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery('')}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-eve-muted hover:text-white transition-colors"
                                    title="Suche zurücksetzen"
                                >
                                    ✕
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Blueprint Vault List */}
            {filteredBlueprints.length === 0 ? (
                <div className="text-center py-12 rounded-lg bg-[#13192b] border border-white/5">
                    <p className="text-eve-muted">Keine passenden Blueprints im Tresor gefunden.</p>
                </div>
            ) : (
                <div style={{ overflowX: 'auto' }}>
                    <table className="w-full border-collapse text-left bg-[#101525] text-eve-text min-w-[800px]">
                        <thead>
                            <tr className="bg-white/2">
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50 w-[50px]">Icon</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50">Name</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50 w-[120px]">Typ</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50 w-[150px]">ME / TE</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50 w-[200px]">Eigentümer</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50">Standort</th>
                                <th className="font-semibold text-eve-muted p-3 text-xs border-b border-eve-border bg-[#0d121fe6]/50 w-[250px]">Forschung / Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/5">
                            {filteredBlueprints.map((bp) => {
                                const isHovered = hoveredItemId === bp.itemId;
                                return (
                                    <tr key={bp.itemId} className="hover:bg-white/2 transition-colors duration-150">
                                        <td className="p-3 text-sm align-middle">
                                            <div 
                                                className="relative w-8 h-8 cursor-help"
                                                onMouseEnter={() => setHoveredItemId(bp.itemId)}
                                                onMouseLeave={() => setHoveredItemId(null)}
                                                title="Fahre mit der Maus darüber, um das fertige Produkt zu sehen"
                                            >
                                                <img
                                                    src={getBlueprintIconUrl(bp)}
                                                    alt={bp.name}
                                                    className={`absolute top-0 left-0 w-8 h-8 rounded transition-opacity duration-200 ease-in-out ${isHovered ? 'opacity-0 z-10' : 'opacity-100 z-20'}`}
                                                    loading="lazy"
                                                />
                                                <img
                                                    src={getProductIconUrl(bp)}
                                                    alt={bp.name}
                                                    className={`absolute top-0 left-0 w-8 h-8 rounded transition-opacity duration-200 ease-in-out ${isHovered ? 'opacity-100 z-20' : 'opacity-0 z-10'}`}
                                                    loading="lazy"
                                                />
                                            </div>
                                        </td>
                                    <td className="p-3 text-sm font-semibold align-middle">
                                        {bp.name}
                                        {bp.quantity > 1 && (
                                            <span className="font-normal text-eve-muted ml-1.5">
                                                (x{bp.quantity})
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 text-sm align-middle">
                                        {bp.isBpo ? (
                                            <span className="inline-flex items-center justify-center px-2 py-1 text-xs font-semibold rounded bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">Original (BPO)</span>
                                        ) : (
                                            <span className="inline-flex flex-col items-center justify-center px-2 py-1 text-xs font-semibold rounded bg-sky-500/15 text-sky-400 border border-sky-500/30 text-center leading-tight">
                                                <span>Kopie (BPC)</span>
                                                <span className="text-[10px] font-semibold">{bp.runs} Runs übrig</span>
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 text-sm align-middle">
                                        <div className="flex gap-1.5">
                                            <span className={getMeTagClass(bp.me)}>
                                                ME: {bp.me}
                                            </span>
                                            <span className={getTeTagClass(bp.te)}>
                                                TE: {bp.te}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="p-3 text-sm align-middle">
                                        <span className="font-semibold">{bp.ownerUserName}</span>
                                    </td>
                                    <td className="p-3 text-sm align-middle">
                                        <div>
                                            <span className="font-semibold text-eve-primary">{bp.systemName}</span>
                                            <div className="text-xs text-eve-muted">{bp.locationName}</div>
                                        </div>
                                    </td>
                                    <td className="p-3 text-sm align-middle">
                                        {bp.activeJob ? formatJobDetails(bp, bp.activeJob) : (
                                            <span className="text-eve-muted text-xs">Bereit (Hangar)</span>
                                        )}
                                    </td>
                                </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
