import React, { useState } from 'react';
import {
    BUTTON_PRIMARY_CLASS,
    BUTTON_SECONDARY_CLASS,
    FittingSummary,
    INPUT_CLASS,
    SlotMap,
    SlotView,
    requestJson,
    typeIconUrl,
    withId,
} from './fittingShared';

interface PreviewResult {
    shipTypeId: number;
    shipName: string;
    name: string | null;
    slots: SlotMap;
    warnings: string[];
    existingFitting: { id: number; name: string } | null;
}

interface SaveResult {
    fitting: FittingSummary;
    created?: boolean;
    warnings: string[];
}

interface FittingEditorModalProps {
    fitting: FittingSummary | null;
    initialEft: string;
    typeIconTemplate: string;
    endpoints: { preview: string; create: string; update: string };
    onClose: () => void;
    onSaved: (fitting: FittingSummary, message: string) => void;
}

export default function FittingEditorModal({ fitting, initialEft, typeIconTemplate, endpoints, onClose, onSaved }: FittingEditorModalProps) {
    const isEdit = fitting !== null;
    const [name, setName] = useState(fitting?.name ?? '');
    const [description, setDescription] = useState(fitting?.description ?? '');
    const [tagsInput, setTagsInput] = useState('');
    const [eft, setEft] = useState(initialEft);
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isBusy, setIsBusy] = useState(false);

    const handlePreview = async () => {
        setIsBusy(true);
        setError(null);
        try {
            const result = await requestJson<PreviewResult>(endpoints.preview, 'POST', { eft });
            setPreview(result);
            if (!name.trim() && result.name) {
                setName(result.name);
            }
        } catch (previewError: any) {
            setPreview(null);
            setError(previewError.message);
        } finally {
            setIsBusy(false);
        }
    };

    const handleSave = async () => {
        setIsBusy(true);
        setError(null);
        try {
            const tags = tagsInput.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
            const result = isEdit
                ? await requestJson<SaveResult>(withId(endpoints.update, fitting.id), 'POST', {
                      name,
                      description,
                      eft: eft !== initialEft ? eft : '',
                  })
                : await requestJson<SaveResult>(endpoints.create, 'POST', { eft, name, description, tags });

            let message = isEdit ? `Fit "${result.fitting.name}" gespeichert.` : `Fit "${result.fitting.name}" angelegt.`;
            if (result.created === false) {
                message = `Dieses Fit existiert bereits als "${result.fitting.name}".`;
            }
            if (result.warnings.length > 0) {
                message += '\n' + result.warnings.join('\n');
            }
            onSaved(result.fitting, message);
        } catch (saveError: any) {
            setError(saveError.message);
        } finally {
            setIsBusy(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/75 backdrop-blur-sm p-4 overflow-y-auto">
            <div className="bg-[#0f1523] border border-eve-border rounded-xl shadow-[0_10px_30px_rgba(0,0,0,0.8)] w-full max-w-[900px] my-8">
                <div className="flex items-center justify-between p-5 border-b border-white/10 bg-[#141b2d] rounded-t-xl">
                    <h2 className="text-lg font-semibold text-white">{isEdit ? 'Fit bearbeiten' : 'Neues Fit anlegen'}</h2>
                    <button type="button" onClick={onClose} className="text-eve-muted hover:text-white cursor-pointer text-sm">
                        Schließen
                    </button>
                </div>

                <div className="p-5 grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div className="space-y-3">
                        <label className="block">
                            <span className="block text-xs text-eve-muted mb-1">Name</span>
                            <input className={INPUT_CLASS} value={name} onChange={(event) => setName(event.target.value)} placeholder="Wird sonst aus dem EFT übernommen" />
                        </label>
                        <label className="block">
                            <span className="block text-xs text-eve-muted mb-1">Beschreibung</span>
                            <textarea className={`${INPUT_CLASS} resize-y`} rows={3} value={description} onChange={(event) => setDescription(event.target.value)} />
                        </label>
                        {!isEdit && (
                            <label className="block">
                                <span className="block text-xs text-eve-muted mb-1">Tags (mit Komma getrennt)</span>
                                <input className={INPUT_CLASS} value={tagsInput} onChange={(event) => setTagsInput(event.target.value)} placeholder="pvp, wurmloch, kite" />
                            </label>
                        )}
                        <label className="block">
                            <span className="block text-xs text-eve-muted mb-1">EFT (aus dem Spiel oder Pyfa kopieren)</span>
                            <textarea
                                className={`${INPUT_CLASS} font-mono text-xs resize-y`}
                                rows={16}
                                value={eft}
                                onChange={(event) => {
                                    setEft(event.target.value);
                                    setPreview(null);
                                }}
                                placeholder={'[Loki, PvP Kite]\nDamage Control II\n...'}
                            />
                        </label>
                        {isEdit && fitting.copies.length > 0 && eft !== initialEft && (
                            <p className="text-xs text-amber-300">
                                Ingame gespeicherte Kopien werden danach als veraltet markiert und können pro Charakter aktualisiert werden.
                            </p>
                        )}
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <span className="text-xs uppercase tracking-wider font-bold text-eve-muted">Vorschau</span>
                            <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={handlePreview} disabled={isBusy || eft.trim() === ''}>
                                EFT prüfen
                            </button>
                        </div>
                        {preview ? (
                            <div className="space-y-3">
                                <div className="flex items-center gap-3">
                                    <img src={typeIconUrl(typeIconTemplate, preview.shipTypeId)} alt="" className="w-10 h-10 rounded" />
                                    <div>
                                        <div className="text-sm font-semibold text-white">{preview.name || name || preview.shipName}</div>
                                        <div className="text-xs text-eve-primary">{preview.shipName}</div>
                                    </div>
                                </div>
                                {preview.existingFitting && preview.existingFitting.id !== fitting?.id && (
                                    <p className="text-xs text-amber-300">Dieses Fit existiert bereits als "{preview.existingFitting.name}".</p>
                                )}
                                {preview.warnings.length > 0 && (
                                    <ul className="text-xs text-amber-300 list-disc pl-4">
                                        {preview.warnings.map((warning) => (
                                            <li key={warning}>{warning}</li>
                                        ))}
                                    </ul>
                                )}
                                <SlotView slots={preview.slots} typeIconTemplate={typeIconTemplate} />
                            </div>
                        ) : (
                            <p className="text-xs text-eve-muted">Füge ein EFT ein und klicke auf "EFT prüfen", um das Fit vor dem Speichern zu sehen.</p>
                        )}
                    </div>
                </div>

                {error && <div className="mx-5 mb-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">{error}</div>}

                <div className="flex justify-end gap-2 p-5 border-t border-white/10">
                    <button type="button" className={BUTTON_SECONDARY_CLASS} onClick={onClose}>
                        Abbrechen
                    </button>
                    <button type="button" className={BUTTON_PRIMARY_CLASS} onClick={handleSave} disabled={isBusy || (!isEdit && eft.trim() === '')}>
                        {isBusy ? 'Speichert ...' : 'Speichern'}
                    </button>
                </div>
            </div>
        </div>
    );
}
