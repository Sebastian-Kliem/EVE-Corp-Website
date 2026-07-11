import React, { useState, useEffect, useMemo } from 'react';

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
        return calculatedReactions.filter(r =>
            r.polymerName.toLowerCase().includes(searchQuery.toLowerCase())
        );
    }, [calculatedReactions, searchQuery]);

    if (loading) {
        return (
            <div className="box has-text-centered p-5" style={{ background: 'transparent', border: 'none' }}>
                <span className="loader" style={{
                    display: 'inline-block',
                    width: '2rem',
                    height: '2rem',
                    border: '3px solid var(--theme-primary)',
                    borderRadius: '50%',
                    borderTopColor: 'transparent',
                    animation: 'spin 1s linear infinite'
                }}></span>
                <p className="mt-3">Reaktionsdaten & Live Systemkosten-Indizes werden geladen...</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className="box has-text-centered p-5" style={{ borderColor: '#ff4444' }}>
                <h3 className="title is-4" style={{ color: '#ff4444' }}>Fehler</h3>
                <p className="subtitle is-6">{error}</p>
                <button className="button is-small is-primary mt-3" onClick={fetchCalcData}>
                    Erneut laden
                </button>
            </div>
        );
    }

    return (
        <div>
            {/* PARAMETERS PANEL */}
            <div className="box mb-4" style={{ background: 'rgba(255,255,255,0.02)', border: '1px solid var(--theme-card-border)', borderRadius: '6px', padding: '1.25rem' }}>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '2rem' }}>
                    
                    {/* Structure & Rigs */}
                    <div>
                        <h4 className="title is-6 mb-3" style={{ color: 'var(--theme-primary)', borderBottom: '1px solid rgba(255,255,255,0.05)', paddingBottom: '5px' }}>
                            🏢 Station & Rigs
                        </h4>
                        <div className="field mb-2">
                            <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Corp-Struktur vorauswählen</label>
                            <div className="select is-fullwidth is-small">
                                <select className="input-dark" value={selectedStructureId} onChange={(e) => handleStructureChange(e.target.value)}>
                                    <option value="custom">Manuelle Einstellungen</option>
                                    {structuresList.map(s => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.solarSystemName || 'Unbekannt'})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }} className="mb-2">
                            <div>
                                <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Strukturtyp</label>
                                <div className="select is-fullwidth is-small">
                                    <select className="input-dark" value={structureType} onChange={(e) => { setStructureType(e.target.value); setSelectedStructureId('custom'); }}>
                                        <option value="athanor">Athanor (Refinery)</option>
                                        <option value="tatara">Tatara (2% Fee Red.)</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Sicherheits-Status</label>
                                <div className="select is-fullwidth is-small">
                                    <select className="input-dark" value={securitySpace} onChange={(e) => { setSecuritySpace(e.target.value); setSelectedStructureId('custom'); }}>
                                        <option value="highsec">Highsec (1.0x Rig)</option>
                                        <option value="lowsec">Lowsec (1.5x Rig)</option>
                                        <option value="nullsec">W-Space / Nullsec (1.9x)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div className="field">
                            <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Reaction Rig</label>
                            <div className="select is-fullwidth is-small">
                                <select className="input-dark" value={rigType} onChange={(e) => { setRigType(e.target.value); setSelectedStructureId('custom'); }}>
                                    <option value="none">Kein Rig (0%)</option>
                                    <option value="t1">T1 Hybrid Reaction Rig (-2% Material)</option>
                                    <option value="t2">T2 Hybrid Reaction Rig (-2.4% Material)</option>
                                </select>
                            </div>
                            <p className="is-size-7 has-text-grey mt-1">Aktuelle Materialreduktion: <strong>{formatPercent(rigBonus * 100)}</strong></p>
                        </div>
                    </div>

                    {/* Job Index, Taxes & Gas Compression */}
                    <div>
                        <h4 className="title is-6 mb-3" style={{ color: 'var(--theme-primary)', borderBottom: '1px solid rgba(255,255,255,0.05)', paddingBottom: '5px' }}>
                            💰 Systemkosten & Gas-Modus
                        </h4>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }} className="mb-2">
                            <div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Cost Index (%)</label>
                                    <span style={{ fontSize: '0.65rem', color: '#00f0ff', fontWeight: 'bold' }}>LIVE API</span>
                                </div>
                                <input
                                    type="number"
                                    className="input input-dark is-small"
                                    value={systemCostIndex}
                                    onChange={(e) => { setSystemCostIndex(parseFloat(e.target.value) || 0); setSelectedStructureId('custom'); }}
                                    step="0.001"
                                    min="0"
                                    max="20"
                                />
                            </div>
                            <div>
                                <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Installation Tax (%)</label>
                                <input
                                    type="number"
                                    className="input input-dark is-small"
                                    value={taxRate}
                                    onChange={(e) => setTaxRate(parseFloat(e.target.value) || 0)}
                                    step="0.1"
                                    min="0"
                                    max="100"
                                />
                            </div>
                        </div>

                        {/* Gas compression strategy selector */}
                        <div className="field mb-2">
                            <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Gas-Opportunitätspreis</label>
                            <div className="select is-fullwidth is-small">
                                <select className="input-dark" value={useCompressedGas ? 'compressed' : 'raw'} onChange={(e) => setUseCompressedGas(e.target.value === 'compressed')}>
                                    <option value="compressed">Komprimiertes Gas (Jita-Mittelwert 10:1) [Standard]</option>
                                    <option value="raw">Unkomprimiertes Rohgas (Jita-Mittelwert)</option>
                                </select>
                            </div>
                            <p className="is-size-7 has-text-grey mt-1">Rechnet bei WH-Gasen mit dem Wert komprimierten Gases (Volumeneinsparung beim Export).</p>
                        </div>

                        <div className="field">
                            <label className="label is-size-7 mb-1" style={{ color: 'var(--theme-text-muted)' }}>Zielsprunggelenk / Hub</label>
                            <div className="select is-fullwidth is-small">
                                <select className="input-dark" value={selectedHub} onChange={(e) => setSelectedHub(e.target.value)}>
                                    <option value="jita">Jita (The Forge)</option>
                                    <option value="amarr">Amarr (Domain)</option>
                                    <option value="dodixie">Dodixie (Sinq Laison)</option>
                                    <option value="hek">Hek (Metropolis)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {/* FILTER SEARCH ROW */}
            <div className="level mb-4">
                <div className="level-left">
                    <h3 className="title is-5 mb-0">Reaktionskalkulationen</h3>
                </div>
                <div className="level-right">
                    <input
                        type="text"
                        className="input input-dark"
                        placeholder="Reaktion filtern..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        style={{ width: '250px' }}
                    />
                </div>
            </div>

            {/* CALCULATOR TABLE */}
            <div className="box p-0" style={{ overflowX: 'auto', border: '1px solid var(--theme-card-border)', background: 'var(--theme-card-bg)', borderRadius: '8px' }}>
                <table className="table is-fullwidth" style={{ background: 'transparent', margin: 0, borderCollapse: 'collapse' }}>
                    <thead>
                        <tr style={{ borderBottom: '2px solid var(--theme-card-border)' }}>
                            <th style={{ padding: '1rem', color: 'var(--theme-text-muted)', fontWeight: 'bold' }}>Reaktion / Polymer</th>
                            <th style={{ padding: '1rem', color: 'var(--theme-text-muted)', fontWeight: 'bold' }}>Input-Kosten (Jita)</th>
                            <th style={{ padding: '1rem', color: 'var(--theme-text-muted)', fontWeight: 'bold' }}>Job-Startgebühr (Corp Cost)</th>
                            <th style={{ padding: '1rem', color: 'var(--theme-text-muted)', fontWeight: 'bold', minWidth: '160px' }}>
                                Sofortverkauf (Buy Order)
                            </th>
                            <th style={{ padding: '1rem', color: 'var(--theme-text-muted)', fontWeight: 'bold', minWidth: '160px' }}>
                                Sell Order (List Preis)
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredReactions.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="has-text-centered p-5 text-muted">
                                    Keine passenden Reaktionen gefunden.
                                </td>
                            </tr>
                        ) : (
                            filteredReactions.map((react) => {
                                const instantIsProfitable = react.instantProfit > 0;
                                const listingIsProfitable = react.listingProfit > 0;

                                return (
                                    <tr key={react.polymerTypeId} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)', verticalAlign: 'top' }}>
                                        {/* Polymer Details with collapsible input list */}
                                        <td style={{ padding: '1rem' }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }} className="mb-2">
                                                <img
                                                    src={imagePaths.types.replace('12345', react.polymerTypeId.toString())}
                                                    onError={(e) => {
                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${react.polymerTypeId}/icon`;
                                                    }}
                                                    alt=""
                                                    style={{ width: '32px', height: '32px', borderRadius: '4px' }}
                                                />
                                                <div>
                                                    <span style={{ fontWeight: 600, display: 'block' }}>{react.polymerName}</span>
                                                    <span style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)' }}>
                                                        {react.outputQuantity}x pro Lauf • Formula: {react.formulaName}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Materials Details Collapsible */}
                                            <details>
                                                <summary style={{ fontSize: '0.75rem', color: 'var(--theme-primary)', cursor: 'pointer', outline: 'none' }}>
                                                    Material-Details anzeigen
                                                </summary>
                                                <div style={{ marginTop: '5px', padding: '8px', background: 'rgba(0,0,0,0.15)', borderRadius: '4px', fontSize: '0.75rem' }}>
                                                    {react.detailedMaterials.map((mat) => (
                                                        <div key={mat.typeId} style={{ display: 'flex', justifyContent: 'space-between', padding: '2px 0', borderBottom: '1px solid rgba(255,255,255,0.02)', alignItems: 'center' }}>
                                                            <span style={{ display: 'flex', alignItems: 'center', gap: '5px' }}>
                                                                <img
                                                                    src={imagePaths.types.replace('12345', mat.usedTypeId.toString())}
                                                                    onError={(e) => {
                                                                        (e.target as HTMLImageElement).src = `https://images.evetech.net/types/${mat.usedTypeId}/icon`;
                                                                    }}
                                                                    alt=""
                                                                    style={{ width: '16px', height: '16px', borderRadius: '2px' }}
                                                                />
                                                                {mat.usedName} x{mat.reducedQty.toFixed(1)}
                                                            </span>
                                                            <span style={{ fontFamily: 'monospace', color: 'var(--theme-text-muted)' }}>
                                                                {formatISK(mat.jitaPrice)} / Stk.
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </details>
                                        </td>

                                        {/* Input Cost */}
                                        <td style={{ padding: '1rem', verticalAlign: 'middle' }}>
                                            <div style={{ fontWeight: 'bold' }}>{formatISK(react.materialCost)}</div>
                                            <div className="is-size-7 text-muted">
                                                {useCompressedGas ? 'Compressed Gas Basis' : 'Raw Gas Basis'}
                                            </div>
                                        </td>

                                        {/* Job Fee */}
                                        <td style={{ padding: '1rem', verticalAlign: 'middle' }}>
                                            <div style={{ fontWeight: 'bold' }}>{formatISK(react.jobFee)}</div>
                                            <div className="is-size-7 text-muted" title={`Base Cost (EIV): ${formatISK(react.baseCost)}`}>
                                                EIV Base: {formatISK(Math.round(react.baseCost))} <br/>
                                                Index: {systemCostIndex.toFixed(3)}%
                                            </div>
                                        </td>

                                        {/* Instant Profit */}
                                        <td style={{ padding: '1rem', verticalAlign: 'middle' }}>
                                            <div style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)' }}>
                                                Hub Preis: {formatISK(react.buyPrice)}
                                            </div>
                                            <div style={{
                                                fontWeight: 'bold',
                                                color: instantIsProfitable ? '#00ff88' : '#ff4444',
                                                fontSize: '1rem',
                                                marginTop: '2px'
                                            }}>
                                                {instantIsProfitable ? '+' : ''}{formatISK(react.instantProfit)}
                                            </div>
                                            <div style={{
                                                fontSize: '0.8rem',
                                                color: instantIsProfitable ? '#00ff88' : '#ff4444',
                                                fontWeight: 'bold'
                                            }}>
                                                Margin: {instantIsProfitable ? '+' : ''}{formatPercent(react.instantMargin)}
                                            </div>
                                        </td>

                                        {/* Listing Profit */}
                                        <td style={{ padding: '1rem', verticalAlign: 'middle' }}>
                                            <div style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)' }}>
                                                Hub Preis: {formatISK(react.sellPrice)}
                                            </div>
                                            <div style={{
                                                fontWeight: 'bold',
                                                color: listingIsProfitable ? '#00f0ff' : '#ff4444',
                                                fontSize: '1rem',
                                                marginTop: '2px'
                                            }}>
                                                {listingIsProfitable ? '+' : ''}{formatISK(react.listingProfit)}
                                            </div>
                                            <div style={{
                                                fontSize: '0.8rem',
                                                color: listingIsProfitable ? '#00f0ff' : '#ff4444',
                                                fontWeight: 'bold'
                                            }}>
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
