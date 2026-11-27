import React, { useState, useMemo } from 'react';

export interface FitItem {
    id: number;
    title: string;
    shipName: string;
    shipTypeId: number | null;
    role: string;
    quantity: number;
    eft: string;
    notes: string;
    fittingUrl: string;
}

export interface DoctrineFleet {
    id: number;
    name: string;
    description: string;
    updatedAt: string;
    fits: FitItem[];
}

interface DefenseDoctrineProps {
    initialNotes: string;
    initialNotesUpdatedAt: string | null;
    initialFleet: DoctrineFleet | null;
    fleetOptions: { id: number; name: string }[];
    fleetsUrl: string;
    canManage?: boolean;
    imagePaths: {
        types: string;
        renders: string;
    };
    apiEndpoints: {
        saveNotes: string;
        saveFleet: string;
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
    initialFleet,
    fleetOptions,
    fleetsUrl,
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

    // Doctrine fleet state
    const [fleet, setFleet] = useState<DoctrineFleet | null>(initialFleet);
    const [fleetDraftId, setFleetDraftId] = useState<number>(initialFleet ? initialFleet.id : 0);
    const [isSavingFleet, setIsSavingFleet] = useState(false);
    const fits = fleet ? fleet.fits : [];
    const [selectedRole, setSelectedRole] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');

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
                showFeedback('error', data.error || 'Fehler beim Speichern der Notizen.');
                return;
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

    // Save which fleet is the defense doctrine
    const handleSaveFleet = async () => {
        setIsSavingFleet(true);
        try {
            const res = await fetch(apiEndpoints.saveFleet, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fleetId: fleetDraftId }),
            });
            const data = await res.json();
            if (!res.ok) {
                showFeedback('error', data.error || 'Fehler beim Speichern der Doktrin-Flotte.');
                return;
            }
            setFleet(data.fleet);
            showFeedback('success', data.fleet ? `Doktrin-Flotte "${data.fleet.name}" gesetzt.` : 'Doktrin-Flotte entfernt.');
        } catch (err: any) {
            showFeedback('error', err.message || 'Speichern fehlgeschlagen.');
        } finally {
            setIsSavingFleet(false);
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
                                {fleet ? `Doktrin-Flotte: ${fleet.name}` : 'Doktrin-Flotte'} ({fits.length})
                            </h2>
                            <p className="text-xs text-eve-muted">
                                Fits und Rollen kommen aus der verknüpften Flotte. Bearbeitet wird sie auf der{' '}
                                <a href={fleetsUrl} className="text-eve-primary hover:underline">Flotten-Seite</a>.
                                {fleet && ` Stand: ${fleet.updatedAt}`}
                            </p>
                        </div>
                    </div>

                    {canManage && (
                        <div className="flex items-center gap-2">
                            <select
                                value={fleetDraftId}
                                onChange={(e) => setFleetDraftId(Number(e.target.value))}
                                className="rounded-lg px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 cursor-pointer"
                            >
                                <option value={0}>Keine Flotte</option>
                                {fleetOptions.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                            <button
                                onClick={handleSaveFleet}
                                disabled={isSavingFleet || fleetDraftId === (fleet ? fleet.id : 0)}
                                className="inline-flex items-center gap-2 rounded-lg bg-eve-primary text-[#060911] font-semibold text-sm px-4 py-2 cursor-pointer transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {isSavingFleet ? 'Speichern...' : 'Als Doktrin setzen'}
                            </button>
                        </div>
                    )}
                </div>

                {fleet && fleet.description.trim() && (
                    <div className="mb-6 text-sm text-eve-text whitespace-pre-line leading-relaxed bg-[#0b0f19]/60 border border-white/5 rounded-lg p-4">
                        {fleet.description}
                    </div>
                )}

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
                        {Object.keys(roleCounts).sort((left, right) => {
                            const leftIndex = AVAILABLE_ROLES.indexOf(left);
                            const rightIndex = AVAILABLE_ROLES.indexOf(right);
                            return (leftIndex === -1 ? 99 : leftIndex) - (rightIndex === -1 ? 99 : rightIndex) || left.localeCompare(right);
                        }).map((r) => {
                            const count = roleCounts[r] || 0;
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
                            {!fleet
                                ? 'Es ist noch keine Flotte als Verteidigungsdoktrin hinterlegt.'
                                : fits.length === 0
                                ? 'Die Doktrin-Flotte enthält noch keine Fits.'
                                : 'Keine passenden Verteidigungsfits für diesen Filter/Suchbegriff gefunden.'}
                        </p>
                        {canManage && (
                            <a
                                href={fleetsUrl}
                                className="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white font-medium text-xs px-4 py-2 cursor-pointer transition-colors duration-200"
                            >
                                Zur Flotten-Seite
                            </a>
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
                                                <div className="relative shrink-0 w-12 h-12 rounded-lg bg-black/50 border border-white/10 p-1 flex items-center justify-center overflow-hidden">
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
                                                    {fit.fittingUrl && (
                                                        <a
                                                            href={fit.fittingUrl}
                                                            className="block text-[11px] text-eve-muted hover:text-eve-primary hover:underline"
                                                        >
                                                            In der Fit-Bibliothek öffnen
                                                        </a>
                                                    )}
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
                                            <span>Anzahl in der Flotte: {fit.quantity}</span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
