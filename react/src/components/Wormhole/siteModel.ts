/**
 * Shared types and calculations for the wormhole site page and the signature calculator.
 */

export type SpecialEffects = Record<string, number>;

export interface Ship {
    qty: number;
    name: string;
    typeId: number | null;
    dps?: number;
    alpha?: number;
    ehp?: number;
    sig?: number;
    orbit_velocity?: string;
    distance?: string;
    special?: SpecialEffects;
    trigger?: boolean;
    triggerOnAttack?: boolean;
    optional?: boolean;
    dta?: boolean;
}

export interface Wave {
    wave: string;
    note?: string;
    ships: Ship[];
    triggerOnHack?: boolean;
    injected?: boolean;
}

export interface Resource {
    qty: number;
    name: string;
    nameDe: string | null;
    legacyName: string | null;
    typeId: number | null;
    amount: number | null;
    volumeM3: number | null; // amount multiplied with the SDE unit volume
    unit: string;
    approximate: boolean;
}

export interface Site {
    name: string;
    nameDe: string | null;
    category: 'combat' | 'data' | 'relic' | 'gas' | 'ore';
    classes: string[];
    occurrence: string | null;
    blueLootIsk: number | null;
    waves: Wave[];
    resources: Resource[];
    notes: string[];
    source: string;
}


export type DrifterMode = 'none' | 'recon' | 'response';

export const CATEGORY_LABELS: Record<Site['category'], string> = {
    combat: 'Combat',
    data: 'Data',
    relic: 'Relic',
    gas: 'Gas',
    ore: 'Erz & Eis',
};

// Module icons from the image service that stand for the NPC effects
export const SPECIAL_EFFECTS: Record<string, { label: string; typeId: number; order: number }> = {
    rr: { label: 'Remote Repair', typeId: 11357, order: 1 },
    web: { label: 'Stasis Webifier', typeId: 526, order: 2 },
    scram: { label: 'Warp Scrambler', typeId: 447, order: 3 },
    neut: { label: 'Energy Neutralizer (GJ/s)', typeId: 12265, order: 4 },
};

export const UPGRADED_AVENGER: Ship = {
    qty: 3, name: 'Upgraded Avenger', typeId: 37472, dps: 1210, alpha: 0, ehp: 1400000, sig: 30000,
    orbit_velocity: '300 m/s', distance: '20km', special: { Scram: 15, Neut: 75, Web: 1 },
};

export const DRIFTER_RECON: Ship = {
    qty: 1, name: 'Drifter Recon Battleship', typeId: 86498, dps: 1067, alpha: 0, ehp: 770000, sig: 25000,
    orbit_velocity: '400 m/s', distance: '13km', special: { Scram: 20, Neut: 100, Web: 1 },
};

export const DRIFTER_RESPONSE: Ship = {
    qty: 1, name: 'Drifter Response Battleship', typeId: 37473, dps: 1600, alpha: 0, ehp: 2350000, sig: 25000,
    orbit_velocity: '400 m/s', distance: '13km', special: { Scram: 20, Neut: 200, Web: 1 },
};

export const numberFormat = new Intl.NumberFormat('de-DE');

export function formatNumber(value: number | undefined | null): string {
    return value === undefined || value === null ? '–' : numberFormat.format(value);
}

export function sortedSpecials(specials: SpecialEffects): [string, number][] {
    return Object.entries(specials).sort(([first], [second]) => {
        return (SPECIAL_EFFECTS[first.toLowerCase()]?.order ?? 99) - (SPECIAL_EFFECTS[second.toLowerCase()]?.order ?? 99);
    });
}

