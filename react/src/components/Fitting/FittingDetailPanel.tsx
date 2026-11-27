import React, { useEffect, useState } from 'react';
import {
    BUTTON_DANGER_CLASS,
    BUTTON_PRIMARY_CLASS,
    BUTTON_SECONDARY_CLASS,
    CARD_CLASS,
    FittingLibraryData,
    FittingSummary,
    INPUT_CLASS,
    PilotList,
    SlotMap,
    SlotView,
    TagBadge,
    copyToClipboard,
    requestJson,
    typeIconUrl,
    withId,
} from './fittingShared';

interface FittingDetail {
    id: number;
    eft: string;
    slots: SlotMap;
    requiredSkills: { skillId: number; name: string; level: number }[];
    ownCharacterSkills: {
        characterId: number;
        characterName: string;
        skillsKnown: boolean;
        missingSkills: { name: string; required: number; current: number }[];
    }[];
}

export interface FittingDetailEndpoints {
    detail: string;
    tags: string;
    corpFlag: string;
    delete: string;
    saveIngame: string;
    refreshCopy: string;
    deleteCopy: string;
}

interface FittingDetailPanelProps {
    fitting: FittingSummary;
    library: FittingLibraryData;
    allTags: string[];
    canManageCorpFlag: boolean;
    typeIconTemplate: string;
    endpoints: FittingDetailEndpoints;
    onUpdated: (fitting: FittingSummary) => void;
    onDeleted: (fittingId: number) => void;
    onEdit: (fitting: FittingSummary, eft: string) => void;
    showFeedback: (type: 'success' | 'error', text: string) => void;
}

const ROMAN_LEVELS = ['0', 'I', 'II', 'III', 'IV', 'V'];

