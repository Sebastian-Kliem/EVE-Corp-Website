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

    // Structure renaming states
    const [editingStructureId, setEditingStructureId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');
    const [editSystem, setEditSystem] = useState('');
    const [editError, setEditError] = useState<string | null>(null);
    const [isSaving, setIsSaving] = useState(false);
    const [localLocations, setLocalLocations] = useState<Record<number, { name: string; systemName: string }>>({});

    const startEditing = (locationId: number, currentName: string) => {
        setEditingStructureId(locationId);
        const currentOverride = localLocations[locationId];
        const initialName = currentOverride?.name ?? (currentName === 'Spieler-Struktur' ? '' : currentName);
        setEditName(initialName);
        setEditSystem(currentOverride?.systemName ?? '');
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
        <div className="w-full max-w-[1200px] mx-auto px-6 mt-10 mb-12">
            {/* Back Link */}
            <nav className="flex gap-2 text-xs text-eve-muted mb-4" aria-label="breadcrumbs">
                <ul className="flex items-center gap-1.5">
                    <li>
                        <a href={backUrl} className="hover:text-eve-primary transition-colors">
                            👤 Profil
                        </a>
                    </li>
                    <span className="text-white/20">/</span>
                    <li className="font-semibold text-white">
                        🎒 Inventar von {character.name}
                    </li>
                </ul>
            </nav>

            {/* Header Section */}
            <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg mb-6 assets-character-panel">
                <div className="flex justify-between items-center flex-wrap gap-4">
                    <div className="flex items-center gap-4">
                        <figure className="w-16 h-16 flex-shrink-0 m-0">
                            <img
                                src={getCharacterPortraitUrl(character.id)}
                                alt={character.name}
                                className="rounded-full w-full h-full object-cover border-2 border-eve-border"
                            />
                        </figure>
                        <div>
                            <h1 className="text-2xl font-bold text-white mb-1">
                                Inventar von {character.name}
                            </h1>
                            <p className="text-sm text-eve-muted">
                                Letzter Stand:{' '}
                                {character.lastAssetsUpdate ? (
                                    character.lastAssetsUpdate
                                ) : (
                                    'Noch nie aktualisiert (Cron-Job abwarten)'
                                )}
                            </p>
                        </div>
                    </div>
                    {hasAssets && (
                        <div className="relative flex items-center ml-auto">
                            <span className="absolute left-2.5 text-xs text-eve-muted">🔍</span>
                            <input
                                id="asset-search"
                                className="rounded pl-7 pr-3 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-[200px]"
                                type="text"
                                placeholder="Inventar durchsuchen..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Assets List */}
            {!hasAssets ? (
                <div className="p-4 rounded bg-sky-500/10 border border-sky-500/30 text-sky-400 text-sm">
                    Für diesen Charakter wurden bisher keine Inventar-Daten in der Datenbank gefunden.
                    Der regelmäßige Abruf erfolgt über einen Cron-Job im Hintergrund.
                </div>
            ) : filteredGroupedAssets.length === 0 ? (
                <div className="p-4 rounded bg-amber-500/10 border border-amber-500/30 text-amber-400 text-sm">
                    Keine Gegenstände gefunden, die Ihrer Suche entsprechen.
                </div>
            ) : (
                <div id="assets-container" className="flex flex-col gap-5">
                    {filteredGroupedAssets.map((group) => {
                        const numericLocationId = parseInt(group.locationId, 10);
                        const displayName = localLocations[numericLocationId]?.name || group.name;

                        return (
                            <div
                                key={group.locationId}
                                className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg location-box assets-location-box"
                                data-location-name={displayName}
                            >
                                <h2 className="text-base font-semibold mb-3 flex justify-between items-center flex-wrap gap-2 text-white border-b border-white/5 pb-2.5" style={{ flexWrap: 'wrap', gap: '8px' }}>
                                    <span style={{ display: 'flex', alignItems: 'center', flexGrow: 1, gap: '8px' }}>
                                        {editingStructureId === numericLocationId ? (
                                            <div 
                                                style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', width: '100%' }} 
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
                                                    onClick={() => handleSave(numericLocationId)}
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
                                                {numericLocationId >= 1000000000000 && (displayName === 'Spieler-Struktur' || displayName.startsWith('Struktur #') || displayName.startsWith('Location #')) && (
                                                     <button
                                                         className="inline-flex items-center justify-center border border-transparent bg-transparent text-eve-text opacity-60 hover:opacity-100 p-1 ml-2 text-xs transition-all duration-200 cursor-pointer"
                                                         title="Struktur bearbeiten"
                                                         onClick={(e) => {
                                                             e.stopPropagation();
                                                             startEditing(numericLocationId, group.name);
                                                         }}
                                                     >
                                                         ✏️
                                                     </button>
                                                 )}
                                            </>
                                        )}
                                    </span>
                                    <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5 font-mono">
                                        {group.items.length}{' '}
                                        {group.items.length === 1 ? 'Gegenstand' : 'Gegenstände'}
                                    </span>
                                </h2>

                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-xs text-[#ccc] assets-table">
                                        <thead>
                                            <tr className="border-b border-eve-border">
                                                <th className="w-10 text-left font-semibold text-eve-muted p-2"></th>
                                                <th className="text-left font-semibold text-eve-muted p-2">Gegenstand Name</th>
                                                <th className="text-right font-semibold text-eve-muted p-2">Menge</th>
                                                <th className="text-left font-semibold text-eve-muted p-2">Ort / Slot</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-white/5">
                                            {group.items.map((item, index) => (
                                                <tr key={`${item.typeId}-${index}`} className="hover:bg-white/2 asset-item-row" data-item-name={item.name}>
                                                    <td className="p-2 vertical-middle">
                                                        <figure className="w-4 h-4 m-0 flex-shrink-0">
                                                            <img
                                                                src={getTypeIconUrl(item)}
                                                                alt={item.name}
                                                                className="rounded-full w-full h-full object-cover"
                                                                loading="lazy"
                                                            />
                                                        </figure>
                                                    </td>
                                                    <td className="p-2 vertical-middle">
                                                        {item.customName ? (
                                                            <div className="flex flex-col">
                                                                <span className="font-semibold text-white">{item.customName}</span>
                                                                <span className="text-eve-muted text-[10px]">({item.name})</span>
                                                            </div>
                                                        ) : (
                                                            item.name
                                                        )}
                                                        {item.isBlueprint ? (
                                                            <span className="inline-flex gap-1 items-center ml-1">
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
                                                                    <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">ME: {item.materialEfficiency}%</span>
                                                                )}
                                                                {item.timeEfficiency !== undefined && item.timeEfficiency !== null && (
                                                                    <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase inline-flex items-center bg-sky-500/15 text-sky-400 border border-sky-500/30">TE: {item.timeEfficiency}%</span>
                                                                )}
                                                            </span>
                                                        ) : item.isBlueprintCopy && (
                                                            <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-sky-500/10 text-sky-400 border border-sky-500/20 ml-1">
                                                                Kopie
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-2 text-right vertical-middle font-mono">
                                                        {item.quantity.toLocaleString('de-DE')}
                                                    </td>
                                                    <td className="p-2 vertical-middle text-eve-muted">
                                                        {item.locationFlag}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )})}
                </div>
            )}
        </div>
    );
}
