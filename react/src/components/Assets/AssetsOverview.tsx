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
    tags?: string[];
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
    const [selectedTag, setSelectedTag] = useState<string>('all');

    // Collect all unique tags
    const allTags = React.useMemo(() => {
        const tags = new Set<string>();
        characterData.forEach(c => {
            if (c.character.tags) {
                c.character.tags.forEach(t => tags.add(t));
            }
        });
        return Array.from(tags).sort();
    }, [characterData]);

    const filteredCharacterDataByTag = React.useMemo(() => {
        if (selectedTag === 'all') return characterData;
        return characterData.filter(c => c.character.tags && c.character.tags.includes(selectedTag));
    }, [characterData, selectedTag]);

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

    const processedCharacterData = filteredCharacterDataByTag.map((data) => {
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

    const hasCharacters = filteredCharacterDataByTag.length > 0;

    // Helper component to render nested assets recursively
    const RenderAssetNode = ({ item }: { item: AssetNode }) => {
        const hasChildren = item.children && item.children.length > 0;
        const isNodeExpanded = isSearching || isFiltering || !!expandedNodes[item.itemId];

        return (
            <div className="ml-[2px]" data-item-name={item.name}>
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
                                        <span className="text-eve-muted">({item.name})</span>
                                    </>
                                ) : (
                                    item.name
                                )}
                            </span>
                            {item.isBlueprint ? (
                                <span className="tags mb-0" style={{ display: 'inline-flex', gap: '4px' }}>
                                    {item.isBlueprintCopy ? (
                                        <>
                                            <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-purple-500/15 text-purple-400 border border-purple-500/30">Kopie</span>
                                            {item.runs !== undefined && item.runs !== null && item.runs >= 0 && (
                                                <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-amber-500/15 text-amber-400 border border-amber-500/30">{item.runs} Runs</span>
                                            )}
                                        </>
                                    ) : (
                                        <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-orange-500/15 text-orange-400 border border-orange-500/30">Original</span>
                                    )}
                                    {item.materialEfficiency !== undefined && item.materialEfficiency !== null && (
                                        <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">ME: {item.materialEfficiency}</span>
                                    )}
                                    {item.timeEfficiency !== undefined && item.timeEfficiency !== null && (
                                        <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-sky-500/15 text-sky-400 border border-sky-500/30">TE: {item.timeEfficiency}</span>
                                    )}
                                </span>
                            ) : item.isBlueprintCopy ? (
                                <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-sky-500/10 text-sky-400 border border-sky-500/20 asset-item-tag">Kopie</span>
                            ) : hasChildren ? (
                                <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5 asset-item-tag is-content-badge">
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
                                    <span className="text-eve-muted text-[11px]">
                                        Einzelwert: {item.price.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                    <span className="text-eve-primary text-[11px] font-semibold">
                                        Gesamtwert: {getAssetValue(item).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                </>
                            ) : (
                                getAssetValue(item) > 0 && (
                                    <span className="text-eve-primary text-[11px] font-semibold">
                                        Gesamtwert: {getAssetValue(item).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </span>
                                )
                            )}
                        </div>
                    </div>
                </div>

                {hasChildren && (
                    <div className={`border-l border-dashed border-white/10 ml-1 mt-[2px] pl-3 ${isNodeExpanded ? '' : 'hidden'}`}>
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
            <div className="flex justify-between items-center flex-wrap gap-4 mb-4">
                <div className="flex-1 min-w-[250px]">
                    <p className="text-xs text-eve-muted">Filtere und durchsuche das Inventar all deiner Charaktere.</p>
                </div>
                {hasCharacters && (
                    <div className="flex gap-3 items-center ml-auto">
                        {allTags.length > 0 && (
                            <div className="relative">
                                <select
                                    value={selectedTag}
                                    onChange={(e) => setSelectedTag(e.target.value)}
                                    className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                                >
                                    <option value="all" style={{ background: '#101525' }}>Alle Tags</option>
                                    {allTags.map(tag => (
                                        <option key={tag} value={tag} style={{ background: '#101525' }}>{tag}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div className="relative flex items-center">
                            <span className="absolute left-2.5 text-xs text-eve-muted">🔍</span>
                            <input
                                id="global-asset-search"
                                className="rounded pl-7 pr-3 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 min-w-[200px]"
                                type="text"
                                placeholder="Gegenstände suchen..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                    </div>
                )}
            </div>

            {/* Filter bar */}
            {hasCharacters && (
                <div className="mb-5 flex gap-2 flex-wrap bg-white/2 p-3 rounded-lg border border-white/5">
                    {[
                        { key: 'all', label: '🌐 Alle' },
                        { key: 'ship', label: '🚀 Schiffe' },
                        { key: 'blueprint', label: '📄 Blueprints' },
                        { key: 'mineral', label: '💎 Mineralien' },
                        { key: 'ore', label: '☄️ Erze' },
                        { key: 'gas', label: '💨 Gase' },
                        { key: 'pi', label: '🪐 PI-Materialien' },
                        { key: 'highvalue', label: '💰 Wertvoll (ab 10M)' },
                    ].map((btn) => (
                        <button
                            key={btn.key}
                            className={`inline-flex items-center justify-center border rounded px-3 py-1 text-xs font-semibold transition-all duration-300 cursor-pointer ${
                                activeFilter === btn.key
                                    ? 'bg-eve-primary border-transparent text-[#060911]'
                                    : 'border-eve-border hover:border-eve-primary text-eve-text hover:text-eve-primary bg-transparent'
                            }`}
                            onClick={() => setActiveFilter(btn.key)}
                        >
                            {btn.label}
                        </button>
                    ))}
                </div>
            )}

            {/* Character Accordion Panels */}
            {!hasCharacters ? (
                <div className="notification is-info">
                    Bisher sind keine EVE Online Charaktere mit diesem Account verknüpft.
                    Bitte verknüpfe einen Charakter über EVE SSO auf deinem Profil.
                </div>
            ) : processedCharacterData.length === 0 ? (
                <div className="p-4 rounded-lg text-sm bg-amber-500/10 border border-amber-500/30 text-amber-400">
                    Keine Gegenstände oder Charaktere gefunden, die Ihrer Suche entsprechen.
                </div>
            ) : (
                processedCharacterData.map((data) => {
                    const charId = data.character.id;
                    const isCharExpanded = !!expandedCharacters[charId];

                    return (
                        <div
                            key={charId}
                            className="bg-eve-card border border-eve-border shadow-eve p-4 rounded-lg mb-4 character-panel-box assets-character-panel"
                        >
                            {/* Panel Header */}
                            <div
                                className="p-4 character-panel-header assets-character-header flex justify-between items-center cursor-pointer border-b border-white/5 pb-3 mb-3"
                                onClick={() => toggleCharacter(charId)}
                            >
                                <div className="assets-character-header-left flex items-center gap-3">
                                    <figure className="w-6 h-6 m-0 flex-shrink-0">
                                        <img
                                            src={getCharacterPortraitUrl(charId)}
                                            alt={data.character.name}
                                            className="rounded-full assets-character-avatar w-full h-full object-cover"
                                            loading="lazy"
                                        />
                                    </figure>
                                    <div className="flex items-center">
                                        <span className="font-bold assets-character-name text-white text-sm">
                                            {data.character.name}
                                        </span>
                                        <span className="text-eve-muted ml-2 text-xs assets-character-id">
                                            ID: {charId}
                                        </span>
                                    </div>
                                </div>
                                <div className="text-right flex flex-col items-end">
                                    {(() => {
                                        const charAssetVal = data.locations.reduce((locSum, loc) => {
                                            return locSum + loc.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                        }, 0);
                                        const charTotal = data.walletBalance + charAssetVal;

                                        return (
                                            <>
                                                <span className="font-bold assets-character-wallet text-sm text-white">
                                                    Gesamt: {charTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                </span>
                                                <div className="text-xs text-eve-muted mt-0.5">
                                                    Wallet: {data.walletBalance.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK |
                                                    Assets: {charAssetVal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                </div>
                                            </>
                                        );
                                    })()}
                                    <span className="text-eve-muted block text-[10px] mt-0.5 assets-character-wallet-block">
                                        Stand:{' '}
                                        {data.character.lastAssetsUpdate
                                            ? data.character.lastAssetsUpdate
                                            : 'nie'}
                                    </span>
                                </div>
                            </div>

                            {/* Panel Content (Asset List) */}
                            <div
                                className={`character-panel-content pt-3 pr-5 pb-3 pl-6 border-l border-dashed border-white/10 ml-9 ${
                                    isCharExpanded ? '' : 'hidden'
                                }`}
                                id={`char-assets-${charId}`}
                            >
                                {data.locations.length === 0 ? (
                                    <p className="text-eve-muted text-center py-4 text-xs">
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
                                                className="mb-4 last:mb-0 border border-white/5 rounded-lg overflow-hidden bg-black/10"
                                                data-location-name={displayName}
                                            >
                                                <h3
                                                    className="text-xs font-semibold flex justify-between items-center cursor-pointer p-3 bg-white/2 hover:bg-white/5 border-b border-white/5"
                                                    onClick={(e) => { e.stopPropagation(); toggleLocation(locKey); }}
                                                >
                                                    <span className="flex-grow mr-4 text-white flex items-center">
                                                        {editingStructureId === location.id ? (
                                                            <div
                                                                className="flex gap-2 items-center flex-wrap w-full"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <input
                                                                    type="text"
                                                                    className="rounded px-2 py-1 text-xs border border-eve-border text-white bg-black/40 focus:outline-none focus:border-eve-primary w-[200px]"
                                                                    placeholder="Strukturname"
                                                                    value={editName}
                                                                    onChange={(e) => setEditName(e.target.value)}
                                                                    autoFocus
                                                                />
                                                                <input
                                                                    type="text"
                                                                    className="rounded px-2 py-1 text-xs border border-eve-border text-white bg-black/40 focus:outline-none focus:border-eve-primary w-[130px]"
                                                                    placeholder="Sonnensystem"
                                                                    value={editSystem}
                                                                    onChange={(e) => setEditSystem(e.target.value)}
                                                                />
                                                                <button
                                                                    className={`inline-flex items-center justify-center border border-transparent rounded bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold text-xs px-2.5 py-1 shadow-eve transition-all duration-300 cursor-pointer ${isSaving ? 'opacity-50 pointer-events-none' : ''}`}
                                                                    onClick={() => handleSave(location.id)}
                                                                >
                                                                    Speichern
                                                                </button>
                                                                <button
                                                                    className="inline-flex items-center justify-center border border-eve-border hover:border-eve-primary text-eve-text hover:text-eve-primary bg-transparent rounded px-2.5 py-1 text-xs font-medium transition-all duration-300 cursor-pointer"
                                                                    onClick={() => setEditingStructureId(null)}
                                                                >
                                                                    Abbrechen
                                                                </button>
                                                                {editError && (
                                                                    <span className="text-rose-400 text-xs ml-2">
                                                                        ⚠️ {editError}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <>
                                                                <span>📍 {displayName}</span>
                                                                {location.id >= 1000000000000 && (displayName === 'Spieler-Struktur' || displayName.startsWith('Struktur #') || displayName.startsWith('Location #')) && (
                                                                    <button
                                                                        className="inline-flex items-center justify-center border border-transparent bg-transparent text-eve-text opacity-60 hover:opacity-100 p-1 ml-2 text-xs transition-all duration-200 cursor-pointer"
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
                                                    <div className="flex gap-2 items-center flex-shrink-0">
                                                        <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5 font-mono ">
                                                            {location.items.length} Top-Level
                                                        </span>
                                                        {(() => {
                                                            const locVal = location.items.reduce((sum, item) => sum + getAssetValue(item), 0);
                                                            return (
                                                                <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-eve-primary/10 text-eve-primary border border-eve-primary/20 font-mono ">
                                                                    {locVal.toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK
                                                                </span>
                                                            );
                                                        })()}
                                                    </div>
                                                </h3>

                                                <div
                                                    className={`asset-tree location-assets-container p-3 ${
                                                        isLocExpanded ? '' : 'hidden'
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
