import React, {useState, useEffect} from 'react';
import PIRouteVisualizer from './PIRouteVisualizer';

interface CharacterListItem {
    id: number;
    name: string;
    accountGroup: string;
    accountName: string;
    tags?: string[];
}

interface Material {
    type_id: number;
    name: string;
    quantity: number;
    volume?: number;
    container?: string;
}

interface RouteMaterial {
    factory_id: string;
    factory_name: string;
    schematic_name: string;
    material_id: number;
    material_name: string;
    quantity: number;
}

interface ExtractorInfo {
    product_type_id: number;
    product_name: string;
    cycle_time: number;
    qty_per_cycle: number;
    heads_count: number;
}

interface FactoryInfo {
    schematic_id: number;
    name: string;
    cycle_time: number;
    inputs: { type_id: number; name: string; quantity: number }[];
    outputs: { type_id: number; name: string; quantity: number }[];
}

interface PinData {
    pin_id: string;
    type_id: number;
    name: string;
    category: 'command_center' | 'launchpad' | 'storage' | 'extractor' | 'factory' | 'other';
    latitude?: number;
    longitude?: number;
    contents: Material[];
    extractor_info: ExtractorInfo | null;
    factory_info: FactoryInfo | null;
    last_cycle_start: string | null;
    expiry_time?: string | null;
    supplied_inputs?: RouteMaterial[];
    received_outputs?: RouteMaterial[];
}

interface PocoData {
    name: string;
    contents: Material[];
    resolved: boolean;
}

interface UnassignedPoco {
    location_id: number;
    name: string;
    solar_system_name: string;
    contents: Material[];
}

interface PlanetData {
    planet_id: number;
    name: string;
    type: string;
    solar_system_name: string;
    solar_system_id: number;
    upgrade_level: number;
    num_pins: number;
    last_update: string;
    pins: PinData[];
    routes?: any[];
    poco: PocoData;
}

interface CharacterPiData {
    character_id: number;
    character_name: string;
    planets: PlanetData[];
    unassigned_pocos?: UnassignedPoco[];
    error?: string;
}

interface PIOverviewProps {
    charactersList: CharacterListItem[];
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
    jwtToken?: string;
}

interface UnassignedPocoRowProps {
    poco: UnassignedPoco;
    planets: PlanetData[];
    apiDataUrl: string;
    setLoading: (loading: boolean) => void;
    setPiData: (data: CharacterPiData[]) => void;
    getTypeIconUrl: (typeId: number) => string;
    jwtToken?: string;
}

function UnassignedPocoRow({
                                poco,
                                planets,
                                apiDataUrl,
                                setLoading,
                                setPiData,
                                getTypeIconUrl,
                                jwtToken
                            }: UnassignedPocoRowProps) {
    const [mappingPlanetId, setMappingPlanetId] = useState<string>('');
    const [mappingLoading, setMappingLoading] = useState<boolean>(false);

    const handleMapPoco = async () => {
        if (!mappingPlanetId) return;
        const targetPlanet = planets.find(p => p.planet_id.toString() === mappingPlanetId);
        if (!targetPlanet) return;

        setMappingLoading(true);
        const formattedType = targetPlanet.type.charAt(0).toUpperCase() + targetPlanet.type.slice(1);
        const newName = `Custom-Office - Planet ${formattedType} - ${targetPlanet.name}`;

        const token = jwtToken || localStorage.getItem('token');
        try {
            const headers: Record<string, string> = {
                'Content-Type': 'application/json',
            };
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            const res = await fetch(`/api/structures/${poco.location_id}`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({
                    name: newName,
                    solarSystemName: targetPlanet.solar_system_name,
                }),
            });

            if (!res.ok) {
                throw new Error('Fehler beim Speichern der Struktur.');
            }

            setLoading(true);
            const refreshRes = await fetch(apiDataUrl);
            if (refreshRes.ok) {
                const freshData = await refreshRes.json();
                setPiData(freshData);
            }
        } catch (err: any) {
            alert(err.message || 'Verknüpfung fehlgeschlagen.');
        } finally {
            setMappingLoading(false);
            setLoading(false);
        }
    };

    return (
        <div className="p-3 rounded bg-white/5 border border-white/5">
            <div className="font-bold text-sm mb-1 flex justify-between flex-wrap gap-2">
                <span>
                    📍 {poco.name}{" "}
                    <span className="text-eve-muted font-normal text-xs">
                        (ID: {poco.location_id})
                    </span>
                </span>
                <span className="text-eve-muted text-xs">
                    System: {poco.solar_system_name}
                </span>
            </div>
            <div className="flex gap-2 flex-wrap mt-1 mb-3">
                {poco.contents.map((item) => (
                    <span
                        key={item.type_id}
                        className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded bg-white/8"
                    >
                        <img
                            src={getTypeIconUrl(item.type_id)}
                            alt={item.name}
                            className="w-4 h-4"
                        />
                        {item.quantity.toLocaleString()}x {item.name}
                        {item.container && (
                            <span className="text-eve-muted text-[10px] ml-1">
                                ({item.container})
                            </span>
                        )}
                    </span>
                ))}
            </div>
            <div className="flex gap-2 items-center mt-2 border-t border-white/5 pt-2">
                <select
                    value={mappingPlanetId}
                    onChange={(e) => setMappingPlanetId(e.target.value)}
                    disabled={mappingLoading}
                    className="px-2 py-1 rounded bg-[#0f172a59] text-white border border-white/15 text-xs flex-1 focus:outline-none focus:border-eve-primary"
                >
                    <option value="">-- Planeten auswählen --</option>
                    {planets.map((planet) => (
                        <option key={planet.planet_id} value={planet.planet_id}>
                            {planet.name} ({planet.type})
                        </option>
                    ))}
                </select>
                <button
                    onClick={handleMapPoco}
                    disabled={!mappingPlanetId || mappingLoading}
                    className={`px-3 py-1 rounded bg-eve-primary text-black font-semibold text-xs cursor-pointer ${
                        !mappingPlanetId || mappingLoading
                            ? "opacity-60 cursor-not-allowed"
                            : ""
                    }`}
                >
                    {mappingLoading ? "Verknüpfe..." : "Verknüpfen"}
                </button>
            </div>
        </div>
    );
}

