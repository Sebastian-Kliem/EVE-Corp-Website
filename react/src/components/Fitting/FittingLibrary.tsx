import React, { useMemo, useState } from 'react';
import FittingDetailPanel, { FittingDetailEndpoints } from './FittingDetailPanel';
import FittingEditorModal from './FittingEditorModal';
import {
    BUTTON_PRIMARY_CLASS,
    BUTTON_SECONDARY_CLASS,
    CARD_CLASS,
    FeedbackBanner,
    FittingLibraryData,
    FittingSummary,
    INPUT_CLASS,
    TagBadge,
    countPilotUsers,
    requestJson,
    typeIconUrl,
    useFeedback,
} from './fittingShared';

interface FittingLibraryProps {
    initialLibrary: FittingLibraryData;
    canManageCorpFlag: boolean;
    initialFittingId: number | null;
    imagePaths: { types: string; characters: string };
    apiEndpoints: FittingDetailEndpoints & { create: string; preview: string; update: string; importOwn: string };
}

type RelationMode = 'stored' | 'pilot';

interface ImportResult {
    characterName: string;
    success: boolean;
    message: string;
}

const PAGE_SIZE = 100;

export default function FittingLibrary({ initialLibrary, canManageCorpFlag, initialFittingId, imagePaths, apiEndpoints }: FittingLibraryProps) {
    const [library, setLibrary] = useState<FittingLibraryData>(initialLibrary);
    const [selectedFittingId, setSelectedFittingId] = useState<number | null>(initialFittingId);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedTags, setSelectedTags] = useState<string[]>([]);
    const [filterUserId, setFilterUserId] = useState<number>(0);
    const [filterCharacterId, setFilterCharacterId] = useState<number>(0);
    const [relationMode, setRelationMode] = useState<RelationMode>('stored');
    const [onlyCorp, setOnlyCorp] = useState(false);
    const [onlyFlyableByMe, setOnlyFlyableByMe] = useState(false);
    const [visibleCount, setVisibleCount] = useState(PAGE_SIZE);
    const [isImporting, setIsImporting] = useState(false);
    const [editor, setEditor] = useState<{ fitting: FittingSummary | null; eft: string } | null>(null);
    const { feedback, showFeedback } = useFeedback();

    const characterUserIds = useMemo(() => {
        const map = new Map<number, number | null>();
        for (const character of library.characters) {
            map.set(character.id, character.userId);
        }
        return map;
    }, [library.characters]);

    const allTags = useMemo(() => {
        const tagsByKey = new Map<string, string>();
        for (const fitting of library.fittings) {
            for (const tag of fitting.tags) {
                if (!tagsByKey.has(tag.toLowerCase())) {
                    tagsByKey.set(tag.toLowerCase(), tag);
                }
            }
        }
        return Array.from(tagsByKey.values()).sort((left, right) => left.localeCompare(right));
    }, [library.fittings]);

    const filteredFittings = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();
        const selectedTagKeys = selectedTags.map((tag) => tag.toLowerCase());

        const matchesPerson = (characterIds: number[]): boolean => {
            if (filterCharacterId !== 0) {
                return characterIds.includes(filterCharacterId);
            }
            if (filterUserId !== 0) {
                return characterIds.some((characterId) => characterUserIds.get(characterId) === filterUserId);
            }
            return true;
        };

        return library.fittings.filter((fitting) => {
            if (query !== '') {
                const haystack = `${fitting.name} ${fitting.shipName} ${fitting.shipGroup}`.toLowerCase();
                if (!haystack.includes(query)) {
                    return false;
                }
            }
            if (selectedTagKeys.length > 0) {
                const fittingTagKeys = fitting.tags.map((tag) => tag.toLowerCase());
                if (!selectedTagKeys.every((tagKey) => fittingTagKeys.includes(tagKey))) {
                    return false;
                }
            }
            if (onlyCorp && !fitting.corpSaved) {
                return false;
            }
            if (onlyFlyableByMe && !fitting.pilotIds.some((characterId) => characterUserIds.get(characterId) === library.currentUserId)) {
                return false;
            }
            const relatedCharacterIds = relationMode === 'stored' ? fitting.copies.map((copy) => copy.characterId) : fitting.pilotIds;
            return matchesPerson(relatedCharacterIds);
        });
    }, [library, searchQuery, selectedTags, filterUserId, filterCharacterId, relationMode, onlyCorp, onlyFlyableByMe, characterUserIds]);

    const selectedFitting = library.fittings.find((fitting) => fitting.id === selectedFittingId) ?? null;
    const filterCharacters = library.characters.filter((character) => filterUserId === 0 || character.userId === filterUserId);
    const hasActiveFilter =
        searchQuery !== '' || selectedTags.length > 0 || filterUserId !== 0 || filterCharacterId !== 0 || onlyCorp || onlyFlyableByMe;

    const upsertFitting = (updatedFitting: FittingSummary) => {
        setLibrary((current) => {
            const exists = current.fittings.some((fitting) => fitting.id === updatedFitting.id);
            const fittings = exists
                ? current.fittings.map((fitting) => (fitting.id === updatedFitting.id ? updatedFitting : fitting))
                : [...current.fittings, updatedFitting].sort((left, right) => left.name.localeCompare(right.name));
            return { ...current, fittings };
        });
    };

    const removeFitting = (fittingId: number) => {
        setLibrary((current) => ({ ...current, fittings: current.fittings.filter((fitting) => fitting.id !== fittingId) }));
        setSelectedFittingId(null);
    };

    const toggleTagFilter = (tag: string) => {
        setSelectedTags((current) => (current.includes(tag) ? current.filter((selectedTag) => selectedTag !== tag) : [...current, tag]));
    };

    const resetFilters = () => {
        setSearchQuery('');
        setSelectedTags([]);
        setFilterUserId(0);
        setFilterCharacterId(0);
        setOnlyCorp(false);
        setOnlyFlyableByMe(false);
    };

    const handleImport = async () => {
        setIsImporting(true);
        try {
            const result = await requestJson<{ results: ImportResult[]; library: FittingLibraryData }>(apiEndpoints.importOwn, 'POST');
            setLibrary(result.library);
            const lines = result.results.map((entry) => `${entry.characterName}: ${entry.message}`);
            const hasError = result.results.some((entry) => !entry.success);
            showFeedback(hasError ? 'error' : 'success', lines.length > 0 ? lines.join('\n') : 'Keine Charaktere verknüpft.');
        } catch (error: any) {
            showFeedback('error', error.message);
        } finally {
            setIsImporting(false);
        }
    };

    const ownPilotCount = (fitting: FittingSummary): number =>
        fitting.pilotIds.filter((characterId) => characterUserIds.get(characterId) === library.currentUserId).length;

    return (
        <div>
            <FeedbackBanner feedback={feedback} />

            <div className={`${CARD_CLASS} p-4 mb-5 space-y-3`}>
                <div className="flex flex-wrap gap-2 items-center">
                    <input
                        className={`${INPUT_CLASS} max-w-xs`}
                        value={searchQuery}
                        onChange={(event) => setSearchQuery(event.target.value)}
                        placeholder="Suche nach Fit, Schiff oder Klasse"
                    />
                    <select
                        className={`${INPUT_CLASS} max-w-[180px] cursor-pointer`}
                        value={relationMode}
                        onChange={(event) => setRelationMode(event.target.value as RelationMode)}
                        title="Worauf sich der User-/Charakter-Filter bezieht"
                    >
                        <option value="stored">Ingame gespeichert bei</option>
                        <option value="pilot">Fliegbar von</option>
                    </select>
                    <select
                        className={`${INPUT_CLASS} max-w-[180px] cursor-pointer`}
                        value={filterUserId}
                        onChange={(event) => {
                            setFilterUserId(Number(event.target.value));
                            setFilterCharacterId(0);
                        }}
                    >
                        <option value={0}>Alle User</option>
                        {library.users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={`${INPUT_CLASS} max-w-[200px] cursor-pointer`}
                        value={filterCharacterId}
                        onChange={(event) => setFilterCharacterId(Number(event.target.value))}
                    >
                        <option value={0}>Alle Charaktere</option>
                        {filterCharacters.map((character) => (
                            <option key={character.id} value={character.id}>
                                {character.name}
                            </option>
                        ))}
                    </select>
                    <label className="inline-flex items-center gap-1.5 text-xs text-eve-text cursor-pointer">
                        <input type="checkbox" checked={onlyFlyableByMe} onChange={(event) => setOnlyFlyableByMe(event.target.checked)} />
                        Kann ich fliegen
                    </label>
                    <label className="inline-flex items-center gap-1.5 text-xs text-eve-text cursor-pointer">
                        <input type="checkbox" checked={onlyCorp} onChange={(event) => setOnlyCorp(event.target.checked)} />
                        Nur Corp-Fits
                    </label>
                    {hasActiveFilter && (
                        <button type="button" className="text-xs text-eve-primary hover:underline cursor-pointer" onClick={resetFilters}>
                            Filter zurücksetzen
                        </button>
                    )}
                    <div className="ml-auto flex gap-2">
                        <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={handleImport} disabled={isImporting}>
                            {isImporting ? 'Importiert ...' : 'Meine Ingame-Fits importieren'}
                        </button>
                        <button type="button" className={BUTTON_PRIMARY_CLASS} onClick={() => setEditor({ fitting: null, eft: '' })}>
                            Neues Fit
                        </button>
                    </div>
                </div>
                {allTags.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 items-center">
                        <span className="text-[11px] text-eve-muted mr-1">Tags:</span>
                        {allTags.map((tag) => (
                            <TagBadge key={tag} tag={tag} active={selectedTags.includes(tag)} onClick={() => toggleTagFilter(tag)} />
                        ))}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-5 items-start">
                <div className={`${CARD_CLASS} overflow-hidden`}>
                    <div className="px-4 py-2.5 border-b border-white/5 text-xs text-eve-muted">
                        {filteredFittings.length} von {library.fittings.length} Fits
                    </div>
                    {filteredFittings.length === 0 ? (
                        <p className="p-6 text-sm text-eve-muted">Keine Fits gefunden.</p>
                    ) : (
                        <ul className="divide-y divide-white/5">
                            {filteredFittings.slice(0, visibleCount).map((fitting) => {
                                const isSelected = fitting.id === selectedFittingId;
                                const ownPilots = ownPilotCount(fitting);
                                return (
                                    <li key={fitting.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedFittingId(fitting.id)}
                                            className={`w-full text-left flex items-center gap-3 px-4 py-2.5 cursor-pointer transition-colors ${
                                                isSelected ? 'bg-eve-primary/10' : 'hover:bg-white/5'
                                            }`}
                                        >
                                            <img
                                                src={typeIconUrl(imagePaths.types, fitting.shipTypeId)}
                                                alt=""
                                                className="w-9 h-9 rounded shrink-0"
                                                loading="lazy"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-sm font-semibold text-white truncate">{fitting.name}</div>
                                                <div className="text-[11px] text-eve-muted truncate">
                                                    {fitting.shipName}
                                                    {fitting.shipGroup && ` · ${fitting.shipGroup}`}
                                                </div>
                                                {fitting.tags.length > 0 && (
                                                    <div className="flex flex-wrap gap-1 mt-1">
                                                        {fitting.tags.map((tag) => (
                                                            <TagBadge key={tag} tag={tag} />
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="shrink-0 text-right text-[11px] space-y-0.5">
                                                <div className="flex gap-1 justify-end">
                                                    {fitting.corpSaved && (
                                                        <span className="rounded bg-emerald-500/15 text-emerald-300 px-1.5 py-0.5 font-bold">Corp</span>
                                                    )}
                                                    {fitting.copies.length > 0 && (
                                                        <span
                                                            className="rounded bg-eve-primary/15 text-eve-primary px-1.5 py-0.5 font-bold"
                                                            title={fitting.copies.map((copy) => copy.characterName).join(', ')}
                                                        >
                                                            Ingame: {fitting.copies.length}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-eve-muted">
                                                    {countPilotUsers(fitting.pilotIds, library.characters)} User können fliegen
                                                </div>
                                                {ownPilots > 0 && <div className="text-emerald-300">Du: {ownPilots} Char{ownPilots > 1 ? 's' : ''}</div>}
                                            </div>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {filteredFittings.length > visibleCount && (
                        <div className="p-3 text-center border-t border-white/5">
                            <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={() => setVisibleCount(visibleCount + PAGE_SIZE)}>
                                Weitere {Math.min(PAGE_SIZE, filteredFittings.length - visibleCount)} anzeigen
                            </button>
                        </div>
                    )}
                </div>

                <div className="xl:sticky xl:top-4">
                    {selectedFitting ? (
                        <FittingDetailPanel
                            fitting={selectedFitting}
                            library={library}
                            allTags={allTags}
                            canManageCorpFlag={canManageCorpFlag}
                            typeIconTemplate={imagePaths.types}
                            endpoints={apiEndpoints}
                            onUpdated={upsertFitting}
                            onDeleted={removeFitting}
                            onEdit={(fitting, eft) => setEditor({ fitting, eft })}
                            showFeedback={showFeedback}
                        />
                    ) : (
                        <div className={`${CARD_CLASS} p-6 text-sm text-eve-muted`}>Wähle links ein Fit aus, um Details zu sehen.</div>
                    )}
                </div>
            </div>

            {editor && (
                <FittingEditorModal
                    fitting={editor.fitting}
                    initialEft={editor.eft}
                    typeIconTemplate={imagePaths.types}
                    endpoints={apiEndpoints}
                    onClose={() => setEditor(null)}
                    onSaved={(fitting, message) => {
                        upsertFitting(fitting);
                        setSelectedFittingId(fitting.id);
                        setEditor(null);
                        showFeedback('success', message);
                    }}
                />
            )}
        </div>
    );
}
