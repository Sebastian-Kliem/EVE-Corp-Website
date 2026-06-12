import React, { useState, useEffect } from 'react';

interface CharacterListItem {
    id: number;
    name: string;
    accountGroup: string;
    accountName: string;
}

interface Material {
    type_id: number;
    name: string;
    quantity: number;
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
    supplied_inputs?: RouteMaterial[];
    received_outputs?: RouteMaterial[];
}

interface PocoData {
    name: string;
    contents: Material[];
    resolved: boolean;
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
    error?: string;
}

interface PIOverviewProps {
    charactersList: CharacterListItem[];
    apiDataUrl: string;
    imagePaths: {
        types: string;
        characters: string;
    };
}

export default function PIOverview({
    charactersList,
    apiDataUrl,
    imagePaths,
}: PIOverviewProps) {
    const [loading, setLoading] = useState(true);
    const [piData, setPiData] = useState<CharacterPiData[]>([]);
    const [error, setError] = useState<string | null>(null);

    // Filter states
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedSystem, setSelectedSystem] = useState('');
    const [selectedMaterial, setSelectedMaterial] = useState('');

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
    }).filter(charData => charData.planets.length > 0 || searchQuery === '');

    // Summary calculation
    let totalPlanetsCount = 0;
    let activeExtractors = 0;
    let idleExtractors = 0;
    let factoriesCount = 0;

    piData.forEach((c) => {
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
                                                            <span className="badge badge-secondary">{planetCount} P</span>
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
                                        <div className="character-planets-grid">
                                            {charData.planets.map((planet) => {
                                                const isCollapsed = collapsedPlanets[planet.planet_id] !== false;
                                                
                                                // Group pins by category
                                                const commandCenters = planet.pins.filter(p => p.category === 'command_center');
                                                const launchpads = planet.pins.filter(p => p.category === 'launchpad');
                                                const storages = planet.pins.filter(p => p.category === 'storage');
                                                const extractors = planet.pins.filter(p => p.category === 'extractor');
                                                const factories = planet.pins.filter(p => p.category === 'factory');

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
                                                    let maxRemainingMs = -1;
                                                    let hasActiveExtractor = false;

                                                    extractors.forEach((pin) => {
                                                        if (pin.expiry_time) {
                                                            const expiryTime = new Date(pin.expiry_time).getTime();
                                                            const now = Date.now();
                                                            const remaining = expiryTime - now;
                                                            if (remaining > 0) {
                                                                hasActiveExtractor = true;
                                                                if (remaining > maxRemainingMs) {
                                                                    maxRemainingMs = remaining;
                                                                }
                                                            }
                                                        }
                                                    });

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
                                                    <div key={planet.planet_id} className={`planet-card planet-type-${planet.type}`}>
                                                        <div
                                                            className="planet-card-header"
                                                            onClick={() => togglePlanet(planet.planet_id)}
                                                        >
                                                            <div className="planet-meta">
                                                                <span className={`planet-type-badge type-${planet.type}`}>
                                                                    {planet.type}
                                                                </span>
                                                                <h3 className="planet-title">{planet.name}</h3>
                                                                <span className="planet-system">({planet.solar_system_name})</span>
                                                            </div>

                                                            <div className="planet-summary-badges">
                                                                <span className={`badge ${statusClass}`} style={{ marginRight: '8px', fontWeight: 'bold' }}>
                                                                    {statusText}
                                                                </span>
                                                                <span className="collapse-arrow">
                                                                    {isCollapsed ? '▶' : '▼'}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        {!isCollapsed && (
                                                            <div className="planet-card-body">
                                                                
                                                                {/* Custom Office (POCO) section */}
                                                                <div className="pi-section poco-section">
                                                                    <div className="section-title">
                                                                        <h4>🪐 Zollamt (POCO): {planet.poco.name}</h4>
                                                                        {planet.poco.resolved ? (
                                                                            <span className="resolved-status text-success">✓ Verbunden</span>
                                                                        ) : (
                                                                            <span className="resolved-status text-muted">⚠ Unverbunden</span>
                                                                        )}
                                                                    </div>
                                                                    {planet.poco.contents.length === 0 ? (
                                                                        <p className="empty-text">Keine Materialien im Zollamt gelagert.</p>
                                                                    ) : (
                                                                        <div className="materials-grid">
                                                                            {planet.poco.contents.map((item) => (
                                                                                <div key={item.type_id} className="material-item">
                                                                                    <img src={getTypeIconUrl(item.type_id)} alt={item.name} className="item-icon" />
                                                                                    <span className="item-qty">{item.quantity.toLocaleString()}x</span>
                                                                                    <span className="item-name">{item.name}</span>
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                {/* Launchpads and Storage Silos */}
                                                                <div className="pi-section storage-section">
                                                                    <h4>📦 Startrampen & Lager</h4>
                                                                    {[...launchpads, ...storages].length === 0 ? (
                                                                        <p className="empty-text">Keine Startrampen oder Lagerhallen gefunden.</p>
                                                                    ) : (
                                                                        <div className="storage-list">
                                                                            {[...launchpads, ...storages].map((pin) => (
                                                                                <div key={pin.pin_id} className="storage-card">
                                                                                    <div className="storage-card-header">
                                                                                        <h5>{pin.name}</h5>
                                                                                        <span className="storage-type-badge">{pin.category === 'launchpad' ? 'Startrampe (10.000 m³)' : 'Lagersilo (40.000 m³)'}</span>
                                                                                    </div>
                                                                                    
                                                                                    {pin.contents.length === 0 ? (
                                                                                        <p className="empty-text-indent">Lager ist leer.</p>
                                                                                    ) : (
                                                                                        <div className="materials-grid-indent">
                                                                                            {pin.contents.map((item) => (
                                                                                                <div key={item.type_id} className="material-item">
                                                                                                    <img src={getTypeIconUrl(item.type_id)} alt={item.name} className="item-icon" />
                                                                                                    <span className="item-qty">{item.quantity.toLocaleString()}x</span>
                                                                                                    <span className="item-name">{item.name}</span>
                                                                                                </div>
                                                                                            ))}
                                                                                        </div>
                                                                                    )}

                                                                                    {/* Production Line Routing: Inputs and Outputs */}
                                                                                    {(pin.supplied_inputs && pin.supplied_inputs.length > 0) && (
                                                                                        <div className="routing-block inputs">
                                                                                            <span className="route-direction">➡️ Versorgt Fabrik-Eingänge:</span>
                                                                                            <div className="routes-list">
                                                                                                {pin.supplied_inputs.map((route, idx) => (
                                                                                                    <div key={idx} className="route-item">
                                                                                                        <span className="route-desc">
                                                                                                            <strong>{route.material_name}</strong> ({route.quantity} Stk/Zyklus)
                                                                                                        </span>
                                                                                                        <span className="route-arrow">➔</span>
                                                                                                        <span className="route-dest" title={route.factory_name}>
                                                                                                            {route.schematic_name}
                                                                                                        </span>
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>
                                                                                        </div>
                                                                                    )}

                                                                                    {(pin.received_outputs && pin.received_outputs.length > 0) && (
                                                                                        <div className="routing-block outputs">
                                                                                            <span className="route-direction">⬅️ Empfängt Fabrik-Ausgänge:</span>
                                                                                            <div className="routes-list">
                                                                                                {pin.received_outputs.map((route, idx) => (
                                                                                                    <div key={idx} className="route-item">
                                                                                                        <span className="route-dest" title={route.factory_name}>
                                                                                                            {route.schematic_name}
                                                                                                        </span>
                                                                                                        <span className="route-arrow">➔</span>
                                                                                                        <span className="route-desc">
                                                                                                            <strong>{route.material_name}</strong> ({route.quantity} Stk/Zyklus)
                                                                                                        </span>
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>
                                                                                        </div>
                                                                                    )}
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                {/* Extractors */}
                                                                <div className="pi-section extractors-section">
                                                                    <h4>⚡ Rohstoffgewinnung (Extraktoren)</h4>
                                                                    {extractors.length === 0 ? (
                                                                        <p className="empty-text">Keine Extraktoren installiert.</p>
                                                                    ) : (
                                                                        <div className="extractor-list">
                                                                            {extractors.map((pin) => {
                                                                                const isRunning = pin.extractor_info && pin.extractor_info.qty_per_cycle > 0;
                                                                                
                                                                                // Calculate remaining time for this specific extractor
                                                                                let timeRemainingStr = '';
                                                                                if (pin.expiry_time) {
                                                                                    const expiryTime = new Date(pin.expiry_time).getTime();
                                                                                    const remaining = expiryTime - Date.now();
                                                                                    if (remaining > 0) {
                                                                                        const totalHours = remaining / (1000 * 60 * 60);
                                                                                        if (totalHours >= 24) {
                                                                                            const days = Math.floor(totalHours / 24);
                                                                                            const hours = Math.round(totalHours % 24);
                                                                                            timeRemainingStr = `${days}d ${hours}h`;
                                                                                        } else {
                                                                                            const mins = Math.round((remaining / (1000 * 60)) % 60);
                                                                                            timeRemainingStr = `${Math.floor(totalHours)}h ${mins}m`;
                                                                                        }
                                                                                    } else {
                                                                                        timeRemainingStr = 'Beendet';
                                                                                    }
                                                                                }

                                                                                return (
                                                                                    <div key={pin.pin_id} className={`extractor-card ${isRunning ? 'running' : 'idle'}`}>
                                                                                        <div className="extractor-card-header">
                                                                                            <h5>{pin.name}</h5>
                                                                                            <span className={`status-badge ${isRunning ? 'badge-success' : 'badge-danger'}`}>
                                                                                                {isRunning ? 'AKTIV' : 'INAKTIV / LEER'}
                                                                                            </span>
                                                                                        </div>
                                                                                        {isRunning && pin.extractor_info ? (
                                                                                            <div className="extractor-details">
                                                                                                <div className="detail-row">
                                                                                                    <span className="label">Produkt:</span>
                                                                                                    <span className="value font-weight-bold">
                                                                                                        <img src={getTypeIconUrl(pin.extractor_info.product_type_id)} className="item-icon-small" />
                                                                                                        {pin.extractor_info.product_name}
                                                                                                    </span>
                                                                                                </div>
                                                                                                <div className="detail-row">
                                                                                                    <span className="label">Ertrag / Zyklus:</span>
                                                                                                    <span className="value">
                                                                                                        {pin.extractor_info.qty_per_cycle.toLocaleString()} Einheiten
                                                                                                    </span>
                                                                                                </div>
                                                                                                <div className="detail-row">
                                                                                                    <span className="label">Zyklusdauer:</span>
                                                                                                    <span className="value">
                                                                                                        {(pin.extractor_info.cycle_time / 60).toFixed(0)} Min.
                                                                                                    </span>
                                                                                                </div>
                                                                                                {/* Only show heads details on production planets, omit for pure extractor planets */}
                                                                                                {isProduction && (
                                                                                                    <div className="detail-row">
                                                                                                        <span className="label">Bohrköpfe:</span>
                                                                                                        <span className="value">{pin.extractor_info.heads_count} Köpfe</span>
                                                                                                    </div>
                                                                                                )}
                                                                                                {timeRemainingStr && (
                                                                                                    <div className="detail-row">
                                                                                                        <span className="label">Restlaufzeit:</span>
                                                                                                        <span className="value font-weight-bold text-success">{timeRemainingStr}</span>
                                                                                                    </div>
                                                                                                )}
                                                                                            </div>
                                                                                        ) : (
                                                                                            <p className="empty-text-indent text-warning">Dieser Extraktor fördert derzeit keine Rohstoffe.</p>
                                                                                        )}
                                                                                    </div>
                                                                                );
                                                                            })}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                {/* Factories */}
                                                                <div className="pi-section factories-section">
                                                                    <h4>🏭 Produktionslinien (Fabriken)</h4>
                                                                    {factories.length === 0 ? (
                                                                        <p className="empty-text">Keine Fabriken installiert.</p>
                                                                    ) : (
                                                                        <div className="factory-grid">
                                                                            {factories.map((pin) => (
                                                                                <div key={pin.pin_id} className="factory-card">
                                                                                    <div className="factory-card-header">
                                                                                        <h5>{pin.name}</h5>
                                                                                        <span className="schematic-badge" title={pin.factory_info?.name ?? 'Kein Rezept'}>
                                                                                            {pin.factory_info?.name ?? 'Kein Rezept'}
                                                                                        </span>
                                                                                    </div>
                                                                                    
                                                                                    {pin.factory_info ? (
                                                                                        <div className="factory-recipe">
                                                                                            <div className="recipe-block inputs">
                                                                                                <h6>Eingang (Verbrauch):</h6>
                                                                                                {pin.factory_info.inputs.map((inp) => (
                                                                                                    <div key={inp.type_id} className="recipe-item">
                                                                                                        <img src={getTypeIconUrl(inp.type_id)} alt={inp.name} className="item-icon-small" />
                                                                                                        <span>{inp.quantity}x {inp.name}</span>
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>
                                                                                            
                                                                                            <div className="recipe-block outputs">
                                                                                                <h6>Ausgang (Produktion):</h6>
                                                                                                {pin.factory_info.outputs.map((out) => (
                                                                                                    <div key={out.type_id} className="recipe-item text-success font-weight-bold">
                                                                                                        <img src={getTypeIconUrl(out.type_id)} alt={out.name} className="item-icon-small" />
                                                                                                        <span>{out.quantity}x {out.name}</span>
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>

                                                                                            <div className="factory-meta">
                                                                                                <span>Zyklus: {(pin.factory_info.cycle_time / 60).toFixed(0)} Min.</span>
                                                                                            </div>
                                                                                        </div>
                                                                                    ) : (
                                                                                        <p className="empty-text-indent text-danger">Keine Produktionslinie eingestellt.</p>
                                                                                    )}
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
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