export default function PIOverview({
                                       charactersList,
                                       apiDataUrl,
                                       imagePaths,
                                       jwtToken,
                                   }: PIOverviewProps) {
    const [loading, setLoading] = useState(true);
    const [piData, setPiData] = useState<CharacterPiData[]>([]);
    const [error, setError] = useState<string | null>(null);

    // Filter states
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedSystem, setSelectedSystem] = useState('');
    const [selectedMaterial, setSelectedMaterial] = useState('');
    const [selectedTag, setSelectedTag] = useState<string>('all');

    // Collect all unique tags
    const allTags = React.useMemo(() => {
        const tags = new Set<string>();
        charactersList.forEach(c => {
            if (c.tags) {
                c.tags.forEach(t => tags.add(t));
            }
        });
        return Array.from(tags).sort();
    }, [charactersList]);

    // Collapse states
    const [collapsedCharacters, setCollapsedCharacters] = useState<Record<number, boolean>>({});
    const [collapsedPlanets, setCollapsedPlanets] = useState<Record<number, boolean>>({});

    useEffect(() => {
        setLoading(true);
        fetch(apiDataUrl)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Fehler beim Laden der PI-Daten.');
                }
                return res.json();
            })
            .then((data: CharacterPiData[]) => {
                setPiData(data);
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

    const getTypeIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', typeId.toString());
    };

    const toggleCharacter = (charId: number) => {
        setCollapsedCharacters((prev) => ({
            ...prev,
            [charId]: !prev[charId],
        }));
    };

    const togglePlanet = (planetId: number) => {
        setCollapsedPlanets((prev) => ({
            ...prev,
            [planetId]: prev[planetId] === false ? true : false,
        }));
    };

    // Extract all unique solar systems and produced/extracted materials for filters
    const systemsSet = new Set<string>();
    const materialsSet = new Set<string>();

    piData.forEach((charData) => {
        charData.planets.forEach((planet) => {
            if (planet.solar_system_name) {
                systemsSet.add(planet.solar_system_name);
            }
            planet.pins.forEach((pin) => {
                if (pin.extractor_info?.product_name) {
                    materialsSet.add(pin.extractor_info.product_name);
                }
                if (pin.factory_info?.outputs) {
                    pin.factory_info.outputs.forEach((out) => materialsSet.add(out.name));
                }
            });
        });
    });

    const uniqueSystems = Array.from(systemsSet).sort();
    const uniqueMaterials = Array.from(materialsSet).sort();



    // Filtering logic
    const filteredPiData = piData.map((charData) => {
        // Tag check
        if (selectedTag !== 'all') {
            const charObj = charactersList.find(c => c.id === charData.character_id);
            if (!charObj || !charObj.tags || !charObj.tags.includes(selectedTag)) {
                return null;
            }
        }

        const filteredPlanets = charData.planets.filter((planet) => {
            // Solar System filter
            if (selectedSystem && planet.solar_system_name !== selectedSystem) {
                return false;
            }

            // Search query filter (matches planet name, system name, or item names on planet)
            const matchesQuery =
                planet.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                planet.solar_system_name.toLowerCase().includes(searchQuery.toLowerCase());

            // Check if planet has selected material (if material filter active)
            let matchesMaterial = !selectedMaterial;
            if (selectedMaterial) {
                planet.pins.forEach((pin) => {
                    if (pin.extractor_info && pin.extractor_info.product_name === selectedMaterial) {
                        matchesMaterial = true;
                    }
                    if (pin.factory_info && pin.factory_info.outputs.some(out => out.name === selectedMaterial)) {
                        matchesMaterial = true;
                    }
                    if (pin.factory_info && pin.factory_info.inputs.some(inp => inp.name === selectedMaterial)) {
                        matchesMaterial = true;
                    }
                });
            }

            // If query is active, check if any pin has matching items
            let matchesQueryOrItems = matchesQuery;
            if (!matchesQueryOrItems && searchQuery) {
                planet.pins.forEach((pin) => {
                    if (pin.name.toLowerCase().includes(searchQuery.toLowerCase())) {
                        matchesQueryOrItems = true;
                    }
                    if (pin.factory_info?.name.toLowerCase().includes(searchQuery.toLowerCase())) {
                        matchesQueryOrItems = true;
                    }
                    pin.contents.forEach((item) => {
                        if (item.name.toLowerCase().includes(searchQuery.toLowerCase())) {
                            matchesQueryOrItems = true;
                        }
                    });
                });
            }

            return matchesQueryOrItems && matchesMaterial;
        });

        return {
            ...charData,
            planets: filteredPlanets,
        };
    }).filter((charData): charData is CharacterPiData => charData !== null && (charData.planets.length > 0 || searchQuery === ''));

    // Summary calculation
    let totalPlanetsCount = 0;
    let activeExtractors = 0;
    let idleExtractors = 0;
    let factoriesCount = 0;

    filteredPiData.forEach((c) => {
        c.planets.forEach((p) => {
            totalPlanetsCount++;
            p.pins.forEach((pin) => {
                if (pin.category === 'extractor') {
                    if (pin.extractor_info && pin.extractor_info.qty_per_cycle > 0) {
                        activeExtractors++;
                    } else {
                        idleExtractors++;
                    }
                } else if (pin.category === 'factory') {
                    factoriesCount++;
                }
            });
        });
    });

    return (
        <div className="p-6">
            {/* Header section with styling matching app.css variables */}
            <div className="flex justify-between items-center flex-wrap gap-6 mb-8 bg-gradient-to-br from-eve-primary/5 to-black/40 border border-eve-border rounded-lg p-6">
                <div className="flex-grow">
                    <h1 className="text-3xl font-extrabold text-eve-primary mb-2">Planetary Interaction (PI)</h1>
                    <p className="text-eve-muted text-sm m-0">Übersicht deiner planetaren Produktionslinien und Lagerbestände</p>
                </div>

                <div className="flex gap-4 flex-wrap">
                    <div className="bg-black/30 border border-white/5 rounded-md py-3 px-5 min-w-[120px] text-center">
                        <div className="text-xs text-eve-muted uppercase tracking-wider mb-1">Planeten Gesamt</div>
                        <div className="text-2xl font-bold text-white">{totalPlanetsCount}</div>
                    </div>
                    <div className="bg-black/30 border border-white/5 rounded-md py-3 px-5 min-w-[120px] text-center">
                        <div className="text-xs text-eve-muted uppercase tracking-wider mb-1">Aktive Extraktoren</div>
                        <div className="text-2xl font-bold text-emerald-400">{activeExtractors}</div>
                    </div>
                    <div className="bg-black/30 border border-white/5 rounded-md py-3 px-5 min-w-[120px] text-center">
                        <div className="text-xs text-eve-muted uppercase tracking-wider mb-1">Inaktive Extraktoren</div>
                        <div className="text-2xl font-bold text-amber-400">{idleExtractors}</div>
                    </div>
                    <div className="bg-black/30 border border-white/5 rounded-md py-3 px-5 min-w-[120px] text-center">
                        <div className="text-xs text-eve-muted uppercase tracking-wider mb-1">Fabriken</div>
                        <div className="text-2xl font-bold text-white">{factoriesCount}</div>
                    </div>
                </div>
            </div>

            {/* Filter and control panel */}
            <div className="flex gap-4 mb-8 flex-wrap items-center">
                <div className="filter-item search-input-wrapper">
                    <span className="search-icon">🔍</span>
                    <input
                        type="text"
                        placeholder="Filter nach Planet, System, Material, Fabrik..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                    />
                </div>

                {allTags.length > 0 && (
                    <div className="filter-item">
                        <select
                            value={selectedTag}
                            onChange={(e) => setSelectedTag(e.target.value)}
                            className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                        >
                            <option value="all">-- Alle Tags --</option>
                            {allTags.map(tag => (
                                <option key={tag} value={tag}>{tag}</option>
                            ))}
                        </select>
                    </div>
                )}

                <div className="filter-item">
                    <select
                        value={selectedSystem}
                        onChange={(e) => setSelectedSystem(e.target.value)}
                        className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                    >
                        <option value="">-- Alle Systeme --</option>
                        {uniqueSystems.map((sys) => (
                            <option key={sys} value={sys}>
                                {sys}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="filter-item">
                    <select
                        value={selectedMaterial}
                        onChange={(e) => setSelectedMaterial(e.target.value)}
                        className="rounded px-3 py-1.5 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                    >
                        <option value="">-- Alle Materialien --</option>
                        {uniqueMaterials.map((mat) => (
                            <option key={mat} value={mat}>
                                {mat}
                            </option>
                        ))}
                    </select>
                </div>

                {(searchQuery || selectedSystem || selectedMaterial) && (
                    <button
                        onClick={() => {
                            setSearchQuery('');
                            setSelectedSystem('');
                            setSelectedMaterial('');
                        }}
                        className="inline-flex items-center justify-center border border-white/10 hover:border-eve-primary text-eve-text hover:text-eve-primary bg-white/5 hover:bg-white/10 rounded px-2.5 py-1 text-xs font-medium transition-all duration-300 cursor-pointer"
                    >
                        Zurücksetzen
                    </button>
                )}
            </div>

            {/* Loading / Error states */}
            {loading && (
                <div className="flex flex-col items-center justify-center p-16 text-eve-muted gap-4">
                    <div className="w-10 h-10 border-4 border-eve-primary/10 border-t-eve-primary rounded-full animate-spin"></div>
                    <p>Lade Planeten und API-Daten von ESI...</p>
                </div>
            )}

            {error && (
                <div className="p-4 rounded-md mb-8 bg-rose-500/10 border border-rose-500/30 text-rose-400">
                    <strong>Fehler:</strong> {error}
                </div>
            )}

            {!loading && !error && (
                <div className="pi-content">

                    <div className="flex flex-col gap-6">
                        {filteredPiData.length === 0 ? (
                            <div className="bg-eve-card border border-eve-border rounded-lg p-6 text-center text-eve-muted">
                                <p>Keine Planeten entsprechen deinen Filterkriterien.</p>
                            </div>
                        ) : (
                            filteredPiData.map((charData) => (
                                <div key={charData.character_id} className="bg-eve-card border border-eve-border rounded-lg overflow-hidden">
                                    <div
                                        className="flex justify-between items-center p-4 bg-black/20 border-b border-white/5 cursor-pointer select-none"
                                        onClick={() => toggleCharacter(charData.character_id)}
                                    >
                                        <div className="char-info">
                                            <img
                                                src={getCharacterPortraitUrl(charData.character_id)}
                                                alt={charData.character_name}
                                                className="w-8 h-8 rounded-full border border-eve-primary"
                                            />
                                            <h2>{charData.character_name}</h2>
                                             <span className="px-2 py-0.5 text-xs font-semibold rounded bg-eve-primary/10 text-eve-primary border border-eve-primary/20">
                                                {charData.planets.length} Planeten
                                             </span>
                                        </div>
                                        <span className="collapse-arrow">
                                            {collapsedCharacters[charData.character_id] ? '▶' : '▼'}
                                        </span>
                                    </div>

                                    {charData.error && (
                                        <div className="char-error p-4 rounded bg-amber-500/10 border border-amber-500/30 text-amber-400">
                                            {charData.error}
                                        </div>
                                    )}

                                    {!collapsedCharacters[charData.character_id] && (
                                        <>
                                            {(() => {
                                                const unassignedList = charData.unassigned_pocos || [];
                                                const unassignedPocos = unassignedList.filter((poco) => {
                                                    const isNpcStation = poco.location_id >= 60000000 && poco.location_id < 64000000;
                                                    const nameLower = poco.name.toLowerCase();
                                                    const isPocoName = nameLower.includes('zollamt') ||
                                                        nameLower.includes('customs office') ||
                                                        nameLower.includes('poco') ||
                                                        nameLower.includes('custom office') ||
                                                        nameLower === 'spieler-struktur';

                                                    return !isNpcStation && isPocoName;
                                                });

                                                if (unassignedPocos.length === 0) {
                                                    return null;
                                                }

                                                    return (
                                                        <div className="char-unassigned-pocos-alert" style={{
                                                            margin: '1rem',
                                                            padding: '1rem',
                                                            borderRadius: '4px',
                                                            backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                                            border: '1px solid rgba(255, 193, 7, 0.3)'
                                                        }}>
                                                            <div style={{
                                                                display: 'flex',
                                                                alignItems: 'center',
                                                                marginBottom: '0.5rem'
                                                            }}>
                                                                <span style={{
                                                                    fontSize: '1.2rem',
                                                                    marginRight: '0.5rem'
                                                                }}>⚠️</span>
                                                                <strong style={{color: '#ffc107'}}>Nicht zugeordnete
                                                                    Zolllager (POCOs) gefunden</strong>
                                                            </div>
                                                            <p style={{
                                                                fontSize: '0.85rem',
                                                                marginBottom: '1rem',
                                                                color: 'var(--theme-text-muted)',
                                                                lineHeight: '1.4'
                                                            }}>
                                                                Es wurden PI-Materialien in Zolllagern (POCOs) gefunden, die
                                                                nicht automatisch einem Planeten zugeordnet sind.
                                                                Wähle unten einen Planeten aus, um das Zollamt direkt zu verknüpfen (benennt die Struktur automatisch in <code>Custom-Office - Planet [Typ] - [Name]</code> um), oder passe den Namen manuell in der <a
                                                                href="/profile/assets" style={{
                                                                textDecoration: 'underline',
                                                                color: 'var(--theme-primary)'
                                                            }}>Assets-Übersicht</a> an.
                                                            </p>
                                                            <div style={{
                                                                display: 'flex',
                                                                flexDirection: 'column',
                                                                gap: '0.75rem'
                                                            }}>
                                                                {unassignedPocos.map((poco) => (
                                                                    <UnassignedPocoRow
                                                                        key={poco.location_id}
                                                                        poco={poco}
                                                                        planets={charData.planets}
                                                                        apiDataUrl={apiDataUrl}
                                                                        setLoading={setLoading}
                                                                        setPiData={setPiData}
                                                                        getTypeIconUrl={getTypeIconUrl}
                                                                        jwtToken={jwtToken}
                                                                    />
                                                                ))}
                                                            </div>
                                                        </div>
                                                    );
                                                })()}
                                            <div className="p-6 grid grid-cols-1 gap-6">
                                                {charData.planets.map((planet) => {
                                                    const isCollapsed = collapsedPlanets[planet.planet_id] !== false;

                                                    // Group pins by category
                                                    const commandCenters = planet.pins.filter(p => p.category === 'command_center');
                                                    const launchpads = planet.pins.filter(p => p.category === 'launchpad');
                                                    const storages = planet.pins.filter(p => p.category === 'storage');
                                                    const extractors = planet.pins.filter(p => p.category === 'extractor');
                                                    const factories = planet.pins.filter(p => p.category === 'factory');

                                                    // Calculate Launchpad capacity and utilization
                                                    const launchpadCapacity = launchpads.length * 10000; // 10,000 m3 per Launchpad
                                                    let launchpadVolumeUsed = 0;
                                                    launchpads.forEach((pin) => {
                                                        pin.contents.forEach((item) => {
                                                            launchpadVolumeUsed += item.quantity * (item.volume ?? 0);
                                                        });
                                                    });
                                                    const launchpadPercent = launchpadCapacity > 0 ? (launchpadVolumeUsed / launchpadCapacity) * 100 : 0;

                                                    // Calculate remaining extractor program time
                                                    let maxRemainingMs = -1;
                                                    let hasActiveExtractor = false;

                                                    extractors.forEach((pin) => {
                                                        if (pin.expiry_time) {
                                                            const expiryTime = new Date(pin.expiry_time).getTime();
                                                            const remaining = expiryTime - Date.now();
                                                            if (remaining > 0) {
                                                                hasActiveExtractor = true;
                                                                if (remaining > maxRemainingMs) {
                                                                    maxRemainingMs = remaining;
                                                                }
                                                            }
                                                        }
                                                    });

                                                    // Determine what is extracted (P0) on this planet
                                                    interface ExtractedMaterial {
                                                        typeId: number;
                                                        name: string;
                                                        ratePerHour: number;
                                                        totalRemainingQty?: number;
                                                    }

                                                    const extractedMaterials: ExtractedMaterial[] = [];

                                                    if (extractors.length > 0) {
                                                        // Raw outputs from extractors (P0)
                                                        const extractorOutputs: Record<number, {
                                                            name: string;
                                                            ratePerHour: number
                                                        }> = {};
                                                        extractors.forEach((pin) => {
                                                            if (pin.extractor_info) {
                                                                const cycleTimeHours = pin.extractor_info.cycle_time / 3600;
                                                                if (cycleTimeHours > 0) {
                                                                    const rate = pin.extractor_info.qty_per_cycle / cycleTimeHours;
                                                                    const typeId = pin.extractor_info.product_type_id;
                                                                    if (typeId > 0) {
                                                                        if (!extractorOutputs[typeId]) {
                                                                            extractorOutputs[typeId] = {
                                                                                name: pin.extractor_info.product_name,
                                                                                ratePerHour: 0
                                                                            };
                                                                        }
                                                                        extractorOutputs[typeId].ratePerHour += rate;
                                                                    }
                                                                }
                                                            }
                                                        });

                                                        Object.entries(extractorOutputs).forEach(([typeIdStr, data]) => {
                                                            const typeId = parseInt(typeIdStr, 10);
                                                            extractedMaterials.push({
                                                                typeId,
                                                                name: data.name,
                                                                ratePerHour: data.ratePerHour,
                                                                totalRemainingQty: maxRemainingMs > 0 ? data.ratePerHour * (maxRemainingMs / (1000 * 3600)) : undefined
                                                            });
                                                        });
                                                    }

                                                    // Determine what is produced (e.g. P1/P2/P3/P4) on this planet
                                                    interface ProducedMaterial {
                                                        typeId: number;
                                                        name: string;
                                                        ratePerHour: number;
                                                    }

                                                    const producedMaterials: ProducedMaterial[] = [];

                                                    if (factories.length > 0) {
                                                        const factoryOutputs: Record<number, {
                                                            name: string;
                                                            ratePerHour: number
                                                        }> = {};
                                                        factories.forEach((pin) => {
                                                            if (pin.factory_info && pin.factory_info.outputs) {
                                                                const cycleTimeHours = pin.factory_info.cycle_time / 3600;
                                                                if (cycleTimeHours > 0) {
                                                                    pin.factory_info.outputs.forEach((out) => {
                                                                        const rate = out.quantity / cycleTimeHours;
                                                                        const typeId = out.type_id;
                                                                        if (typeId > 0) {
                                                                            if (!factoryOutputs[typeId]) {
                                                                                factoryOutputs[typeId] = {
                                                                                    name: out.name,
                                                                                    ratePerHour: 0
                                                                                };
                                                                            }
                                                                            factoryOutputs[typeId].ratePerHour += rate;
                                                                        }
                                                                    });
                                                                }
                                                            }
                                                        });

                                                        Object.entries(factoryOutputs).forEach(([typeIdStr, data]) => {
                                                            const typeId = parseInt(typeIdStr, 10);
                                                            producedMaterials.push({
                                                                typeId,
                                                                name: data.name,
                                                                ratePerHour: data.ratePerHour,
                                                            });
                                                        });
                                                    }

                                                    // 1. Determine if it's a production planet (has factories but NO extractors)
                                                    const isProduction = factories.length > 0 && extractors.length === 0;

                                                    let statusText = '';
                                                    let statusClass = '';

                                                    if (isProduction) {
                                                        // Calculate supply duration
                                                        // Stock: Launchpads + Storages + Command Centers
                                                        const stock: Record<number, number> = {};
                                                        planet.pins.forEach((pin) => {
                                                            if (pin.contents) {
                                                                pin.contents.forEach((item) => {
                                                                    stock[item.type_id] = (stock[item.type_id] || 0) + item.quantity;
                                                                });
                                                            }
                                                        });

                                                        // Consumption rate per hour
                                                        const consumption: Record<number, number> = {};
                                                        factories.forEach((pin) => {
                                                            if (pin.factory_info) {
                                                                const cycleTimeHours = pin.factory_info.cycle_time / 3600;
                                                                if (cycleTimeHours > 0) {
                                                                    pin.factory_info.inputs.forEach((input) => {
                                                                        const ratePerHour = input.quantity / cycleTimeHours;
                                                                        consumption[input.type_id] = (consumption[input.type_id] || 0) + ratePerHour;
                                                                    });
                                                                }
                                                            }
                                                        });

                                                        let minDurationHours = Infinity;
                                                        let hasConsumption = false;
                                                        Object.entries(consumption).forEach(([typeIdStr, rate]) => {
                                                            const typeId = parseInt(typeIdStr, 10);
                                                            if (rate > 0) {
                                                                hasConsumption = true;
                                                                const currentStock = stock[typeId] || 0;
                                                                const durationHours = currentStock / rate;
                                                                if (durationHours < minDurationHours) {
                                                                    minDurationHours = durationHours;
                                                                }
                                                            }
                                                        });

                                                                if (hasConsumption) {
                                                                    if (minDurationHours === Infinity) {
                                                                        statusText = 'Unbekannt';
                                                                        statusClass = 'bg-white/10 text-eve-muted border border-white/5';
                                                                    } else if (minDurationHours === 0) {
                                                                        statusText = 'Vorrat LEER!';
                                                                        statusClass = 'bg-rose-500/15 text-rose-400 border border-rose-500/30';
                                                                    } else {
                                                                        const totalHours = minDurationHours;
                                                                        if (totalHours >= 24) {
                                                                            const days = Math.floor(totalHours / 24);
                                                                            const hours = Math.round(totalHours % 24);
                                                                            statusText = `Vorrat: ${days}d ${hours}h`;
                                                                        } else {
                                                                            statusText = `Vorrat: ${Math.round(totalHours)}h`;
                                                                        }
                                                                        statusClass = totalHours < 6 ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30' : (totalHours < 24 ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30');
                                                                    }
                                                                } else {
                                                                    statusText = 'Kein Verbrauch';
                                                                    statusClass = 'bg-white/10 text-eve-muted border border-white/5';
                                                                }
                                                            } else if (extractors.length > 0) {
                                                                // Pure extractor planet: calculate extraction time remaining
                                                                if (hasActiveExtractor && maxRemainingMs > 0) {
                                                                    const totalHours = maxRemainingMs / (1000 * 60 * 60);
                                                                    if (totalHours >= 24) {
                                                                        const days = Math.floor(totalHours / 24);
                                                                        const hours = Math.round(totalHours % 24);
                                                                        statusText = `Abbau: ${days}d ${hours}h`;
                                                                    } else {
                                                                        const mins = Math.round((maxRemainingMs / (1000 * 60)) % 60);
                                                                        statusText = `Abbau: ${Math.floor(totalHours)}h ${mins}m`;
                                                                    }
                                                                    statusClass = 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30';
                                                                } else {
                                                                    statusText = 'Abbau beendet!';
                                                                    statusClass = 'bg-rose-500/15 text-rose-400 border border-rose-500/30';
                                                                }
                                                            } else {
                                                                statusText = 'Inaktiv';
                                                                statusClass = 'bg-white/10 text-eve-muted border border-white/5';
                                                            }

                                                    return (
                                                        <div key={planet.planet_id}
                                                             className={`planet-card planet-type-${planet.type}`}>
                                                            <div
                                                                className="flex justify-between items-center py-3 px-4 bg-white/[0.02] border-b border-white/5 cursor-pointer select-none"
                                                                onClick={() => togglePlanet(planet.planet_id)}
                                                            >
                                                                <div className="flex items-center gap-2 flex-wrap">
                                                                <span
                                                                    className={`planet-type-badge type-${planet.type}`}>
                                                                    {planet.type}
                                                                </span>
                                                                    <h3 className="text-lg font-bold text-[#eee] m-0">{planet.name}</h3>
                                                                    <span
                                                                        className="text-eve-muted text-sm">({planet.solar_system_name})</span>
                                                                </div>

                                                                <div className="flex items-center gap-2">
                                                                    {producedMaterials.map((mat) => (
                                                                        <span
                                                                            key={mat.typeId}
                                                                            className="inline-flex items-center gap-1 px-2 py-0.5 bg-white/5 border border-white/10 rounded text-xs text-eve-text"
                                                                            title={`${mat.name} (Hergestellt)`}
                                                                            style={{ borderStyle: 'dashed' }}
                                                                        >
                                                                            <img
                                                                                src={getTypeIconUrl(mat.typeId)}
                                                                                alt={mat.name}
                                                                            />
                                                                        </span>
                                                                    ))}
                                                                    {launchpadCapacity > 0 && (
                                                                        <span
                                                                            className={`px-2 py-0.5 text-xs font-semibold rounded ${launchpadPercent >= 90 ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30' : (launchpadPercent >= 75 ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30')}`}
                                                                            title={`Launchpad-Auslastung: ${Math.round(launchpadVolumeUsed).toLocaleString()} / ${launchpadCapacity.toLocaleString()} m³ (${Math.round(launchpadPercent)}%)`}
                                                                        >
                                                                        🚀 {Math.round(launchpadPercent)}%
                                                                    </span>
                                                                    )}
                                                                    <span className={`px-2 py-0.5 text-xs font-semibold rounded ${statusClass}`}>
                                                                    {statusText}
                                                                </span>
                                                                    <span className="collapse-arrow">
                                                                    {isCollapsed ? '▶' : '▼'}
                                                                </span>
                                                                </div>
                                                            </div>


                                                            {!isCollapsed && (
                                                                <div className="planet-card-body">
                                                                    {planet.routes && planet.routes.length > 0 && (
                                                                        <PIRouteVisualizer
                                                                            pins={planet.pins}
                                                                            routes={planet.routes}
                                                                            getTypeIconUrl={getTypeIconUrl}
                                                                        />
                                                                    )}

                                                                    {/* Extractors (P0) section */}
                                                                    {extractors.length > 0 && (
                                                                        <div className="border-b border-dashed border-white/5 pb-5">
                                                                            <div className="section-title">
                                                                                <h4>⛏️ Extraktion (P0-Material)</h4>
                                                                            </div>
                                                                            <div className="flex flex-col gap-3">
                                                                                {extractors.map((pin) => {
                                                                                    if (!pin.extractor_info) return null;
                                                                                    const cycleTimeHours = pin.extractor_info.cycle_time / 3600;
                                                                                    const rate = cycleTimeHours > 0 ? pin.extractor_info.qty_per_cycle / cycleTimeHours : 0;
                                                                                    const typeId = pin.extractor_info.product_type_id;

                                                                                    let remainingStr = 'Kein aktives Programm';
                                                                                    if (pin.expiry_time) {
                                                                                        const expiryTime = new Date(pin.expiry_time).getTime();
                                                                                        const remaining = expiryTime - Date.now();
                                                                                        if (remaining > 0) {
                                                                                            const totalHours = remaining / (1000 * 60 * 60);
                                                                                            if (totalHours >= 24) {
                                                                                                const days = Math.floor(totalHours / 24);
                                                                                                const hours = Math.round(totalHours % 24);
                                                                                                remainingStr = `Abbau läuft: ${days}d ${hours}h verbleibend`;
                                                                                            } else {
                                                                                                const mins = Math.round((remaining / (1000 * 60)) % 60);
                                                                                                remainingStr = `Abbau läuft: ${Math.floor(totalHours)}h ${mins}m verbleibend`;
                                                                                            }
                                                                                        } else {
                                                                                            remainingStr = 'Abbau beendet!';
                                                                                        }
                                                                                    }

                                                                                    return (
                                                                                        <div key={pin.pin_id} className="bg-white/[0.01] border border-white/[0.04] rounded-md p-3" style={{ padding: '0.75rem' }}>
                                                                                            <div className="flex justify-between items-center mb-2 border-b border-white/[0.02] pb-1" style={{ borderBottom: 'none', paddingBottom: 0 }}>
                                                                                                <h5 style={{ margin: 0, fontSize: '0.95rem', display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                                                                    <img
                                                                                                        src={getTypeIconUrl(typeId)}
                                                                                                        alt={pin.extractor_info.product_name}
                                                                                                        style={{ width: '20px', height: '20px' }}
                                                                                                    />
                                                                                                    {pin.extractor_info.product_name}
                                                                                                </h5>
                                                                                                <span className="text-xs text-eve-muted" style={{ fontSize: '0.8rem' }}>
                                                                                                    ~{Math.round(rate).toLocaleString()}/h ({pin.extractor_info.heads_count} Köpfe)
                                                                                                </span>
                                                                                            </div>
                                                                                            <div style={{ fontSize: '0.8rem', color: 'var(--theme-text-muted)', marginTop: '0.25rem', paddingLeft: '28px' }}>
                                                                                                {remainingStr}
                                                                                            </div>
                                                                                        </div>
                                                                                    );
                                                                                })}
                                                                            </div>
                                                                        </div>
                                                                    )}

                                                                    {/* Custom Office (POCO) section */}
                                                                    <div className="border-b border-dashed border-white/5 pb-5">
                                                                        <div className="section-title">
                                                                            <h4>🪐 Zollamt
                                                                                (POCO): {planet.poco.name}</h4>
                                                                            {planet.poco.resolved ? (
                                                                                <span
                                                                                    className="resolved-status text-emerald-400">✓ Verbunden</span>
                                                                            ) : (
                                                                                <span
                                                                                    className="resolved-status text-muted">⚠ Unverbunden</span>
                                                                            )}
                                                                        </div>
                                                                        {planet.poco.contents.length === 0 ? (
                                                                            <p className="empty-text">Keine Materialien
                                                                                im Zollamt gelagert.</p>
                                                                        ) : (
                                                                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                                                                                {planet.poco.contents.map((item) => (
                                                                                    <div key={item.type_id}
                                                                                         className="flex items-center gap-1.5 bg-white/[0.03] border border-white/[0.05] p-1.5 px-2 rounded text-xs">
                                                                                        <img
                                                                                            src={getTypeIconUrl(item.type_id)}
                                                                                            alt={item.name}
                                                                                            className="item-icon"/>
                                                                                        <span
                                                                                            className="text-amber-400 font-bold font-mono">{item.quantity.toLocaleString()}x</span>
                                                                                        <span
                                                                                            className="text-[#ccc] truncate">{item.name}</span>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        )}
                                                                    </div>

                                                                    {/* Launchpads and Storage Silos */}
                                                                    <div className="border-b border-dashed border-white/5 pb-5">
                                                                        <h4>📦 Startrampen & Lager</h4>
                                                                        {[...launchpads, ...storages].length === 0 ? (
                                                                            <p className="empty-text">Keine Startrampen
                                                                                oder Lagerhallen gefunden.</p>
                                                                        ) : (
                                                                            <div className="flex flex-col gap-3">
                                                                                {[...launchpads, ...storages].map((pin) => {
                                                                                    const capacity = pin.category === 'launchpad' ? 10000 : 40000;
                                                                                    let usedVolume = 0;
                                                                                    pin.contents.forEach((item) => {
                                                                                        usedVolume += item.quantity * (item.volume ?? 0);
                                                                                    });
                                                                                    const percent = capacity > 0 ? (usedVolume / capacity) * 100 : 0;

                                                                                    return (
                                                                                        <div key={pin.pin_id}
                                                                                             className="bg-white/[0.01] border border-white/[0.04] rounded-md p-3">
                                                                                            <div
                                                                                                className="flex justify-between items-center mb-2 border-b border-white/[0.02] pb-1">
                                                                                                <h5>{pin.name}</h5>
                                                                                                <span
                                                                                                    className="text-xs text-eve-muted">
                                                                                                {pin.category === 'launchpad' ? 'Startrampe' : 'Lagersilo'}
                                                                                                    {` (${Math.round(usedVolume).toLocaleString()} / ${capacity.toLocaleString()} m³ - ${Math.round(percent)}%)`}
                                                                                            </span>
                                                                                            </div>

                                                                                            <div
                                                                                                className="h-1 bg-white/5 rounded-sm overflow-hidden my-2">
                                                                                                <div
                                                                                                    className={`pi-progress-bar ${percent >= 90 ? 'bg-rose-500' : (percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500')}`}
                                                                                                    style={{width: `${Math.min(percent, 100)}%`}}
                                                                                                />
                                                                                            </div>

                                                                                            {pin.contents.length === 0 ? (
                                                                                                <p className="empty-text-indent">Lager
                                                                                                    ist leer.</p>
                                                                                            ) : (
                                                                                                <div
                                                                                                    className="materials-grid-indent">
                                                                                                    {pin.contents.map((item) => (
                                                                                                        <div
                                                                                                            key={item.type_id}
                                                                                                            className="flex items-center gap-1.5 bg-white/[0.03] border border-white/[0.05] p-1.5 px-2 rounded text-xs">
                                                                                                            <img
                                                                                                                src={getTypeIconUrl(item.type_id)}
                                                                                                                alt={item.name}
                                                                                                                className="item-icon"/>
                                                                                                            <span
                                                                                                                className="text-amber-400 font-bold font-mono">{item.quantity.toLocaleString()}x</span>
                                                                                                            <span
                                                                                                                className="text-[#ccc] truncate">{item.name}</span>
                                                                                                        </div>
                                                                                                    ))}
                                                                                                </div>
                                                                                            )}

                                                                                        </div>
                                                                                    );
                                                                             })}
                                                                            </div>
                                                                        )}
                                                                    </div>

                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
