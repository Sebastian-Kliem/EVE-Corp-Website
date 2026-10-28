import React, { useMemo, useState } from 'react';
import { CATEGORY_LABELS, DrifterMode, Site, formatNumber, gasValue, prepareSite } from './siteModel';

interface SignatureCalculatorProps {
    sites: Site[];
    aliases: Record<string, string>; // pasted site name => English site name
    saveAliasUrl: string;
    gasPrices: Record<number, number>; // Jita buy price per unit by type id
    drifterMode: DrifterMode;
}

interface ScannedSignature {
    signatureId: string;
    group: string;
    pastedName: string;
    site: Site | null;
}

const SIGNATURE_ID_PATTERN = /^[A-Z]{3}-\d{3}$/;

/**
 * Builds the lookup of lower-cased English names, German names and saved aliases to their site.
 */
function buildNameLookup(sites: Site[], aliases: Record<string, string>): Map<string, Site> {
    const lookup = new Map<string, Site>();
    const sitesByName = new Map<string, Site>();
    for (const site of sites) {
        sitesByName.set(site.name, site);
        lookup.set(site.name.toLowerCase(), site);
    }
    // Some German names are shared by two ore sites, the first one wins
    for (const site of sites) {
        if (site.nameDe && !lookup.has(site.nameDe.toLowerCase())) {
            lookup.set(site.nameDe.toLowerCase(), site);
        }
    }
    for (const [alias, siteName] of Object.entries(aliases)) {
        const site = sitesByName.get(siteName);
        if (site) {
            lookup.set(alias.toLowerCase(), site);
        }
    }
    return lookup;
}

/**
 * Parses the tab separated probe scanner copy: ID, group, type, name, signal strength, distance.
 */
function parseScannerCopy(text: string, lookup: Map<string, Site>): ScannedSignature[] {
    const signatures: ScannedSignature[] = [];
    const seenIds = new Set<string>();
    for (const line of text.split('\n')) {
        const columns = line.split('\t').map((column) => column.trim());
        const signatureId = columns[0] ?? '';
        if (!SIGNATURE_ID_PATTERN.test(signatureId) || seenIds.has(signatureId)) {
            continue;
        }
        seenIds.add(signatureId);
        const pastedName = columns[3] ?? '';
        signatures.push({
            signatureId,
            group: columns[2] ?? '',
            pastedName,
            site: pastedName === '' ? null : lookup.get(pastedName.toLowerCase()) ?? null,
        });
    }
    return signatures;
}

function siteLabel(site: Site): string {
    return site.nameDe ? `${site.nameDe} (${site.name})` : site.name;
}

function AliasMapper({ pastedName, sites, onSave }: { pastedName: string; sites: Site[]; onSave: (pastedName: string, siteName: string) => Promise<void> }) {
    const [selectedSite, setSelectedSite] = useState('');
    const [isSaving, setIsSaving] = useState(false);

    const save = async () => {
        setIsSaving(true);
        await onSave(pastedName, selectedSite);
        setIsSaving(false);
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <select className="border border-eve-border rounded-sm px-2 py-1 text-[11px] text-eve-text cursor-pointer focus:border-eve-primary focus:outline-none max-w-[420px]" value={selectedSite} onChange={(event) => setSelectedSite(event.target.value)}>
                <option value="">Site zuordnen...</option>
                {sites.map((site) => <option key={site.name} value={site.name}>{siteLabel(site)} · {site.classes.join(', ')}</option>)}
            </select>
            <button type="button" disabled={selectedSite === '' || isSaving} onClick={save} className="px-2 py-1 rounded-sm border border-eve-primary/60 text-[11px] text-eve-primary hover:bg-eve-primary/10 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                {isSaving ? 'Speichert...' : 'Speichern'}
            </button>
        </div>
    );
}

