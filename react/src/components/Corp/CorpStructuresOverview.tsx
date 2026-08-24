import React, { useState, useMemo } from 'react';

interface FittingCharge {
    itemId: number;
    typeId: number;
    typeName: string;
    quantity: number;
    locationFlag: string;
}

interface FittingItem {
    itemId: number;
    typeId: number;
    typeName: string;
    locationFlag: string;
    slotIndex?: number | null;
    quantity: number;
    charges?: FittingCharge[];
}

interface StructureFittings {
    services?: FittingItem[];
    rigs?: FittingItem[];
    high?: FittingItem[];
    medium?: FittingItem[];
    low?: FittingItem[];
    fuel?: FittingItem[];
    fighters?: FittingItem[];
    cargo?: FittingItem[];
    other?: FittingItem[];
}

interface StructureService {
    name: string;
    state: string; // 'online' | 'offline' | etc.
}

interface UpwellStructure {
    id: string;
    name: string;
    typeId: number;
    typeName: string;
    solarSystemId: number;
    solarSystemName: string;
    state: string;
    fuelExpires: string | null; // ISO string
    services?: StructureService[];
    reinforceHour: number | null;
    lastUpdated: string | null;
    fittings?: StructureFittings;
}

interface StarbaseFuel {
    typeId: number;
    typeName: string;
    quantity: number;
}

interface Starbase {
    id: string;
    typeId: number;
    typeName: string;
    solarSystemId: number;
    solarSystemName: string;
    state: string;
    onlinedSince: string | null;
    reinforcedUntil: string | null;
    fuels?: StarbaseFuel[];
    modules?: any[];
    lastUpdated: string | null;
}

interface CorporationInfo {
    id: number;
    name: string;
    ticker: string;
}

interface CorporationData {
    corporation: CorporationInfo;
    structures?: UpwellStructure[];
    starbases?: Starbase[];
}

interface CorpStructuresOverviewProps {
    corpsData?: CorporationData[];
    imagePaths: {
        types: string;
        corporations: string;
        renders?: string;
    };
}

