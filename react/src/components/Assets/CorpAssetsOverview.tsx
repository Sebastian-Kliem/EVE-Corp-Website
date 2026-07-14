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
                                    <span className="assets-item-type-name text-eve-muted">({item.name})</span>
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
                    <div className={`nested-children-container ${isNodeExpanded ? '' : 'hidden'}`}>
                        {item.children.map((child, idx) => (
                            <RenderAssetNode key={`${child.itemId}-${idx}`} item={child} />
                        ))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="w-full max-w-[1200px] mx-auto px-6 mt-10 mb-12">
            {/* Header */}
            <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg mb-6 assets-header-gradient relative overflow-hidden">
                <div className="assets-header-bg-text">CORP</div>
                <div className="flex justify-between items-center flex-wrap gap-4">
                    <div className="flex-grow">
                        <span className="text-sm text-eve-muted uppercase tracking-wider">
                            EVE Online Corporation
                        </span>
                        <h1 className="text-3xl font-extrabold mt-1 text-white">
                            Corp-Inventar
                        </h1>
                    </div>
                    {hasCorps && (
                        <div className="relative flex items-center ml-auto">
                            <span className="absolute left-2.5 text-xs text-eve-muted">🔍</span>
                            <input
                                id="global-asset-search"
                                className="rounded pl-7 pr-3 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-[200px]"
                                type="text"
                                placeholder="Gegenstände suchen..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Filter bar */}
            {hasCorps && (
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

            {/* Accordion Panels */}
            {!hasCorps ? (
                <div className="p-4 rounded-lg text-sm bg-sky-500/10 border border-sky-500/30 text-sky-400">
                    Bisher sind keine EVE Online Charaktere mit diesem Account verknüpft, die zu einer Corporation gehören.
                    Bitte verknüpfe einen Charakter über EVE SSO auf deinem Profil.
                </div>
            ) : processedCorpData.length === 0 ? (
                <div className="p-4 rounded-lg text-sm bg-amber-500/10 border border-amber-500/30 text-amber-400">
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
                            className="bg-eve-card border border-eve-border shadow-eve p-4 rounded-lg mb-4 character-panel-box assets-character-panel"
                        >
                            {/* Panel Header */}
                            <div
                                className="p-4 character-panel-header assets-character-header flex justify-between items-center cursor-pointer border-b border-white/5 pb-3 mb-3"
                                onClick={() => toggleCorp(corpId)}
                            >
                                <div className="assets-character-header-left flex items-center gap-3">
                                    <figure className="w-8 h-8 m-0 flex-shrink-0">
                                        <img
                                            src={getCorpLogoUrl(corpId)}
                                            alt={data.corporation.name}
                                            className="assets-character-avatar w-full h-full object-cover rounded"
                                            loading="lazy"
                                        />
                                    </figure>
                                    <div className="flex items-center">
                                        <span className="font-bold assets-character-name text-white text-sm">
                                            {data.corporation.name}
                                        </span>
                                        <span className="text-eve-muted ml-2 text-xs assets-character-id">
                                            ID: {corpId}
                                        </span>
                                    </div>
                                </div>
                                <div className="text-right flex flex-col items-end">
                                    {lastUpdate ? (
                                        <>
                                            {(() => {
                                                const corpVal = data.locations.reduce((locSum, loc) => {
                                                    return locSum + loc.divisions.reduce((divSum, div) => {
                                                        return divSum + div.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                                    }, 0);
                                                }, 0);

                                                return (
                                                    <span className="font-bold text-sm text-white">
                                                        Wert: {corpVal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                                    </span>
                                                );
                                            })()}
                                            <span className="text-eve-muted block text-[10px] mt-0.5 assets-character-wallet-block">
                                                Stand: {lastUpdate}
                                            </span>
                                            <span className="text-eve-muted block text-xs mt-0.5">
                                                Synchronisiert über: {syncCharName}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-amber-400 block text-xs font-semibold">
                                            ⚠️ Keine Daten (SSO-Login von Director/CEO erforderlich)
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Panel Content (Asset List) */}
                            <div
                                className={`character-panel-content character-assets-panel-content ${
                                    isCorpExpanded ? '' : 'hidden'
                                }`}
                                id={`corp-assets-${corpId}`}
                            >
                                {!lastUpdate ? (
                                    <div className="p-5 text-center">
                                        <p className="text-amber-400 mb-3 text-sm font-semibold">
                                            Es wurden noch keine Corp-Assets für diese Corporation importiert.
                                        </p>
                                        <p className="text-eve-muted text-xs max-w-[600px] mx-auto leading-relaxed">
                                            Damit das Corp-Inventar ausgelesen werden kann, muss sich ein Charakter mit 
                                            entsprechenden Rechten (<strong>Director</strong> oder <strong>CEO</strong> in EVE Online, z.B. <em>Bobder Noob</em>) 
                                            auf der Profilseite per EVE-SSO anmelden. Die Daten werden danach automatisch per Cronjob aktualisiert.
                                        </p>
                                    </div>
                                ) : data.locations.length === 0 ? (
                                    <p className="text-eve-muted text-center py-4 text-xs">
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
                                                className="location-block mb-4 last:mb-0 border border-white/5 rounded-lg overflow-hidden bg-black/10"
                                                data-location-name={displayName}
                                            >
                                                <h3
                                                    className="text-xs font-semibold flex justify-between items-center cursor-pointer p-3 bg-white/2 hover:bg-white/5 location-header border-b border-white/5"
                                                    onClick={() => toggleLocation(locKey)}
                                                >
                                                    <span className="location-header-title flex-grow mr-4 text-white flex items-center">
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
                                                        <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5 font-mono">
                                                            {location.divisions.length} {location.divisions.length === 1 ? 'Abteilung' : 'Abteilungen'}
                                                        </span>
                                                        {(() => {
                                                            const locVal = location.divisions.reduce((sum, div) => {
                                                                return sum + div.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                                                            }, 0);
                                                            return (
                                                                <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-eve-primary/10 text-eve-primary border border-eve-primary/20 font-mono">
                                                                    {locVal.toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK
                                                                </span>
                                                            );
                                                        })()}
                                                    </div>
                                                </h3>

                                                <div className={`location-content p-3 ${isLocExpanded ? '' : 'hidden'}`}>
                                                    {location.divisions.map((div) => (
                                                        <div key={div.name} className="division-block mb-4 last:mb-0 border-l border-amber-500/50 pl-3 mt-3">
                                                            <div className="division-header mb-2 flex items-center justify-between gap-2 border-b border-white/5 pb-1">
                                                                <span className="text-xs font-bold text-amber-500">📁 {div.name}</span>
                                                                <div className="flex gap-2 items-center">
                                                                    <span className="px-1 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5">{div.items.length}</span>
                                                                    {(() => {
                                                                        const divVal = div.items.reduce((sum, item) => sum + getAssetValue(item), 0);
                                                                        return (
                                                                            <span className="text-xs font-semibold text-eve-primary">
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
