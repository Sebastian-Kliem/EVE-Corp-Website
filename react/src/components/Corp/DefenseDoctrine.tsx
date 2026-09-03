import React, { useState, useMemo } from 'react';

export interface FitItem {
    id: number;
    title: string;
    shipName: string;
    shipTypeId: number | null;
    role: string;
    eft: string;
    notes: string;
    sortOrder: number;
    updatedAt: string;
    createdByName: string | null;
}

interface DefenseDoctrineProps {
    initialNotes: string;
    initialNotesUpdatedAt: string | null;
    initialFits: FitItem[];
    canManage?: boolean;
    imagePaths: {
        types: string;
        renders: string;
    };
    apiEndpoints: {
        saveNotes: string;
        createFit: string;
        updateFit: string; // contains '123456789' as placeholder for id
        deleteFit: string; // contains '123456789' as placeholder for id
    };
}

const AVAILABLE_ROLES = [
    'DPS',
    'Logistik',
    'Tackle',
    'E-War',
    'Support',
    'Booster / Fleet Command',
    'Struktur-Verteidigung',
    'Sonstige'
];

export default function DefenseDoctrine({
    initialNotes,
    initialNotesUpdatedAt,
    initialFits,
    canManage = false,
    imagePaths,
    apiEndpoints,
}: DefenseDoctrineProps) {
    // Notes state
    const [notes, setNotes] = useState(initialNotes);
    const [notesUpdatedAt, setNotesUpdatedAt] = useState(initialNotesUpdatedAt);
    const [isEditingNotes, setIsEditingNotes] = useState(false);
    const [notesDraft, setNotesDraft] = useState(initialNotes);
    const [isSavingNotes, setIsSavingNotes] = useState(false);

    // Fits state
    const [fits, setFits] = useState<FitItem[]>(initialFits);
    const [selectedRole, setSelectedRole] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');

    // Fit Form modal/drawer state
    const [isFormOpen, setIsFormOpen] = useState<boolean>(false);
    const [editingFitId, setEditingFitId] = useState<number | null>(null);
    const [formTitle, setFormTitle] = useState('');
    const [formShipName, setFormShipName] = useState('');
    const [formRole, setFormRole] = useState('DPS');
    const [formEft, setFormEft] = useState('');
    const [formNotes, setFormNotes] = useState('');
    const [isSubmittingFit, setIsSubmittingFit] = useState(false);

    // UX feedback
    const [copiedFitId, setCopiedFitId] = useState<number | null>(null);
    const [expandedFitIds, setExpandedFitIds] = useState<Set<number>>(new Set());
    const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const showFeedback = (type: 'success' | 'error', text: string) => {
        setFeedbackMessage({ type, text });
        setTimeout(() => {
            setFeedbackMessage(null);
        }, 4000);
    };

    // Parse EFT on paste or change
    const handleEftChange = (newEft: string) => {
        setFormEft(newEft);

        // Auto-extract ship name and fit title if empty
        const lines = newEft.trim().split(/\r?\n/);
        if (lines.length > 0) {
            const firstLine = lines[0].trim();
            const match = firstLine.match(/^\s*\[\s*([^,\]]+?)\s*,\s*([^\]]+?)\s*\]/);
            if (match) {
                const parsedShip = match[1].trim();
                const parsedTitle = match[2].trim();
                if (!formShipName) {
                    setFormShipName(parsedShip);
                }
                if (!formTitle) {
                    setFormTitle(parsedTitle);
                }
            } else {
                const singleMatch = firstLine.match(/^\s*\[\s*([^\]]+?)\s*\]/);
                if (singleMatch && !formShipName) {
                    setFormShipName(singleMatch[1].trim());
                }
            }
        }
    };

    // Save General Notes
    const handleSaveNotes = async () => {
        setIsSavingNotes(true);
        try {
            const res = await fetch(apiEndpoints.saveNotes, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notes: notesDraft }),
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || 'Fehler beim Speichern der Notizen.');
            }
            setNotes(data.notes);
            setNotesUpdatedAt(data.updatedAt);
            setIsEditingNotes(false);
            showFeedback('success', 'Verteidigungsdoktrin-Anmerkungen erfolgreich aktualisiert.');
        } catch (err: any) {
            showFeedback('error', err.message || 'Speichern fehlgeschlagen.');
        } finally {
            setIsSavingNotes(false);
        }
    };

    // Open Fit Form (Create)
    const handleOpenCreateForm = () => {
        setEditingFitId(null);
        setFormTitle('');
        setFormShipName('');
        setFormRole('DPS');
        setFormEft('');
        setFormNotes('');
        setIsFormOpen(true);
    };

    // Open Fit Form (Edit)
    const handleOpenEditForm = (fit: FitItem) => {
        setEditingFitId(fit.id);
        setFormTitle(fit.title);
        setFormShipName(fit.shipName);
        setFormRole(fit.role || 'DPS');
        setFormEft(fit.eft);
        setFormNotes(fit.notes || '');
        setIsFormOpen(true);
    };

    // Submit Fit (Create or Update)
    const handleSubmitFit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!formEft.trim()) {
            showFeedback('error', 'Bitte gib ein gültiges EFT-Fitting ein.');
            return;
        }

        setIsSubmittingFit(true);
        try {
            const isEditing = editingFitId !== null;
            const endpoint = isEditing
                ? apiEndpoints.updateFit.replace('123456789', editingFitId.toString())
                : apiEndpoints.createFit;

            const res = await fetch(endpoint, {
                method: isEditing ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: formTitle,
                    shipName: formShipName,
                    role: formRole,
                    eft: formEft,
                    notes: formNotes,
                }),
            });

            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || 'Fehler beim Speichern des Fits.');
            }

            if (isEditing) {
                setFits((prev) => prev.map((f) => (f.id === editingFitId ? data.fit : f)));
                showFeedback('success', `Fit "${data.fit.title}" wurde erfolgreich aktualisiert.`);
            } else {
                setFits((prev) => [data.fit, ...prev]);
                showFeedback('success', `Neuer Fit "${data.fit.title}" wurde erfolgreich hinzugefügt.`);
            }

            setIsFormOpen(false);
        } catch (err: any) {
            showFeedback('error', err.message || 'Speichern fehlgeschlagen.');
        } finally {
            setIsSubmittingFit(false);
        }
    };

    // Delete Fit
    const handleDeleteFit = async (fit: FitItem) => {
        if (!window.confirm(`Möchtest du den Fit "${fit.title}" wirklich löschen?`)) {
            return;
        }

        try {
            const endpoint = apiEndpoints.deleteFit.replace('123456789', fit.id.toString());
            const res = await fetch(endpoint, {
                method: 'DELETE',
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || 'Fehler beim Löschen.');
            }

            setFits((prev) => prev.filter((f) => f.id !== fit.id));
            showFeedback('success', `Fit "${fit.title}" wurde gelöscht.`);
        } catch (err: any) {
            showFeedback('error', err.message || 'Löschen fehlgeschlagen.');
        }
    };

    // Copy EFT to clipboard
    const handleCopyEft = (fit: FitItem) => {
        navigator.clipboard.writeText(fit.eft).then(() => {
            setCopiedFitId(fit.id);
            setTimeout(() => {
                setCopiedFitId(null);
            }, 2000);
        });
    };

    // Toggle expand fit view
    const toggleExpandFit = (fitId: number) => {
        setExpandedFitIds((prev) => {
            const next = new Set(prev);
            if (next.has(fitId)) {
                next.delete(fitId);
            } else {
                next.add(fitId);
            }
            return next;
        });
    };

    // Filter fits
    const filteredFits = useMemo(() => {
        return fits.filter((fit) => {
            if (selectedRole !== 'all' && fit.role !== selectedRole) {
                return false;
            }
            if (searchQuery.trim()) {
                const q = searchQuery.toLowerCase();
                const matchesTitle = fit.title.toLowerCase().includes(q);
                const matchesShip = fit.shipName.toLowerCase().includes(q);
                const matchesRole = fit.role.toLowerCase().includes(q);
                const matchesNotes = (fit.notes || '').toLowerCase().includes(q);
                const matchesEft = fit.eft.toLowerCase().includes(q);
                if (!matchesTitle && !matchesShip && !matchesRole && !matchesNotes && !matchesEft) {
                    return false;
                }
            }
            return true;
        });
    }, [fits, selectedRole, searchQuery]);

    // Role counts
    const roleCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        fits.forEach((fit) => {
            const r = fit.role || 'Sonstige';
            counts[r] = (counts[r] || 0) + 1;
        });
        return counts;
    }, [fits]);

    const getRoleBadgeStyle = (role: string) => {
        switch (role) {
            case 'DPS':
                return 'bg-red-500/20 text-red-400 border border-red-500/40 shadow-[0_0_8px_rgba(239,68,68,0.2)]';
            case 'Logistik':
                return 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-[0_0_8px_rgba(16,185,129,0.2)]';
            case 'Tackle':
                return 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/40 shadow-[0_0_8px_rgba(6,182,212,0.2)]';
            case 'E-War':
                return 'bg-amber-500/20 text-amber-400 border border-amber-500/40 shadow-[0_0_8px_rgba(245,158,11,0.2)]';
            case 'Support':
            case 'Booster / Fleet Command':
                return 'bg-purple-500/20 text-purple-400 border border-purple-500/40 shadow-[0_0_8px_rgba(168,85,247,0.2)]';
            case 'Struktur-Verteidigung':
                return 'bg-orange-500/20 text-orange-400 border border-orange-500/40 shadow-[0_0_8px_rgba(249,115,22,0.2)]';
            default:
                return 'bg-sky-500/20 text-sky-300 border border-sky-500/40';
        }
    };

    const getShipIconUrl = (fit: FitItem) => {
        if (fit.shipTypeId) {
            return imagePaths.types.replace('12345', fit.shipTypeId.toString());
        }
        return '/assets/images/fallback_item.png';
    };

    return (
        <div className="flex flex-col gap-8">
            {/* Feedback Alert */}
            {feedbackMessage && (
                <div
                    className={`border rounded-lg p-4 transition-all duration-300 ${
                        feedbackMessage.type === 'error'
                            ? 'bg-red-500/15 border-red-500/40 text-red-300'
                            : 'bg-emerald-500/15 border-emerald-500/40 text-emerald-300'
                    }`}
                >
                    {feedbackMessage.text}
                </div>
            )}

            {/* Section 1: Allgemeine Doktrin-Anmerkungen & Taktische Richtlinien */}
            <div className="bg-eve-card border border-eve-border rounded-xl p-6 shadow-eve">
                <div className="flex flex-wrap items-center justify-between gap-4 mb-4 border-b border-white/5 pb-4">
                    <div className="flex items-center gap-3">
                        <span className="text-2xl">📝</span>
                        <div>
                            <h2 className="text-xl font-bold text-white tracking-wide">
                                Taktische Anmerkungen & Richtlinien
                            </h2>
                            <p className="text-xs text-eve-muted">
                                Allgemeine Vorgaben, Primär-Ziele, Funkfrequenzen, WH-Massenlimits und Verhalten bei Angriffen.
                            </p>
                        </div>
                    </div>

                    {canManage && (
                        !isEditingNotes ? (
                            <button
                                onClick={() => {
                                    setNotesDraft(notes);
                                    setIsEditingNotes(true);
                                }}
                                className="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white font-medium text-xs px-3.5 py-2 cursor-pointer transition-colors duration-200"
                            >
                                ✏️ Anmerkungen bearbeiten
                            </button>
                        ) : (
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={handleSaveNotes}
                                    disabled={isSavingNotes}
                                    className="inline-flex items-center gap-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white font-medium text-xs px-3.5 py-2 cursor-pointer transition-colors duration-200 disabled:opacity-50"
                                >
                                    {isSavingNotes ? 'Speichern...' : '💾 Speichern'}
                                </button>
                                <button
                                    onClick={() => setIsEditingNotes(false)}
                                    disabled={isSavingNotes}
                                    className="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white font-medium text-xs px-3.5 py-2 cursor-pointer transition-colors duration-200"
                                >
                                    Abbrechen
                                </button>
                            </div>
                        )
                    )}
                </div>

                {isEditingNotes && canManage ? (
                    <div className="flex flex-col gap-3">
                        <textarea
                            value={notesDraft}
                            onChange={(e) => setNotesDraft(e.target.value)}
                            rows={6}
                            placeholder="Schreibe hier kurze Anmerkungen und Taktiken für die Verteidigung (z.B. primäre Ziele, Notfall-Anchor, Drohnen-Fokus, Massenlimits)..."
                            className="rounded-lg w-full px-4 py-3 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300 resize-y font-mono leading-relaxed"
                        />
                        <div className="text-right text-[11px] text-eve-muted">
                            Tipp: Unterstützt Zeilenumbrüche und übersichtliche Aufzählungen.
                        </div>
                    </div>
                ) : (
                    <div>
                        {notes && notes.trim() ? (
                            <div className="text-sm text-eve-text whitespace-pre-line leading-relaxed bg-[#0b0f19]/60 border border-white/5 rounded-lg p-4">
                                {notes}
                            </div>
                        ) : (
                            <div className="text-sm text-eve-muted italic bg-white/[0.02] border border-dashed border-white/10 rounded-lg p-6 text-center">
                                {canManage
                                    ? 'Noch keine allgemeinen Anmerkungen hinterlegt. Klicke auf "Anmerkungen bearbeiten", um taktische Notizen hinzuzufügen.'
                                    : 'Aktuell sind keine allgemeinen Doktrin-Anmerkungen hinterlegt.'}
                            </div>
                        )}
                        {notesUpdatedAt && (
                            <div className="text-right text-[11px] text-eve-muted mt-2">
                                Zuletzt aktualisiert: {notesUpdatedAt}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Section 2: Doktrin-Fits */}
            <div className="bg-eve-card border border-eve-border rounded-xl p-6 shadow-eve">
                {/* Fits Header & Filter Toolbar */}
                <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <div className="flex items-center gap-3">
                        <span className="text-2xl">🚀</span>
                        <div>
                            <h2 className="text-xl font-bold text-white tracking-wide">
                                Doktrin-Fits ({fits.length})
                            </h2>
                            <p className="text-xs text-eve-muted">
                                Standardisierte Fittings für Schiffe unserer Verteidigungsflotte mit 1-Click Export.
                            </p>
                        </div>
                    </div>

                    {canManage && (
                        <button
                            onClick={handleOpenCreateForm}
                            className="inline-flex items-center gap-2 border border-transparent rounded-lg bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold text-sm px-4 py-2.5 shadow-eve transition-all duration-300 hover:-translate-y-0.5 cursor-pointer"
                        >
                            ➕ Neuen Fit hinzufügen
                        </button>
                    )}
                </div>

                {/* Filters & Search */}
                <div className="flex flex-wrap items-center justify-between gap-4 mb-6 bg-black/20 p-3 rounded-lg border border-white/5">
                    {/* Role Filter Pills */}
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            onClick={() => setSelectedRole('all')}
                            className={`text-xs px-3 py-1.5 rounded-lg font-medium transition-all duration-200 cursor-pointer ${
                                selectedRole === 'all'
                                    ? 'bg-eve-primary text-[#060911] font-bold shadow-eve'
                                    : 'bg-white/5 text-eve-muted hover:text-white hover:bg-white/10'
                            }`}
                        >
                            Alle ({fits.length})
                        </button>
                        {AVAILABLE_ROLES.map((r) => {
                            const count = roleCounts[r] || 0;
                            if (count === 0 && selectedRole !== r) return null;
                            return (
                                <button
                                    key={r}
                                    onClick={() => setSelectedRole(r)}
                                    className={`text-xs px-3 py-1.5 rounded-lg font-medium transition-all duration-200 cursor-pointer ${
                                        selectedRole === r
                                            ? 'bg-eve-primary text-[#060911] font-bold shadow-eve'
                                            : 'bg-white/5 text-eve-muted hover:text-white hover:bg-white/10'
                                    }`}
                                >
                                    {r} ({count})
                                </button>
                            );
                        })}
                    </div>

                    {/* Search Input */}
                    <div className="relative min-w-[240px]">
                        <input
                            type="text"
                            placeholder="Fit, Schiff oder Modul suchen..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="rounded-lg text-xs pl-8 pr-8 py-1.5 w-full border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                        />
                        <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-eve-muted pointer-events-none">🔍</span>
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-eve-muted hover:text-white transition-colors"
                            >
                                ✕
                            </button>
                        )}
                    </div>
                </div>

                {/* Fits Grid */}
                {filteredFits.length === 0 ? (
                    <div className="text-center py-16 rounded-xl bg-white/[0.02] border border-dashed border-white/10">
                        <p className="text-eve-muted mb-3">
                            {fits.length === 0
                                ? 'Aktuell sind noch keine Verteidigungsfits hinterlegt.'
                                : 'Keine passenden Verteidigungsfits für diesen Filter/Suchbegriff gefunden.'}
                        </p>
                        {canManage && (
                            <button
                                onClick={handleOpenCreateForm}
                                className="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white font-medium text-xs px-4 py-2 cursor-pointer transition-colors duration-200"
                            >
                                ➕ Ersten Fit erstellen
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        {filteredFits.map((fit) => {
                            const isCopied = copiedFitId === fit.id;
                            const isExpanded = expandedFitIds.has(fit.id);

                            return (
                                <div
                                    key={fit.id}
                                    className="flex flex-col justify-between bg-[#0e1322] border border-eve-border/60 hover:border-eve-border rounded-xl p-5 shadow-eve transition-all duration-200 hover:shadow-[0_4px_20px_rgba(0,240,255,0.08)]"
                                >
                                    <div>
                                        {/* Card Header: Ship Icon + Titles + Role Badge */}
                                        <div className="flex items-start justify-between gap-3 mb-4">
                                            <div className="flex items-center gap-3">
                                                <div className="relative flex-shrink-0 w-12 h-12 rounded-lg bg-black/50 border border-white/10 p-1 flex items-center justify-center overflow-hidden">
                                                    <img
                                                        src={getShipIconUrl(fit)}
                                                        alt={fit.shipName}
                                                        className="w-10 h-10 object-contain rounded"
                                                        loading="lazy"
                                                    />
                                                </div>
                                                <div>
                                                    <h3 className="text-base font-bold text-white leading-tight">
                                                        {fit.title}
                                                    </h3>
                                                    <span className="text-xs font-semibold text-eve-primary">
                                                        {fit.shipName}
                                                    </span>
                                                </div>
                                            </div>

                                            <span
                                                className={`text-[11px] font-bold px-2.5 py-1 rounded-md tracking-wide leading-none uppercase ${getRoleBadgeStyle(
                                                    fit.role
                                                )}`}
                                            >
                                                {fit.role}
                                            </span>
                                        </div>

                                        {/* Fit Specific Notes */}
                                        {fit.notes && fit.notes.trim() ? (
                                            <div className="mb-4 text-xs text-amber-200/90 bg-amber-500/10 border border-amber-500/20 rounded-lg p-3 whitespace-pre-line leading-relaxed">
                                                <div className="flex items-center gap-1.5 font-bold text-amber-300 text-[11px] mb-1">
                                                    <span>💡 Anmerkung zum Fit:</span>
                                                </div>
                                                {fit.notes}
                                            </div>
                                        ) : null}
                                    </div>

                                    {/* Action Buttons */}
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2 pt-3 border-t border-white/5">
                                            <button
                                                onClick={() => handleCopyEft(fit)}
                                                className={`flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-2 cursor-pointer transition-all duration-200 ${
                                                    isCopied
                                                        ? 'bg-emerald-500 text-white shadow-[0_0_12px_rgba(16,185,129,0.4)]'
                                                        : 'bg-eve-primary/15 hover:bg-eve-primary/25 text-eve-primary border border-eve-primary/30'
                                                }`}
                                            >
                                                {isCopied ? '✓ In die Zwischenablage kopiert!' : '📋 EFT kopieren'}
                                            </button>

                                            <button
                                                onClick={() => toggleExpandFit(fit.id)}
                                                title={isExpanded ? 'Fitting einklappen' : 'Fitting anzeigen'}
                                                className="inline-flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white text-xs px-2.5 py-2 cursor-pointer transition-colors duration-200 border border-white/5"
                                            >
                                                {isExpanded ? '▲' : '👁️'}
                                            </button>

                                            {canManage && (
                                                <>
                                                    <button
                                                        onClick={() => handleOpenEditForm(fit)}
                                                        title="Fit bearbeiten"
                                                        className="inline-flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white text-xs px-2.5 py-2 cursor-pointer transition-colors duration-200 border border-white/5"
                                                    >
                                                        ✏️
                                                    </button>

                                                    <button
                                                        onClick={() => handleDeleteFit(fit)}
                                                        title="Fit löschen"
                                                        className="inline-flex items-center justify-center rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 text-xs px-2.5 py-2 cursor-pointer transition-colors duration-200 border border-red-500/20"
                                                    >
                                                        🗑️
                                                    </button>
                                                </>
                                            )}
                                        </div>

                                        {/* Expanded EFT Fitting Raw View */}
                                        {isExpanded && (
                                            <div className="mt-3 bg-black/60 border border-white/10 rounded-lg p-3 relative">
                                                <div className="flex items-center justify-between mb-2">
                                                    <span className="text-[10px] text-eve-muted uppercase font-bold tracking-wider">
                                                        EFT Fitting Export
                                                    </span>
                                                    <button
                                                        onClick={() => handleCopyEft(fit)}
                                                        className="text-[11px] text-eve-primary hover:underline cursor-pointer"
                                                    >
                                                        {isCopied ? '✓ Kopiert' : 'Kopieren'}
                                                    </button>
                                                </div>
                                                <pre className="text-[11px] font-mono text-eve-text overflow-x-auto max-h-[220px] whitespace-pre leading-relaxed select-all">
                                                    {fit.eft}
                                                </pre>
                                            </div>
                                        )}

                                        <div className="flex items-center justify-between text-[10px] text-eve-muted mt-3">
                                            <span>
                                                {fit.createdByName ? `Erstellt von: ${fit.createdByName}` : 'Corp-Fit'}
                                            </span>
                                            <span>Aktualisiert: {fit.updatedAt}</span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Modal: Fit erstellen / bearbeiten */}
            {isFormOpen && canManage && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm p-4 overflow-y-auto">
                    <div className="bg-[#0f1523] border border-eve-border rounded-xl shadow-[0_10px_30px_rgba(0,0,0,0.8)] w-full max-w-[650px] overflow-hidden my-8">
                        <div className="flex items-center justify-between p-5 border-b border-white/10 bg-[#141b2d]">
                            <div className="flex items-center gap-2.5">
                                <span className="text-xl">🚀</span>
                                <h3 className="text-lg font-bold text-white">
                                    {editingFitId !== null ? 'Doktrin-Fit bearbeiten' : 'Neuen Doktrin-Fit hinzufügen'}
                                </h3>
                            </div>
                            <button
                                onClick={() => setIsFormOpen(false)}
                                className="text-eve-muted hover:text-white text-lg font-bold cursor-pointer p-1"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSubmitFit} className="p-6 flex flex-col gap-4">
                            {/* EFT Input Area */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-xs font-semibold text-white flex items-center justify-between">
                                    <span>EFT-Fitting (Copy & Paste aus EVE / Pyfa): *</span>
                                    <span className="text-[11px] text-eve-muted font-normal">
                                        Schiffsname & Titel werden automatisch erkannt
                                    </span>
                                </label>
                                <textarea
                                    required
                                    rows={8}
                                    placeholder="[Praxis, Heavy Shield Brawler]&#10;Damage Control II&#10;...&#10;Heavy Missile Launcher II&#10;...&#10;Large Core Defence Field Extender I"
                                    value={formEft}
                                    onChange={(e) => handleEftChange(e.target.value)}
                                    className="rounded-lg w-full px-3.5 py-2.5 text-xs font-mono border border-eve-border text-eve-text bg-[#080c14] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300 resize-y leading-relaxed"
                                />
                            </div>

                            {/* Two Column Row: Ship Name & Fit Title */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-xs font-semibold text-white">
                                        Schiffsmodell: *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Z. B. Praxis, Dominix, Guardian"
                                        value={formShipName}
                                        onChange={(e) => setFormShipName(e.target.value)}
                                        className="rounded-lg w-full px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                                    />
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <label className="text-xs font-semibold text-white">
                                        Fit-Bezeichnung / Titel: *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Z. B. Heavy Armor Brawler"
                                        value={formTitle}
                                        onChange={(e) => setFormTitle(e.target.value)}
                                        className="rounded-lg w-full px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                                    />
                                </div>
                            </div>

                            {/* Role Dropdown */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-xs font-semibold text-white">
                                    Rolle / Kategorie:
                                </label>
                                <select
                                    value={formRole}
                                    onChange={(e) => setFormRole(e.target.value)}
                                    className="rounded-lg w-full px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                                >
                                    {AVAILABLE_ROLES.map((r) => (
                                        <option key={r} value={r}>
                                            {r}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Notes for this specific fit */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-xs font-semibold text-white">
                                    Anmerkungen & Taktik für diesen Fit (optional):
                                </label>
                                <textarea
                                    rows={3}
                                    placeholder="Z. B. Drohnen-Setup: 5x Infiltrator II. Munition: Caldari Navy Scourge. Immer Cap Booster nachladen..."
                                    value={formNotes}
                                    onChange={(e) => setFormNotes(e.target.value)}
                                    className="rounded-lg w-full px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 resize-y leading-relaxed"
                                />
                            </div>

                            {/* Form Actions */}
                            <div className="flex items-center justify-end gap-3 mt-4 pt-4 border-t border-white/10">
                                <button
                                    type="button"
                                    onClick={() => setIsFormOpen(false)}
                                    className="rounded-lg px-4 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/20 transition-colors duration-200 cursor-pointer"
                                >
                                    Abbrechen
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSubmittingFit}
                                    className="rounded-lg px-5 py-2 text-xs font-semibold text-[#060911] bg-eve-primary hover:brightness-115 shadow-eve transition-all duration-200 cursor-pointer disabled:opacity-50"
                                >
                                    {isSubmittingFit ? 'Speichern...' : '💾 Fit speichern'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
