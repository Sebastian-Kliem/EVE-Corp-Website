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
    category?: 'ship' | 'blueprint' | 'container' | 'mineral' | 'ore' | 'gas' | 'pi' | 'other';
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

interface DivisionData {
    name: string;
    items: AssetNode[];
}

interface LocationData {
    id: number;
    name: string;
    systemName: string;
    divisions: DivisionData[];
}

interface Corporation {
    id: number;
    name: string;
    lastAssetsUpdate: string | null;
    syncCharacterName: string | null;
}

interface CorporationData {
    corporation: Corporation;
    locations: LocationData[];
}

interface CorpAssetsOverviewProps {
    corpData: CorporationData[];
    imagePaths: {
        types: string;
        corporations: string;
    };
}

export default function CorpAssetsOverview({
    corpData,
    imagePaths,
}: CorpAssetsOverviewProps) {
    const [searchQuery, setSearchQuery] = useState('');
    
    // Tracks expanded corporations
    const [expandedCorps, setExpandedCorps] = useState<Record<number, boolean>>(() => {
        const initial: Record<number, boolean> = {};
        corpData.forEach((d) => {
            initial[d.corporation.id] = true;
        });
        return initial;
    });

    // Tracks expanded locations
    const [expandedLocations, setExpandedLocations] = useState<Record<string, boolean>>({});

    // Tracks expanded asset nodes
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

    const getCorpLogoUrl = (corpId: number) => {
        return imagePaths.corporations.replace('12345', corpId.toString());
    };

    const toggleCorp = (corpId: number) => {
        setExpandedCorps((prev) => ({
            ...prev,
            [corpId]: !prev[corpId],
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

    // Recursive search filter with active category filter
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
                matchesFilter = node.category === filter;
            }
        }

        let filteredChildren: AssetNode[] = [];
        let anyChildMatches = false;

        if (node.children && node.children.length > 0) {
            node.children.forEach((child) => {
                const { node: filteredChild, hasMatch } = filterAssetNode(child, query, filter);
                if (hasMatch && filteredChild) {
                    filteredChildren.push(filteredChild);
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

    const query = searchQuery.trim().toLowerCase();
    const isSearching = query.length > 0;
    const isFiltering = activeFilter !== 'all';

    const processedCorpData = corpData.map((data) => {
        if (!isSearching && !isFiltering) {
            return data;
        }

        const filteredLocations = data.locations.map((loc) => {
            const filteredDivisions = loc.divisions.map((div) => {
                const matchedItems: AssetNode[] = [];
                div.items.forEach((item) => {
                    const { node, hasMatch } = filterAssetNode(item, query, activeFilter);
                    if (hasMatch && node) {
                        matchedItems.push(node);
                    }
                });

                return {
                    ...div,
                    items: matchedItems,
                };
            }).filter((div) => div.items.length > 0);

            return {
                ...loc,
                divisions: filteredDivisions,
            };
        }).filter((loc) => loc.divisions.length > 0);

        return {
            ...data,
            locations: filteredLocations,
        };
    }).filter((data) => data.locations.length > 0 || isSearching || isFiltering);

    const hasCorps = corpData.length > 0;

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
                        <div className="asset-item-name-row">
                            {item.customName ? (
                                <div className="assets-item-name-wrapper">
                                    <span className="assets-item-custom-name">{item.customName}</span>
                                    <span className="assets-item-type-name">({item.name})</span>
                                </div>
                            ) : (
                                <span className="asset-item-name">{item.name}</span>
                            )}
                            {item.isBlueprint ? (
                                <span style={{ display: 'flex', gap: '4px', alignItems: 'center', marginLeft: '4px' }}>
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
        <div className="container mt-5 mb-6">
            {/* Header */}
            <div className="box p-5 mb-5 assets-header-gradient">
                <div className="assets-header-bg-text">CORP</div>
                <div className="columns is-vcentered">
                    <div className="column">
                        <span className="has-text-grey-light is-size-6 uppercase-tracking">
                            EVE Online Corporation
                        </span>
                        <h1 className="title is-1 mt-1 assets-header-title">
                            Corp-Inventar
                        </h1>
                    </div>
                    {hasCorps && (
                        <div className="column is-narrow">
                            <div className="field">
                                <div className="control has-icons-left">
                                    <input
                                        id="global-asset-search"
                                        className="input assets-search-input assets-overview-search-input"
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
            </div>

            {/* Filter bar */}
            {hasCorps && (
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

            {/* Accordion Panels */}
            {!hasCorps ? (
                <div className="notification is-info">
                    Bisher sind keine EVE Online Charaktere mit diesem Account verknüpft, die zu einer Corporation gehören.
                    Bitte verknüpfe einen Charakter über EVE SSO auf deinem Profil.
                </div>
            ) : processedCorpData.length === 0 ? (
                <div className="notification is-warning">
                    Keine Gegenstände oder Corporation-Daten gefunden, die Ihrer Suche entsprechen.
                </div>
            ) : (
                processedCorpData.map((data) => {
                    const corpId = data.corporation.id;
                    const isCorpExpanded = !!expandedCorps[corpId];
                    const lastUpdate = data.corporation.lastAssetsUpdate;
                    const syncCharName = data.corporation.syncCharacterName;

                    return (
                        <div
                            key={corpId}
                            className="box mb-5 character-panel-box assets-character-panel"
                        >
                            {/* Panel Header */}
                            <div
                                className="p-4 character-panel-header assets-character-header"
                                onClick={() => toggleCorp(corpId)}
                            >
                                <div className="assets-character-header-left">
                                    <figure className="image is-32x32 m-0">
                                        <img
                                            src={getCorpLogoUrl(corpId)}
                                            alt={data.corporation.name}
                                            className="assets-character-avatar"
                                            loading="lazy"
                                            style={{ borderRadius: '4px' }}
                                        />
                                    </figure>
                                    <div>
                                        <span className="has-text-weight-bold assets-character-name">
                                            {data.corporation.name}
                                        </span>
                                        <span className="has-text-grey ml-2 assets-character-id">
                                            ID: {corpId}
                                        </span>
                                    </div>
                                </div>
                                <div className="has-text-right" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end' }}>
                                    {lastUpdate ? (
                                        <>
                                            {(() => {
                                                const corpVal = data.locations.reduce((locSum, loc) => {
                                                    return locSum + loc.divisions.reduce((divSum, div) => {
                                                        return divSum + div.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                                    }, 0);
                                                }, 0);

                                                return (
                                                    <span className="has-text-weight-bold" style={{ fontSize: '1.05rem', color: 'var(--theme-text)' }}>
                                                        Wert: {corpVal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                    </span>
                                                );
                                            })()}
                                            <span className="has-text-grey block assets-character-wallet-block" style={{ fontSize: '0.75rem', marginTop: '0.2rem' }}>
                                                Stand: {lastUpdate}
                                            </span>
                                            <span className="has-text-grey block is-size-7" style={{ marginTop: '2px' }}>
                                                Synchronisiert über: {syncCharName}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="has-text-warning block" style={{ fontWeight: '500' }}>
                                            ⚠️ Keine Daten (SSO-Login von Director/CEO erforderlich)
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Panel Content (Asset List) */}
                            <div
                                className={`character-panel-content character-assets-panel-content ${
                                    isCorpExpanded ? '' : 'is-hidden'
                                }`}
                                id={`corp-assets-${corpId}`}
                            >
                                {!lastUpdate ? (
                                    <div className="p-5 has-text-centered">
                                        <p className="has-text-warning mb-3" style={{ fontWeight: '500' }}>
                                            Es wurden noch keine Corp-Assets für diese Corporation importiert.
                                        </p>
                                        <p className="has-text-grey is-size-7" style={{ maxWidth: '600px', margin: '0 auto' }}>
                                            Damit das Corp-Inventar ausgelesen werden kann, muss sich ein Charakter mit 
                                            entsprechenden Rechten (<strong>Director</strong> oder <strong>CEO</strong> in EVE Online, z.B. <em>Bobder Noob</em>) 
                                            auf der Profilseite per EVE-SSO anmelden. Die Daten werden danach automatisch per Cronjob aktualisiert.
                                        </p>
                                    </div>
                                ) : data.locations.length === 0 ? (
                                    <p className="has-text-grey has-text-centered py-4">
                                        Bisher keine Gegenstände für diese Corporation vorhanden.
                                    </p>
) : (
                                    data.locations.map((location) => {
                                        const locKey = `${corpId}-${location.id}`;
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
                                                    onClick={() => toggleLocation(locKey)}
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
                                                        <span className="tag is-dark location-items-count">
                                                            {location.divisions.length} {location.divisions.length === 1 ? 'Abteilung' : 'Abteilungen'}
                                                        </span>
                                                        {(() => {
                                                            const locVal = location.divisions.reduce((sum, div) => {
                                                                return sum + div.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                                            }, 0);
                                                            return (
                                                                <span className="tag is-info is-rounded is-small font-family-monospace location-header-tag" style={{ backgroundColor: 'rgba(0, 240, 255, 0.1)', border: '1px solid rgba(0, 240, 255, 0.2)', color: 'var(--theme-primary)' }}>
                                                                    {locVal.toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK
                                                                </span>
                                                            );
                                                        })()}
                                                    </div>
                                                </h3>

                                                <div className={`location-content ${isLocExpanded ? '' : 'is-hidden'}`}>
                                                    {location.divisions.map((div) => (
                                                        <div key={div.name} className="division-block mb-4" style={{ borderLeft: '2px solid #ffaa00', paddingLeft: '12px', marginTop: '12px' }}>
                                                            <div className="division-header mb-2" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', borderBottom: '1px solid rgba(255, 255, 255, 0.05)', paddingBottom: '4px' }}>
                                                                <span style={{ fontSize: '0.9rem', fontWeight: 'bold', color: '#ffaa00' }}>📁 {div.name}</span>
                                                                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                                                                    <span className="tag is-dark is-small" style={{ fontSize: '0.7rem' }}>{div.items.length}</span>
                                                                    {(() => {
                                                                        const divVal = div.items.reduce((sum, item) => sum + getAssetValue(item), 0);
                                                                        return (
                                                                            <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--theme-primary)' }}>
                                                                                {divVal.toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK
                                                                            </span>
                                                                        );
                                                                    })()}
                                                                </div>
                                                            </div>
                                                            <div className="asset-tree-container">
                                                                {div.items.map((item, idx) => (
                                                                    <RenderAssetNode
                                                                        key={`${item.itemId}-${idx}`}
                                                                        item={item}
                                                                    />
                                                                ))}
                                                            </div>
                                                        </div>
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
