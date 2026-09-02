import React, { useState, useEffect, useMemo } from 'react';
import { cleanItemSearch } from '../../utils/itemSearch';

interface Material {
    typeId: number;
    name: string;
    quantity: number;
}

interface Reaction {
    polymerTypeId: number;
    polymerName: string;
    formulaTypeId: number;
    formulaName: string;
    outputQuantity: number;
    materials: Material[];
}

interface MarketStats {
    maxBuyPrice: number | null;
    totalBuyVolume: number;
    minSellPrice: number | null;
    totalSellVolume: number;
}

interface HubData {
    jita: MarketStats;
    amarr?: MarketStats;
    dodixie?: MarketStats;
    hek?: MarketStats;
}

interface CalculatorData {
    reactions: Reaction[];
    marketPrices: Record<number, HubData>;
    compressedGasMap: Record<number, number>;
    adjustedPrices: Record<number, number>;
    systemCostIndices: Record<number, number>;
    hubs: Record<string, any>;
}

interface Structure {
    id: string;
    name: string;
    solarSystemId: number;
    solarSystemName: string | null;
    structureType?: string;
    rigType?: string;
}

interface ReactionDashboardProps {
    apiDataUrl: string;
    imagePaths: {
        types: string;
    };
    structuresList: Structure[];
}

