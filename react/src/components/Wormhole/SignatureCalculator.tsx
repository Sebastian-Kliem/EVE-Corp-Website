import React, { useMemo, useState } from 'react';
import { CATEGORY_LABELS, DrifterMode, Site, formatNumber, prepareSite, resourceValue } from './siteModel';

interface SignatureCalculatorProps {
    sites: Site[];
    aliases: Record<string, string>; // pasted site name => English site name
    saveAliasUrl: string;
    resourcePrices: Record<number, number>; // Jita buy price per unit by type id
    drifterMode: DrifterMode;
}

interface ScannedSignature {
    signatureId: string;
    group: string;
    pastedName: string;
    site: Site | null;
}

const LINE_PATTERN = /^\s*([A-Z]{3}-\d{3})(?:\t|\s+)(.*)$/;
// Tabs, or runs of two or more spaces when the tabs got lost while copying
const COLUMN_SEPARATOR = /\t| {2,}/;
const SIGNAL_STRENGTH_PATTERN = /^\d+(?:[.,]\d+)?\s*%/;

/**
 * Splits the columns after the signature ID: group, type, name. Everything from the signal strength on is dropped.
 */
function _splitColumns(rest: string): string[] {
    const columns: string[] = [];
    for (const column of rest.split(COLUMN_SEPARATOR)) {
        const trimmedColumn = column.trim();
        if (SIGNAL_STRENGTH_PATTERN.test(trimmedColumn)) {
            break;
        }
        columns.push(trimmedColumn);
    }
    return columns;
}

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
 * Also accepts copies whose tabs were converted to spaces.
 */
function parseScannerCopy(text: string, lookup: Map<string, Site>): ScannedSignature[] {
    const signatures: ScannedSignature[] = [];
    const seenIds = new Set<string>();
    for (const line of text.split('\n')) {
        const match = LINE_PATTERN.exec(line);
        if (!match || seenIds.has(match[1])) {
            continue;
        }
        const signatureId = match[1];
        seenIds.add(signatureId);
        const columns = _splitColumns(match[2]);
        const pastedName = columns[2] ?? '';
        signatures.push({
            signatureId,
            group: columns[1] ?? '',
            pastedName,
            site: pastedName === '' ? null : lookup.get(pastedName.toLowerCase()) ?? null,
        });
    }
    return signatures;
}

type Category = Site['category'];

const CATEGORY_ORDER: Category[] = ['combat', 'data', 'relic', 'gas', 'ore'];

// Literal class names so Tailwind picks them up
const CATEGORY_STYLES: Record<Category, { badge: string; text: string; header: string }> = {
    combat: { badge: 'border-red-500/50 bg-red-500/10 text-red-300', text: 'text-red-300', header: 'bg-red-500/10 border-l-red-500' },
    data: { badge: 'border-sky-500/50 bg-sky-500/10 text-sky-300', text: 'text-sky-300', header: 'bg-sky-500/10 border-l-sky-500' },
    relic: { badge: 'border-violet-500/50 bg-violet-500/10 text-violet-300', text: 'text-violet-300', header: 'bg-violet-500/10 border-l-violet-500' },
    gas: { badge: 'border-teal-500/50 bg-teal-500/10 text-teal-300', text: 'text-teal-300', header: 'bg-teal-500/10 border-l-teal-500' },
    ore: { badge: 'border-amber-500/50 bg-amber-500/10 text-amber-300', text: 'text-amber-300', header: 'bg-amber-500/10 border-l-amber-500' },
};

interface CategoryGroup {
    category: Category;
    signatures: ScannedSignature[];
    total: number;
}

/**
 * Removes all lines of the given signature from the pasted scanner copy.
 */
function removeSignatureLines(text: string, signatureId: string): string {
    const keptLines: string[] = [];
    for (const line of text.split('\n')) {
        if (LINE_PATTERN.exec(line)?.[1] !== signatureId) {
            keptLines.push(line);
        }
    }
    return keptLines.join('\n');
}

