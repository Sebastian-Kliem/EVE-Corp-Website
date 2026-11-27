import React, { useState } from 'react';

export interface FittingCopy {
    id: number;
    characterId: number;
    characterName: string;
    userId: number | null;
    ingameName: string;
    outdated: boolean;
    isOwn: boolean;
}

export interface FittingSummary {
    id: number;
    name: string;
    description: string;
    shipTypeId: number;
    shipName: string;
    shipGroup: string;
    tags: string[];
    corpSaved: boolean;
    createdById: number | null;
    createdByName: string | null;
    updatedAt: string;
    canEdit: boolean;
    copies: FittingCopy[];
    pilotIds: number[];
}

export interface CharacterRef {
    id: number;
    name: string;
    userId: number | null;
}

export interface UserRef {
    id: number;
    name: string;
}

export interface OwnCharacter {
    id: number;
    name: string;
    canRead: boolean;
    canWrite: boolean;
}

export interface FittingLibraryData {
    fittings: FittingSummary[];
    characters: CharacterRef[];
    users: UserRef[];
    ownCharacters: OwnCharacter[];
    currentUserId: number;
}

export interface SlotItem {
    typeId: number;
    name: string;
    quantity: number;
    slotIndex: number;
}

export type SlotMap = Record<string, SlotItem[]>;

export const SLOT_SECTIONS: { key: string; label: string }[] = [
    { key: 'high', label: 'High Slots' },
    { key: 'med', label: 'Mid Slots' },
    { key: 'low', label: 'Low Slots' },
    { key: 'rig', label: 'Rigs' },
    { key: 'subsystem', label: 'Subsysteme' },
    { key: 'service', label: 'Service Slots' },
    { key: 'drone', label: 'Drohnen' },
    { key: 'fighter', label: 'Fighter' },
    { key: 'cargo', label: 'Fracht' },
];

export const INPUT_CLASS =
    'rounded-lg w-full px-3 py-2 text-sm border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300';
export const BUTTON_PRIMARY_CLASS =
    'inline-flex items-center justify-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-2 cursor-pointer transition-colors bg-eve-primary/15 hover:bg-eve-primary/25 text-eve-primary border border-eve-primary/30 disabled:opacity-50 disabled:cursor-not-allowed';
export const BUTTON_SECONDARY_CLASS =
    'inline-flex items-center justify-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-2 cursor-pointer transition-colors bg-white/5 hover:bg-white/10 text-white border border-white/10 disabled:opacity-50 disabled:cursor-not-allowed';
export const BUTTON_DANGER_CLASS =
    'inline-flex items-center justify-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-2 cursor-pointer transition-colors bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 disabled:opacity-50 disabled:cursor-not-allowed';
export const CARD_CLASS = 'bg-[#0e1322] border border-eve-border/60 rounded-xl';

export const withId = (url: string, id: number): string => url.replace('123456789', String(id));

export const typeIconUrl = (template: string, typeId: number): string => template.replace('12345', String(typeId));

/**
 * JSON request helper; throws with the server's error message.
 */
export async function requestJson<T>(url: string, method: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    let data: any = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }
    if (!response.ok) {
        throw new Error(data?.error || data?.detail || `Anfrage fehlgeschlagen (HTTP ${response.status}).`);
    }
    return data as T;
}

