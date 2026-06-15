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
}

export default function AssetsOverview({
    totalWallet,
    characterData,
    imagePaths,
    profileUrl,
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

    // Tracks which locations are expanded. Initially all locations are collapsed (matching Twig display: none).
    const [expandedLocations, setExpandedLocations] = useState<Record<string, boolean>>({});

    // Tracks which asset nodes (containers/ships) are expanded.
    const [expandedNodes, setExpandedNodes] = useState<Record<number, boolean>>({});

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

    // Recursive function to filter asset nodes based on search query
    // Returns the filtered node (with matching children) and a boolean indicating if there's any match in this branch
    const filterAssetNode = (node: AssetNode, query: string): { node: AssetNode | null; hasMatch: boolean } => {
        const isSelfMatch = node.name.toLowerCase().includes(query) ||
            (node.customName && node.customName.toLowerCase().includes(query));

        let filteredChildren: AssetNode[] = [];
        let anyChildMatches = false;

        if (node.children && node.children.length > 0) {
            node.children.forEach((child) => {
                const result = filterAssetNode(child, query);
                if (result.hasMatch && result.node) {
                    filteredChildren.push(result.node);
                    anyChildMatches = true;
                }
            });
        }

        const hasMatch = isSelfMatch || anyChildMatches;

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

    // Filter characters, locations and items based on search query
    const queryNormalized = searchQuery.toLowerCase().trim();
    const isSearching = queryNormalized !== '';

    const processedCharacterData = characterData.map((data) => {
        const filteredLocations = data.locations.map((loc) => {
            let filteredItems: AssetNode[] = [];

            loc.items.forEach((item) => {
                if (isSearching) {
                    const result = filterAssetNode(item, queryNormalized);
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
        // Nodes are expanded if clicked by user, or automatically expanded if searching
        const isNodeExpanded = isSearching || !!expandedNodes[item.itemId];

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
            {/* Breadcrumbs */}
            <nav className="breadcrumb" aria-label="breadcrumbs">
                <ul>
                    <li>
                        <a href={profileUrl} className="assets-breadcrumbs-link">
                            👤 Profil
                        </a>
                    </li>
                    <li className="is-active">
                        <a href="#" aria-current="page" className="has-text-grey-light">
                            🎒 Gesamt-Inventar & Wallet
                        </a>
                    </li>
                </ul>
            </nav>

            {/* Header & Combined Wallet Balance */}
            <div className="box p-5 mb-5 assets-header-gradient" style={{ position: 'relative', overflow: 'hidden' }}>
                <div className="assets-header-bg-text">ISK</div>
                {(() => {
                    const totalAssetVal = characterData.reduce((charSum, char) => {
                        return charSum + char.locations.reduce((locSum, loc) => {
                            return locSum + loc.items.reduce((itemSum, item) => itemSum + getAssetValue(item), 0);
                        }, 0);
                    }, 0);
                    const netWorth = totalWallet + totalAssetVal;

                    return (
                        <div className="columns is-vcentered">
                            <div className="column">
                                <span className="has-text-grey-light is-size-6 uppercase-tracking" style={{ letterSpacing: '1px' }}>
                                    GESAMTVERMÖGEN (Wallet + Assets)
                                </span>
                                <h1 className="title is-1 mt-1 mb-3 assets-header-title" style={{ fontSize: '2.5rem', fontWeight: 700 }}>
                                    {netWorth.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}{' '}
                                    <span className="is-size-3">ISK</span>
                                </h1>
                                <div style={{ display: 'flex', gap: '2rem', flexWrap: 'wrap', borderTop: '1px solid rgba(255,255,255,0.08)', paddingTop: '0.75rem' }}>
                                    <div>
                                        <span className="has-text-grey-light is-size-7 uppercase-tracking">Wallet</span>
                                        <p style={{ fontWeight: 600, fontSize: '1rem', color: 'var(--theme-text)' }}>
                                            {totalWallet.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                        </p>
                                    </div>
                                    <div>
                                        <span className="has-text-grey-light is-size-7 uppercase-tracking">Assets</span>
                                        <p style={{ fontWeight: 600, fontSize: '1rem', color: 'var(--theme-primary)' }}>
                                            {totalAssetVal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                        </p>
                                    </div>
                                </div>
                            </div>
                            {hasCharacters && (
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
                    );
                })()}
            </div>

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
                        >
                            {/* Panel Header */}
                            <div
                                className="p-4 character-panel-header assets-character-header"
                                onClick={() => toggleCharacter(charId)}
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
                                        // Locations are expanded if search is active or if user clicked to expand
                                        const isLocExpanded = isSearching || !!expandedLocations[locKey];

                                        return (
                                            <div
                                                key={locKey}
                                                className="location-block"
                                                data-location-name={location.name}
                                            >
                                                <h3
                                                    className="title is-6 location-header"
                                                    onClick={() => toggleLocation(locKey)}
                                                >
                                                    <span className="location-header-title">
                                                        <span>{location.name}</span>
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
