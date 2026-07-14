import React, { useState } from 'react';

interface ParsedItem {
    typeId: number;
    name: string;
    quantity: number;
    variation?: string;
}

interface ItemPasteInputProps {
    jwtToken: string;
    onItemsParsed: (items: ParsedItem[]) => void;
    buttonLabel?: string;
    placeholder?: string;
}

export default function ItemPasteInput({
    jwtToken,
    onItemsParsed,
    buttonLabel = '📋 Liste aus EVE einfügen',
    placeholder = 'Kopiere Gegenstände aus deinem EVE Hangar (Strg+C) und füge sie hier ein...'
}: ItemPasteInputProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [pastedText, setPastedText] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [resultSummary, setResultSummary] = useState<{
        successCount: number;
        unresolved: string[];
    } | null>(null);

    const handleParse = (e: React.FormEvent) => {
        e.preventDefault();
        if (!pastedText.trim()) return;

        setLoading(true);
        setError(null);
        setResultSummary(null);

        fetch('/api/sde/parse-items', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${jwtToken}`
            },
            body: JSON.stringify({ text: pastedText })
        })
            .then(res => {
                if (!res.ok) throw new Error('Fehler bei der Server-Antwort.');
                return res.json();
            })
            .then((data: { items: ParsedItem[]; unresolved: string[] }) => {
                setLoading(false);
                if (data.items.length > 0) {
                    onItemsParsed(data.items);
                    setResultSummary({
                        successCount: data.items.length,
                        unresolved: data.unresolved
                    });
                    setPastedText(''); // Clear on success
                } else if (data.unresolved.length > 0) {
                    setError('Keines der eingetragenen Items konnte in der SDE-Datenbank gefunden werden.');
                    setResultSummary({
                        successCount: 0,
                        unresolved: data.unresolved
                    });
                } else {
                    setError('Keine gültigen Zeilen gefunden.');
                }
            })
            .catch(err => {
                console.error(err);
                setError(err.message || 'Verbindungsfehler.');
                setLoading(false);
            });
    };

    return (
        <div className="mb-4">
            {!isOpen ? (
                <button 
                    type="button" 
                    className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-eve-primary/8 border border-dashed border-eve-primary text-eve-primary rounded-md text-xs font-semibold cursor-pointer transition-all duration-200 hover:bg-eve-primary/15 hover:shadow-[0_0_8px_rgba(0,240,255,0.3)]"
                    onClick={() => setIsOpen(true)}
                >
                    {buttonLabel}
                </button>
            ) : (
                <div className="mt-2 p-4 bg-eve-card/85 border border-eve-border rounded-lg shadow-[0_4px_15px_rgba(0,0,0,0.4)] backdrop-blur-sm animate-slide-down">
                    <div className="flex justify-between items-center mb-2">
                        <span className="text-xs font-bold text-white">EVE Hangar Copy'n'Paste</span>
                        <button 
                            type="button" 
                            className="text-eve-muted hover:text-white px-1 h-auto text-xs cursor-pointer border-none bg-transparent"
                            onClick={() => {
                                setIsOpen(false);
                                setError(null);
                                setResultSummary(null);
                            }}
                        >
                            Abbrechen
                        </button>
                    </div>

                    <form onSubmit={handleParse}>
                        <textarea
                            className="w-full min-h-[120px] bg-black/40 border border-eve-border rounded-md text-white font-mono text-xs p-2 resize-y outline-none transition-colors duration-200 focus:border-eve-primary focus:shadow-[0_0_5px_rgba(0,240,255,0.2)]"
                            placeholder={placeholder}
                            value={pastedText}
                            onChange={(e) => setPastedText(e.target.value)}
                            disabled={loading}
                        />

                        {error && (
                            <div className="text-red-500 text-xs mt-1">
                                ⚠️ {error}
                            </div>
                        )}

                        {resultSummary && (
                            <div className="text-green-500 text-xs mt-1 flex flex-col gap-1">
                                <div>✅ {resultSummary.successCount} Gegenstände erfolgreich hinzugefügt!</div>
                                {resultSummary.unresolved.length > 0 && (
                                    <div className="mt-2 px-2.5 py-2 bg-red-500/5 border border-red-500/20 rounded-md text-xs">
                                        <div className="text-[#ffcc88]">
                                            ⚠️ Die folgenden {resultSummary.unresolved.length} Zeilen konnten nicht zugeordnet werden:
                                        </div>
                                        <ul className="max-h-[80px] overflow-y-auto mt-1 pl-4 text-[#ffaa55]">
                                            {resultSummary.unresolved.map((line, idx) => (
                                                <li key={idx}>{line}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex gap-2 mt-2 justify-end">
                            <button
                                type="submit"
                                className={`inline-flex items-center justify-center border border-transparent rounded-lg bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold px-3 py-1 text-xs shadow-eve transition-all duration-300 hover:-translate-y-0.5 cursor-pointer ${loading ? 'opacity-70 cursor-not-allowed' : ''}`}
                                disabled={loading || !pastedText.trim()}
                            >
                                {loading ? 'Lädt...' : 'Hinzufügen'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
