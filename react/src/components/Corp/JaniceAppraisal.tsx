import React, { useState, useEffect, useMemo } from 'react';
import OrderWorkflowGuide from './OrderWorkflowGuide';
import { formatThousands } from '../../utils/numberFormat';

interface AppraisalItem {
    typeId: number;
    name: string;
    quantity: number;
    volume: number;
    packagedVolume: number;
    totalVolume: number;
    slot: string;
    categoryId: number;
    categoryName: string;
    groupId: number;
    groupName: string;
    variation: string;
    sortOrder: number;
    unitPrice: number;
    adjustedUnitPrice: number;
    totalPrice: number;
    baseTotalPrice: number;
    priceWarning: boolean;
    priceMessage: string | null;
}

interface AppraisalResult {
    isFitting: boolean;
    fitTitle: string | null;
    shipName: string | null;
    shipTypeId: number | null;
    type: 'BUY' | 'SELL';
    percent: number;
    items: AppraisalItem[];
    unresolved: string[];
    totalPrice: number;
    totalBasePrice: number;
    totalVolume: number;
    totalItemCount: number;
}

interface DoctrineFit {
    id: number;
    title: string;
    shipName: string;
    shipTypeId: number | null;
    role: string;
    eft: string;
}

interface JaniceAppraisalProps {
    doctrineFits?: DoctrineFit[];
    defaultType?: 'BUY' | 'SELL';
    onOrderCreated?: (order: any) => void;
    embedded?: boolean;
}

