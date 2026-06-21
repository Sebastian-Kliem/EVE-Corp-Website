import React, { useState } from 'react';

interface AssetNode {
    itemId: number;
    typeId: number;
    name: string;
    customName?: string | null;
    quantity: number;
    locationFlag: string;
    isBlueprintCopy: boolean;
    isBlueprint?: boolean;
    isSingleton: boolean;
    price?: number;
    materialEfficiency?: number | null;
    timeEfficiency?: number | null;
    runs?: number | null;
    children: AssetNode[];
}

function getAssetValue(node: AssetNode): number {
    const ownVal = node.typeId === 0 ? 0 : (node.price || 0) * node.quantity;
    let childrenVal = 0;
    if (node.children && node.children.length > 0) {
        node.children.forEach(child => {
            childrenVal += getAssetValue(child);
        });
    }
    return ownVal + childrenVal;
}

interface LocationData {
    id: number;
    name: string;
    systemName: string;
    items: AssetNode[];
}

interface Character {
    id: number;
    name: string;
    lastAssetsUpdate: string | null;
}

interface CharacterData {
    character: Character;
    walletBalance: number;
    locations: LocationData[];
}

interface AssetsOverviewProps {
    totalWallet: number;
    characterData: CharacterData[];
    imagePaths: {
        types: string;
        characters: string;
    };
    profileUrl: string;
    jwtToken: string;
}