export async function copyToClipboard(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

export function TagBadge({ tag, active = false, onClick }: { tag: string; active?: boolean; onClick?: () => void }) {
    const baseClass = 'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold border';
    const colorClass = active
        ? 'bg-eve-primary/25 text-eve-primary border-eve-primary/50'
        : 'bg-white/5 text-eve-muted border-white/10';
    if (onClick) {
        return (
            <button type="button" onClick={onClick} className={`${baseClass} ${colorClass} cursor-pointer hover:border-eve-primary/50`}>
                {tag}
            </button>
        );
    }
    return <span className={`${baseClass} ${colorClass}`}>{tag}</span>;
}

/**
 * Slot layout of a fit grouped by section.
 */
export function SlotView({ slots, typeIconTemplate }: { slots: SlotMap; typeIconTemplate: string }) {
    const sections = SLOT_SECTIONS.filter((section) => (slots[section.key] || []).length > 0);
    if (sections.length === 0) {
        return <p className="text-xs text-eve-muted">Keine Module.</p>;
    }

    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {sections.map((section) => (
                <div key={section.key} className="bg-black/30 border border-white/5 rounded-lg p-2.5">
                    <div className="text-[10px] uppercase tracking-wider font-bold text-eve-muted mb-1.5">{section.label}</div>
                    <ul className="space-y-1">
                        {slots[section.key].map((item, index) => (
                            <li key={`${section.key}-${index}`} className="flex items-center gap-2 text-xs text-eve-text">
                                <img
                                    src={typeIconUrl(typeIconTemplate, item.typeId)}
                                    alt=""
                                    className="w-5 h-5 rounded shrink-0"
                                    loading="lazy"
                                />
                                <span className="truncate">{item.name}</span>
                                {item.quantity > 1 && <span className="ml-auto text-eve-muted shrink-0">x{item.quantity}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

/**
 * Characters able to fly a fit, grouped by user.
 */
export function PilotList({
    pilotIds,
    characters,
    users,
    highlightUserId,
}: {
    pilotIds: number[];
    characters: CharacterRef[];
    users: UserRef[];
    highlightUserId?: number | null;
}) {
    const [expanded, setExpanded] = useState(false);
    const pilotIdSet = new Set(pilotIds);
    const groups: { user: UserRef; characterNames: string[] }[] = [];
    for (const user of users) {
        const characterNames: string[] = [];
        for (const character of characters) {
            if (character.userId === user.id && pilotIdSet.has(character.id)) {
                characterNames.push(character.name);
            }
        }
        if (characterNames.length > 0) {
            groups.push({ user, characterNames });
        }
    }

    if (groups.length === 0) {
        return <p className="text-xs text-eve-muted">Niemand erfüllt aktuell die Grundskills.</p>;
    }

    const visibleGroups = expanded ? groups : groups.slice(0, 8);
    return (
        <div className="space-y-1.5">
            {visibleGroups.map((group) => (
                <div key={group.user.id} className="text-xs">
                    <span className={`font-semibold ${group.user.id === highlightUserId ? 'text-eve-primary' : 'text-white'}`}>
                        {group.user.name}
                    </span>
                    <span className="text-eve-muted">: {group.characterNames.join(', ')}</span>
                </div>
            ))}
            {groups.length > 8 && (
                <button type="button" className="text-[11px] text-eve-primary hover:underline cursor-pointer" onClick={() => setExpanded(!expanded)}>
                    {expanded ? 'Weniger anzeigen' : `Alle ${groups.length} User anzeigen`}
                </button>
            )}
        </div>
    );
}

export function countPilotUsers(pilotIds: number[], characters: CharacterRef[]): number {
    const pilotIdSet = new Set(pilotIds);
    const userIds = new Set<number>();
    for (const character of characters) {
        if (character.userId !== null && pilotIdSet.has(character.id)) {
            userIds.add(character.userId);
        }
    }
    return userIds.size;
}

export function FeedbackBanner({ feedback }: { feedback: { type: 'success' | 'error'; text: string } | null }) {
    if (!feedback) {
        return null;
    }
    const colorClass =
        feedback.type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
            : 'bg-red-500/10 border-red-500/30 text-red-300';
    return <div className={`mb-4 rounded-lg border px-4 py-2.5 text-sm whitespace-pre-line ${colorClass}`}>{feedback.text}</div>;
}

export function useFeedback() {
    const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const showFeedback = (type: 'success' | 'error', text: string) => {
        setFeedback({ type, text });
        window.setTimeout(() => setFeedback(null), type === 'error' ? 8000 : 5000);
    };
    return { feedback, showFeedback };
}