export default function JaniceAppraisal({
    doctrineFits = [],
    defaultType = 'BUY',
    onOrderCreated,
    embedded = false,
}: JaniceAppraisalProps) {
    const [rawText, setRawText] = useState('');
    const [orderType, setOrderType] = useState<'BUY' | 'SELL'>(defaultType);
    const [percent, setPercent] = useState<number>(defaultType === 'BUY' ? 100 : 90);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<AppraisalResult | null>(null);

    // Modal / Creation state
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [orderTitle, setOrderTitle] = useState('');
    const [orderNote, setOrderNote] = useState('');
    const [isSubmittingOrder, setIsSubmittingOrder] = useState(false);
    const [feedback, setFeedback] = useState<string | null>(null);
    const [showJitaInfo, setShowJitaInfo] = useState(false);

    const handleCalculate = (textToAppraise = rawText, currentPercent = percent, currentType = orderType) => {
        if (!textToAppraise.trim()) {
            setResult(null);
            return;
        }

        setLoading(true);
        setError(null);

        fetch('/api/orders/appraise', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                text: textToAppraise,
                percent: currentPercent,
                type: currentType,
            }),
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler bei der Wertermittlung.');
                }
                return res.json();
            })
            .then((data: AppraisalResult) => {
                setResult(data);
                if (data.isFitting && data.fitTitle) {
                    setOrderTitle(data.fitTitle);
                } else if (data.shipName) {
                    setOrderTitle(data.shipName);
                } else if (data.items.length > 0) {
                    setOrderTitle(`${data.items[0].name} (${data.items.length} Posten)`);
                }
            })
            .catch(err => {
                setError(err.message || 'Verbindungsfehler beim Abrufen der Preise.');
            })
            .finally(() => {
                setLoading(false);
            });
    };

    const handleTypeChange = (newType: 'BUY' | 'SELL') => {
        setOrderType(newType);
        const newPercent = newType === 'BUY' ? 100 : 90;
        setPercent(newPercent);
        if (rawText.trim()) {
            handleCalculate(rawText, newPercent, newType);
        }
    };

    const handlePercentPreset = (p: number) => {
        setPercent(p);
        if (rawText.trim()) {
            handleCalculate(rawText, p, orderType);
        }
    };

    const handleDoctrineSelect = (fit: DoctrineFit) => {
        setRawText(fit.eft);
        setOrderTitle(fit.title || fit.shipName);
        handleCalculate(fit.eft, percent, orderType);
    };

    const handleCopyMultibuy = () => {
        if (!result || result.items.length === 0) return;
        const lines = result.items.map(i => `${i.name} ${i.quantity}`);
        navigator.clipboard.writeText(lines.join('\n'));
        showFeedbackMessage('Multibuy-Liste in die Zwischenablage kopiert.');
    };

    const handleCreateOrder = (e: React.FormEvent) => {
        e.preventDefault();
        if (!result || result.items.length === 0) return;

        setIsSubmittingOrder(true);

        const payload = {
            type: orderType,
            title: orderTitle.trim() || (result.isFitting ? 'Fitting Bestellung' : 'Material Auftrag'),
            percent: percent,
            note: orderNote.trim() || null,
            isFitting: result.isFitting,
            shipTypeId: result.shipTypeId,
            items: result.items.map(item => ({
                typeId: item.typeId,
                name: item.name,
                quantity: item.quantity,
                unitPrice: item.unitPrice,
                unitVolume: item.packagedVolume,
                slot: item.slot,
                sortOrder: item.sortOrder,
            })),
        };

        fetch('/api/orders/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        })
            .then(async res => {
                if (!res.ok) {
                    const data = await res.json().catch(() => null);
                    throw new Error(data?.error || data?.message || 'Fehler beim Erstellen der Bestellung.');
                }
                return res.json();
            })
            .then(data => {
                setIsCreateModalOpen(false);
                setRawText('');
                setResult(null);
                setOrderNote('');
                showFeedbackMessage('Bestellung erfolgreich aufgegeben!');
                if (onOrderCreated) {
                    onOrderCreated(data.order);
                } else if (!embedded) {
                    window.location.href = '/corp/orders';
                }
            })
            .catch(err => {
                alert(err.message || 'Fehler beim Erstellen der Bestellung.');
            })
            .finally(() => {
                setIsSubmittingOrder(false);
            });
    };

    const showFeedbackMessage = (msg: string) => {
        setFeedback(msg);
        setTimeout(() => setFeedback(null), 4000);
    };

    const getItemIconUrl = (typeId: number, variation = 'icon') => {
        return `/eve/image/types/${typeId}/${variation}?size=64`;
    };

    const getSlotBadgeStyle = (slot: string) => {
        switch (slot) {
            case 'hull':
                return 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            case 'high':
                return 'bg-red-500/15 text-red-300 border-red-500/30';
            case 'med':
                return 'bg-blue-500/15 text-blue-300 border-blue-500/30';
            case 'low':
                return 'bg-amber-600/15 text-amber-400 border-amber-600/30';
            case 'rig':
                return 'bg-purple-500/15 text-purple-300 border-purple-500/30';
            case 'subsystem':
                return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
            case 'drone':
            case 'fighter':
                return 'bg-teal-500/15 text-teal-300 border-teal-500/30';
            case 'charge':
                return 'bg-orange-500/15 text-orange-300 border-orange-500/30';
            default:
                return 'bg-slate-500/15 text-slate-300 border-slate-500/30';
        }
    };

    return (
        <div className="w-full">
            {!embedded && <OrderWorkflowGuide />}

            {feedback && (
                <div className="mb-4 p-3 rounded-lg bg-emerald-500/15 border border-emerald-500/40 text-emerald-300 text-xs flex items-center justify-between animate-fade-in">
                    <span>{feedback}</span>
                    <button type="button" onClick={() => setFeedback(null)} className="text-emerald-400 hover:text-white">x</button>
                </div>
            )}

            <div className="p-6 rounded-lg border border-eve-border bg-eve-card/40 shadow-eve mb-6">
                <div className="flex flex-wrap items-center justify-between gap-4 mb-4">
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => handleTypeChange('BUY')}
                            className={`px-4 py-2 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                                orderType === 'BUY'
                                    ? 'bg-eve-primary/15 border-eve-primary/60 text-eve-primary shadow-[0_0_10px_rgba(0,240,255,0.2)]'
                                    : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'
                            }`}
                        >
                            Kaufgesuch (Buy Order / Jita-Sell)
                        </button>
                        <button
                            type="button"
                            onClick={() => handleTypeChange('SELL')}
                            className={`px-4 py-2 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                                orderType === 'SELL'
                                    ? 'bg-emerald-500/15 border-emerald-500/60 text-emerald-300 shadow-[0_0_10px_rgba(16,185,129,0.2)]'
                                    : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'
                            }`}
                        >
                            Verkaufsangebot (Sell Order / Jita-Buy)
                        </button>
                    </div>

                    {doctrineFits.length > 0 && (
                        <div className="flex items-center gap-2">
                            <label className="text-xs text-eve-muted">Doctrine Fit laden:</label>
                            <select
                                onChange={(e) => {
                                    const fit = doctrineFits.find(f => f.id === parseInt(e.target.value));
                                    if (fit) handleDoctrineSelect(fit);
                                }}
                                defaultValue=""
                                className="px-3 py-1.5 rounded-lg text-xs bg-[#0f172a59] border border-eve-border text-white focus:outline-none focus:border-eve-primary"
                            >
                                <option value="" disabled>-- Fit auswählen --</option>
                                {doctrineFits.map(fit => (
                                    <option key={fit.id} value={fit.id}>
                                        {fit.shipName} - {fit.title} ({fit.role})
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                <div className="mb-4">
                    <label className="block text-xs font-semibold text-white mb-1.5">
                        EVE Copy & Paste (EFT Fitting, Hangar-Liste, Multibuy oder Mengen):
                    </label>
                    <textarea
                        rows={6}
                        value={rawText}
                        onChange={(e) => setRawText(e.target.value)}
                        placeholder="[Caracal, Heavy Missiles]&#10;Damage Control II&#10;100MN Afterburner II&#10;Heavy Missile Launcher II x5&#10;Scourge Heavy Missile x1000&#10;&#10;-- ODER Hangar-Copy (Strg+A / Strg+C aus EVE Inventory) --"
                        className="w-full rounded-lg px-3 py-2 text-xs font-mono border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.2)] transition-all resize-y"
                    />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-4 pt-2 border-t border-eve-border/40">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-semibold text-white">Jita-Anpassung:</span>
                            <button
                                type="button"
                                onClick={() => setShowJitaInfo(!showJitaInfo)}
                                className="w-4 h-4 rounded-full bg-eve-primary/15 border border-eve-primary/40 text-eve-primary font-bold text-[10px] flex items-center justify-center hover:bg-eve-primary/30 transition-colors cursor-pointer"
                                title="Erklärung zu den Prozent-Optionen anzeigen"
                            >
                                i
                            </button>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <input
                                type="number"
                                value={percent}
                                onChange={(e) => setPercent(parseInt(e.target.value) || 100)}
                                className="w-20 rounded-lg px-2.5 py-1 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                            />
                            <span className="text-xs text-eve-muted">%</span>
                        </div>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => handlePercentPreset(100)}
                                className={`px-2 py-1 rounded text-[11px] border cursor-pointer ${percent === 100 ? 'bg-eve-primary/15 border-eve-primary/50 text-eve-primary' : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'}`}
                            >
                                100%
                            </button>
                            <button
                                type="button"
                                onClick={() => handlePercentPreset(90)}
                                className={`px-2 py-1 rounded text-[11px] border cursor-pointer ${percent === 90 ? 'bg-eve-primary/15 border-eve-primary/50 text-eve-primary' : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'}`}
                            >
                                90% (Buyback)
                            </button>
                            <button
                                type="button"
                                onClick={() => handlePercentPreset(105)}
                                className={`px-2 py-1 rounded text-[11px] border cursor-pointer ${percent === 105 ? 'bg-eve-primary/15 border-eve-primary/50 text-eve-primary' : 'bg-[#0a0f1d] border-white/10 text-eve-muted hover:text-white'}`}
                            >
                                105% (Logistik)
                            </button>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {rawText.trim() && (
                            <button
                                type="button"
                                onClick={() => {
                                    setRawText('');
                                    setResult(null);
                                }}
                                className="px-3 py-1.5 rounded-lg text-xs border border-white/10 bg-[#0a0f1d] text-eve-muted hover:text-white cursor-pointer"
                            >
                                Zurücksetzen
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => handleCalculate()}
                            disabled={loading || !rawText.trim()}
                            className={`px-4 py-2 rounded-lg text-xs font-semibold border border-transparent bg-eve-primary text-[#060911] shadow-eve hover:brightness-115 transition-all cursor-pointer ${
                                loading || !rawText.trim() ? 'opacity-50 cursor-not-allowed' : ''
                            }`}
                        >
                            {loading ? 'Berechne...' : 'Auswerten & Schätzen'}
                        </button>
                    </div>
                </div>

                {/* Collapsible Info Card for Jita-Anpassung */}
                {showJitaInfo && (
                    <div className="mt-4 p-4 rounded-lg bg-black/40 border border-eve-border/60 text-xs text-slate-300 animate-slide-down">
                        <div className="flex items-center justify-between mb-3 pb-2 border-b border-eve-border/40">
                            <span className="font-bold text-white text-xs">Leitfaden: Wann nutzt man welche Jita-Anpassung?</span>
                            <button
                                type="button"
                                onClick={() => setShowJitaInfo(false)}
                                className="text-eve-muted hover:text-white text-xs cursor-pointer"
                            >
                                Schließen
                            </button>
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div className="p-3 rounded-md bg-eve-card/60 border border-eve-border/40 flex flex-col gap-1.5">
                                <div className="flex items-center justify-between">
                                    <span className="font-bold text-eve-primary">100% (Fair Trade)</span>
                                    <span className="px-1.5 py-0.5 rounded text-[10px] bg-eve-primary/15 text-eve-primary border border-eve-primary/30">Selbstkosten</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    1:1 Jita-Marktwert (Buy = Jita-Sell, Sell = Jita-Buy). Geeignet für interne Gefallen unter Corp-Mitgliedern, Weitergabe gebrauchter Schiffe oder Bestellungen ohne zusätzlichen Logistik-Aufschlag.
                                </p>
                            </div>

                            <div className="p-3 rounded-md bg-eve-card/60 border border-eve-border/40 flex flex-col gap-1.5">
                                <div className="flex items-center justify-between">
                                    <span className="font-bold text-amber-300">90% (Buyback)</span>
                                    <span className="px-1.5 py-0.5 rounded text-[10px] bg-amber-500/15 text-amber-300 border border-amber-500/30">Ankauf vor Ort</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    10% Abschlag für Ankaufsprogramme im Wurmloch (z. B. Erz, PI, Gas, Loot). Der Verkäufer spart das Transportrisiko und den Zeitaufwand nach Jita; der Aufkäufer übernimmt Frachtraum und Vermarktung.
                                </p>
                            </div>

                            <div className="p-3 rounded-md bg-eve-card/60 border border-eve-border/40 flex flex-col gap-1.5">
                                <div className="flex items-center justify-between">
                                    <span className="font-bold text-emerald-300">105% (Logistik)</span>
                                    <span className="px-1.5 py-0.5 rounded text-[10px] bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">Lieferbonus</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    5% Aufschlag für Lieferungen direkt zur heimischen Struktur. Dient als Trinkgeld und Entlohnung für den Hauler-Piloten, der den Einkauf in Jita und den Frachtraum im Transporter stellt.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {error && (
                    <div className="mt-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 text-xs">
                        {error}
                    </div>
                )}
            </div>

            {result && result.items.length > 0 && (
                <div className="p-6 rounded-lg border border-eve-border bg-eve-card/40 shadow-eve animate-fade-in">
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 p-4 rounded-lg bg-black/30 border border-eve-border/60">
                        <div>
                            <span className="text-[11px] text-eve-muted block mb-1">Gesamtwert (vereinbart {result.percent}%):</span>
                            <span className="text-xl font-bold text-eve-primary">
                                {formatThousands(result.totalPrice)} ISK
                            </span>
                        </div>
                        <div>
                            <span className="text-[11px] text-eve-muted block mb-1">100% Jita Basiswert:</span>
                            <span className="text-sm font-semibold text-slate-300">
                                {formatThousands(result.totalBasePrice)} ISK
                            </span>
                        </div>
                        <div>
                            <span className="text-[11px] text-eve-muted block mb-1">Frachtvolumen:</span>
                            <span className="text-sm font-semibold text-slate-300">
                                {formatThousands(result.totalVolume)} m³
                            </span>
                        </div>
                        <div>
                            <span className="text-[11px] text-eve-muted block mb-1">Positionen & Einheiten:</span>
                            <span className="text-sm font-semibold text-slate-300">
                                {result.items.length} Posten ({formatThousands(result.totalItemCount)} Items)
                            </span>
                        </div>
                    </div>

                    {result.unresolved.length > 0 && (
                        <div className="mb-4 p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs">
                            <span className="font-semibold block mb-1">Folgende Zeilen konnten nicht im SDE aufgelöst werden:</span>
                            <span className="text-eve-muted">{result.unresolved.join(', ')}</span>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-bold text-white">
                                {result.isFitting ? `Fitting: ${result.fitTitle}` : 'Aufgeschlüsselte Positionen'}
                            </h3>
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={handleCopyMultibuy}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold border border-white/10 bg-[#0a0f1d] hover:bg-white/5 text-slate-300 hover:text-white transition-colors cursor-pointer"
                            >
                                Multibuy kopieren
                            </button>
                            <button
                                type="button"
                                onClick={() => setIsCreateModalOpen(true)}
                                className="px-4 py-2 rounded-lg text-xs font-semibold border border-transparent bg-eve-primary text-[#060911] shadow-eve hover:brightness-115 transition-all cursor-pointer"
                            >
                                {orderType === 'BUY' ? 'Als Kaufauftrag (Buy Order) aufgeben' : 'Als Verkaufsangebot (Sell Order) einstellen'}
                            </button>
                        </div>
                    </div>

                    <div className="overflow-x-auto border border-eve-border/60 rounded-lg">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr className="border-b border-eve-border/60 bg-black/40 text-eve-muted text-left">
                                    <th className="p-3 w-10"></th>
                                    <th className="p-3">Gegenstand</th>
                                    <th className="p-3">Kategorie / Slot</th>
                                    <th className="p-3 text-right">Menge</th>
                                    <th className="p-3 text-right">Volumen (m³)</th>
                                    <th className="p-3 text-right">Basis Jita</th>
                                    <th className="p-3 text-right">Preis ({result.percent}%)</th>
                                    <th className="p-3 text-right">Gesamt (ISK)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5 bg-black/20">
                                {result.items.map((item, idx) => (
                                    <tr key={`${item.typeId}-${idx}`} className="hover:bg-white/5 transition-colors">
                                        <td className="p-2.5">
                                            <img
                                                src={getItemIconUrl(item.typeId, item.variation)}
                                                alt={item.name}
                                                className="w-8 h-8 rounded border border-white/10 bg-black/40 object-contain"
                                                loading="lazy"
                                                onError={(e) => {
                                                    (e.target as HTMLImageElement).src = '/assets/images/fallback_item.png';
                                                }}
                                            />
                                        </td>
                                        <td className="p-2.5 font-medium text-white">
                                            {item.name}
                                            {item.priceWarning && (
                                                <span className="ml-1.5 text-amber-400 text-[10px]" title={item.priceMessage || 'Geringe Markttiefe'}>
                                                    [Warnung]
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2.5">
                                            <span className={`px-2 py-0.5 rounded text-[10px] font-semibold border ${getSlotBadgeStyle(item.slot)}`}>
                                                {result.isFitting ? item.slot.toUpperCase() : item.categoryName}
                                            </span>
                                        </td>
                                        <td className="p-2.5 text-right font-mono text-slate-300">
                                            {formatThousands(item.quantity)}
                                        </td>
                                        <td className="p-2.5 text-right font-mono text-eve-muted">
                                            {formatThousands(item.totalVolume)} m³
                                        </td>
                                        <td className="p-2.5 text-right font-mono text-eve-muted">
                                            {formatThousands(item.unitPrice)}
                                        </td>
                                        <td className="p-2.5 text-right font-mono text-slate-300">
                                            {formatThousands(item.adjustedUnitPrice)}
                                        </td>
                                        <td className="p-2.5 text-right font-mono font-semibold text-eve-primary">
                                            {formatThousands(item.totalPrice)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Create Order Modal */}
            {isCreateModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-fade-in">
                    <div className="w-full max-w-lg rounded-lg border border-eve-border bg-eve-card p-6 shadow-2xl">
                        <div className="flex items-center justify-between pb-3 border-b border-eve-border/60 mb-4">
                            <h3 className="text-base font-bold text-white">
                                {orderType === 'BUY' ? 'Kaufauftrag aufgeben' : 'Verkaufsangebot einstellen'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsCreateModalOpen(false)}
                                className="text-eve-muted hover:text-white text-sm"
                            >
                                x
                            </button>
                        </div>

                        <form onSubmit={handleCreateOrder} className="flex flex-col gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-white mb-1">Titel / Bezeichnung:</label>
                                <input
                                    type="text"
                                    value={orderTitle}
                                    onChange={(e) => setOrderTitle(e.target.value)}
                                    placeholder="z. B. Cerberus Doctrine Fit, PI Einkauf, etc."
                                    required
                                    className="w-full rounded-lg px-3 py-2 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-white mb-1">Notiz / Zusatzinformation (Optional):</label>
                                <textarea
                                    rows={3}
                                    value={orderNote}
                                    onChange={(e) => setOrderNote(e.target.value)}
                                    placeholder="z. B. Bitte nach Jita 4-4 oder Home-System liefern..."
                                    className="w-full rounded-lg px-3 py-2 text-xs border border-eve-border text-white bg-[#0f172a59] focus:outline-none focus:border-eve-primary resize-y"
                                />
                            </div>

                            <div className="p-3 rounded-lg bg-black/40 border border-eve-border/60 text-xs text-slate-300 flex justify-between">
                                <div>
                                    <span className="text-eve-muted block">Gesamtsumme ({percent}%):</span>
                                    <span className="font-bold text-eve-primary text-sm">{formatThousands(result?.totalPrice || 0)} ISK</span>
                                </div>
                                <div>
                                    <span className="text-eve-muted block">Gesamtvolumen:</span>
                                    <span className="font-semibold">{formatThousands(result?.totalVolume || 0)} m³</span>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-3 border-t border-eve-border/60">
                                <button
                                    type="button"
                                    onClick={() => setIsCreateModalOpen(false)}
                                    className="px-4 py-2 rounded-lg text-xs font-semibold border border-white/10 bg-[#0a0f1d] hover:bg-white/5 text-eve-muted hover:text-white cursor-pointer"
                                >
                                    Abbrechen
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSubmittingOrder}
                                    className={`px-5 py-2 rounded-lg text-xs font-semibold border border-transparent bg-eve-primary text-[#060911] shadow-eve hover:brightness-115 transition-all cursor-pointer ${
                                        isSubmittingOrder ? 'opacity-50 cursor-not-allowed' : ''
                                    }`}
                                >
                                    {isSubmittingOrder ? 'Speichere...' : 'Auftrag verbindlich aufgeben'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
