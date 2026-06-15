import React, { useState } from 'react';

interface Character {
    id: number;
    name: string;
    lastAssetsUpdate: string | null;
}

interface AssetItem {
    typeId: number;
    name: string;
    customName?: string | null;
    quantity: number;
    locationFlag: string;
    isBlueprintCopy: boolean;
    isBlueprint?: boolean;
    isSingleton: boolean;
    materialEfficiency?: number | null;
    timeEfficiency?: number | null;
    runs?: number | null;
}

interface GroupedAsset {
    name: string;
    items: AssetItem[];
}

interface CharacterAssetsProps {
    character: Character;
    groupedAssets: Record<string, GroupedAsset>;
    imagePaths: {
        types: string;
        characters: string;
    };
    backUrl: string;
}

export default function CharacterAssets({
    character,
    groupedAssets,
    imagePaths,
    backUrl,
}: CharacterAssetsProps) {
    const [searchQuery, setSearchQuery] = useState('');

    const getTypeIconUrl = (item: AssetItem) => {
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

    // Filter the grouped assets based on the search query
    const filteredGroupedAssets = Object.entries(groupedAssets)
        .map(([locationId, group]) => {
            const filteredItems = group.items.filter((item) =>
                item.name.toLowerCase().includes(searchQuery.toLowerCase().trim()) ||
                (item.customName && item.customName.toLowerCase().includes(searchQuery.toLowerCase().trim()))
            );

            return {
                locationId,
                name: group.name,
                items: filteredItems,
            };
        })
        .filter((group) => group.items.length > 0);

    const hasAssets = Object.keys(groupedAssets).length > 0;

    return (
        <div className="container mt-5 mb-6">
            {/* Back Link */}
            <nav className="breadcrumb" aria-label="breadcrumbs">
                <ul>
                    <li>
                        <a href={backUrl} className="assets-breadcrumbs-link">
                            👤 Profil
                        </a>
                    </li>
                    <li className="is-active">
                        <a href="#" aria-current="page" className="has-text-grey-light">
                            🎒 Inventar von {character.name}
                        </a>
                    </li>
                </ul>
            </nav>

            {/* Header Section */}
            <div className="box p-5 assets-character-panel">
                <div className="columns is-vcentered">
                    <div className="column is-narrow">
                        <figure className="image is-64x64">
                            <img
                                src={getCharacterPortraitUrl(character.id)}
                                alt={character.name}
                                className="is-rounded assets-character-avatar-large"
                            />
                        </figure>
                    </div>
                    <div className="column">
                        <h1 className="title is-3 text-gradient mb-1">
                            Inventar von {character.name}
                        </h1>
                        <p className="subtitle is-6 has-text-grey">
                            Letzter Stand:{' '}
                            {character.lastAssetsUpdate ? (
                                character.lastAssetsUpdate
                            ) : (
                                'Noch nie aktualisiert (Cron-Job abwarten)'
                            )}
                        </p>
                    </div>
                    {hasAssets && (
                        <div className="column is-narrow">
                            {/* Search Box */}
                            <div className="field">
                                <div className="control has-icons-left">
                                    <input
                                        id="asset-search"
                                        className="input assets-search-input"
                                        type="text"
                                        placeholder="Inventar durchsuchen..."
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

            {/* Assets List */}
            {!hasAssets ? (
                <div className="notification is-info">
                    Für diesen Charakter wurden bisher keine Inventar-Daten in der Datenbank gefunden.
                    Der regelmäßige Abruf erfolgt über einen Cron-Job im Hintergrund.
                </div>
            ) : filteredGroupedAssets.length === 0 ? (
                <div className="notification is-warning">
                    Keine Gegenstände gefunden, die Ihrer Suche entsprechen.
                </div>
            ) : (
                <div id="assets-container">
                    {filteredGroupedAssets.map((group) => (
                        <div
                            key={group.locationId}
                            className="box mb-5 location-box assets-location-box"
                            data-location-name={group.name}
                        >
                            <h2 className="title is-5 mb-3 assets-location-title">
                                <span>📍 {group.name}</span>
                                <span className="tag is-dark is-rounded is-small font-family-monospace assets-location-count-tag">
                                    {group.items.length}{' '}
                                    {group.items.length === 1 ? 'Gegenstand' : 'Gegenstände'}
                                </span>
                            </h2>

                            <div className="table-container">
                                <table className="table is-fullwidth is-striped is-hoverable assets-table">
                                    <thead>
                                        <tr>
                                            <th className="assets-table-width-40"></th>
                                            <th>Gegenstand Name</th>
                                            <th className="assets-table-align-right">Menge</th>
                                            <th>Ort / Slot</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {group.items.map((item, index) => (
                                            <tr key={`${item.typeId}-${index}`} className="asset-item-row" data-item-name={item.name}>
                                                <td>
                                                    <figure className="image is-16x16">
                                                        <img
                                                            src={getTypeIconUrl(item)}
                                                            alt={item.name}
                                                            className="is-rounded assets-type-icon"
                                                            loading="lazy"
                                                        />
                                                    </figure>
                                                </td>
                                                <td className="assets-table-cell-name">
                                                    {item.customName ? (
                                                        <div className="assets-item-name-wrapper">
                                                            <span className="assets-item-custom-name">{item.customName}</span>
                                                            <span className="assets-item-type-name">({item.name})</span>
                                                        </div>
                                                    ) : (
                                                        item.name
                                                    )}
                                                    {item.isBlueprint ? (
                                                        <span style={{ display: 'inline-flex', gap: '4px', alignItems: 'center', marginLeft: '4px' }}>
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
                                                    ) : item.isBlueprintCopy && (
                                                        <span className="tag is-info is-light is-small ml-1 assets-badge-blueprint-copy">
                                                            Kopie
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="assets-table-align-right assets-table-cell-quantity">
                                                    {item.quantity.toLocaleString('de-DE')}
                                                </td>
                                                <td className="assets-table-cell-flag">
                                                    {item.locationFlag}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
