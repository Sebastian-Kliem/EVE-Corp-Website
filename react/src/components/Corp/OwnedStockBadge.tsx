import React, { useState } from 'react';
import { formatThousands } from '../../utils/numberFormat';

export interface OwnedStockLocation {
    location: string;
    system: string | null;
    path: string;
    owner: string;
    quantity: number;
}

export interface OwnedStockEntry {
    total: number;
    locations: OwnedStockLocation[];
}

// Keyed by type ID; PHP sends an empty array when nothing is owned
export type OwnedStock = Record<number, OwnedStockEntry> | [];

export function findOwnedStock(ownedStock: OwnedStock | undefined, typeId: number): OwnedStockEntry | undefined {
    if (!ownedStock || Array.isArray(ownedStock)) {
        return undefined;
    }
    return ownedStock[typeId];
}

interface OwnedStockBadgeProps {
    stock: OwnedStockEntry | undefined;
    requiredAmount: number;
}

const POPOVER_WIDTH = 340;

// Shows how many of an item the current user owns, hovering lists every location
export default function OwnedStockBadge({ stock, requiredAmount }: OwnedStockBadgeProps) {
    const [popoverPosition, setPopoverPosition] = useState<{ top: number; left: number } | null>(null);

    if (!stock || stock.total <= 0) {
        return null;
    }

    const coversOrder = stock.total >= requiredAmount;

    const showPopover = (event: React.MouseEvent<HTMLSpanElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - POPOVER_WIDTH - 8));
        setPopoverPosition({ top: rect.bottom + 6, left });
    };

    return (
        <span
            className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold border cursor-help whitespace-nowrap ${
                coversOrder
                    ? 'bg-emerald-500/25 text-emerald-200 border-emerald-400/60'
                    : 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
            }`}
            onMouseEnter={showPopover}
            onMouseLeave={() => setPopoverPosition(null)}
            onClick={(event) => event.stopPropagation()}
        >
            Im Lager: {formatThousands(stock.total)}
            {popoverPosition && (
                <span
                    className="fixed z-50 block rounded-lg border border-eve-border bg-[#0a0f1d] p-3 text-left font-normal shadow-eve"
                    style={{ top: popoverPosition.top, left: popoverPosition.left, width: POPOVER_WIDTH }}
                >
                    <span className="block mb-2 text-[11px] font-semibold text-white">
                        Dein Bestand: {formatThousands(stock.total)} Stück{' '}
                        <span className={coversOrder ? 'text-emerald-300' : 'text-amber-300'}>
                            ({coversOrder ? 'deckt' : 'deckt nicht'} die bestellten {formatThousands(requiredAmount)})
                        </span>
                    </span>
                    {stock.locations.map((location, index) => (
                        <span key={index} className="flex justify-between gap-3 py-1 border-t border-white/5 text-[11px]">
                            <span className="min-w-0">
                                <span className="block text-slate-200 break-words">{location.location}</span>
                                {location.path !== '' && <span className="block text-eve-muted break-words">{location.path}</span>}
                                <span className="block text-eve-muted">{location.owner}</span>
                            </span>
                            <span className="shrink-0 font-mono text-emerald-300">{formatThousands(location.quantity)}</span>
                        </span>
                    ))}
                </span>
            )}
        </span>
    );
}
