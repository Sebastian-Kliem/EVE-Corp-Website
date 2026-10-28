import React, { useMemo, useState } from 'react';
import SignatureCalculator from './SignatureCalculator';
import {
    CATEGORY_LABELS, DrifterMode, Resource, SPECIAL_EFFECTS, Ship, Site, SpecialEffects, Wave,
    formatNumber, gasValue, hasEffect, prepareSite, sitePeaks, sortedSpecials, waveTotals,
} from './siteModel';

interface WormholeSitesProps {
    sites: Site[];
    aliases: Record<string, string>; // pasted site name => English site name
    saveAliasUrl: string;
    gasPrices: Record<number, number>; // Jita buy price per unit by type id
    typeIconUrl: string; // contains '12345' as placeholder for the type id
}

// Class of the corp home system, preselected on page load
const HOME_CLASS = 'C4';

// Class tabs; gas, ore and ice sites spawn in several classes and get their own tab
const CROSS_CLASS_TAB = 'cross';
const CLASS_TABS: { key: string; label: string }[] = [
    { key: 'C1', label: 'C1' },
    { key: 'C2', label: 'C2' },
    { key: 'C3', label: 'C3' },
    { key: 'C4', label: 'C4' },
    { key: 'C5', label: 'C5' },
    { key: 'C6', label: 'C6' },
    { key: CROSS_CLASS_TAB, label: 'Übergreifend' },
];

function isCrossClassSite(site: Site): boolean {
    return site.category === 'gas' || site.category === 'ore';
}

function matchesClassTab(site: Site, tab: string | null): boolean {
    if (tab === null) {
        return true;
    }
    if (tab === CROSS_CLASS_TAB) {
        return isCrossClassSite(site);
    }
    return !isCrossClassSite(site) && site.classes.includes(tab);
}

function TypeIcon({ typeId, url, title, size = 'w-5 h-5' }: { typeId: number | null; url: string; title: string; size?: string }) {
    if (!typeId) {
        return null;
    }
    return <img src={`${url.replace('12345', typeId.toString())}?size=32`} alt={title} title={title} className={`${size} rounded-sm shrink-0`} loading="lazy" />;
}

function SpecialList({ specials, typeIconUrl }: { specials: SpecialEffects; typeIconUrl: string }) {
    return (
        <div className="flex gap-2 items-center flex-wrap">
            {sortedSpecials(specials).map(([effect, value]) => {
                const key = effect.toLowerCase();
                const definition = SPECIAL_EFFECTS[key];
                return (
                    <div key={effect} className="flex items-center gap-1 text-slate-200" title={definition?.label ?? effect}>
                        <TypeIcon typeId={definition?.typeId ?? null} url={typeIconUrl} title={definition?.label ?? effect} size="w-4 h-4" />
                        <span className="text-[11px]">{key === 'neut' ? `-${value}` : `x${value}`}</span>
                    </div>
                );
            })}
        </div>
    );
}