export default function CorpStructuresOverview({
    corpsData = [],
    imagePaths,
}: CorpStructuresOverviewProps) {
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'online' | 'offline' | 'reinforced' | 'low_fuel'>('all');
    const [typeFilter, setTypeFilter] = useState<'all' | 'upwell' | 'starbase'>('all');
    const [selectedCorpId, setSelectedCorpId] = useState<number | 'all'>('all');
    const [expandedStructureIds, setExpandedStructureIds] = useState<Record<string, boolean>>({});

    // Tracks expanded state for each corporation card (default: collapsed)
    const [expandedCorps, setExpandedCorps] = useState<Record<number, boolean>>({});

    const toggleCorpExpanded = (corpId: number) => {
        setExpandedCorps(prev => ({
            ...prev,
            [corpId]: !prev[corpId],
        }));
    };

    const toggleStructureExpanded = (id: string) => {
        setExpandedStructureIds(prev => ({
            ...prev,
            [id]: !prev[id],
        }));
    };

    // Calculate fuel remaining helper
    const getFuelRemaining = (expiresAt: string | null) => {
        if (!expiresAt) return null;
        const expireDate = new Date(expiresAt);
        const now = new Date();
        const diffMs = expireDate.getTime() - now.getTime();

        if (diffMs <= 0) {
            return {
                expired: true,
                days: 0,
                hours: 0,
                label: 'Treibstoff abgelaufen',
                isLow: true,
                isCritical: true,
            };
        }

        const totalHours = Math.floor(diffMs / (1000 * 60 * 60));
        const days = Math.floor(totalHours / 24);
        const hours = totalHours % 24;

        let label = '';
        if (days > 0) {
            label = `${days}d ${hours}h`;
        } else {
            label = `${hours}h`;
        }

        return {
            expired: false,
            days,
            hours,
            totalHours,
            label,
            isLow: days <= 7,
            isCritical: days < 1,
        };
    };

    // Filter structures & starbases
    const filteredCorps = useMemo(() => {
        return corpsData
            .filter(cData => {
                if (selectedCorpId !== 'all' && cData.corporation?.id !== selectedCorpId) {
                    return false;
                }
                return true;
            })
            .map(cData => {
                const query = searchQuery.toLowerCase().trim();
                const structures = cData.structures || [];
                const starbases = cData.starbases || [];

                // Filter Upwell structures
                const filteredStructures = (typeFilter === 'starbase' ? [] : structures).filter(s => {
                    const fittings = s.fittings || {};
                    const services = s.services || [];

                    // Search query
                    if (query !== '') {
                        const matchName = (s.name || '').toLowerCase().includes(query);
                        const matchType = (s.typeName || '').toLowerCase().includes(query);
                        const matchSystem = (s.solarSystemName || '').toLowerCase().includes(query);
                        const matchServices = services.some(srv => (srv.name || '').toLowerCase().includes(query));
                        const matchFittings = Object.values(fittings).some(list =>
                            Array.isArray(list) && list.some(f => (f.typeName || '').toLowerCase().includes(query))
                        );

                        if (!matchName && !matchType && !matchSystem && !matchServices && !matchFittings) {
                            return false;
                        }
                    }

                    // Status filter
                    const stateLower = (s.state || '').toLowerCase();
                    const fuelInfo = getFuelRemaining(s.fuelExpires);

                    if (statusFilter === 'online' && stateLower !== 'online') return false;
                    if (statusFilter === 'offline' && stateLower !== 'offline') return false;
                    if (statusFilter === 'reinforced' && !stateLower.includes('reinforce') && !stateLower.includes('armor') && !stateLower.includes('hull')) return false;
                    if (statusFilter === 'low_fuel' && (!fuelInfo || !fuelInfo.isLow)) return false;

                    return true;
                });

                // Filter Starbases
                const filteredStarbases = (typeFilter === 'upwell' ? [] : starbases).filter(sb => {
                    // Search query
                    if (query !== '') {
                        const matchType = (sb.typeName || '').toLowerCase().includes(query);
                        const matchSystem = (sb.solarSystemName || '').toLowerCase().includes(query);
                        if (!matchType && !matchSystem) {
                            return false;
                        }
                    }

                    // Status filter
                    const stateLower = (sb.state || '').toLowerCase();
                    if (statusFilter === 'online' && stateLower !== 'online') return false;
                    if (statusFilter === 'offline' && stateLower !== 'offline') return false;
                    if (statusFilter === 'reinforced' && !stateLower.includes('reinforce')) return false;
                    if (statusFilter === 'low_fuel') return false;

                    return true;
                });

                return {
                    ...cData,
                    structures: filteredStructures,
                    starbases: filteredStarbases,
                };
            })
            .filter(cData => {
                if (searchQuery.trim() !== '' || statusFilter !== 'all' || typeFilter !== 'all') {
                    return (cData.structures?.length || 0) > 0 || (cData.starbases?.length || 0) > 0;
                }
                return true;
            });
    }, [corpsData, searchQuery, statusFilter, typeFilter, selectedCorpId]);

    // Total stats
    const totalStats = useMemo(() => {
        let structuresCount = 0;
        let starbasesCount = 0;
        let onlineCount = 0;
        let lowFuelCount = 0;
        let reinforcedCount = 0;

        corpsData.forEach(c => {
            const structures = c.structures || [];
            const starbases = c.starbases || [];

            structuresCount += structures.length;
            starbasesCount += starbases.length;

            structures.forEach(s => {
                const stateLower = (s.state || '').toLowerCase();
                if (stateLower === 'online') onlineCount++;
                if (stateLower.includes('reinforce') || stateLower.includes('armor') || stateLower.includes('hull')) {
                    reinforcedCount++;
                }
                const fuel = getFuelRemaining(s.fuelExpires);
                if (fuel && fuel.isLow) lowFuelCount++;
            });

            starbases.forEach(sb => {
                const stateLower = (sb.state || '').toLowerCase();
                if (stateLower === 'online') onlineCount++;
                if (stateLower.includes('reinforce')) reinforcedCount++;
            });
        });

        return {
            total: structuresCount + starbasesCount,
            structuresCount,
            starbasesCount,
            onlineCount,
            lowFuelCount,
            reinforcedCount,
        };
    }, [corpsData]);

    const getCorpLogoUrl = (corpId: number) => {
        return imagePaths.corporations.replace('12345', corpId.toString());
    };

    const getTypeIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', typeId.toString());
    };

    const getTypeRenderUrl = (typeId: number) => {
        if (imagePaths.renders) {
            return imagePaths.renders.replace('12345', typeId.toString());
        }
        return getTypeIconUrl(typeId);
    };

    const getStateBadge = (state: string) => {
        const s = (state || '').toLowerCase();
        if (s === 'online') {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Online
                </span>
            );
        }
        if (s === 'offline') {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                    <span className="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                    Offline
                </span>
            );
        }
        if (s.includes('anchoring') || s.includes('anchor')) {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                    <span className="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    Verankerung
                </span>
            );
        }
        if (s.includes('unanchor')) {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-500/15 text-purple-400 border border-purple-500/30">
                    <span className="w-1.5 h-1.5 rounded-full bg-purple-400"></span>
                    Abbau (Unanchoring)
                </span>
            );
        }
        if (s.includes('reinforce') || s.includes('armor') || s.includes('hull')) {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-600/20 text-red-400 border border-red-500/40">
                    <span className="w-1.5 h-1.5 rounded-full bg-red-400 animate-ping"></span>
                    Reinforced ({state})
                </span>
            );
        }
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-500/15 text-slate-300 border border-slate-500/30">
                {state}
            </span>
        );
    };

    if (corpsData.length === 0) {
        return (
            <div className="bg-[#111625] border border-white/10 rounded-xl p-10 text-center shadow-eve">
                <div className="text-5xl mb-4">🏢</div>
                <h3 className="text-xl font-bold text-white mb-2">Keine Corporation-Stationen gefunden</h3>
                <p className="text-sm text-eve-muted max-w-md mx-auto">
                    Es wurden für deine Charaktere keine Corporation-Strukturen gefunden oder es ist noch kein Director/CEO mit ESI-Berechtigungen eingeloggt.
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {/* Summary statistics bar */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div className="bg-[#111625]/90 border border-white/5 p-4 rounded-xl shadow-eve">
                    <div className="text-xs font-medium text-eve-muted uppercase tracking-wider">Gesamt-Strukturen</div>
                    <div className="text-2xl font-bold text-white mt-1">{totalStats.total}</div>
                    <div className="text-xs text-eve-muted mt-0.5">
                        {totalStats.structuresCount} Upwell · {totalStats.starbasesCount} POS
                    </div>
                </div>

                <div className="bg-[#111625]/90 border border-white/5 p-4 rounded-xl shadow-eve">
                    <div className="text-xs font-medium text-eve-muted uppercase tracking-wider">Online</div>
                    <div className="text-2xl font-bold text-emerald-400 mt-1">{totalStats.onlineCount}</div>
                    <div className="text-xs text-eve-muted mt-0.5">Aktiv in Betrieb</div>
                </div>

                <div className="bg-[#111625]/90 border border-white/5 p-4 rounded-xl shadow-eve">
                    <div className="text-xs font-medium text-eve-muted uppercase tracking-wider">Treibstoff &lt; 7 Tage</div>
                    <div className={`text-2xl font-bold mt-1 ${totalStats.lowFuelCount > 0 ? 'text-amber-400' : 'text-slate-400'}`}>
                        {totalStats.lowFuelCount}
                    </div>
                    <div className="text-xs text-eve-muted mt-0.5">Nachfüllen erforderlich</div>
                </div>

                <div className="bg-[#111625]/90 border border-white/5 p-4 rounded-xl shadow-eve">
                    <div className="text-xs font-medium text-eve-muted uppercase tracking-wider">Reinforced / Angriffe</div>
                    <div className={`text-2xl font-bold mt-1 ${totalStats.reinforcedCount > 0 ? 'text-rose-400' : 'text-slate-400'}`}>
                        {totalStats.reinforcedCount}
                    </div>
                    <div className="text-xs text-eve-muted mt-0.5">Aktive Timer</div>
                </div>
            </div>

            {/* Filter and search controls */}
            <div className="bg-eve-card border border-eve-border p-4 rounded-xl shadow-eve flex flex-col md:flex-row gap-4 justify-between items-stretch md:items-center backdrop-blur-md">
                {/* Search input */}
                <div className="relative flex-1 min-w-[240px]">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-eve-muted">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Suche nach Name, System, Typ, Fitting..."
                        className="w-full pl-10 pr-4 py-2 bg-[#080d1a] border border-white/10 rounded-lg text-sm text-white placeholder-eve-muted focus:outline-none focus:border-eve-primary/60 focus:shadow-[0_0_10px_rgba(0,240,255,0.2)] transition-all duration-300"
                    />
                    {searchQuery && (
                        <button
                            type="button"
                            onClick={() => setSearchQuery('')}
                            className="absolute inset-y-0 right-0 pr-3 flex items-center text-xs text-eve-muted hover:text-eve-primary transition-colors cursor-pointer"
                        >
                            ✕
                        </button>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2">
                    {/* Corp Filter if multiple */}
                    {corpsData.length > 1 && (
                        <select
                            value={selectedCorpId}
                            onChange={(e) => setSelectedCorpId(e.target.value === 'all' ? 'all' : parseInt(e.target.value, 10))}
                            className="rounded-lg text-xs px-3 py-2 border border-white/10 text-white bg-[#080d1a] focus:outline-none focus:border-eve-primary/60 focus:shadow-[0_0_10px_rgba(0,240,255,0.2)] transition-all duration-300 cursor-pointer"
                        >
                            <option value="all">Alle Corporations ({corpsData.length})</option>
                            {corpsData.map(c => (
                                <option key={c.corporation?.id} value={c.corporation?.id} className="bg-[#0c111d] text-white">
                                    {c.corporation?.name} {c.corporation?.ticker ? `[${c.corporation.ticker}]` : ''}
                                </option>
                            ))}
                        </select>
                    )}

                    {/* Type Filter */}
                    <div className="inline-flex rounded-lg bg-[#080d1a] p-1 border border-white/10 gap-1">
                        <button
                            type="button"
                            onClick={() => setTypeFilter('all')}
                            className={`px-3 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                typeFilter === 'all'
                                    ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.15)]'
                                    : 'bg-transparent border-transparent text-eve-muted hover:text-white hover:bg-white/5'
                            }`}
                        >
                            Alle
                        </button>
                        <button
                            type="button"
                            onClick={() => setTypeFilter('upwell')}
                            className={`px-3 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                typeFilter === 'upwell'
                                    ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.15)]'
                                    : 'bg-transparent border-transparent text-eve-muted hover:text-white hover:bg-white/5'
                            }`}
                        >
                            Upwell
                        </button>
                        <button
                            type="button"
                            onClick={() => setTypeFilter('starbase')}
                            className={`px-3 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                typeFilter === 'starbase'
                                    ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.15)]'
                                    : 'bg-transparent border-transparent text-eve-muted hover:text-white hover:bg-white/5'
                            }`}
                        >
                            POS
                        </button>
                    </div>

                    {/* Status Filter */}
                    <div className="inline-flex rounded-lg bg-[#080d1a] p-1 border border-white/10 gap-1">
                        <button
                            type="button"
                            onClick={() => setStatusFilter('all')}
                            className={`px-2.5 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                statusFilter === 'all'
                                    ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.15)]'
                                    : 'bg-transparent border-transparent text-eve-muted hover:text-white hover:bg-white/5'
                            }`}
                        >
                            Status: Alle
                        </button>
                        <button
                            type="button"
                            onClick={() => setStatusFilter('online')}
                            className={`px-2.5 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                statusFilter === 'online'
                                    ? 'bg-emerald-500/15 border-emerald-500/60 text-emerald-400 shadow-[0_0_10px_rgba(16,185,129,0.15)]'
                                    : 'bg-transparent border-transparent text-emerald-400/70 hover:text-emerald-400 hover:bg-emerald-500/10'
                            }`}
                        >
                            Online
                        </button>
                        <button
                            type="button"
                            onClick={() => setStatusFilter('low_fuel')}
                            className={`px-2.5 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                statusFilter === 'low_fuel'
                                    ? 'bg-amber-500/15 border-amber-500/60 text-amber-400 shadow-[0_0_10px_rgba(245,158,11,0.15)]'
                                    : 'bg-transparent border-transparent text-amber-400/70 hover:text-amber-400 hover:bg-amber-500/10'
                            }`}
                        >
                            ⚠️ Wenig Fuel
                        </button>
                        <button
                            type="button"
                            onClick={() => setStatusFilter('offline')}
                            className={`px-2.5 py-1.5 rounded-md text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                statusFilter === 'offline'
                                    ? 'bg-rose-500/15 border-rose-500/60 text-rose-400 shadow-[0_0_10px_rgba(244,63,94,0.15)]'
                                    : 'bg-transparent border-transparent text-rose-400/70 hover:text-rose-400 hover:bg-rose-500/10'
                            }`}
                        >
                            Offline
                        </button>
                    </div>
                </div>
            </div>

            {/* List grouped by Corporation */}
            {filteredCorps.map((corpData) => {
                const corporation = corpData.corporation || { id: 0, name: 'Unbekannt', ticker: '' };
                const structures = corpData.structures || [];
                const starbases = corpData.starbases || [];
                const totalCorpStructures = structures.length + starbases.length;
                const isCorpExpanded = !!expandedCorps[corporation.id];

                return (
                    <div key={corporation.id} className="bg-eve-card border border-eve-border rounded-xl shadow-eve overflow-hidden transition-all duration-300">
                        {/* Corporation Header (Collapsible Accordion Trigger) */}
                        <div
                            onClick={() => toggleCorpExpanded(corporation.id)}
                            className="px-6 py-4 bg-gradient-to-r from-[#141b2b]/90 to-[#0c1220]/90 border-b border-eve-border/60 flex flex-wrap items-center justify-between gap-4 cursor-pointer hover:bg-white/[0.04] transition-colors select-none group"
                        >
                            <div className="flex items-center gap-3.5">
                                <img
                                    src={getCorpLogoUrl(corporation.id)}
                                    alt={corporation.name}
                                    className="w-11 h-11 rounded-lg border border-eve-border bg-[#0c101c] p-0.5 object-cover"
                                    loading="lazy"
                                />
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-lg font-bold text-eve-text m-0 group-hover:text-eve-primary transition-colors">
                                            {corporation.name}
                                        </h2>
                                        {corporation.ticker && (
                                            <span className="px-2 py-0.5 rounded bg-eve-primary/10 text-eve-primary border border-eve-primary/20 font-mono text-xs font-semibold">
                                                [{corporation.ticker}]
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-xs text-eve-muted mt-0.5">
                                        {structures.length} Upwell-Strukturen · {starbases.length} POS-Türme
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <div className="text-xs text-eve-muted hidden sm:block">
                                    Corp ID: <span className="font-mono text-eve-text">{corporation.id}</span>
                                </div>
                                <div className={`w-8 h-8 rounded-lg bg-[#080d1a] flex items-center justify-center text-xs text-eve-primary border border-white/10 group-hover:border-eve-primary/50 transition-all duration-200 ${isCorpExpanded ? 'rotate-180' : ''}`}>
                                    ▼
                                </div>
                            </div>
                        </div>

                        {/* Collapsible Content */}
                        {isCorpExpanded && (
                            <div className="p-6 border-t border-white/5">
                                {totalCorpStructures === 0 ? (
                                    <div className="py-8 text-center text-sm text-eve-muted bg-[#0c101c]/50 rounded-lg border border-white/5">
                                        Keine passenden Stationen oder Strukturen für diese Corporation vorhanden.
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-5">
                                        {/* Upwell Structures Section */}
                                        {structures.length > 0 && (
                                            <div className="flex flex-col gap-3">
                                                <h3 className="text-xs font-bold uppercase tracking-wider text-eve-muted flex items-center gap-2">
                                                    <span>Upwell-Strukturen</span>
                                                    <span className="px-1.5 py-0.2 rounded-full bg-white/10 text-[10px] text-white">
                                                        {structures.length}
                                                    </span>
                                                </h3>

                                                <div className="grid grid-cols-1 gap-3.5">
                                                    {structures.map((s) => {
                                                        const isExpanded = !!expandedStructureIds[s.id];
                                                        const fuelInfo = getFuelRemaining(s.fuelExpires);
                                                        const fittings = s.fittings || {};
                                                        const serviceFittings = fittings.services || [];
                                                        const rigFittings = fittings.rigs || [];
                                                        const highFittings = fittings.high || [];
                                                        const medFittings = fittings.medium || [];
                                                        const lowFittings = fittings.low || [];
                                                        const fuelFittings = fittings.fuel || [];
                                                        const fighterFittings = fittings.fighters || [];
                                                        const cargoFittings = fittings.cargo || [];
                                                        const services = s.services || [];

                                                        const hasFittings = serviceFittings.length > 0 ||
                                                            rigFittings.length > 0 ||
                                                            highFittings.length > 0 ||
                                                            medFittings.length > 0 ||
                                                            lowFittings.length > 0 ||
                                                            fuelFittings.length > 0 ||
                                                            fighterFittings.length > 0 ||
                                                            cargoFittings.length > 0;

                                                        // Prepare combined list of services
                                                        const allServiceItems = [...serviceFittings];
                                                        const serviceNamesInFittings = serviceFittings.map(f => f.typeName.toLowerCase());
                                                        const unmatchedServices = services.filter(srv =>
                                                            !serviceNamesInFittings.some(fName => fName.includes(srv.name.toLowerCase()) || srv.name.toLowerCase().includes(fName))
                                                        );

                                                        return (
                                                            <div
                                                                key={s.id}
                                                                className="bg-[#0c101c]/80 border border-white/5 hover:border-white/15 rounded-xl p-4 transition-all duration-200"
                                                            >
                                                                {/* Main row */}
                                                                <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                                                    {/* Structure identity */}
                                                                    <div className="flex items-start sm:items-center gap-3.5 flex-1 min-w-0">
                                                                        <img
                                                                            src={getTypeRenderUrl(s.typeId)}
                                                                            alt={s.typeName}
                                                                            className="w-12 h-12 rounded-lg bg-[#111625] border border-white/10 object-contain p-1 flex-shrink-0"
                                                                            onError={(e) => {
                                                                                (e.target as HTMLImageElement).src = getTypeIconUrl(s.typeId);
                                                                            }}
                                                                            loading="lazy"
                                                                        />
                                                                        <div className="min-w-0 flex-1">
                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                <span className="font-semibold text-white text-base truncate">
                                                                                    {s.name}
                                                                                </span>
                                                                                {getStateBadge(s.state)}
                                                                            </div>
                                                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-eve-muted mt-1">
                                                                                <span className="text-white/80 font-medium">
                                                                                    {s.typeName}
                                                                                </span>
                                                                                <span>•</span>
                                                                                <span className="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 border border-sky-500/20 font-mono font-medium">
                                                                                    🌌 {s.solarSystemName}
                                                                                </span>
                                                                                {s.reinforceHour !== null && s.reinforceHour !== undefined && (
                                                                                    <>
                                                                                        <span>•</span>
                                                                                        <span className="text-amber-400/90 font-mono" title="Reinforce Verwundbarkeitszeit (UTC)">
                                                                                            🛡️ {String(s.reinforceHour).padStart(2, '0')}:00 UTC
                                                                                        </span>
                                                                                    </>
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    {/* Status & Fuel details */}
                                                                    <div className="flex flex-wrap items-center gap-3 lg:justify-end">
                                                                        {/* Fuel Status Badge */}
                                                                        {s.fuelExpires ? (
                                                                            <div
                                                                                className={`px-3 py-1.5 rounded-lg border text-xs flex flex-col sm:items-end ${
                                                                                    fuelInfo?.isCritical
                                                                                        ? 'bg-rose-500/15 border-rose-500/40 text-rose-300'
                                                                                        : fuelInfo?.isLow
                                                                                        ? 'bg-amber-500/15 border-amber-500/40 text-amber-300'
                                                                                        : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
                                                                                }`}
                                                                                title={`Treibstoff reicht bis: ${new Date(s.fuelExpires).toLocaleString()}`}
                                                                            >
                                                                                <div className="flex items-center gap-1.5 font-semibold">
                                                                                <span>⛽</span>
                                                                                <span>{fuelInfo?.label}</span>
                                                                            </div>
                                                                            <span className="text-[10px] text-white/50">
                                                                                bis {new Date(s.fuelExpires).toLocaleDateString()}
                                                                            </span>
                                                                        </div>
                                                                    ) : (
                                                                        <div className="px-3 py-1.5 rounded-lg border border-slate-500/20 bg-slate-500/10 text-xs text-slate-400">
                                                                            ⛽ Kein Fuel / Offline
                                                                        </div>
                                                                    )}

                                                                    {/* Details Toggle Button */}
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => toggleStructureExpanded(s.id)}
                                                                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all duration-300 cursor-pointer ${
                                                                            isExpanded
                                                                                ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.15)]'
                                                                                : 'bg-[#080d1a] border-white/10 hover:border-eve-primary/50 text-white/90 hover:text-eve-primary'
                                                                        }`}
                                                                    >
                                                                        <span>Module, Dienste & Cargo</span>
                                                                        <span className={`transition-transform duration-200 ${isExpanded ? 'rotate-180' : ''}`}>
                                                                            ▼
                                                                        </span>
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            {/* Services preview tags (always visible) */}
                                                            {services.length > 0 && (
                                                                <div className="flex flex-wrap gap-1.5 mt-3 pt-3 border-t border-white/5">
                                                                    {services.map((srv, idx) => {
                                                                        const isOnline = (srv.state || '').toLowerCase() === 'online';
                                                                        return (
                                                                            <span
                                                                                key={idx}
                                                                                className={`px-2 py-0.5 rounded text-[11px] font-medium border ${
                                                                                    isOnline
                                                                                        ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'
                                                                                        : 'bg-rose-500/10 text-rose-300 border-rose-500/20'
                                                                                }`}
                                                                            >
                                                                                {srv.name} ({srv.state})
                                                                            </span>
                                                                        );
                                                                    })}
                                                                </div>
                                                            )}

                                                            {/* Collapsible Structured Fitting Details Section */}
                                                            {isExpanded && (
                                                                <div className="mt-4 pt-4 border-t border-white/10">
                                                                    {!hasFittings && services.length === 0 ? (
                                                                        <div className="text-xs text-eve-muted italic py-3 text-center bg-[#111625]/50 rounded-lg border border-white/5">
                                                                            Keine verankerten Module, Dienste oder Cargo in den Corporation-Assets erfasst.
                                                                        </div>
                                                                    ) : (
                                                                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                                                            {/* Service Modules & Active Services */}
                                                                            {(allServiceItems.length > 0 || unmatchedServices.length > 0) && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>⚙️</span> Service-Module & Dienste ({allServiceItems.length + unmatchedServices.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {allServiceItems.map((f, i) => {
                                                                                            const matched = services.find(srv =>
                                                                                                f.typeName.toLowerCase().includes(srv.name.toLowerCase()) ||
                                                                                                srv.name.toLowerCase().includes(f.typeName.toLowerCase())
                                                                                            );
                                                                                            const isOnline = matched ? matched.state.toLowerCase() === 'online' : true;

                                                                                            return (
                                                                                                <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5">
                                                                                                    <div className="flex items-center justify-between gap-2 text-xs text-white">
                                                                                                        <div className="flex items-center gap-2 truncate">
                                                                                                            <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                            <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                        </div>
                                                                                                        <span className={`text-[10px] px-2 py-0.5 rounded font-medium border ${
                                                                                                            isOnline
                                                                                                                ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'
                                                                                                                : 'bg-rose-500/10 text-rose-300 border-rose-500/20'
                                                                                                        }`}>
                                                                                                            {matched ? matched.state : 'Online'}
                                                                                                        </span>
                                                                                                    </div>
                                                                                                    <div className="text-[10px] text-eve-muted font-mono pl-8 mt-0.5">
                                                                                                        {f.locationFlag}
                                                                                                    </div>
                                                                                                </div>
                                                                                            );
                                                                                        })}

                                                                                        {unmatchedServices.map((srv, i) => {
                                                                                            const isOnline = srv.state.toLowerCase() === 'online';
                                                                                            return (
                                                                                                <div key={`srv-${i}`} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5 flex items-center justify-between gap-2 text-xs text-white">
                                                                                                    <span className="font-semibold">{srv.name}</span>
                                                                                                    <span className={`text-[10px] px-2 py-0.5 rounded font-medium border ${
                                                                                                        isOnline
                                                                                                            ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'
                                                                                                            : 'bg-rose-500/10 text-rose-300 border-rose-500/20'
                                                                                                    }`}>
                                                                                                        {srv.state}
                                                                                                    </span>
                                                                                                </div>
                                                                                            );
                                                                                        })}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* High Slots */}
                                                                            {highFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-rose-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>🎯</span> High Slots ({highFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {highFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5">
                                                                                                <div className="flex items-center justify-between gap-2 text-xs text-white">
                                                                                                    <div className="flex items-center gap-2 truncate">
                                                                                                        <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                        <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                    </div>
                                                                                                    <span className="text-[10px] text-eve-muted font-mono">{f.locationFlag}</span>
                                                                                                </div>
                                                                                                {f.charges && f.charges.length > 0 && (
                                                                                                    <div className="mt-1.5 pt-1.5 border-t border-white/5 pl-8 flex flex-col gap-1">
                                                                                                        {f.charges.map((c, ci) => (
                                                                                                            <div key={ci} className="flex items-center justify-between gap-1.5 text-[11px] text-sky-300">
                                                                                                                <div className="flex items-center gap-1.5 truncate">
                                                                                                                    <img src={getTypeIconUrl(c.typeId)} alt="" className="w-4 h-4 rounded object-contain" />
                                                                                                                    <span className="truncate font-medium">{c.typeName}</span>
                                                                                                                </div>
                                                                                                                <span className="font-mono font-bold">{c.quantity.toLocaleString()}x</span>
                                                                                                            </div>
                                                                                                        ))}
                                                                                                    </div>
                                                                                                )}
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Medium Slots */}
                                                                            {medFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-sky-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>🛡️</span> Medium Slots ({medFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {medFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5">
                                                                                                <div className="flex items-center justify-between gap-2 text-xs text-white">
                                                                                                    <div className="flex items-center gap-2 truncate">
                                                                                                        <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                        <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                    </div>
                                                                                                    <span className="text-[10px] text-eve-muted font-mono">{f.locationFlag}</span>
                                                                                                </div>
                                                                                                {f.charges && f.charges.length > 0 && (
                                                                                                    <div className="mt-1.5 pt-1.5 border-t border-white/5 pl-8 flex flex-col gap-1">
                                                                                                        {f.charges.map((c, ci) => (
                                                                                                            <div key={ci} className="flex items-center justify-between gap-1.5 text-[11px] text-sky-300">
                                                                                                                <div className="flex items-center gap-1.5 truncate">
                                                                                                                    <img src={getTypeIconUrl(c.typeId)} alt="" className="w-4 h-4 rounded object-contain" />
                                                                                                                    <span className="truncate font-medium">{c.typeName}</span>
                                                                                                                </div>
                                                                                                                <span className="font-mono font-bold">{c.quantity.toLocaleString()}x</span>
                                                                                                            </div>
                                                                                                        ))}
                                                                                                    </div>
                                                                                                )}
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Low Slots */}
                                                                            {lowFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-amber-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>⚡</span> Low Slots ({lowFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {lowFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5">
                                                                                                <div className="flex items-center justify-between gap-2 text-xs text-white">
                                                                                                    <div className="flex items-center gap-2 truncate">
                                                                                                        <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                        <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                    </div>
                                                                                                    <span className="text-[10px] text-eve-muted font-mono">{f.locationFlag}</span>
                                                                                                </div>
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Rigs */}
                                                                            {rigFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-purple-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>🔧</span> Rigs ({rigFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {rigFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5">
                                                                                                <div className="flex items-center justify-between gap-2 text-xs text-white">
                                                                                                    <div className="flex items-center gap-2 truncate">
                                                                                                        <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                        <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                    </div>
                                                                                                    <span className="text-[10px] text-eve-muted font-mono">{f.locationFlag}</span>
                                                                                                </div>
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Fuel inventory in structure */}
                                                                            {fuelFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>⛽</span> Treibstoff-Vorrat
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {fuelFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5 flex items-center justify-between gap-2 text-xs text-white">
                                                                                                <div className="flex items-center gap-2 truncate">
                                                                                                    <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                    <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                </div>
                                                                                                <span className="font-mono text-emerald-400 font-bold">{(f.quantity || 0).toLocaleString()}</span>
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Cargo / Struktur-Lager */}
                                                                            {cargoFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-amber-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>📦</span> Cargo / Struktur-Lager ({cargoFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2 max-h-60 overflow-y-auto pr-1">
                                                                                        {cargoFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5 flex items-center justify-between gap-2 text-xs text-white">
                                                                                                <div className="flex items-center gap-2 truncate">
                                                                                                    <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                    <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                </div>
                                                                                                <span className="font-mono text-amber-300 font-bold">{(f.quantity || 1).toLocaleString()}x</span>
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}

                                                                            {/* Fighters */}
                                                                            {fighterFittings.length > 0 && (
                                                                                <div className="bg-[#111625] p-3.5 rounded-xl border border-white/5 shadow-eve">
                                                                                    <div className="text-[11px] font-bold text-indigo-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                                                                                        <span>🚀</span> Fighter Bay ({fighterFittings.length})
                                                                                    </div>
                                                                                    <div className="flex flex-col gap-2">
                                                                                        {fighterFittings.map((f, i) => (
                                                                                            <div key={f.itemId || i} className="bg-[#0c101c]/80 p-2 rounded-lg border border-white/5 flex items-center justify-between gap-2 text-xs text-white">
                                                                                                <div className="flex items-center gap-2 truncate">
                                                                                                    <img src={getTypeIconUrl(f.typeId)} alt="" className="w-6 h-6 rounded object-contain flex-shrink-0" />
                                                                                                    <span className="truncate font-semibold">{f.typeName}</span>
                                                                                                </div>
                                                                                                <span className="font-mono text-white/80 font-bold">{f.quantity || 1}x</span>
                                                                                            </div>
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}

                                    {/* Starbases (POS) Section */}
                                    {starbases.length > 0 && (
                                        <div className="flex flex-col gap-3 mt-3">
                                            <h3 className="text-xs font-bold uppercase tracking-wider text-eve-muted flex items-center gap-2">
                                                <span>Starbases (POS-Kontrolltürme)</span>
                                                <span className="px-1.5 py-0.2 rounded-full bg-white/10 text-[10px] text-white">
                                                    {starbases.length}
                                                </span>
                                            </h3>

                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                                                {starbases.map((sb) => {
                                                    const fuels = sb.fuels || [];
                                                    return (
                                                        <div
                                                            key={sb.id}
                                                            className="bg-[#0c101c]/80 border border-white/5 hover:border-white/15 rounded-xl p-4 transition-all duration-200"
                                                        >
                                                            <div className="flex items-center justify-between gap-3">
                                                                <div className="flex items-center gap-3 min-w-0">
                                                                    <img
                                                                        src={getTypeIconUrl(sb.typeId)}
                                                                        alt={sb.typeName}
                                                                        className="w-10 h-10 rounded-lg bg-[#111625] border border-white/10 p-1 object-contain flex-shrink-0"
                                                                        loading="lazy"
                                                                    />
                                                                    <div className="min-w-0">
                                                                        <div className="font-semibold text-white text-sm truncate">
                                                                            {sb.typeName}
                                                                        </div>
                                                                        <div className="text-xs text-sky-400 font-mono mt-0.5">
                                                                            🌌 {sb.solarSystemName}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                {getStateBadge(sb.state)}
                                                            </div>

                                                            {/* POS Fuel info */}
                                                            {fuels.length > 0 && (
                                                                <div className="mt-3 pt-3 border-t border-white/5 flex flex-wrap gap-2">
                                                                    {fuels.map((f, i) => (
                                                                        <span
                                                                            key={i}
                                                                            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-[#111625] border border-white/10 text-xs text-white"
                                                                        >
                                                                            <img src={getTypeIconUrl(f.typeId)} alt="" className="w-4 h-4 rounded object-contain" />
                                                                            <span>{f.typeName}:</span>
                                                                            <strong className="font-mono text-emerald-400">{(f.quantity || 0).toLocaleString()}</strong>
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            );
        })}
    </div>
);
}
