import React, {useState, useEffect} from 'react';

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
        <div style={{
            padding: '0.75rem',
            borderRadius: '4px',
            backgroundColor: 'rgba(255,255,255,0.05)',
            border: '1px solid rgba(255,255,255,0.05)'
        }}>
            <div style={{
                fontWeight: 'bold',
                fontSize: '0.9rem',
                marginBottom: '0.25rem',
                display: 'flex',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: '0.5rem'
            }}>
                <span>📍 {poco.name} <span style={{
                    color: 'var(--theme-text-muted)',
                    fontWeight: 'normal',
                    fontSize: '0.8rem'
                }}>(ID: {poco.location_id})</span></span>
                <span style={{
                    color: 'var(--theme-text-muted)',
                    fontSize: '0.8rem'
                }}>System: {poco.solar_system_name}</span>
            </div>
            <div style={{
                display: 'flex',
                gap: '0.5rem',
                flexWrap: 'wrap',
                marginTop: '0.25rem',
                marginBottom: '0.75rem'
            }}>
                {poco.contents.map((item) => (
                    <span key={item.type_id} style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '0.25rem',
                        fontSize: '0.8rem',
                        padding: '0.1rem 0.4rem',
                        borderRadius: '3px',
                        backgroundColor: 'rgba(255,255,255,0.08)'
                    }}>
                        <img src={getTypeIconUrl(item.type_id)}
                             alt={item.name} style={{
                            width: '16px',
                            height: '16px'
                        }}/>
                        {item.quantity.toLocaleString()}x {item.name}
                        {item.container && (
                            <span style={{
                                color: 'var(--theme-text-muted)',
                                fontSize: '0.75rem',
                                marginLeft: '0.25rem'
                            }}>
                                ({item.container})
                            </span>
                        )}
                    </span>
                ))}
            </div>
            <div style={{
                display: 'flex',
                gap: '0.5rem',
                alignItems: 'center',
                marginTop: '0.5rem',
                borderTop: '1px solid rgba(255,255,255,0.05)',
                paddingTop: '0.5rem'
            }}>
                <select
                    value={mappingPlanetId}
                    onChange={(e) => setMappingPlanetId(e.target.value)}
                    disabled={mappingLoading}
                    style={{
                        padding: '0.3rem 0.5rem',
                        borderRadius: '4px',
                        backgroundColor: 'var(--theme-bg-dark, #1e1e24)',
                        color: 'var(--theme-text, #ffffff)',
                        border: '1px solid rgba(255,255,255,0.15)',
                        fontSize: '0.85rem',
                        flex: '1'
                    }}
                >
                    <option value="">-- Planeten auswählen --</option>
                    {planets.map(planet => (
                        <option key={planet.planet_id} value={planet.planet_id}>
                            {planet.name} ({planet.type})
                        </option>
                    ))}
                </select>
                <button
                    onClick={handleMapPoco}
                    disabled={!mappingPlanetId || mappingLoading}
                    style={{
                        padding: '0.3rem 0.75rem',
                        borderRadius: '4px',
                        backgroundColor: 'var(--theme-primary, #007bff)',
                        color: '#fff',
                        border: 'none',
                        cursor: 'pointer',
                        fontSize: '0.85rem',
                        opacity: (!mappingPlanetId || mappingLoading) ? 0.6 : 1
                    }}
                >
                    {mappingLoading ? 'Verknüpfe...' : 'Verknüpfen'}
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

    // Group the characters in characterList by account group/name for sidebar or select
    const groupedAccounts: Record<string, Record<string, CharacterListItem[]>> = {};
    charactersList.forEach((char) => {
        const group = char.accountGroup;
        const account = char.accountName;
        if (!groupedAccounts[group]) {
            groupedAccounts[group] = {};
        }
        if (!groupedAccounts[group][account]) {
            groupedAccounts[group][account] = [];
        }
        groupedAccounts[group][account].push(char);
    });

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
        <div className="pi-dashboard">
            {/* Header section with styling matching app.css variables */}
            <div className="pi-header">
                <div className="pi-title-block">
                    <h1>Planetary Interaction (PI)</h1>
                    <p className="subtitle">Übersicht deiner planetaren Produktionslinien und Lagerbestände</p>
                </div>

                <div className="pi-stats-grid">
                    <div className="stat-card">
                        <div className="stat-label">Planeten Gesamt</div>
                        <div className="stat-value">{totalPlanetsCount}</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-label">Aktive Extraktoren</div>
                        <div className="stat-value text-success">{activeExtractors}</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-label">Inaktive Extraktoren</div>
                        <div className="stat-value text-warning">{idleExtractors}</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-label">Fabriken</div>
                        <div className="stat-value">{factoriesCount}</div>
                    </div>
                </div>
            </div>

            {/* Filter and control panel */}
            <div className="pi-filter-bar">
                <div className="filter-item search-input-wrapper">
                    <span className="search-icon">🔍</span>
                    <input
                        type="text"
                        placeholder="Filter nach Planet, System, Material, Fabrik..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="form-control input-dark"
                    />
                </div>

                {allTags.length > 0 && (
                    <div className="filter-item">
                        <select
                            value={selectedTag}
                            onChange={(e) => setSelectedTag(e.target.value)}
                            className="form-control select-dark"
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
                        className="form-control select-dark"
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
                        className="form-control select-dark"
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
                        className="btn btn-secondary btn-sm"
                    >
                        Zurücksetzen
                    </button>
                )}
            </div>

            {/* Loading / Error states */}
            {loading && (
                <div className="pi-loading-state">
                    <div className="spinner"></div>
                    <p>Lade Planeten und API-Daten von ESI...</p>
                </div>
            )}

            {error && (
                <div className="pi-error-state message-danger">
                    <strong>Fehler:</strong> {error}
                </div>
            )}

            {/* Main content grid */}
            {!loading && !error && (
                <div className="pi-content">
                    {/* Account configuration hierarchy info */}
                    <div className="pi-accounts-sidebar card-dark">
                        <h3>Charakter-Gruppen</h3>
                        <div className="sidebar-group-list">
                            {Object.entries(groupedAccounts).map(([groupName, accounts]) => (
                                <div key={groupName} className="sidebar-group">
                                    <h4 className="group-title">{groupName}</h4>
                                    {Object.entries(accounts).map(([accountName, chars]) => (
                                        <div key={accountName} className="sidebar-account">
                                            <div className="account-title">{accountName}</div>
                                            <div className="sidebar-chars">
                                                {chars.map((char) => {
                                                    const characterData = piData.find(c => c.character_id === char.id);
                                                    const planetCount = characterData?.planets.length ?? 0;
                                                    return (
                                                        <div key={char.id} className="sidebar-char-item">
                                                            <img
                                                                src={getCharacterPortraitUrl(char.id)}
                                                                alt={char.name}
                                                                className="char-icon"
                                                            />
                                                            <span className="char-name">{char.name}</span>
                                                            <span
                                                                className="badge badge-secondary">{planetCount} P</span>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="pi-planets-list">
                        {filteredPiData.length === 0 ? (
                            <div className="pi-empty-state card-dark">
                                <p>Keine Planeten entsprechen deinen Filterkriterien.</p>
                            </div>
                        ) : (
                            filteredPiData.map((charData) => (
                                <div key={charData.character_id} className="character-block card-dark">
                                    <div
                                        className="character-header"
                                        onClick={() => toggleCharacter(charData.character_id)}
                                    >
                                        <div className="char-info">
                                            <img
                                                src={getCharacterPortraitUrl(charData.character_id)}
                                                alt={charData.character_name}
                                                className="char-portrait"
                                            />
                                            <h2>{charData.character_name}</h2>
                                            <span className="badge badge-primary">
                                                {charData.planets.length} Planeten
                                            </span>
                                        </div>
                                        <span className="collapse-arrow">
                                            {collapsedCharacters[charData.character_id] ? '▶' : '▼'}
                                        </span>
                                    </div>

                                    {charData.error && (
                                        <div className="char-error message-warning">
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
                                            <div className="character-planets-grid">
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
                                                                statusClass = 'badge-secondary';
                                                            } else if (minDurationHours === 0) {
                                                                statusText = 'Vorrat LEER!';
                                                                statusClass = 'badge-danger';
                                                            } else {
                                                                const totalHours = minDurationHours;
                                                                if (totalHours >= 24) {
                                                                    const days = Math.floor(totalHours / 24);
                                                                    const hours = Math.round(totalHours % 24);
                                                                    statusText = `Vorrat: ${days}d ${hours}h`;
                                                                } else {
                                                                    statusText = `Vorrat: ${Math.round(totalHours)}h`;
                                                                }
                                                                statusClass = totalHours < 6 ? 'badge-danger' : (totalHours < 24 ? 'badge-warning' : 'badge-success');
                                                            }
                                                        } else {
                                                            statusText = 'Kein Verbrauch';
                                                            statusClass = 'badge-secondary';
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
                                                            statusClass = 'badge-success';
                                                        } else {
                                                            statusText = 'Abbau beendet!';
                                                            statusClass = 'badge-danger';
                                                        }
                                                    } else {
                                                        statusText = 'Inaktiv';
                                                        statusClass = 'badge-secondary';
                                                    }

                                                    return (
                                                        <div key={planet.planet_id}
                                                             className={`planet-card planet-type-${planet.type}`}>
                                                            <div
                                                                className="planet-card-header"
                                                                onClick={() => togglePlanet(planet.planet_id)}
                                                            >
                                                                <div className="planet-meta">
                                                                <span
                                                                    className={`planet-type-badge type-${planet.type}`}>
                                                                    {planet.type}
                                                                </span>
                                                                    <h3 className="planet-title">{planet.name}</h3>
                                                                    <span
                                                                        className="planet-system">({planet.solar_system_name})</span>
                                                                </div>

                                                                <div className="planet-summary-badges">
                                                                    {producedMaterials.map((mat) => (
                                                                        <span
                                                                            key={mat.typeId}
                                                                            className="planet-output-badge"
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
                                                                            className={`badge ${launchpadPercent >= 90 ? 'badge-danger' : (launchpadPercent >= 75 ? 'badge-warning' : 'badge-success')}`}
                                                                            title={`Launchpad-Auslastung: ${Math.round(launchpadVolumeUsed).toLocaleString()} / ${launchpadCapacity.toLocaleString()} m³ (${Math.round(launchpadPercent)}%)`}
                                                                        >
                                                                        🚀 {Math.round(launchpadPercent)}%
                                                                    </span>
                                                                    )}
                                                                    <span className={`badge ${statusClass}`}>
                                                                    {statusText}
                                                                </span>
                                                                    <span className="collapse-arrow">
                                                                    {isCollapsed ? '▶' : '▼'}
                                                                </span>
                                                                </div>
                                                            </div>


                                                            {!isCollapsed && (
                                                                <div className="planet-card-body">

                                                                    {/* Extractors (P0) section */}
                                                                    {extractors.length > 0 && (
                                                                        <div className="pi-section extractor-section">
                                                                            <div className="section-title">
                                                                                <h4>⛏️ Extraktion (P0-Material)</h4>
                                                                            </div>
                                                                            <div className="storage-list">
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
                                                                                        <div key={pin.pin_id} className="storage-card" style={{ padding: '0.75rem' }}>
                                                                                            <div className="storage-card-header" style={{ borderBottom: 'none', paddingBottom: 0 }}>
                                                                                                <h5 style={{ margin: 0, fontSize: '0.95rem', display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                                                                    <img
                                                                                                        src={getTypeIconUrl(typeId)}
                                                                                                        alt={pin.extractor_info.product_name}
                                                                                                        style={{ width: '20px', height: '20px' }}
                                                                                                    />
                                                                                                    {pin.extractor_info.product_name}
                                                                                                </h5>
                                                                                                <span className="storage-type-badge" style={{ fontSize: '0.8rem' }}>
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
                                                                    <div className="pi-section poco-section">
                                                                        <div className="section-title">
                                                                            <h4>🪐 Zollamt
                                                                                (POCO): {planet.poco.name}</h4>
                                                                            {planet.poco.resolved ? (
                                                                                <span
                                                                                    className="resolved-status text-success">✓ Verbunden</span>
                                                                            ) : (
                                                                                <span
                                                                                    className="resolved-status text-muted">⚠ Unverbunden</span>
                                                                            )}
                                                                        </div>
                                                                        {planet.poco.contents.length === 0 ? (
                                                                            <p className="empty-text">Keine Materialien
                                                                                im Zollamt gelagert.</p>
                                                                        ) : (
                                                                            <div className="materials-grid">
                                                                                {planet.poco.contents.map((item) => (
                                                                                    <div key={item.type_id}
                                                                                         className="material-item">
                                                                                        <img
                                                                                            src={getTypeIconUrl(item.type_id)}
                                                                                            alt={item.name}
                                                                                            className="item-icon"/>
                                                                                        <span
                                                                                            className="item-qty">{item.quantity.toLocaleString()}x</span>
                                                                                        <span
                                                                                            className="item-name">{item.name}</span>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        )}
                                                                    </div>

                                                                    {/* Launchpads and Storage Silos */}
                                                                    <div className="pi-section storage-section">
                                                                        <h4>📦 Startrampen & Lager</h4>
                                                                        {[...launchpads, ...storages].length === 0 ? (
                                                                            <p className="empty-text">Keine Startrampen
                                                                                oder Lagerhallen gefunden.</p>
                                                                        ) : (
                                                                            <div className="storage-list">
                                                                                {[...launchpads, ...storages].map((pin) => {
                                                                                    const capacity = pin.category === 'launchpad' ? 10000 : 40000;
                                                                                    let usedVolume = 0;
                                                                                    pin.contents.forEach((item) => {
                                                                                        usedVolume += item.quantity * (item.volume ?? 0);
                                                                                    });
                                                                                    const percent = capacity > 0 ? (usedVolume / capacity) * 100 : 0;

                                                                                    return (
                                                                                        <div key={pin.pin_id}
                                                                                             className="storage-card">
                                                                                            <div
                                                                                                className="storage-card-header">
                                                                                                <h5>{pin.name}</h5>
                                                                                                <span
                                                                                                    className="storage-type-badge">
                                                                                                {pin.category === 'launchpad' ? 'Startrampe' : 'Lagersilo'}
                                                                                                    {` (${Math.round(usedVolume).toLocaleString()} / ${capacity.toLocaleString()} m³ - ${Math.round(percent)}%)`}
                                                                                            </span>
                                                                                            </div>

                                                                                            <div
                                                                                                className="pi-progress-bar-container">
                                                                                                <div
                                                                                                    className={`pi-progress-bar ${percent >= 90 ? 'bg-danger' : (percent >= 75 ? 'bg-warning' : 'bg-success')}`}
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
                                                                                                            className="material-item">
                                                                                                            <img
                                                                                                                src={getTypeIconUrl(item.type_id)}
                                                                                                                alt={item.name}
                                                                                                                className="item-icon"/>
                                                                                                            <span
                                                                                                                className="item-qty">{item.quantity.toLocaleString()}x</span>
                                                                                                            <span
                                                                                                                className="item-name">{item.name}</span>
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