export default function FittingDetailPanel({
    fitting,
    library,
    allTags,
    canManageCorpFlag,
    typeIconTemplate,
    endpoints,
    onUpdated,
    onDeleted,
    onEdit,
    showFeedback,
}: FittingDetailPanelProps) {
    const [detail, setDetail] = useState<FittingDetail | null>(null);
    const [detailError, setDetailError] = useState<string | null>(null);
    const [tagDraft, setTagDraft] = useState('');
    const [saveCharacterId, setSaveCharacterId] = useState<number>(0);
    const [busyAction, setBusyAction] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        setDetail(null);
        setDetailError(null);
        requestJson<FittingDetail>(withId(endpoints.detail, fitting.id), 'GET')
            .then((result) => {
                if (!cancelled) {
                    setDetail(result);
                }
            })
            .catch((error: Error) => {
                if (!cancelled) {
                    setDetailError(error.message);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [fitting.id, fitting.updatedAt]);

    const runAction = async (actionKey: string, action: () => Promise<void>) => {
        setBusyAction(actionKey);
        try {
            await action();
        } catch (error: any) {
            showFeedback('error', error.message);
        } finally {
            setBusyAction(null);
        }
    };

    const saveTags = (tags: string[]) =>
        runAction('tags', async () => {
            const result = await requestJson<{ fitting: FittingSummary }>(withId(endpoints.tags, fitting.id), 'POST', { tags });
            onUpdated(result.fitting);
        });

    const handleAddTag = () => {
        const newTags = tagDraft.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
        if (newTags.length === 0) {
            return;
        }
        setTagDraft('');
        saveTags([...fitting.tags, ...newTags]);
    };

    const handleRemoveTag = (tagToRemove: string) => {
        saveTags(fitting.tags.filter((tag) => tag !== tagToRemove));
    };

    const handleToggleCorp = () =>
        runAction('corp', async () => {
            const result = await requestJson<{ fitting: FittingSummary }>(withId(endpoints.corpFlag, fitting.id), 'POST', {
                corpSaved: !fitting.corpSaved,
            });
            onUpdated(result.fitting);
        });

    const handleDelete = () => {
        const ingameHint = fitting.copies.length > 0 ? '\nEs ist noch ingame gespeichert und taucht beim nächsten Import wieder auf.' : '';
        if (!window.confirm(`Fit "${fitting.name}" aus der Bibliothek löschen?${ingameHint}`)) {
            return;
        }
        runAction('delete', async () => {
            await requestJson(withId(endpoints.delete, fitting.id), 'DELETE');
            onDeleted(fitting.id);
            showFeedback('success', `Fit "${fitting.name}" gelöscht.`);
        });
    };

    const handleSaveIngame = () =>
        runAction('saveIngame', async () => {
            const result = await requestJson<{ fitting: FittingSummary }>(withId(endpoints.saveIngame, fitting.id), 'POST', {
                characterId: saveCharacterId,
            });
            onUpdated(result.fitting);
            setSaveCharacterId(0);
            showFeedback('success', 'Fit wurde ingame gespeichert.');
        });

    const handleRefreshCopy = (copyId: number) =>
        runAction(`refresh-${copyId}`, async () => {
            const result = await requestJson<{ fitting: FittingSummary }>(withId(endpoints.refreshCopy, copyId), 'POST');
            onUpdated(result.fitting);
            showFeedback('success', 'Ingame-Fit wurde aktualisiert.');
        });

    const handleDeleteCopy = (copyId: number, characterName: string) => {
        if (!window.confirm(`Fit ingame bei ${characterName} löschen?`)) {
            return;
        }
        runAction(`delete-${copyId}`, async () => {
            const result = await requestJson<{ fitting: FittingSummary }>(withId(endpoints.deleteCopy, copyId), 'DELETE');
            onUpdated(result.fitting);
            showFeedback('success', `Fit ingame bei ${characterName} gelöscht.`);
        });
    };

    const handleCopyEft = async () => {
        if (detail && (await copyToClipboard(detail.eft))) {
            showFeedback('success', 'EFT in die Zwischenablage kopiert. Ingame: Fitting-Fenster, "Import from Clipboard".');
        }
    };

    const savedCharacterIds = new Set(fitting.copies.map((copy) => copy.characterId));
    const saveableCharacters = library.ownCharacters.filter((character) => character.canWrite && !savedCharacterIds.has(character.id));
    const tagSuggestions = allTags.filter((tag) => !fitting.tags.includes(tag));

    return (
        <div className={`${CARD_CLASS} p-5 space-y-5`}>
            <div className="flex items-start gap-3">
                <img src={typeIconUrl(typeIconTemplate, fitting.shipTypeId)} alt="" className="w-14 h-14 rounded-lg border border-white/10" />
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-bold text-white leading-tight break-words">{fitting.name}</h2>
                    <div className="text-xs text-eve-primary">
                        {fitting.shipName}
                        {fitting.shipGroup && <span className="text-eve-muted"> · {fitting.shipGroup}</span>}
                    </div>
                    <div className="text-[11px] text-eve-muted mt-1">
                        {fitting.createdByName ? `Von ${fitting.createdByName}` : 'Ersteller unbekannt'} · aktualisiert {fitting.updatedAt}
                    </div>
                </div>
            </div>

            {fitting.description && <p className="text-sm text-eve-text whitespace-pre-line">{fitting.description}</p>}

            <div className="flex flex-wrap gap-2">
                <button type="button" className={BUTTON_PRIMARY_CLASS} onClick={handleCopyEft} disabled={!detail}>
                    EFT kopieren
                </button>
                {fitting.canEdit && (
                    <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={() => detail && onEdit(fitting, detail.eft)} disabled={!detail}>
                        Bearbeiten
                    </button>
                )}
                {canManageCorpFlag && (
                    <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={handleToggleCorp} disabled={busyAction !== null}>
                        {fitting.corpSaved ? 'Corp-Markierung entfernen' : 'Als Corp-Fit markieren'}
                    </button>
                )}
                {fitting.canEdit && (
                    <button type="button" className={BUTTON_DANGER_CLASS} onClick={handleDelete} disabled={busyAction !== null}>
                        Löschen
                    </button>
                )}
            </div>

            <section>
                <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Tags</h3>
                <div className="flex flex-wrap gap-1.5 mb-2">
                    {fitting.tags.length === 0 && <span className="text-xs text-eve-muted">Noch keine Tags.</span>}
                    {fitting.tags.map((tag) => (
                        <span key={tag} className="inline-flex items-center gap-1">
                            <TagBadge tag={tag} active />
                            <button
                                type="button"
                                title="Tag entfernen"
                                className="text-[11px] text-eve-muted hover:text-red-400 cursor-pointer"
                                onClick={() => handleRemoveTag(tag)}
                                disabled={busyAction !== null}
                            >
                                x
                            </button>
                        </span>
                    ))}
                </div>
                <div className="flex gap-2">
                    <input
                        className={INPUT_CLASS}
                        value={tagDraft}
                        list={`fitting-tag-suggestions-${fitting.id}`}
                        onChange={(event) => setTagDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                handleAddTag();
                            }
                        }}
                        placeholder="Tag hinzufügen, z. B. pvp"
                    />
                    <datalist id={`fitting-tag-suggestions-${fitting.id}`}>
                        {tagSuggestions.map((tag) => (
                            <option key={tag} value={tag} />
                        ))}
                    </datalist>
                    <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={handleAddTag} disabled={busyAction !== null || tagDraft.trim() === ''}>
                        Hinzufügen
                    </button>
                </div>
            </section>

            <section>
                <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Ingame gespeichert</h3>
                {fitting.corpSaved && <p className="text-xs text-emerald-300 mb-2">In den Corp-Fittings hinterlegt (manuelle Markierung).</p>}
                {fitting.copies.length === 0 && !fitting.corpSaved && <p className="text-xs text-eve-muted mb-2">Bei keinem Charakter gespeichert.</p>}
                <ul className="space-y-1.5 mb-3">
                    {fitting.copies.map((copy) => (
                        <li key={copy.id} className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="font-semibold text-white">{copy.characterName}</span>
                            {copy.ingameName !== fitting.name && <span className="text-eve-muted">als "{copy.ingameName}"</span>}
                            {copy.outdated && <span className="rounded bg-amber-500/15 text-amber-300 px-1.5 py-0.5 text-[10px] font-bold">VERALTET</span>}
                            {copy.isOwn && (
                                <span className="ml-auto flex gap-1.5">
                                    {copy.outdated && (
                                        <button
                                            type="button"
                                            className={BUTTON_SECONDARY_CLASS}
                                            onClick={() => handleRefreshCopy(copy.id)}
                                            disabled={busyAction !== null}
                                        >
                                            Ingame aktualisieren
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className={BUTTON_DANGER_CLASS}
                                        onClick={() => handleDeleteCopy(copy.id, copy.characterName)}
                                        disabled={busyAction !== null}
                                    >
                                        Ingame löschen
                                    </button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                {saveableCharacters.length > 0 && (
                    <div className="flex gap-2">
                        <select
                            className={`${INPUT_CLASS} cursor-pointer`}
                            value={saveCharacterId}
                            onChange={(event) => setSaveCharacterId(Number(event.target.value))}
                        >
                            <option value={0}>Charakter wählen ...</option>
                            {saveableCharacters.map((character) => (
                                <option key={character.id} value={character.id}>
                                    {character.name}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            className={BUTTON_PRIMARY_CLASS}
                            onClick={handleSaveIngame}
                            disabled={saveCharacterId === 0 || busyAction !== null}
                        >
                            {busyAction === 'saveIngame' ? 'Speichert ...' : 'Ingame speichern'}
                        </button>
                    </div>
                )}
            </section>

            <section>
                <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Fitting</h3>
                {detailError && <p className="text-xs text-red-300">{detailError}</p>}
                {!detail && !detailError && <p className="text-xs text-eve-muted">Lädt ...</p>}
                {detail && <SlotView slots={detail.slots} typeIconTemplate={typeIconTemplate} />}
            </section>

            {detail && (
                <section>
                    <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Deine Charaktere</h3>
                    {detail.ownCharacterSkills.length === 0 && <p className="text-xs text-eve-muted">Keine Charaktere verknüpft.</p>}
                    <ul className="space-y-1.5">
                        {detail.ownCharacterSkills.map((status) => (
                            <li key={status.characterId} className="text-xs">
                                <span className="font-semibold text-white">{status.characterName}</span>
                                {!status.skillsKnown ? (
                                    <span className="text-eve-muted">: Skills noch nicht synchronisiert</span>
                                ) : status.missingSkills.length === 0 ? (
                                    <span className="text-emerald-300">: kann fliegen</span>
                                ) : (
                                    <span className="text-amber-300">
                                        : fehlt{' '}
                                        {status.missingSkills
                                            .map((skill) => `${skill.name} ${ROMAN_LEVELS[skill.required]} (hat ${ROMAN_LEVELS[skill.current]})`)
                                            .join(', ')}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section>
                <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Wer kann fliegen (Grundskills)</h3>
                <PilotList
                    pilotIds={fitting.pilotIds}
                    characters={library.characters}
                    users={library.users}
                    highlightUserId={library.currentUserId}
                />
            </section>

            {detail && detail.requiredSkills.length > 0 && (
                <section>
                    <h3 className="text-xs uppercase tracking-wider font-bold text-eve-muted mb-2">Benötigte Grundskills</h3>
                    <div className="flex flex-wrap gap-1.5">
                        {detail.requiredSkills.map((skill) => (
                            <span key={skill.skillId} className="rounded-md bg-white/5 border border-white/10 px-2 py-0.5 text-[11px] text-eve-text">
                                {skill.name} {ROMAN_LEVELS[skill.level]}
                            </span>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
