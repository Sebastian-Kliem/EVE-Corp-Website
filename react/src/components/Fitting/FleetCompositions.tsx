import React, { useMemo, useState } from 'react';
import {
    BUTTON_DANGER_CLASS,
    BUTTON_PRIMARY_CLASS,
    BUTTON_SECONDARY_CLASS,
    CARD_CLASS,
    FeedbackBanner,
    FittingLibraryData,
    FittingSummary,
    INPUT_CLASS,
    PilotList,
    countPilotUsers,
    requestJson,
    typeIconUrl,
    useFeedback,
    withId,
} from './fittingShared';

interface FleetEntry {
    id?: number;
    fittingId: number;
    role: string;
    quantity: number;
    notes: string;
}

interface Fleet {
    id: number;
    name: string;
    description: string;
    createdByName: string | null;
    updatedAt: string;
    canEdit: boolean;
    isDefenseDoctrine: boolean;
    entries: FleetEntry[];
}

interface FleetCompositionsProps {
    initialFleets: Fleet[];
    library: FittingLibraryData;
    fittingsUrl: string;
    imagePaths: { types: string };
    apiEndpoints: { create: string; update: string; delete: string };
}

const FLEET_ROLES = ['DPS', 'Logistik', 'Tackle', 'E-War', 'Scout', 'Booster / Fleet Command', 'Support', 'Sonstige'];