export default function ReactionDashboard({ apiDataUrl, imagePaths, structuresList = [] }: ReactionDashboardProps) {
    const [calcData, setCalcData] = useState<CalculatorData | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);

    // Filter
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [selectedHub, setSelectedHub] = useState<string>('jita');

    // Gas pricing strategy: compressed (true) vs raw (false)
    const [useCompressedGas, setUseCompressedGas] = useState<boolean>(true);

    // Structure & Rig State
    const [selectedStructureId, setSelectedStructureId] = useState<string>('custom');
    const [structureType, setStructureType] = useState<string>('athanor'); // athanor, tatara
    const [rigType, setRigType] = useState<string>('none'); // none, t1, t2
    const [securitySpace, setSecuritySpace] = useState<string>('nullsec'); // highsec, lowsec, nullsec

    // Fee parameters (Live cost index is fetched and populated dynamically)
    const [systemCostIndex, setSystemCostIndex] = useState<number>(1.0); // in %
    const [taxRate, setTaxRate] = useState<number>(0.0); // in %

    // Fetch calculator SDE and price data
    const fetchCalcData = () => {
        setLoading(true);
        setError(null);
        fetch(apiDataUrl)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Fehler beim Laden der Reaktionsdaten.');
                }
                return res.json();
            })
            .then((data: CalculatorData) => {
                setCalcData(data);
                setLoading(false);

                // Auto-fill initial cost index for Jita (30000142) if available
                const jitaIndex = data.systemCostIndices[30000142];
                if (jitaIndex !== undefined) {
                    setSystemCostIndex(jitaIndex * 100);
                }
            })
            .catch((err) => {
                setError(err.message || 'Ein unbekannter Fehler ist aufgetreten.');
                setLoading(false);
            });
    };

    useEffect(() => {
        fetchCalcData();
    }, [apiDataUrl]);

    // Update Cost Index automatically if hub changes and manual/hub mode is active
    useEffect(() => {
        if (!calcData || selectedStructureId !== 'custom') return;
        const hubInfo = calcData.hubs[selectedHub];
        if (hubInfo) {
            const systemId = hubInfo.solarSystemId;
            const index = calcData.systemCostIndices[systemId];
            if (index !== undefined) {
                setSystemCostIndex(index * 100);
            } else {
                setSystemCostIndex(1.0); // Default fallback
            }
        }
    }, [selectedHub, selectedStructureId, calcData]);

    // Handle Corp Structure Selection
    const handleStructureChange = (structIdStr: string) => {
        setSelectedStructureId(structIdStr);
        if (structIdStr === 'custom') {
            if (calcData) {
                const hubInfo = calcData.hubs[selectedHub];
                const index = calcData.systemCostIndices[hubInfo?.solarSystemId];
                if (index !== undefined) {
                    setSystemCostIndex(index * 100);
                } else {
                    setSystemCostIndex(1.0);
                }
            }
            return;
        }

        const structure = structuresList.find(s => s.id === structIdStr);
        if (structure) {
            const systemName = structure.solarSystemName || '';
            const isWormhole = /^J\d{6}$/i.test(systemName);

            if (isWormhole) {
                setSecuritySpace('nullsec');
            } else {
                setSecuritySpace('nullsec');
            }

            // Dynamically set structure type and rig type from corporation asset scan
            if (structure.structureType) {
                setStructureType(structure.structureType);
            } else {
                setStructureType('athanor');
            }

            if (structure.rigType) {
                setRigType(structure.rigType);
            } else {
                setRigType('none');
            }

            // Prefill Live Cost Index from API for this structure's solar system
            if (calcData) {
                const systemId = structure.solarSystemId;
                const index = calcData.systemCostIndices[systemId];
                if (index !== undefined && index > 0) {
                    setSystemCostIndex(index * 100);
                } else {
                    setSystemCostIndex(1.0); // Default fallback
                }
            }
        }
    };

    // Rig reduction calculations
    const rigBonus = useMemo(() => {
        const base = rigType === 't2' ? 0.024 : rigType === 't1' ? 0.02 : 0.0;
        const secMod = securitySpace === 'lowsec' ? 1.5 : securitySpace === 'nullsec' ? 1.9 : 1.0;
        return base * secMod;
    }, [rigType, securitySpace]);

    // Structure cost modifier
    const structureCostModifier = useMemo(() => {
        return structureType === 'tatara' ? 0.98 : 1.0;
    }, [structureType]);

    // Format helpers
    const formatISK = (val: number | null): string => {
        if (val === null) return '-';
        return new Intl.NumberFormat('de-DE', {
            style: 'decimal',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(val) + ' ISK';
    };

    const formatPercent = (val: number): string => {
        return val.toFixed(1) + '%';
    };

    // Main calculator logic
    const calculatedReactions = useMemo(() => {
        if (!calcData) return [];

        return calcData.reactions.map((react) => {
            const polymerPrices = calcData.marketPrices[react.polymerTypeId]?.[selectedHub] || { maxBuyPrice: null, minSellPrice: null };

            // Calculate material cost and Base Cost (EIV base value)
            let materialCost = 0;
            let rawMaterialBaseValue = 0;

            const detailedMaterials = react.materials.map(mat => {
                const isGas = calcData.compressedGasMap[mat.typeId] !== undefined;
                let jitaPrice = 0;
                let usedName = mat.name;
                let usedTypeId = mat.typeId;

                if (isGas && useCompressedGas) {
                    const compressedId = calcData.compressedGasMap[mat.typeId];
                    const compPrice = calcData.marketPrices[compressedId]?.jita?.minSellPrice || 0;
                    jitaPrice = compPrice / 10;
                    usedName = `Kompr. ${mat.name} (10:1)`;
                    usedTypeId = compressedId;
                } else {
                    jitaPrice = calcData.marketPrices[mat.typeId]?.jita?.minSellPrice || 0;
                }

                const reducedQty = mat.quantity * (1 - rigBonus);
                const cost = reducedQty * jitaPrice;
                materialCost += cost;

                const materialAdjustedPrice = calcData.adjustedPrices[mat.typeId] || 0.0;
                rawMaterialBaseValue += mat.quantity * materialAdjustedPrice;

                return {
                    ...mat,
                    reducedQty,
                    jitaPrice,
                    cost,
                    isGas,
                    usedName,
                    usedTypeId
                };
            });

            // Job installation cost: Base Cost (materials EIV) * System Cost Index * Structure Cost Mod * Tax
            const adjustedPrice = calcData.adjustedPrices[react.polymerTypeId] || 0.0;
            const jobFee = rawMaterialBaseValue * (systemCostIndex / 100) * structureCostModifier * (1 + (taxRate / 100));

            const totalCost = materialCost + jobFee;

            // Output value in selected hub
            const sellPrice = polymerPrices.minSellPrice || 0;
            const buyPrice = polymerPrices.maxBuyPrice || 0;

            const instantRevenue = buyPrice * react.outputQuantity;
            const instantProfit = instantRevenue - totalCost;
            const instantMargin = totalCost > 0 ? (instantProfit / totalCost) * 100 : 0;

            const listingRevenue = sellPrice * react.outputQuantity;
            const listingProfit = listingRevenue - totalCost;
            const listingMargin = totalCost > 0 ? (listingProfit / totalCost) * 100 : 0;

            return {
                ...react,
                baseCost: rawMaterialBaseValue,
                detailedMaterials,
                materialCost,
                jobFee,
                totalCost,
                sellPrice,
                buyPrice,
                instantRevenue,
                instantProfit,
                instantMargin,
                listingRevenue,
                listingProfit,
                listingMargin,
            };
        });
    }, [calcData, selectedHub, rigBonus, structureCostModifier, systemCostIndex, taxRate, useCompressedGas]);

    const filteredReactions = useMemo(() => {
        const cleanQuery = cleanItemSearch(searchQuery).toLowerCase().trim();
        return calculatedReactions.filter(r =>
            !cleanQuery || r.polymerName.toLowerCase().includes(cleanQuery)
        );
    }, [calculatedReactions, searchQuery]);

    if (loading) {
        return (
            <div className="text-center p-6 bg-transparent border-none">
                <span className="inline-block w-8 h-8 border-[3px] border-eve-primary border-t-transparent rounded-full animate-spin"></span>
                <p className="mt-3 text-eve-muted">Reaktionsdaten & Live Systemkosten-Indizes werden geladen...</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className="text-center p-6 bg-eve-card border border-red-500/30 rounded-lg max-w-md mx-auto mt-6 shadow-eve">
                <h3 className="text-xl font-semibold mb-3 text-red-500">Fehler</h3>
                <p className="text-sm text-eve-muted mb-4">{error}</p>
                <button 
                    className="inline-flex items-center justify-center border border-transparent rounded-lg bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold text-xs px-3 py-1 shadow-eve transition-all duration-300 hover:-translate-y-0.5 cursor-pointer"
                    onClick={fetchCalcData}
                >
                    Erneut laden
                </button>
            </div>
        );
    }

    return (
        <div>
            {/* PARAMETERS PANEL */}
            <div className="mb-4 bg-white/2 border border-eve-border rounded-lg p-5">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                    
                    {/* Structure & Rigs */}
                    <div>
                        <h4 className="text-sm font-semibold mb-3 text-eve-primary border-b border-white/5 pb-1.5">
                            🏢 Station & Rigs
                        </h4>
                        <div className="mb-3">
                            <label className="block text-xs text-eve-muted mb-1">Corp-Struktur vorauswählen</label>
                            <select 
                                className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                value={selectedStructureId} 
                                onChange={(e) => handleStructureChange(e.target.value)}
                            >
                                <option value="custom">Manuelle Einstellungen</option>
                                {structuresList.map(s => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.solarSystemName || 'Unbekannt'})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid grid-cols-2 gap-2.5 mb-3">
                            <div>
                                <label className="block text-xs text-eve-muted mb-1">Strukturtyp</label>
                                <select 
                                    className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                    value={structureType} 
                                    onChange={(e) => { setStructureType(e.target.value); setSelectedStructureId('custom'); }}
                                >
                                    <option value="athanor">Athanor (Refinery)</option>
                                    <option value="tatara">Tatara (2% Fee Red.)</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-eve-muted mb-1">Sicherheits-Status</label>
                                <select 
                                    className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                    value={securitySpace} 
                                    onChange={(e) => { setSecuritySpace(e.target.value); setSelectedStructureId('custom'); }}
                                >
                                    <option value="highsec">Highsec (1.0x Rig)</option>
                                    <option value="lowsec">Lowsec (1.5x Rig)</option>
                                    <option value="nullsec">W-Space / Nullsec (1.9x)</option>
                                </select>
                            </div>
                        </div>

                        <div className="mb-3">
                            <label className="block text-xs text-eve-muted mb-1">Reaction Rig</label>
                            <select 
                                className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                value={rigType} 
                                onChange={(e) => { setRigType(e.target.value); setSelectedStructureId('custom'); }}
                            >
                                <option value="none">Kein Rig (0%)</option>
                                <option value="t1">T1 Hybrid Reaction Rig (-2% Material)</option>
                                <option value="t2">T2 Hybrid Reaction Rig (-2.4% Material)</option>
                            </select>
                            <p className="text-[11px] text-eve-muted mt-1.5">Aktuelle Materialreduktion: <strong>{formatPercent(rigBonus * 100)}</strong></p>
                        </div>
                    </div>

                    {/* Job Index, Taxes & Gas Compression */}
                    <div>
                        <h4 className="text-sm font-semibold mb-3 text-eve-primary border-b border-white/5 pb-1.5">
                            💰 Systemkosten & Gas-Modus
                        </h4>
                        <div className="grid grid-cols-2 gap-2.5 mb-3">
                            <div>
                                <div className="flex justify-between items-center mb-1">
                                    <label className="block text-xs text-eve-muted">Cost Index (%)</label>
                                    <span className="text-[9px] text-[#00f0ff] font-bold">LIVE API</span>
                                </div>
                                <input
                                    type="number"
                                    className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                                    value={systemCostIndex}
                                    onChange={(e) => { setSystemCostIndex(parseFloat(e.target.value) || 0); setSelectedStructureId('custom'); }}
                                    step="0.001"
                                    min="0"
                                    max="20"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-eve-muted mb-1">Installation Tax (%)</label>
                                <input
                                    type="number"
                                    className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                                    value={taxRate}
                                    onChange={(e) => setTaxRate(parseFloat(e.target.value) || 0)}
                                    step="0.1"
                                    min="0"
                                    max="100"
                                />
                            </div>
                        </div>

                        {/* Gas compression strategy selector */}
                        <div className="mb-3">
                            <label className="block text-xs text-eve-muted mb-1">Gas-Opportunitätspreis</label>
                            <select 
                                className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                value={useCompressedGas ? 'compressed' : 'raw'} 
                                onChange={(e) => setUseCompressedGas(e.target.value === 'compressed')}
                            >
                                <option value="compressed">Komprimiertes Gas (Jita-Mittelwert 10:1) [Standard]</option>
                                <option value="raw">Unkomprimiertes Rohgas (Jita-Mittelwert)</option>
                            </select>
                            <p className="text-[11px] text-eve-muted mt-1.5">Rechnet bei WH-Gasen mit dem Wert komprimierten Gases (Volumeneinsparung beim Export).</p>
                        </div>

                        <div className="mb-3">
                            <label className="block text-xs text-eve-muted mb-1">Zielsprunggelenk / Hub</label>
                            <select 
                                className="w-full rounded-lg text-xs px-2.5 py-1.5 border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                value={selectedHub} 
                                onChange={(e) => setSelectedHub(e.target.value)}
                            >
                                <option value="jita">Jita (The Forge)</option>
                                <option value="amarr">Amarr (Domain)</option>
                                <option value="dodixie">Dodixie (Sinq Laison)</option>
                                <option value="hek">Hek (Metropolis)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {/* FILTER SEARCH ROW */}
            <div className="flex justify-between items-center flex-wrap gap-4 mb-4">
                <div>
                    <h3 className="text-lg font-semibold text-white">Reaktionskalkulationen</h3>
                </div>
                <div>
                    <input
                        type="text"
                        className="rounded-lg text-xs px-2.5 py-1.5 w-[250px] border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                        placeholder="Reaktion filtern..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(cleanItemSearch(e.target.value))}
                    />
                </div>
            </div>

            {/* CALCULATOR TABLE */}
            <div className="overflow-x-auto border border-eve-border bg-eve-card rounded-lg shadow-eve">
                <table className="w-full border-collapse text-left text-eve-text">
                    <thead>
                        <tr className="border-b-2 border-eve-border bg-[#0d121fe6]/50">
                            <th className="p-4 text-eve-muted font-bold text-sm">Reaktion / Polymer</th>
                            <th className="p-4 text-eve-muted font-bold text-sm">Input-Kosten (Jita)</th>
                            <th className="p-4 text-eve-muted font-bold text-sm">Job-Startgebühr (Corp Cost)</th>
                            <th className="p-4 text-eve-muted font-bold text-sm min-w-[160px]">Sofortverkauf (Buy Order)</th>
                            <th className="p-4 text-eve-muted font-bold text-sm min-w-[160px]">Sell Order (List Preis)</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {filteredReactions.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="text-center p-6 text-eve-muted">
                                    Keine passenden Reaktionen gefunden.
                                </td>
                            </tr>
                        ) : (
                            filteredReactions.map((react) => {
                                const instantIsProfitable = react.instantProfit > 0;
                                const listingIsProfitable = react.listingProfit > 0;

                                return (
                                    <tr key={react.polymerTypeId} className="hover:bg-white/2 transition-colors duration-150 align-top">
                                        {/* Polymer Details with collapsible input list */}
                                        <td className="p-4 align-middle">
                                            <div className="flex items-center gap-2.5 mb-2">
                                                <img
                                                    src={imagePaths.types.replace('12345', react.polymerTypeId.toString())}
                                                    onError={(e) => {
                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${react.polymerTypeId}/icon`;
                                                    }}
                                                    alt=""
                                                    className="w-8 h-8 rounded"
                                                />
                                                <div>
                                                    <span className="font-semibold block">{react.polymerName}</span>
                                                    <span className="text-xs text-eve-muted">
                                                        {react.outputQuantity}x pro Lauf • Formula: {react.formulaName}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Materials Details Collapsible */}
                                            <details className="group">
                                                <summary className="text-xs text-eve-primary cursor-pointer outline-none select-none flex items-center gap-1.5">
                                                    <span>Material-Details anzeigen</span>
                                                    <span className="text-[10px] transition-transform duration-200 group-open:rotate-180">▼</span>
                                                </summary>
                                                <div className="mt-1.5 p-2 bg-black/30 border border-white/5 rounded text-xs">
                                                    {react.detailedMaterials.map((mat) => (
                                                        <div key={mat.typeId} className="flex justify-between py-1 border-b border-white/5 last:border-b-0 items-center gap-2">
                                                            <span className="flex items-center gap-1.5">
                                                                <img
                                                                    src={imagePaths.types.replace('12345', mat.usedTypeId.toString())}
                                                                    onError={(e) => {
                                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${mat.usedTypeId}/icon`;
                                                                    }}
                                                                    alt=""
                                                                    className="w-4 h-4 rounded-sm"
                                                                />
                                                                {mat.usedName} x{mat.reducedQty.toFixed(1)}
                                                            </span>
                                                            <span className="font-mono text-eve-muted text-[11px]">
                                                                {formatISK(mat.jitaPrice)} / Stk.
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </details>
                                        </td>

                                        {/* Input Cost */}
                                        <td className="p-4 align-middle">
                                            <div className="font-bold">{formatISK(react.materialCost)}</div>
                                            <div className="text-xs text-eve-muted">
                                                {useCompressedGas ? 'Compressed Gas Basis' : 'Raw Gas Basis'}
                                            </div>
                                        </td>

                                        {/* Job Fee */}
                                        <td className="p-4 align-middle">
                                            <div className="font-bold">{formatISK(react.jobFee)}</div>
                                            <div className="text-xs text-eve-muted" title={`Base Cost (EIV): ${formatISK(react.baseCost)}`}>
                                                EIV Base: {formatISK(Math.round(react.baseCost))} <br/>
                                                Index: {systemCostIndex.toFixed(3)}%
                                            </div>
                                        </td>

                                        {/* Instant Profit */}
                                        <td className="p-4 align-middle">
                                            <div className="text-xs text-eve-muted">
                                                Hub Preis: {formatISK(react.buyPrice)}
                                            </div>
                                            <div className={`font-bold text-base mt-0.5 ${instantIsProfitable ? 'text-emerald-400' : 'text-rose-400'}`}>
                                                {instantIsProfitable ? '+' : ''}{formatISK(react.instantProfit)}
                                            </div>
                                            <div className={`text-xs font-bold ${instantIsProfitable ? 'text-emerald-400' : 'text-rose-400'}`}>
                                                Margin: {instantIsProfitable ? '+' : ''}{formatPercent(react.instantMargin)}
                                            </div>
                                        </td>

                                        {/* Listing Profit */}
                                        <td className="p-4 align-middle">
                                            <div className="text-xs text-eve-muted">
                                                Hub Preis: {formatISK(react.sellPrice)}
                                            </div>
                                            <div className={`font-bold text-base mt-0.5 ${listingIsProfitable ? 'text-eve-primary' : 'text-rose-400'}`}>
                                                {listingIsProfitable ? '+' : ''}{formatISK(react.listingProfit)}
                                            </div>
                                            <div className={`text-xs font-bold ${listingIsProfitable ? 'text-eve-primary' : 'text-rose-400'}`}>
                                                Margin: {listingIsProfitable ? '+' : ''}{formatPercent(react.listingMargin)}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
