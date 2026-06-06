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
    children: AssetNode[];
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
                    {item.typeId === 27 ? (
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
                            {item.isBlueprintCopy ? (
                                <span className="tag is-info is-light is-small asset-item-tag">Kopie</span>
                            ) : hasChildren ? (
                                <span className="tag is-small asset-item-tag is-content-badge">
                                    📦 {item.children.length} {item.children.length === 1 ? 'Inhalt' : 'Inhalte'}
                                </span>
                            ) : null}
                        </div>
                        <div className="asset-item-info-row">
                            <span className="asset-item-quantity">
                                x{item.quantity.toLocaleString('de-DE')}
                            </span>
                            <span className="asset-item-flag">{item.locationFlag}</span>
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
            <div className="box p-5 mb-5 assets-header-gradient">
                <div className="assets-header-bg-text">ISK</div>
                <div className="columns is-vcentered">
                    <div className="column">
                        <span className="has-text-grey-light is-size-6 uppercase-tracking">
                            Gesamtguthaben aller Accounts
                        </span>
                        <h1 className="title is-1 mt-1 assets-header-title">
                            {totalWallet.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}{' '}
                            <span className="is-size-3">ISK</span>
                        </h1>
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
                                <div className="has-text-right">
                                    <span className="has-text-weight-bold assets-character-wallet">
                                        {data.walletBalance.toLocaleString('de-DE', {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })}{' '}
                                        ISK
                                    </span>
                                    <span className="has-text-grey block assets-character-wallet-block">
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
                                                    <span className="tag is-dark is-rounded is-small font-family-monospace location-header-tag">
                                                        {location.items.length} Top-Level
                                                    </span>
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
