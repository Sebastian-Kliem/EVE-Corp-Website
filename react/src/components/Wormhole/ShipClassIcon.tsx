import React from 'react';
import { SHIP_CLASS_LABELS, ShipClass } from './siteModel';

// Outline paths in a 16x16 box, modelled after the in-game ship class icons
const SHIP_CLASS_PATHS: Record<ShipClass, string> = {
    frigate: 'M4.5 10.5 L8 7 L11.5 10.5 Z',
    destroyer: 'M4.5 9.5 L8 6 L11.5 9.5 Z M4.5 12 H11.5',
    cruiser: 'M4 12 V8 L8 4.5 L12 8 V12 Z',
    battlecruiser: 'M4 10.5 V7.5 L8 4 L12 7.5 V10.5 Z M4 13 H12',
    battleship: 'M4 13 V7 L8 3 L12 7 V13 L8 9.5 Z',
};

export default function ShipClassIcon({ shipClass, className = 'w-4 h-4' }: { shipClass: ShipClass; className?: string }) {
    const label = SHIP_CLASS_LABELS[shipClass];
    return (
        <svg viewBox="0 0 16 16" className={`${className} shrink-0 text-slate-300`} fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" role="img" aria-label={label}>
            <title>{label}</title>
            <path d={SHIP_CLASS_PATHS[shipClass]} />
        </svg>
    );
}