export default function SignatureCalculator({ sites, aliases, saveAliasUrl, gasPrices, drifterMode }: SignatureCalculatorProps) {
    const [scannerCopy, setScannerCopy] = useState('');
    const [knownAliases, setKnownAliases] = useState<Record<string, string>>(aliases);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const lookup = useMemo(() => buildNameLookup(sites, knownAliases), [sites, knownAliases]);
    const signatures = useMemo(() => parseScannerCopy(scannerCopy, lookup), [scannerCopy, lookup]);
    const sortedSites = useMemo(() => [...sites].sort((first, second) => siteLabel(first).localeCompare(siteLabel(second), 'de')), [sites]);

    const recognized = signatures.filter((signature) => signature.site !== null);
    const unknown = signatures.filter((signature) => signature.site === null && signature.pastedName !== '');
    const unscannedCount = signatures.filter((signature) => signature.pastedName === '').length;

    let totalLoot = 0;
    let totalGas = 0;
    const valueBySignature = new Map<string, number | null>();
    for (const signature of recognized) {
        const site = signature.site as Site;
        const siteGasValue = gasValue(site, gasPrices);
        if (siteGasValue !== null) {
            valueBySignature.set(signature.signatureId, siteGasValue);
            totalGas += siteGasValue;
            continue;
        }
        const blueLoot = prepareSite(site, drifterMode, false).blueLootIsk;
        valueBySignature.set(signature.signatureId, blueLoot);
        totalLoot += blueLoot ?? 0;
    }

    const saveAlias = async (pastedName: string, siteName: string) => {
        setErrorMessage(null);
        try {
            const response = await fetch(saveAliasUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ alias: pastedName, siteName }),
            });
            const result = await response.json();
            if (!response.ok) {
                setErrorMessage(result.error ?? 'Zuordnung konnte nicht gespeichert werden.');
                return;
            }
            setKnownAliases({ ...knownAliases, [result.alias]: result.siteName });
        } catch {
            setErrorMessage('Zuordnung konnte nicht gespeichert werden.');
        }
    };

    return (
        <details className="bg-eve-card border border-eve-border rounded-lg overflow-hidden group">
            <summary className="px-4 py-3 cursor-pointer list-none flex justify-between items-center select-none">
                <span className="text-[14px] font-semibold text-slate-50">Signatur-Rechner</span>
                <span className="text-xs text-eve-primary transition-transform duration-200 group-open:rotate-180">▼</span>
            </summary>
            <div className="px-4 pb-4 space-y-3 border-t border-eve-border pt-3">
                <p className="text-[11px] text-eve-muted m-0">
                    Im Sondenscanner alle Einträge markieren (Strg+A), kopieren (Strg+C) und hier einfügen. Summiert werden der Blue Loot aller erkannten Sites
                    {drifterMode !== 'none' ? ' (inklusive der gewählten Drifter-Wellen)' : ''} und der Gaswert ausgescannter Gas-Sites zum Jita-Buy-Preis.
                </p>
                <textarea
                    className="w-full h-32 bg-black/30 border border-eve-border rounded-sm px-2 py-1.5 text-[11px] font-mono text-eve-text focus:border-eve-primary focus:outline-none"
                    placeholder="ABC-123	Kosmische Anomalie	Kampfgebiet	Wichtige Garnison	100,0 %	4,32 AE"
                    value={scannerCopy}
                    onChange={(event) => setScannerCopy(event.target.value)}
                />

                {signatures.length > 0 && (
                    <>
                        <div className="flex flex-wrap items-center gap-4 text-[12px]">
                            <div className="text-slate-200">Gesamt: <span className="text-green-400 font-semibold">{formatNumber(Math.round(totalLoot + totalGas))} ISK</span></div>
                            <div className="text-slate-300">Blue Loot: {formatNumber(totalLoot)} ISK</div>
                            <div className="text-slate-300">Gas: {formatNumber(Math.round(totalGas))} ISK</div>
                            <div className="text-eve-muted">{recognized.length} erkannt · {unscannedCount} nicht gescannt · {unknown.length} unbekannt</div>
                        </div>

                        {recognized.length > 0 && (
                            <div className="overflow-x-auto border border-eve-border rounded">
                                <table className="table-auto w-full text-[11px] border-collapse">
                                    <thead>
                                        <tr className="bg-white/5 text-slate-100">
                                            <th className="px-2.5 py-1.5 text-left font-semibold">ID</th>
                                            <th className="px-2.5 py-1.5 text-left font-semibold">Site</th>
                                            <th className="px-2.5 py-1.5 text-left font-semibold">Klasse</th>
                                            <th className="px-2.5 py-1.5 text-left font-semibold">Typ</th>
                                            <th className="px-2.5 py-1.5 text-right font-semibold">Wert</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recognized.map((signature) => {
                                            const site = signature.site as Site;
                                            const value = valueBySignature.get(signature.signatureId);
                                            return (
                                                <tr key={signature.signatureId} className="border-t border-eve-border">
                                                    <td className="px-2.5 py-1.5 font-mono">{signature.signatureId}</td>
                                                    <td className="px-2.5 py-1.5 text-slate-100">{siteLabel(site)}</td>
                                                    <td className="px-2.5 py-1.5">{site.classes.join(', ')}</td>
                                                    <td className="px-2.5 py-1.5">{CATEGORY_LABELS[site.category]}</td>
                                                    <td className="px-2.5 py-1.5 text-right">{value ? `${formatNumber(Math.round(value))} ISK` : '–'}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {unknown.length > 0 && (
                            <div className="space-y-2">
                                <div className="text-[11px] text-amber-300">Unbekannte Namen: Zuordnung wird für alle gespeichert und beim nächsten Einfügen erkannt.</div>
                                {unknown.map((signature) => (
                                    <div key={signature.signatureId} className="flex flex-wrap items-center justify-between gap-2 px-2.5 py-1.5 bg-black/20 border border-eve-border rounded text-[11px]">
                                        <span>
                                            <span className="font-mono mr-2">{signature.signatureId}</span>
                                            <span className="text-slate-100">{signature.pastedName}</span>
                                            {signature.group && <span className="ml-2 text-eve-muted">{signature.group}</span>}
                                        </span>
                                        <AliasMapper pastedName={signature.pastedName} sites={sortedSites} onSave={saveAlias} />
                                    </div>
                                ))}
                                {errorMessage && <div className="text-[11px] text-red-400">{errorMessage}</div>}
                            </div>
                        )}
                    </>
                )}
            </div>
        </details>
    );
}