export default function FleetCompositions({ initialFleets, library, fittingsUrl, imagePaths, apiEndpoints }: FleetCompositionsProps) {
    const [fleets, setFleets] = useState<Fleet[]>(initialFleets);
    const [editingFleet, setEditingFleet] = useState<Fleet | null>(null);
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [expandedPilotKeys, setExpandedPilotKeys] = useState<Set<string>>(new Set());
    const { feedback, showFeedback } = useFeedback();

    const fittingsById = useMemo(() => {
        const map = new Map<number, FittingSummary>();
        for (const fitting of library.fittings) {
            map.set(fitting.id, fitting);
        }
        return map;
    }, [library.fittings]);

    const ownCharacterIds = useMemo(() => {
        const ids = new Set<number>();
        for (const character of library.characters) {
            if (character.userId === library.currentUserId) {
                ids.add(character.id);
            }
        }
        return ids;
    }, [library]);

    const togglePilots = (key: string) => {
        setExpandedPilotKeys((current) => {
            const next = new Set(current);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const handleSaved = (savedFleet: Fleet) => {
        setFleets((current) => {
            const exists = current.some((fleet) => fleet.id === savedFleet.id);
            const nextFleets = exists ? current.map((fleet) => (fleet.id === savedFleet.id ? savedFleet : fleet)) : [...current, savedFleet];
            return nextFleets.sort((left, right) => left.name.localeCompare(right.name));
        });
        setIsEditorOpen(false);
        showFeedback('success', `Flotte "${savedFleet.name}" gespeichert.`);
    };

    const handleDelete = async (fleet: Fleet) => {
        if (!window.confirm(`Flotte "${fleet.name}" löschen?`)) {
            return;
        }
        try {
            await requestJson(withId(apiEndpoints.delete, fleet.id), 'DELETE');
            setFleets((current) => current.filter((currentFleet) => currentFleet.id !== fleet.id));
            showFeedback('success', `Flotte "${fleet.name}" gelöscht.`);
        } catch (error: any) {
            showFeedback('error', error.message);
        }
    };

    return (
        <div>
            <FeedbackBanner feedback={feedback} />

            <div className="flex justify-end mb-5">
                <button
                    type="button"
                    className={BUTTON_PRIMARY_CLASS}
                    onClick={() => {
                        setEditingFleet(null);
                        setIsEditorOpen(true);
                    }}
                >
                    Neue Flotte
                </button>
            </div>

            {fleets.length === 0 && <div className={`${CARD_CLASS} p-6 text-sm text-eve-muted`}>Noch keine Flotten angelegt.</div>}

            <div className="space-y-5">
                {fleets.map((fleet) => {
                    const totalShips = fleet.entries.reduce((sum, entry) => sum + entry.quantity, 0);
                    return (
                        <div key={fleet.id} className={`${CARD_CLASS} p-5`}>
                            <div className="flex flex-wrap items-start gap-3 mb-3">
                                <div className="flex-1 min-w-0">
                                    <h2 className="text-lg font-bold text-white">
                                        {fleet.name}
                                        {fleet.isDefenseDoctrine && (
                                            <span className="ml-2 align-middle rounded bg-amber-500/15 text-amber-300 px-1.5 py-0.5 text-[10px] font-bold">
                                                VERTEIDIGUNGSDOKTRIN
                                            </span>
                                        )}
                                    </h2>
                                    <div className="text-[11px] text-eve-muted">
                                        {fleet.entries.length} Fits · {totalShips} Schiffe
                                        {fleet.createdByName && ` · von ${fleet.createdByName}`} · aktualisiert {fleet.updatedAt}
                                    </div>
                                </div>
                                {fleet.canEdit && (
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            className={BUTTON_SECONDARY_CLASS}
                                            onClick={() => {
                                                setEditingFleet(fleet);
                                                setIsEditorOpen(true);
                                            }}
                                        >
                                            Bearbeiten
                                        </button>
                                        {!fleet.isDefenseDoctrine && (
                                            <button type="button" className={BUTTON_DANGER_CLASS} onClick={() => handleDelete(fleet)}>
                                                Löschen
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                            {fleet.description && <p className="text-sm text-eve-text whitespace-pre-line mb-4">{fleet.description}</p>}

                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="text-left text-eve-muted border-b border-white/10">
                                            <th className="py-2 pr-3 font-semibold">Fit</th>
                                            <th className="py-2 pr-3 font-semibold">Rolle</th>
                                            <th className="py-2 pr-3 font-semibold text-right">Anzahl</th>
                                            <th className="py-2 pr-3 font-semibold">Piloten</th>
                                            <th className="py-2 font-semibold">Notizen</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/5">
                                        {fleet.entries.map((entry, index) => {
                                            const fitting = fittingsById.get(entry.fittingId);
                                            const pilotKey = `${fleet.id}-${index}`;
                                            const ownPilots = fitting ? fitting.pilotIds.filter((characterId) => ownCharacterIds.has(characterId)).length : 0;
                                            return (
                                                <tr key={pilotKey} className="align-top">
                                                    <td className="py-2 pr-3">
                                                        {fitting ? (
                                                            <a href={`${fittingsUrl}?fit=${fitting.id}`} className="flex items-center gap-2 hover:underline">
                                                                <img src={typeIconUrl(imagePaths.types, fitting.shipTypeId)} alt="" className="w-7 h-7 rounded" loading="lazy" />
                                                                <span>
                                                                    <span className="block font-semibold text-white">{fitting.name}</span>
                                                                    <span className="block text-eve-muted">{fitting.shipName}</span>
                                                                </span>
                                                            </a>
                                                        ) : (
                                                            <span className="text-eve-muted">Fit #{entry.fittingId}</span>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3 text-eve-text">{entry.role || '-'}</td>
                                                    <td className="py-2 pr-3 text-right text-white font-semibold">{entry.quantity}</td>
                                                    <td className="py-2 pr-3 min-w-[180px]">
                                                        {fitting && (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    className="text-eve-primary hover:underline cursor-pointer"
                                                                    onClick={() => togglePilots(pilotKey)}
                                                                >
                                                                    {countPilotUsers(fitting.pilotIds, library.characters)} User
                                                                </button>
                                                                {ownPilots > 0 && <span className="text-emerald-300"> · Du: {ownPilots}</span>}
                                                                {expandedPilotKeys.has(pilotKey) && (
                                                                    <div className="mt-1.5">
                                                                        <PilotList
                                                                            pilotIds={fitting.pilotIds}
                                                                            characters={library.characters}
                                                                            users={library.users}
                                                                            highlightUserId={library.currentUserId}
                                                                        />
                                                                    </div>
                                                                )}
                                                            </>
                                                        )}
                                                    </td>
                                                    <td className="py-2 text-eve-muted whitespace-pre-line">{entry.notes}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    );
                })}
            </div>

            {isEditorOpen && (
                <FleetEditorModal
                    fleet={editingFleet}
                    fittings={library.fittings}
                    fittingsById={fittingsById}
                    typeIconTemplate={imagePaths.types}
                    apiEndpoints={apiEndpoints}
                    onClose={() => setIsEditorOpen(false)}
                    onSaved={handleSaved}
                />
            )}
        </div>
    );
}

interface FleetEditorModalProps {
    fleet: Fleet | null;
    fittings: FittingSummary[];
    fittingsById: Map<number, FittingSummary>;
    typeIconTemplate: string;
    apiEndpoints: { create: string; update: string };
    onClose: () => void;
    onSaved: (fleet: Fleet) => void;
}

function FleetEditorModal({ fleet, fittings, fittingsById, typeIconTemplate, apiEndpoints, onClose, onSaved }: FleetEditorModalProps) {
    const [name, setName] = useState(fleet?.name ?? '');
    const [description, setDescription] = useState(fleet?.description ?? '');
    const [entries, setEntries] = useState<FleetEntry[]>(fleet ? fleet.entries.map((entry) => ({ ...entry })) : []);
    const [fittingQuery, setFittingQuery] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [isSaving, setIsSaving] = useState(false);

    const matchingFittings = useMemo(() => {
        const query = fittingQuery.trim().toLowerCase();
        if (query.length < 2) {
            return [];
        }
        return fittings
            .filter((fitting) => `${fitting.name} ${fitting.shipName} ${fitting.tags.join(' ')}`.toLowerCase().includes(query))
            .slice(0, 15);
    }, [fittingQuery, fittings]);

    const updateEntry = (index: number, changes: Partial<FleetEntry>) => {
        setEntries((current) => current.map((entry, entryIndex) => (entryIndex === index ? { ...entry, ...changes } : entry)));
    };

    const moveEntry = (index: number, direction: -1 | 1) => {
        setEntries((current) => {
            const targetIndex = index + direction;
            if (targetIndex < 0 || targetIndex >= current.length) {
                return current;
            }
            const next = [...current];
            [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
            return next;
        });
    };

    const handleSave = async () => {
        setIsSaving(true);
        setError(null);
        try {
            const url = fleet ? withId(apiEndpoints.update, fleet.id) : apiEndpoints.create;
            const result = await requestJson<{ fleet: Fleet }>(url, 'POST', { name, description, entries });
            onSaved(result.fleet);
        } catch (saveError: any) {
            setError(saveError.message);
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/75 backdrop-blur-sm p-4 overflow-y-auto">
            <div className="bg-[#0f1523] border border-eve-border rounded-xl shadow-[0_10px_30px_rgba(0,0,0,0.8)] w-full max-w-[900px] my-8">
                <div className="flex items-center justify-between p-5 border-b border-white/10 bg-[#141b2d] rounded-t-xl">
                    <h2 className="text-lg font-semibold text-white">{fleet ? 'Flotte bearbeiten' : 'Neue Flotte'}</h2>
                    <button type="button" onClick={onClose} className="text-eve-muted hover:text-white cursor-pointer text-sm">
                        Schließen
                    </button>
                </div>

                <div className="p-5 space-y-4">
                    <label className="block">
                        <span className="block text-xs text-eve-muted mb-1">Name</span>
                        <input className={INPUT_CLASS} value={name} onChange={(event) => setName(event.target.value)} />
                    </label>
                    <label className="block">
                        <span className="block text-xs text-eve-muted mb-1">Beschreibung</span>
                        <textarea className={`${INPUT_CLASS} resize-y`} rows={4} value={description} onChange={(event) => setDescription(event.target.value)} />
                    </label>

                    <div>
                        <span className="block text-xs text-eve-muted mb-1">Fits</span>
                        {entries.length === 0 && <p className="text-xs text-eve-muted mb-2">Noch keine Fits hinzugefügt.</p>}
                        <datalist id="fleet-role-suggestions">
                            {FLEET_ROLES.map((role) => (
                                <option key={role} value={role} />
                            ))}
                        </datalist>
                        <ul className="space-y-2 mb-3">
                            {entries.map((entry, index) => {
                                const fitting = fittingsById.get(entry.fittingId);
                                return (
                                    <li key={`${entry.fittingId}-${index}`} className="bg-black/30 border border-white/5 rounded-lg p-2.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {fitting && <img src={typeIconUrl(typeIconTemplate, fitting.shipTypeId)} alt="" className="w-7 h-7 rounded" />}
                                            <span className="text-xs font-semibold text-white flex-1 min-w-[160px]">
                                                {fitting ? `${fitting.name} (${fitting.shipName})` : `Fit #${entry.fittingId}`}
                                            </span>
                                            <input
                                                className={`${INPUT_CLASS} max-w-[170px]`}
                                                list="fleet-role-suggestions"
                                                value={entry.role}
                                                onChange={(event) => updateEntry(index, { role: event.target.value })}
                                                placeholder="Rolle"
                                            />
                                            <input
                                                type="number"
                                                min={1}
                                                className={`${INPUT_CLASS} max-w-[80px]`}
                                                value={entry.quantity}
                                                onChange={(event) => updateEntry(index, { quantity: Math.max(1, Number(event.target.value) || 1) })}
                                                title="Anzahl"
                                            />
                                            <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={() => moveEntry(index, -1)} title="Nach oben">
                                                Hoch
                                            </button>
                                            <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={() => moveEntry(index, 1)} title="Nach unten">
                                                Runter
                                            </button>
                                            <button
                                                type="button"
                                                className={BUTTON_DANGER_CLASS}
                                                onClick={() => setEntries((current) => current.filter((_, entryIndex) => entryIndex !== index))}
                                            >
                                                Entfernen
                                            </button>
                                        </div>
                                        <input
                                            className={`${INPUT_CLASS} mt-2`}
                                            value={entry.notes}
                                            onChange={(event) => updateEntry(index, { notes: event.target.value })}
                                            placeholder="Notiz zum Fit in dieser Flotte"
                                        />
                                    </li>
                                );
                            })}
                        </ul>

                        <input
                            className={INPUT_CLASS}
                            value={fittingQuery}
                            onChange={(event) => setFittingQuery(event.target.value)}
                            placeholder="Fit aus der Bibliothek suchen (Name, Schiff, Tag)"
                        />
                        {matchingFittings.length > 0 && (
                            <ul className="mt-1 border border-white/10 rounded-lg divide-y divide-white/5 max-h-64 overflow-y-auto">
                                {matchingFittings.map((fitting) => (
                                    <li key={fitting.id}>
                                        <button
                                            type="button"
                                            className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-xs hover:bg-white/5 cursor-pointer"
                                            onClick={() => {
                                                setEntries((current) => [...current, { fittingId: fitting.id, role: '', quantity: 1, notes: '' }]);
                                                setFittingQuery('');
                                            }}
                                        >
                                            <img src={typeIconUrl(typeIconTemplate, fitting.shipTypeId)} alt="" className="w-6 h-6 rounded" loading="lazy" />
                                            <span className="text-white">{fitting.name}</span>
                                            <span className="text-eve-muted">{fitting.shipName}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                {error && <div className="mx-5 mb-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">{error}</div>}

                <div className="flex justify-end gap-2 p-5 border-t border-white/10">
                    <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={onClose}>
                        Abbrechen
                    </button>
                    <button type="button" className={BUTTON_PRIMARY_CLASS} onClick={handleSave} disabled={isSaving || name.trim() === ''}>
                        {isSaving ? 'Speichert ...' : 'Speichern'}
                    </button>
                </div>
            </div>
        </div>
    );
}