function ShipTable({ ships, typeIconUrl }: { ships: Ship[]; typeIconUrl: string }) {
    const headerClass = 'border border-eve-border px-2.5 py-1.5 text-left font-semibold';
    return (
        <div className="overflow-x-auto border border-eve-border rounded">
            <table className="table-auto w-full text-[11px] border-collapse">
                <thead>
                    <tr className="bg-white/5 text-slate-100">
                        <th className={`${headerClass} w-12`}>Anz.</th>
                        <th className={`${headerClass} w-64`}>Name</th>
                        <th className={headerClass}>EHP</th>
                        <th className={headerClass}>DPS</th>
                        <th className={headerClass}>Alpha</th>
                        <th className={headerClass}>Sig</th>
                        <th className={headerClass}>Orbit</th>
                        <th className={headerClass}>Distanz</th>
                        <th className={`${headerClass} w-44`}>Effekte</th>
                    </tr>
                </thead>
                <tbody>
                    {ships.map((ship, index) => (
                        <tr key={`${ship.name}-${index}`} className={`border-t border-eve-border ${index % 2 === 0 ? 'bg-black/20' : 'bg-black/35'} hover:bg-white/5`}>
                            <td className="px-2.5 py-1.5">{ship.qty}</td>
                            <td className="px-2.5 py-1.5">
                                <div className="flex items-center gap-2">
                                    <TypeIcon typeId={ship.typeId} url={typeIconUrl} title={ship.name} />
                                    <span className="text-slate-100">{ship.name}</span>
                                    {ship.trigger && <span className="text-[10px] bg-red-700 text-white px-1 rounded-sm">Trigger</span>}
                                    {ship.triggerOnAttack && <span className="text-[10px] bg-orange-700 text-orange-50 px-1 rounded-sm whitespace-nowrap">Trigger bei Angriff</span>}
                                    {ship.optional && <span className="text-[10px] bg-green-700 text-white px-1 rounded-sm">Optional</span>}
                                    {ship.dta && <span className="text-[10px] bg-purple-700 text-white px-1 rounded-sm" title="Despawnt mit dem Trigger">DTR</span>}
                                </div>
                            </td>
                            <td className="px-2.5 py-1.5">{formatNumber(ship.ehp)}</td>
                            <td className="px-2.5 py-1.5">{formatNumber(ship.dps)}</td>
                            <td className="px-2.5 py-1.5">{formatNumber(ship.alpha)}</td>
                            <td className="px-2.5 py-1.5">{formatNumber(ship.sig)}</td>
                            <td className="px-2.5 py-1.5">{ship.orbit_velocity ?? '–'}</td>
                            <td className="px-2.5 py-1.5">{ship.distance ?? '–'}</td>
                            <td className="px-2.5 py-1.5"><SpecialList specials={ship.special ?? {}} typeIconUrl={typeIconUrl} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function WaveBlock({ wave, typeIconUrl }: { wave: Wave; typeIconUrl: string }) {
    const totals = waveTotals(wave.ships);
    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center justify-between gap-3 px-2.5 py-1.5 bg-black/30 border border-eve-border rounded text-[11px]">
                <h3 className="font-semibold text-[12px] text-sky-200">
                    {wave.wave}
                    {wave.note && <span className="ml-2 font-normal text-eve-muted">{wave.note}</span>}
                </h3>
                {wave.ships.length > 0 && (
                    <div className="flex flex-wrap items-center gap-4 text-slate-300">
                        <div>EHP: <span className="text-slate-50">{formatNumber(totals.ehp)}</span></div>
                        <div>DPS: <span className="text-slate-50">{formatNumber(totals.dps)}</span></div>
                        <div>Alpha: <span className="text-slate-50">{formatNumber(totals.alpha)}</span></div>
                        <SpecialList specials={totals.specials} typeIconUrl={typeIconUrl} />
                    </div>
                )}
            </div>
            {wave.triggerOnHack && <div className="text-[11px] text-amber-300 font-semibold px-2">Wird durch das Hacken ausgelöst</div>}
            {wave.ships.length > 0 && <ShipTable ships={wave.ships} typeIconUrl={typeIconUrl} />}
        </div>
    );
}

function ResourceList({ resources, typeIconUrl }: { resources: Resource[]; typeIconUrl: string }) {
    return (
        <div className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
            {resources.map((resource, index) => (
                <div key={`${resource.name}-${index}`} className="flex items-center gap-2 px-2 py-1 bg-black/20 border border-eve-border rounded text-[11px]">
                    <TypeIcon typeId={resource.typeId} url={typeIconUrl} title={resource.name} />
                    <span className="text-slate-100">{resource.qty} x {resource.nameDe ?? resource.name}</span>
                    {resource.legacyName && <span className="text-eve-muted" title="Name vor der Umbenennung 2024">({resource.legacyName})</span>}
                    <span className="ml-auto text-eve-muted whitespace-nowrap">
                        {resource.amount !== null ? `${resource.approximate ? 'ca. ' : ''}${formatNumber(resource.amount)} ${resource.unit}` : ''}
                        {resource.volumeM3 !== null && <span className="text-slate-400"> · {formatNumber(resource.volumeM3)} m³</span>}
                    </span>
                </div>
            ))}
        </div>
    );
}

function SiteCard({ site, drifterMode, referenceMode, gasPrices, typeIconUrl }: { site: Site; drifterMode: DrifterMode; referenceMode: boolean; gasPrices: Record<number, number>; typeIconUrl: string }) {
    const [isOpen, setIsOpen] = useState(false);
    const { waves, blueLootIsk } = useMemo(() => prepareSite(site, drifterMode, referenceMode), [site, drifterMode, referenceMode]);

    const allWaves = useMemo(() => prepareSite(site, drifterMode, false).waves, [site, drifterMode]);
    const peaks = sitePeaks(allWaves);
    let totalEhp = 0;
    for (const wave of allWaves) {
        totalEhp += waveTotals(wave.ships).ehp;
    }
    const iskPerEhp = blueLootIsk !== null && totalEhp > 0 ? blueLootIsk / totalEhp : null;
    const hasWebs = hasEffect(allWaves, 'Web');
    const hasScrams = hasEffect(allWaves, 'Scram');
    const tackleLabel = hasWebs && hasScrams ? 'Webs und Scrams' : hasWebs ? 'Webs' : hasScrams ? 'Scrams' : 'Keine Webs oder Scrams';
    const hasEnemies = allWaves.some((wave) => wave.ships.length > 0);
    const classLabel = site.occurrence ?? site.classes.join(', ');
    const siteGasValue = gasValue(site, gasPrices);

    return (
        <div className="bg-eve-card border border-eve-border backdrop-blur-md shadow-eve rounded-lg overflow-hidden">
            <button type="button" className="w-full flex items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-white/5 cursor-pointer" onClick={() => setIsOpen(!isOpen)}>
                <div className="min-w-0">
                    <h2 className="text-[14px] font-semibold text-slate-50 m-0">
                        {site.nameDe ?? site.name}
                        {site.nameDe && <span className="ml-1.5 font-normal text-slate-400">({site.name})</span>}
                    </h2>
                    <p className="text-[11px] text-eve-muted m-0">
                        {classLabel} · {CATEGORY_LABELS[site.category]}
                    </p>
                </div>
                <span className={`text-xs text-eve-primary transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}>▼</span>
            </button>

            {!referenceMode && (blueLootIsk !== null || siteGasValue !== null || hasEnemies) && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2 border-t border-eve-border bg-black/20 text-[11px]">
                    <div className="flex flex-wrap items-center gap-3">
                        {blueLootIsk !== null && (
                            <>
                                <div className="text-slate-200">Blue Loot: <span className="text-green-400 font-semibold">{formatNumber(blueLootIsk)} ISK</span></div>
                                <div className="border-l border-eve-border pl-3 text-eve-muted" title="Blue Loot geteilt durch die gesamte EHP der Site. Vergleichswert, keine ISK pro Stunde.">
                                    ISK/EHP: <span className="font-mono font-semibold text-sky-300">{iskPerEhp !== null ? iskPerEhp.toFixed(1).replace('.', ',') : '–'}</span>
                                </div>
                            </>
                        )}
                        {siteGasValue !== null && (
                            <div className="text-slate-200" title="Alle Gaswolken zum aktuellen Jita-Buy-Preis">Gaswert: <span className="text-green-400 font-semibold">{formatNumber(Math.round(siteGasValue))} ISK</span></div>
                        )}
                    </div>
                    {hasEnemies && (
                        <div className="flex flex-wrap gap-2 text-[10px]">
                            <div className={`flex items-center gap-1 px-2 py-1 rounded-sm border border-green-800 bg-green-950/60 text-green-400 ${!hasWebs && !hasScrams ? 'font-bold' : ''}`}>
                                {hasWebs && <TypeIcon typeId={SPECIAL_EFFECTS.web.typeId} url={typeIconUrl} title="Webs" size="w-3.5 h-3.5" />}
                                {hasScrams && <TypeIcon typeId={SPECIAL_EFFECTS.scram.typeId} url={typeIconUrl} title="Scrams" size="w-3.5 h-3.5" />}
                                <span>{tackleLabel}</span>
                            </div>
                            <div className="px-2 py-1 rounded-sm border border-red-900 bg-red-950/60 text-red-300">Peak DPS: <span className="font-semibold">{formatNumber(peaks.peakDps)}</span></div>
                            <div className="px-2 py-1 rounded-sm border border-orange-900 bg-orange-950/60 text-orange-300">Peak Alpha: <span className="font-semibold">{formatNumber(peaks.peakAlpha)}</span></div>
                            <div className="flex items-center gap-1 px-2 py-1 rounded-sm border border-sky-900 bg-sky-950/60 text-sky-300">
                                Peak Neut: <TypeIcon typeId={SPECIAL_EFFECTS.neut.typeId} url={typeIconUrl} title="Neut" size="w-3.5 h-3.5" />
                                <span className="font-semibold">{peaks.peakNeut > 0 ? '-' : ''}{formatNumber(peaks.peakNeut)}</span> GJ/s
                            </div>
                        </div>
                    )}
                </div>
            )}

            {isOpen && (
                <div className="px-4 py-3 space-y-3 border-t border-eve-border">
                    {site.resources.length > 0 && <ResourceList resources={site.resources} typeIconUrl={typeIconUrl} />}
                    {waves.map((wave, index) => <WaveBlock key={`${wave.wave}-${index}`} wave={wave} typeIconUrl={typeIconUrl} />)}
                    {site.notes.length > 0 && (
                        <ul className="list-disc pl-5 text-[11px] text-eve-muted space-y-0.5 m-0">
                            {site.notes.map((note, index) => <li key={index}>{note}</li>)}
                        </ul>
                    )}
                    <p className="text-[10px] text-slate-500 m-0">Quelle: {site.source}</p>
                </div>
            )}
        </div>
    );
}

function ToggleButton({ active, onClick, children, title }: { active: boolean; onClick: () => void; children: React.ReactNode; title?: string }) {
    const stateClass = active
        ? 'bg-eve-primary/15 border-eve-primary text-eve-text shadow-[0_0_8px_rgba(0,240,255,0.35)]'
        : 'bg-black/30 border-eve-border text-slate-300 hover:bg-white/5';
    return (
        <button type="button" title={title} onClick={onClick} className={`px-3 py-1 rounded-sm border text-[11px] uppercase tracking-widest transition-colors cursor-pointer ${stateClass}`}>
            {children}
        </button>
    );
}

export default function WormholeSites({ sites, aliases, saveAliasUrl, gasPrices, typeIconUrl }: WormholeSitesProps) {
    const [selectedClass, setSelectedClass] = useState<string | null>(HOME_CLASS);
    const [selectedCategory, setSelectedCategory] = useState<string>('');
    const [drifterMode, setDrifterMode] = useState<DrifterMode>('none');
    const [referenceMode, setReferenceMode] = useState(false);

    const filteredSites = useMemo(() => {
        return sites.filter((site) => {
            const matchesClass = matchesClassTab(site, selectedClass);
            const matchesCategory = selectedCategory === '' || site.category === selectedCategory;
            return matchesClass && matchesCategory;
        });
    }, [sites, selectedClass, selectedCategory]);

    const toggleDrifter = (mode: DrifterMode) => setDrifterMode(drifterMode === mode ? 'none' : mode);

    return (
        <div className="space-y-4">
            <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="bg-eve-card border border-eve-border rounded-lg p-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="uppercase tracking-widest text-[10px] text-eve-muted">Filter</span>
                        <ToggleButton active={referenceMode} onClick={() => setReferenceMode(!referenceMode)} title="Zeigt pro Welle nur Trigger- und DTR-Schiffe sowie Drifter- und Avenger-Wellen, für schnelle Ansagen in der Site.">
                            Nur Trigger
                        </ToggleButton>
                    </div>
                    <div className="flex flex-wrap gap-1">
                        {CLASS_TABS.map((tab) => (
                            <ToggleButton key={tab.key} active={selectedClass === tab.key} onClick={() => setSelectedClass(selectedClass === tab.key ? null : tab.key)}>
                                {tab.label}
                            </ToggleButton>
                        ))}
                    </div>
                    <label className="grid gap-1 w-fit">
                        <span className="text-[10px] uppercase tracking-widest text-eve-muted">Typ</span>
                        <select className="border border-eve-border rounded-sm px-2 py-1 text-[12px] text-eve-text cursor-pointer focus:border-eve-primary focus:outline-none" value={selectedCategory} onChange={(event) => setSelectedCategory(event.target.value)}>
                            <option value="">Alle Typen</option>
                            {Object.entries(CATEGORY_LABELS).map(([category, label]) => <option key={category} value={category}>{label}</option>)}
                        </select>
                    </label>
                </div>

                <div className="bg-eve-card border border-eve-border rounded-lg p-4 space-y-2">
                    <span className="uppercase tracking-widest text-[10px] text-eve-muted">Drifter-Wellen</span>
                    <div className="grid gap-2">
                        <ToggleButton active={drifterMode === 'recon'} onClick={() => toggleDrifter('recon')}>Drifter Recon Battleship (Mini-Drifter)</ToggleButton>
                        <ToggleButton active={drifterMode === 'response'} onClick={() => toggleDrifter('response')}>Drifter Response Battleship (Big Drifter)</ToggleButton>
                    </div>
                    <p className="text-[10px] text-eve-muted leading-snug m-0">Nur für Sleeper-Sites in C5 und C6. Fügt die zusätzlichen Wellen und den zusätzlichen Blue Loot hinzu.</p>
                </div>
            </div>

            <SignatureCalculator sites={sites} aliases={aliases} saveAliasUrl={saveAliasUrl} gasPrices={gasPrices} drifterMode={drifterMode} />

            <div className="text-[11px] text-eve-muted">{filteredSites.length} passende Sites</div>

            <div className="space-y-3">
                {filteredSites.length === 0 && (
                    <div className="bg-eve-card border border-eve-border rounded-lg p-4 text-sm text-eve-muted">
                        Keine passenden Sites. Klassen- oder Typfilter anpassen.
                    </div>
                )}
                {filteredSites.map((site) => (
                    <SiteCard key={site.name} site={site} drifterMode={drifterMode} referenceMode={referenceMode} gasPrices={gasPrices} typeIconUrl={typeIconUrl} />
                ))}
            </div>
        </div>
    );
}
