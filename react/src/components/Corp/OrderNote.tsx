import React from 'react';

interface OrderNoteProps {
    note: string;
}

// Highlighted remark of an order, spans the full width of the card header
export default function OrderNote({ note }: OrderNoteProps) {
    if (note.trim() === '') {
        return null;
    }

    return (
        <div className="basis-full flex items-start gap-2 rounded-md border border-amber-500/40 border-l-4 border-l-amber-400 bg-amber-500/10 px-3 py-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="mt-0.5 shrink-0 text-amber-400" aria-hidden="true">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            <div className="min-w-0">
                <span className="block text-[11px] font-semibold uppercase tracking-wide text-amber-400">Bemerkung</span>
                <p className="text-sm text-amber-100 whitespace-pre-line break-words">{note}</p>
            </div>
        </div>
    );
}