export function waveTotals(ships: Ship[]) {
    let ehp = 0;
    let dps = 0;
    let alpha = 0;
    const specials: SpecialEffects = {};
    for (const ship of ships) {
        ehp += (ship.ehp ?? 0) * ship.qty;
        dps += (ship.dps ?? 0) * ship.qty;
        alpha += (ship.alpha ?? 0) * ship.qty;
        for (const [effect, value] of Object.entries(ship.special ?? {})) {
            specials[effect] = (specials[effect] ?? 0) + value * ship.qty;
        }
    }
    return { ehp, dps, alpha, specials };
}

// Peak values ignore the optional Drifter and Avenger waves, like triff.tools did
export function sitePeaks(waves: Wave[]) {
    let peakDps = 0;
    let peakAlpha = 0;
    let peakNeut = 0;
    for (const wave of waves) {
        if (wave.injected) {
            continue;
        }
        const totals = waveTotals(wave.ships);
        peakDps = Math.max(peakDps, totals.dps);
        peakAlpha = Math.max(peakAlpha, totals.alpha);
        peakNeut = Math.max(peakNeut, totals.specials.Neut ?? 0);
    }
    return { peakDps, peakAlpha, peakNeut };
}

export function hasEffect(waves: Wave[], effect: string): boolean {
    return waves.some((wave) => wave.ships.some((ship) => (ship.special?.[effect] ?? 0) > 0));
}

export function isKeyShip(ship: Ship): boolean {
    return ship.trigger === true || ship.triggerOnAttack === true || ship.dta === true;
}

/**
 * Adds the optional Drifter waves (C5/C6 only) and applies the trigger-only reference mode.
 */
export function prepareSite(site: Site, drifterMode: DrifterMode, referenceMode: boolean): { waves: Wave[]; blueLootIsk: number | null } {
    const leadingWaves: Wave[] = [];
    const trailingWaves: Wave[] = [];
    let extraLoot = 0;
    const isHighClass = site.classes.includes('C5') || site.classes.includes('C6');
    const isSleeperSite = site.category === 'combat' || site.category === 'data' || site.category === 'relic';

    if (isHighClass && isSleeperSite) {
        const isC5 = site.classes.includes('C5');
        if (drifterMode === 'response') {
            leadingWaves.push({ wave: 'Upgraded Avengers', injected: true, ships: [{ ...UPGRADED_AVENGER, qty: isC5 ? 3 : 4 }] });
            extraLoot += isC5 ? 150_000_000 : 200_000_000;
        }
        if (site.category === 'combat' && drifterMode === 'recon') {
            trailingWaves.push({ wave: 'Drifter Recon Battleship', injected: true, ships: [DRIFTER_RECON] });
            extraLoot += 100_000_000;
        }
        if (site.category === 'combat' && drifterMode === 'response') {
            trailingWaves.push({ wave: 'Drifter Response Battleship', injected: true, ships: [DRIFTER_RESPONSE] });
            extraLoot += 300_000_000;
        }
    }

    let waves = [...leadingWaves, ...site.waves, ...trailingWaves];
    if (referenceMode) {
        const reducedWaves: Wave[] = [];
        for (const wave of waves) {
            const ships = wave.injected ? wave.ships : wave.ships.filter(isKeyShip);
            if (ships.length > 0) {
                reducedWaves.push({ ...wave, ships });
            }
        }
        waves = reducedWaves;
    }

    const blueLootIsk = site.blueLootIsk !== null || extraLoot > 0 ? (site.blueLootIsk ?? 0) + extraLoot : null;
    return { waves, blueLootIsk };
}

/**
 * Value of all gas clouds of a gas site at the given unit prices, null when no price is known.
 */
export function gasValue(site: Site, gasPrices: Record<number, number>): number | null {
    if (site.category !== 'gas') {
        return null;
    }
    let value = 0;
    let hasPrice = false;
    for (const resource of site.resources) {
        const price = resource.typeId ? gasPrices[resource.typeId] : undefined;
        if (price !== undefined && resource.amount !== null) {
            value += price * resource.amount;
            hasPrice = true;
        }
    }
    return hasPrice ? value : null;
}
