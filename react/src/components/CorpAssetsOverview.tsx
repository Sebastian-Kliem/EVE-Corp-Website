import React, { useState } from 'react';

interface AssetNode {
    itemId: number;
    typeId: number;
    name: string;
    quantity: number;
    locationFlag: string;
    isBlueprintCopy: boolean;
    isSingleton: boolean;
    children: AssetNode[];
}

interface LocationData {
    id: number;
    name: string;
    systemName: string;
    items: AssetNode[];
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

    const getTypeIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', typeId.toString());
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

    // Recursive search filter
    const filterAssetNode = (node: AssetNode, query: string): { node: AssetNode | null; hasMatch: boolean } => {
        const isSelfMatch = node.name.toLowerCase().includes(query);

        let filteredChildren: AssetNode[] = [];
        let anyChildMatches = false;

        if (node.children && node.children.length > 0) {
            node.children.forEach((child) => {
                const { node: filteredChild, hasMatch } = filterAssetNode(child, query);
                if (hasMatch && filteredChild) {
                    filteredChildren.push(filteredChild);
                    anyChildMatches = true;
                }
            });
        }

        if (isSelfMatch || anyChildMatches) {
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

    const processedCorpData = corpData.map((data) => {
        if (!isSearching) {
            return data;
        }

        const filteredLocations = data.locations.map((loc) => {
            const matchedItems: AssetNode[] = [];
            loc.items.forEach((item) => {
                const { node, hasMatch } = filterAssetNode(item, query);
                if (hasMatch && node) {
                    matchedItems.push(node);
                }
            });

            return {
                ...loc,
                items: matchedItems,
            };
        }).filter((loc) => loc.items.length > 0);

        return {
            ...data,
            locations: filteredLocations,
        };
    }).filter((data) => data.locations.length > 0 || isSearching);

    const hasCorps = corpData.length > 0;

    const RenderAssetNode = ({ item }: { item: AssetNode }) => {
        const hasChildren = item.children && item.children.length > 0;
        const isNodeExpanded = isSearching || !!expandedNodes[item.itemId];

        return (
            <div className="asset-tree-node" data-item-name={item.name}>
                <div
                    className={`py-1 asset-header-row ${hasChildren ? 'has-children' : ''}`}
                    onClick={() => hasChildren && toggleNode(item.itemId)}
                >
                    <img
                        src={getTypeIconUrl(item.typeId)}
                        alt={item.name}
                        className="asset-item-icon"
                        loading="lazy"
                    />

                    <div className="asset-item-details">
                        <div className="asset-item-name-row">
                            <span className="asset-item-name">{item.name}</span>
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
                                <div className="has-text-right">
                                    {lastUpdate ? (
                                        <>
                                            <span className="has-text-grey block assets-character-wallet-block">
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
                                                    <span className="tag is-dark location-items-count">
                                                        {location.items.length} {location.items.length === 1 ? 'Gruppe' : 'Gruppen'}
                                                    </span>
                                                </h3>

                                                <div className={`location-content ${isLocExpanded ? '' : 'is-hidden'}`}>
                                                    <div className="asset-tree-container">
                                                        {location.items.map((item, idx) => (
                                                            <RenderAssetNode
                                                                key={`${item.itemId}-${idx}`}
                                                                item={item}
                                                            />
                                                        ))}
                                                    </div>
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