function RemoveButton({ signatureId, onRemove }: { signatureId: string; onRemove: (signatureId: string) => void }) {
    return (
        <button type="button" title={`${signatureId} entfernen`} onClick={() => onRemove(signatureId)} className="px-1.5 leading-none text-[14px] text-eve-muted hover:text-red-400 cursor-pointer">
            ×
        </button>
    );
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

export default function SignatureCalculator({ sites, aliases, saveAliasUrl, resourcePrices, drifterMode }: SignatureCalculatorProps) {
    const [scannerCopy, setScannerCopy] = useState('');
    const [knownAliases, setKnownAliases] = useState<Record<string, string>>(aliases);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const lookup = useMemo(() => buildNameLookup(sites, knownAliases), [sites, knownAliases]);
    const signatures = useMemo(() => parseScannerCopy(scannerCopy, lookup), [scannerCopy, lookup]);
    const sortedSites = useMemo(() => [...sites].sort((first, second) => siteLabel(first).localeCompare(siteLabel(second), 'de')), [sites]);

    const recognized = signatures.filter((signature) => signature.site !== null);
    const unknown = signatures.filter((signature) => signature.site === null && signature.pastedName !== '');
    const unscannedCount = signatures.filter((signature) => signature.pastedName === '').length;

    const valueBySignature = new Map<string, number | null>();
    const groupsByCategory = new Map<Category, CategoryGroup>();
    let grandTotal = 0;
    for (const signature of recognized) {
        const site = signature.site as Site;
        // Gas and ore sites are valued by their resources, everything else by blue loot
        const value = resourceValue(site, resourcePrices) ?? prepareSite(site, drifterMode, false).blueLootIsk;
        valueBySignature.set(signature.signatureId, value);
        let group = groupsByCategory.get(site.category);
        if (!group) {
            group = { category: site.category, signatures: [], total: 0 };
            groupsByCategory.set(site.category, group);
        }
        group.signatures.push(signature);
        group.total += value ?? 0;
        grandTotal += value ?? 0;
    }
    const categoryGroups: CategoryGroup[] = [];
    for (const category of CATEGORY_ORDER) {
        const group = groupsByCategory.get(category);
        if (group) {
            categoryGroups.push(group);
        }
    }

    const removeSignature = (signatureId: string) => {
        setScannerCopy(removeSignatureLines(scannerCopy, signatureId));
    };

    const clearList = () => {
        setScannerCopy('');
        setErrorMessage(null);
    };

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
                    {drifterMode !== 'none' ? ' (inklusive der gewählten Drifter-Wellen)' : ''} sowie der Gas- und Erzwert ausgescannter Gas- und Erz-Sites zum Jita-Buy-Preis der komprimierten Ware.
                </p>
                <textarea
                    className="w-full h-32 bg-black/30 border border-eve-border rounded-sm px-2 py-1.5 text-[11px] font-mono text-eve-text focus:border-eve-primary focus:outline-none"
                    placeholder="ABC-123	Kosmische Anomalie	Kampfgebiet	Wichtige Garnison	100,0 %	4,32 AE"
                    value={scannerCopy}
                    onChange={(event) => setScannerCopy(event.target.value)}
                />

                {scannerCopy.trim() !== '' && (
                    <div className="flex flex-wrap items-center gap-2 text-[12px]">
                        {signatures.length > 0 ? (
                            <>
                                <div className="px-2 py-0.5 rounded border border-green-500/60 bg-green-500/15 text-green-300 font-semibold">Gesamt: {formatNumber(Math.round(grandTotal))} ISK</div>
                                {categoryGroups.map((group) => (
                                    <div key={group.category} className={`px-2 py-0.5 rounded border ${CATEGORY_STYLES[group.category].badge}`}>
                                        {CATEGORY_LABELS[group.category]}: {formatNumber(Math.round(group.total))} ISK
                                    </div>
                                ))}
                                <div className="text-eve-muted">{recognized.length} erkannt · {unscannedCount} nicht gescannt · {unknown.length} unbekannt</div>
                            </>
                        ) : (
                            <div className="text-amber-300">Keine Signaturen erkannt. Erwartet wird eine Zeile pro Signatur, beginnend mit der ID (z. B. ABC-123).</div>
                        )}
                        <button type="button" onClick={clearList} className="ml-auto px-2 py-1 rounded-sm border border-red-500/60 text-[11px] text-red-300 hover:bg-red-500/10 cursor-pointer">
                            Liste leeren
                        </button>
                    </div>
                )}

                {signatures.length > 0 && (
                    <>

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
                                            <th className="px-1 py-1.5 w-6"></th>
                                        </tr>
                                    </thead>
                                    {categoryGroups.map((group) => {
                                        const styles = CATEGORY_STYLES[group.category];
                                        return (
                                            <tbody key={group.category}>
                                                <tr className={`border-t border-eve-border border-l-2 ${styles.header}`}>
                                                    <td colSpan={4} className={`px-2.5 py-1.5 font-semibold ${styles.text}`}>
                                                        {CATEGORY_LABELS[group.category]} <span className="font-normal text-eve-muted">· {group.signatures.length}</span>
                                                    </td>
                                                    <td className={`px-2.5 py-1.5 text-right font-semibold ${styles.text}`}>{formatNumber(Math.round(group.total))} ISK</td>
                                                    <td></td>
                                                </tr>
                                                {group.signatures.map((signature) => {
                                                    const site = signature.site as Site;
                                                    const value = valueBySignature.get(signature.signatureId);
                                                    return (
                                                        <tr key={signature.signatureId} className="border-t border-eve-border">
                                                            <td className="px-2.5 py-1.5 font-mono">{signature.signatureId}</td>
                                                            <td className="px-2.5 py-1.5 text-slate-100">{siteLabel(site)}</td>
                                                            <td className="px-2.5 py-1.5">{site.classes.join(', ')}</td>
                                                            <td className="px-2.5 py-1.5">{CATEGORY_LABELS[site.category]}</td>
                                                            <td className={`px-2.5 py-1.5 text-right ${styles.text}`}>{value ? `${formatNumber(Math.round(value))} ISK` : '–'}</td>
                                                            <td className="px-1 py-1.5 text-center"><RemoveButton signatureId={signature.signatureId} onRemove={removeSignature} /></td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        );
                                    })}
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
                                        <div className="flex items-center gap-1">
                                            <AliasMapper pastedName={signature.pastedName} sites={sortedSites} onSave={saveAlias} />
                                            <RemoveButton signatureId={signature.signatureId} onRemove={removeSignature} />
                                        </div>
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
