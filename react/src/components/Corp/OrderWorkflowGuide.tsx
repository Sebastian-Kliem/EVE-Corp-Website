import React, { useState } from 'react';

export default function OrderWorkflowGuide() {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <div className="mb-6 rounded-lg border border-eve-border bg-eve-card/40 shadow-eve overflow-hidden transition-all duration-300">
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="w-full flex items-center justify-between p-4 bg-black/20 hover:bg-white/5 text-left transition-colors cursor-pointer"
            >
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-md bg-eve-primary/15 border border-eve-primary/30 flex items-center justify-center text-eve-primary font-bold text-sm">
                        i
                    </div>
                    <div>
                        <h4 className="text-sm font-semibold text-white">Anleitung: Bestellungen & Ingame-Vertragsabwicklung</h4>
                        <p className="text-xs text-eve-muted">So funktioniert das Bestellen, Einkaufen und automatische Erfassen via EVE-Online Verträgen</p>
                    </div>
                </div>
                <div className={`text-eve-primary transition-transform duration-300 ${isOpen ? 'rotate-180' : ''}`}>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>
            </button>

            {isOpen && (
                <>
                    <div className="p-5 border-t border-eve-border/60 bg-black/30 grid grid-cols-1 md:grid-cols-4 gap-4 text-xs text-slate-300">
                        <div className="p-3.5 rounded-lg border border-eve-border/40 bg-eve-card/60 flex flex-col gap-2">
                            <div className="flex items-center gap-2">
                                <span className="w-5 h-5 rounded-full bg-eve-primary/20 text-eve-primary font-bold flex items-center justify-center text-[10px]">1</span>
                                <span className="font-semibold text-white">Auftrag aufgeben</span>
                            </div>
                            <p className="text-eve-muted leading-relaxed">
                                Füge ein EFT-Fit oder eine Hangar-Liste ein. Das System ermittelt automatisch Typen, Volumina (m³) und Jita-Preise.
                            </p>
                        </div>

                        <div className="p-3.5 rounded-lg border border-eve-border/40 bg-eve-card/60 flex flex-col gap-2">
                            <div className="flex items-center gap-2">
                                <span className="w-5 h-5 rounded-full bg-eve-primary/20 text-eve-primary font-bold flex items-center justify-center text-[10px]">2</span>
                                <span className="font-semibold text-white">Multibuy Einkauf</span>
                            </div>
                            <p className="text-eve-muted leading-relaxed">
                                Der Erfüller klickt auf <strong className="text-eve-primary">"Multibuy kopieren"</strong> und fügt die Liste direkt im EVE-Multibuy-Fenster (Jita 4-4) ein.
                            </p>
                        </div>

                        <div className="p-3.5 rounded-lg border border-eve-border/40 bg-eve-card/60 flex flex-col gap-2">
                            <div className="flex items-center gap-2">
                                <span className="w-5 h-5 rounded-full bg-eve-primary/20 text-eve-primary font-bold flex items-center justify-center text-[10px]">3</span>
                                <span className="font-semibold text-white">Vertrag in EVE</span>
                            </div>
                            <p className="text-eve-muted leading-relaxed">
                                Erstelle einen Item Exchange Vertrag in EVE. Nutze die 1-Klick Kopierbuttons für Betrag, Empfänger und Betreff (z. B. <strong className="text-eve-primary">"WH-Order #42"</strong>).
                            </p>
                        </div>

                        <div className="p-3.5 rounded-lg border border-eve-border/40 bg-eve-card/60 flex flex-col gap-2">
                            <div className="flex items-center gap-2">
                                <span className="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 font-bold flex items-center justify-center text-[10px]">4</span>
                                <span className="font-semibold text-white">Auto-Sync & Abschluss</span>
                            </div>
                            <p className="text-eve-muted leading-relaxed">
                                Das System matcht den Vertrag automatisch. Der Besteller kann ihn mit <strong className="text-eve-primary">"Im EVE-Client öffnen"</strong> direkt im Spiel annehmen.
                            </p>
                        </div>
                    </div>

                    <div className="p-5 border-t border-eve-border/40 bg-black/40 text-xs text-slate-300">
                        <h5 className="font-bold text-white mb-2.5 flex items-center gap-2">
                            <span>Preise & Jita-Anpassung (% Richtwerte):</span>
                        </h5>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div className="p-2.5 rounded bg-black/30 border border-eve-border/30">
                                <div className="flex items-center justify-between mb-1">
                                    <span className="font-bold text-eve-primary">100% (Fair Trade)</span>
                                    <span className="text-[10px] text-eve-muted">Selbstkosten</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    1:1 Jita-Marktwert (Buy = Jita-Sell, Sell = Jita-Buy). Für reguläre Bestellungen unter Corp-Kollegen oder direkte Weitergabe zum Einkaufspreis.
                                </p>
                            </div>

                            <div className="p-2.5 rounded bg-black/30 border border-eve-border/30">
                                <div className="flex items-center justify-between mb-1">
                                    <span className="font-bold text-amber-300">90% (Buyback)</span>
                                    <span className="text-[10px] text-eve-muted">Ankauf vor Ort</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    10% Abschlag für Ankaufsprogramme im Wurmloch (Erz, PI, Gas, Loot). Spart dem Verkäufer Zeit und Hauling-Risiko nach Jita.
                                </p>
                            </div>

                            <div className="p-2.5 rounded bg-black/30 border border-eve-border/30">
                                <div className="flex items-center justify-between mb-1">
                                    <span className="font-bold text-emerald-300">105% (Logistik)</span>
                                    <span className="text-[10px] text-eve-muted">Liefergebühr</span>
                                </div>
                                <p className="text-eve-muted text-[11px] leading-relaxed">
                                    5% Aufschlag für Lieferungen direkt zur heimischen Struktur als Belohnung für den Transporteur (Hauling-Aufwand & Frachtraum).
                                </p>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