export default function AssetsOverview({
    totalWallet,
    characterData,
    imagePaths,
    profileUrl,
    jwtToken,
}: AssetsOverviewProps) {
    const [searchQuery, setSearchQuery] = useState('');

    // Tracks which character panels are expanded. Initially all character panels are expanded.
    const [expandedCharacters, setExpandedCharacters] = useState<Record<number, boolean>>(() => {
        const initial: Record<number, boolean> = {};
        characterData.forEach((d) => {
            initial[d.character.id] = true;
        });
        return initial;
    });

    // Tracks which locations are expanded.
    const [expandedLocations, setExpandedLocations] = useState<Record<string, boolean>>({});

    // Tracks which asset nodes (containers/ships) are expanded.
    const [expandedNodes, setExpandedNodes] = useState<Record<number, boolean>>({});

    // Active category filter
    const [activeFilter, setActiveFilter] = useState<string>('all');

    // Structure renaming states
    const [editingStructureId, setEditingStructureId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');
    const [editSystem, setEditSystem] = useState('');
    const [editError, setEditError] = useState<string | null>(null);
    const [isSaving, setIsSaving] = useState(false);
    const [localLocations, setLocalLocations] = useState<Record<number, { name: string; systemName: string }>>({});

    const startEditing = (location: LocationData) => {
        setEditingStructureId(location.id);
        const currentOverride = localLocations[location.id];
        setEditName(currentOverride?.name ?? (location.name === 'Spieler-Struktur' ? '' : location.name));
        setEditSystem(currentOverride?.systemName ?? (location.systemName === 'Unbekannt' ? '' : location.systemName));
        setEditError(null);
    };

    const handleSave = async (locationId: number) => {
        if (!editName.trim()) {
            setEditError('Name darf nicht leer sein.');
            return;
        }

        setIsSaving(true);
        setEditError(null);

        try {
            const response = await fetch(`/api/structures/${locationId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${jwtToken}`,
                },
                body: JSON.stringify({
                    name: editName.trim(),
                    solarSystemName: editSystem.trim(),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                setEditError(data.message || 'Fehler beim Speichern.');
            } else {
                setLocalLocations((prev) => ({
                    ...prev,
                    [locationId]: {
                        name: data.name,
                        systemName: data.solarSystemName,
                    },
                }));
                setEditingStructureId(null);
            }
        } catch (e) {
            setEditError('Netzwerkfehler beim Speichern.');
        } finally {
            setIsSaving(false);
        }
    };

    const getTypeIconUrl = (item: AssetNode) => {
        let url = imagePaths.types.replace('12345', item.typeId.toString());
        if (item.isBlueprint) {
            if (item.isBlueprintCopy) {
                url = url.replace('/icon', '/bpc');
            } else {
                url = url.replace('/icon', '/bp');
            }
        }
        return url;
    };

    const getCharacterPortraitUrl = (charId: number) => {
        return imagePaths.characters.replace('12345', charId.toString());
    };

    const toggleCharacter = (charId: number) => {
        setExpandedCharacters((prev) => ({
            ...prev,
            [charId]: !prev[charId],
        }));
    };

    const toggleLocation = (locKey: string) => {
        setExpandedLocations((prev) => ({
            ...prev,
            [locKey]: !prev[locKey],
        }));
    };

    const toggleNode = (nodeId: number) => {
        setExpandedNodes((prev) => ({
            ...prev,
            [nodeId]: !prev[nodeId],
        }));
    };

    // Recursive function to filter asset nodes based on search query and active filter
    const filterAssetNode = (node: AssetNode, query: string, filter: string): { node: AssetNode | null; hasMatch: boolean } => {
        const matchesQuery = query === '' ||
            node.name.toLowerCase().includes(query) ||
            (node.customName && node.customName.toLowerCase().includes(query));

        let matchesFilter = true;
        if (filter !== 'all') {
            if (filter === 'highvalue') {
                const itemValue = node.typeId === 0 ? 0 : (node.price || 0) * node.quantity;
                matchesFilter = itemValue >= 10000000; // >= 10M ISK
            } else {
                // @ts-ignore
                matchesFilter = node.category === filter;
            }
        }

        let filteredChildren: AssetNode[] = [];
        let anyChildMatches = false;

        if (node.children && node.children.length > 0) {
            node.children.forEach((child) => {
                const result = filterAssetNode(child, query, filter);
                if (result.hasMatch && result.node) {
                    filteredChildren.push(result.node);
                    anyChildMatches = true;
                }
            });
        }

        const isDirectMatch = node.typeId !== 0 && matchesQuery && matchesFilter;
        const hasMatch = isDirectMatch || anyChildMatches;

        if (hasMatch) {
            return {
                node: {
                    ...node,
                    children: filteredChildren,
                },
                hasMatch: true,
            };
        }

        return { node: null, hasMatch: false };
    };

    // Filter characters, locations and items based on search query and active filter
    const queryNormalized = searchQuery.toLowerCase().trim();
    const isSearching = queryNormalized !== '';
    const isFiltering = activeFilter !== 'all';

    const processedCharacterData = characterData.map((data) => {
        const filteredLocations = data.locations.map((loc) => {
            let filteredItems: AssetNode[] = [];

            loc.items.forEach((item) => {
                if (isSearching || isFiltering) {
                    const result = filterAssetNode(item, queryNormalized, activeFilter);
                    if (result.node) {
                        filteredItems.push(result.node);
                    }
                } else {
                    filteredItems.push(item);
                }
            });

            return {
                ...loc,
                items: filteredItems,
            };
        }).filter((loc) => loc.items.length > 0);

        return {
            ...data,
            locations: filteredLocations,
        };
    }).filter((data) => data.locations.length > 0);

    const hasCharacters = characterData.length > 0;

    // Helper component to render nested assets recursively
    const RenderAssetNode = ({ item }: { item: AssetNode }) => {
        const hasChildren = item.children && item.children.length > 0;
        const isNodeExpanded = isSearching || isFiltering || !!expandedNodes[item.itemId];

        return (
            <div className="asset-tree-node" data-item-name={item.name}>
                <div
                    className={`py-1 asset-header-row ${hasChildren ? 'has-children' : ''}`}
                    onClick={() => hasChildren && toggleNode(item.itemId)}
                >
                    {item.typeId === 0 ? (
                        item.name === 'Schiffe (Ships)' ? (
                            <span className="asset-item-icon" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: '1.2rem', margin: 0, padding: 0 }}>🚀</span>
                        ) : (
                            <span className="asset-item-icon" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: '1.2rem', margin: 0, padding: 0 }}>📁</span>
                        )
                    ) : item.typeId === 27 ? (
                        <span className="asset-item-icon" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: '1.2rem', margin: 0, padding: 0 }}>🏢</span>
                    ) : (
                        <img
                            src={getTypeIconUrl(item)}
                            alt={item.name}
                            className="asset-item-icon"
                            loading="lazy"
                        />
                    )}

                    <div className="asset-item-details">
                        <div className="asset-item-name-row" style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                            <span className="asset-item-name">
                                {item.customName ? (
                                    <>
                                        <strong>{item.customName}</strong>{' '}
                                        <span className="has-text-grey">({item.name})</span>
                                    </>
                                ) : (
                                    item.name
                                )}
                            </span>
                            {item.isBlueprint ? (
                                <span className="tags mb-0" style={{ display: 'inline-flex', gap: '4px' }}>
                                    {item.isBlueprintCopy ? (
                                        <>
                                            <span className="asset-blueprint-tag bpc">Kopie</span>
                                            {item.runs !== undefined && item.runs !== null && item.runs >= 0 && (
                                                <span className="asset-blueprint-tag runs">{item.runs} Runs</span>
                                            )}
                                        </>
                                    ) : (
                                        <span className="asset-blueprint-tag bpo">Original</span>
                                    )}
                                    {item.materialEfficiency !== undefined && item.materialEfficiency !== null && (
                                        <span className="asset-blueprint-tag me">ME: {item.materialEfficiency}%</span>
                                    )}
                                    {item.timeEfficiency !== undefined && item.timeEfficiency !== null && (
                                        <span className="asset-blueprint-tag te">TE: {item.timeEfficiency}%</span>
                                    )}
                                </span>
                            ) : item.isBlueprintCopy ? (
                                <span className="tag is-info is-light is-small asset-item-tag">Kopie</span>
                            ) : hasChildren ? (
                                <span className="tag is-small asset-item-tag is-content-badge">
                                    📦 {item.children.length} {item.children.length === 1 ? 'Inhalt' : 'Inhalte'}
                                </span>
                            ) : null}
                        </div>
                        <div className="asset-item-info-row" style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
                            <span className="asset-item-quantity">
                                x{item.quantity.toLocaleString('de-DE')}
                            </span>
                            <span className="asset-item-flag">{item.locationFlag}</span>
                            {item.price && item.price > 0 ? (
                                <>
                                    <span style={{ color: 'var(--theme-text-muted)', fontSize: '0.85rem' }}>
                                        Einzelwert: {item.price.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                    <span style={{ color: 'var(--theme-primary)', fontSize: '0.85rem', fontWeight: 600 }}>
                                        Gesamtwert: {getAssetValue(item).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                </>
                            ) : (
                                getAssetValue(item) > 0 && (
                                    <span style={{ color: 'var(--theme-primary)', fontSize: '0.85rem', fontWeight: 600 }}>
                                        Gesamtwert: {getAssetValue(item).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                )
                            )}
                        </div>
                    </div>
                </div>

                {hasChildren && (
                    <div className={`nested-children-container ${isNodeExpanded ? '' : 'is-hidden'}`}>
                        {item.children.map((child, idx) => (
                            <RenderAssetNode key={`${child.itemId}-${idx}`} item={child} />
                        ))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div>
            <div className="columns is-vcentered mb-4">
                <div className="column">
                    <p className="is-size-7 has-text-grey-light">Filtere und durchsuche das Inventar all deiner Charaktere.</p>
                </div>
                {hasCharacters && (
                    <div className="column is-narrow">
                        <div className="field mb-0">
                            <div className="control has-icons-left">
                                <input
                                    id="global-asset-search"
                                    className="input is-small assets-search-input assets-overview-search-input"
                                    type="text"
                                    placeholder="Gegenstände suchen..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                                <span className="icon is-small is-left">🔍</span>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Filter bar */}
            {hasCharacters && (
                <div className="assets-filter-bar mb-5" style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', background: 'rgba(255, 255, 255, 0.03)', padding: '12px', borderRadius: '6px', border: '1px solid rgba(255, 255, 255, 0.06)' }}>
                    <button
                        className={`button is-small ${activeFilter === 'all' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('all')}
                    >
                        🌐 Alle
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'ship' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('ship')}
                    >
                        🚀 Schiffe
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'blueprint' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('blueprint')}
                    >
                        📄 Blueprints
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'mineral' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('mineral')}
                    >
                        💎 Mineralien
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'ore' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('ore')}
                    >
                        ☄️ Erze
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'gas' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('gas')}
                    >
                        💨 Gase
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'pi' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('pi')}
                    >
                        🪐 PI-Materialien
                    </button>
                    <button
                        className={`button is-small ${activeFilter === 'highvalue' ? 'is-primary' : 'is-dark'}`}
                        onClick={() => setActiveFilter('highvalue')}
                    >
                        💰 Wertvoll (ab 10M)
                    </button>
                </div>
            )}

            {/* Character Accordion Panels */}
            {!hasCharacters ? (
                <div className="notification is-info">
                    Bisher sind keine EVE Online Charaktere mit diesem Account verknüpft.
                    Bitte verknüpfe einen Charakter über EVE SSO auf deinem Profil.
                </div>
            ) : processedCharacterData.length === 0 ? (
                <div className="notification is-warning">
                    Keine Gegenstände oder Charaktere gefunden, die Ihrer Suche entsprechen.
                </div>
            ) : (
                processedCharacterData.map((data) => {
                    const charId = data.character.id;
                    const isCharExpanded = !!expandedCharacters[charId];

                    return (
                        <div
                            key={charId}
                            className="box mb-5 character-panel-box assets-character-panel"
                            style={{ background: 'rgba(255, 255, 255, 0.03)', border: '1px solid rgba(255, 255, 255, 0.05)' }}
                        >
                            {/* Panel Header */}
                            <div
                                className="p-4 character-panel-header assets-character-header"
                                onClick={() => toggleCharacter(charId)}
                                style={{ cursor: 'pointer' }}
                            >
                                <div className="assets-character-header-left">
                                    <figure className="image is-24x24 m-0">
                                        <img
                                            src={getCharacterPortraitUrl(charId)}
                                            alt={data.character.name}
                                            className="is-rounded assets-character-avatar"
                                            loading="lazy"
                                        />
                                    </figure>
                                    <div>
                                        <span className="has-text-weight-bold assets-character-name">
                                            {data.character.name}
                                        </span>
                                        <span className="has-text-grey ml-2 assets-character-id">
                                            ID: {charId}
                                        </span>
                                    </div>
                                </div>
                                <div className="has-text-right" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end' }}>
                                    {(() => {
                                        const charAssetVal = data.locations.reduce((locSum, loc) => {
                                            return locSum + loc.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                        }, 0);
                                        const charTotal = data.walletBalance + charAssetVal;

                                        return (
                                            <>
                                                <span className="has-text-weight-bold assets-character-wallet" style={{ fontSize: '1.05rem', color: 'var(--theme-text)' }}>
                                                    Gesamt: {charTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                </span>
                                                <div style={{ fontSize: '0.8rem', color: 'var(--theme-text-muted)', marginTop: '0.2rem' }}>
                                                    Wallet: {data.walletBalance.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK |
                                                    Assets: {charAssetVal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                </div>
                                            </>
                                        );
                                    })()}
                                    <span className="has-text-grey block assets-character-wallet-block" style={{ fontSize: '0.75rem', marginTop: '0.2rem' }}>
                                        Stand:{' '}
                                        {data.character.lastAssetsUpdate
                                            ? data.character.lastAssetsUpdate
                                            : 'nie'}
                                    </span>
                                </div>
                            </div>

                            {/* Panel Content (Asset List) */}
                            <div
                                className={`character-panel-content character-assets-panel-content ${
                                    isCharExpanded ? '' : 'is-hidden'
                                }`}
                                id={`char-assets-${charId}`}
                            >
                                {data.locations.length === 0 ? (
                                    <p className="has-text-grey has-text-centered py-4">
                                        Bisher keine Inventar-Daten für diesen Charakter vorhanden. Der Cron-Job läuft im Hintergrund.
                                    </p>
                                ) : (
                                    data.locations.map((location) => {
                                        const locKey = `${charId}-${location.id}`;
                                        const isLocExpanded = isSearching || !!expandedLocations[locKey];
                                        const displayName = localLocations[location.id]?.name || location.name;

                                        return (
                                            <div
                                                key={locKey}
                                                className="location-block"
                                                data-location-name={displayName}
                                            >
                                                <h3
                                                    className="title is-6 location-header"
                                                    onClick={(e) => { e.stopPropagation(); toggleLocation(locKey); }}
                                                >
                                                    <span className="location-header-title" style={{ flexGrow: 1, marginRight: '1rem' }}>
                                                        {editingStructureId === location.id ? (
                                                            <div
                                                                style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', width: '100%' }}
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <input
                                                                    type="text"
                                                                    className="input is-small"
                                                                    style={{ width: '200px', background: 'rgba(0,0,0,0.4)', color: 'white', border: '1px solid var(--theme-card-border)' }}
                                                                    placeholder="Strukturname"
                                                                    value={editName}
                                                                    onChange={(e) => setEditName(e.target.value)}
                                                                    autoFocus
                                                                />
                                                                <input
                                                                    type="text"
                                                                    className="input is-small"
                                                                    style={{ width: '130px', background: 'rgba(0,0,0,0.4)', color: 'white', border: '1px solid var(--theme-card-border)' }}
                                                                    placeholder="Sonnensystem"
                                                                    value={editSystem}
                                                                    onChange={(e) => setEditSystem(e.target.value)}
                                                                />
                                                                <button
                                                                    className={`button is-primary is-small ${isSaving ? 'is-loading' : ''}`}
                                                                    onClick={() => handleSave(location.id)}
                                                                >
                                                                    Speichern
                                                                </button>
                                                                <button
                                                                    className="button is-dark is-small"
                                                                    onClick={() => setEditingStructureId(null)}
                                                                >
                                                                    Abbrechen
                                                                </button>
                                                                {editError && (
                                                                    <span style={{ color: '#f14668', fontSize: '0.8rem', marginLeft: '0.5rem' }}>
                                                                        ⚠️ {editError}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <>
                                                                <span>📍 {displayName}</span>
                                                                {location.id >= 1000000000000 && (displayName === 'Spieler-Struktur' || displayName.startsWith('Struktur #') || displayName.startsWith('Location #')) && (
                                                                    <button
                                                                        className="button is-dark is-small p-1 ml-2"
                                                                        style={{ border: 'none', background: 'transparent', opacity: 0.6 }}
                                                                        title="Struktur bearbeiten"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            startEditing(location);
                                                                        }}
                                                                    >
                                                                        ✏️
                                                                    </button>
                                                                )}
                                                            </>
                                                        )}
                                                    </span>
                                                    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                                                        <span className="tag is-dark is-rounded is-small font-family-monospace location-header-tag">
                                                            {location.items.length} Top-Level
                                                        </span>
                                                        {(() => {
                                                            const locVal = location.items.reduce((sum, item) => sum + getAssetValue(item), 0);
                                                            return (
                                                                <span className="tag is-info is-rounded is-small font-family-monospace location-header-tag" style={{ backgroundColor: 'rgba(0, 240, 255, 0.1)', border: '1px solid rgba(0, 240, 255, 0.2)', color: 'var(--theme-primary)' }}>
                                                                    {locVal.toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK
                                                                </span>
                                                            );
                                                        })()}
                                                    </div>
                                                </h3>

                                                <div
                                                    className={`asset-tree location-assets-container ${
                                                        isLocExpanded ? '' : 'is-hidden'
                                                    }`}
                                                >
                                                    {location.items.map((item, idx) => (
                                                        <RenderAssetNode
                                                            key={`${item.itemId}-${idx}`}
                                                            item={item}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    );
                })
            )}
        </div>
    );
}
