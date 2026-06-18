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
        <div className="item-paste-container-prof">
            <style>{`
                .item-paste-container-prof {
                    margin-bottom: 1rem;
                }
                .paste-toggle-btn-prof {
                    background: rgba(0, 240, 255, 0.08);
                    border: 1px dashed var(--theme-primary, #00f0ff);
                    color: var(--theme-primary, #00f0ff);
                    border-radius: 6px;
                    padding: 8px 12px;
                    font-size: 0.8rem;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    transition: all 0.2s;
                    font-weight: 600;
                    width: 100%;
                    justify-content: center;
                }
                .paste-toggle-btn-prof:hover {
                    background: rgba(0, 240, 255, 0.15);
                    box-shadow: 0 0 8px rgba(0, 240, 255, 0.3);
                }
                .paste-card-prof {
                    background: rgba(20, 27, 43, 0.85);
                    border: 1px solid var(--theme-card-border, #333);
                    border-radius: 8px;
                    padding: 1rem;
                    margin-top: 8px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.4);
                    backdrop-filter: blur(8px);
                }
                .textarea-dark-prof {
                    width: 100%;
                    min-height: 120px;
                    background: rgba(0,0,0,0.4);
                    border: 1px solid var(--theme-card-border, #444);
                    border-radius: 6px;
                    color: #fff;
                    font-family: monospace;
                    font-size: 0.8rem;
                    padding: 8px;
                    resize: vertical;
                    outline: none;
                    transition: border-color 0.2s;
                }
                .textarea-dark-prof:focus {
                    border-color: var(--theme-primary, #00f0ff);
                    box-shadow: 0 0 5px rgba(0, 240, 255, 0.2);
                }
                .unresolved-box-prof {
                    background: rgba(255, 50, 50, 0.05);
                    border: 1px solid rgba(255, 50, 50, 0.2);
                    border-radius: 6px;
                    padding: 8px 10px;
                    margin-top: 8px;
                    font-size: 0.75rem;
                }
                .unresolved-list-prof {
                    max-height: 80px;
                    overflow-y: auto;
                    margin-top: 4px;
                    padding-left: 15px;
                    color: #ffaa55;
                }
            `}</style>

            {!isOpen ? (
                <button 
                    type="button" 
                    className="paste-toggle-btn-prof"
                    onClick={() => setIsOpen(true)}
                >
                    {buttonLabel}
                </button>
            ) : (
                <div className="paste-card-prof animate-slide-down">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                        <span style={{ fontSize: '0.8rem', fontWeight: 'bold', color: '#fff' }}>EVE Hangar Copy'n'Paste</span>
                        <button 
                            type="button" 
                            className="button is-small is-text" 
                            style={{ color: '#888', padding: '0 4px', height: 'auto' }}
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
                            className="textarea-dark-prof"
                            placeholder={placeholder}
                            value={pastedText}
                            onChange={(e) => setPastedText(e.target.value)}
                            disabled={loading}
                        />

                        {error && (
                            <div className="has-text-danger is-size-7 mt-1">
                                ⚠️ {error}
                            </div>
                        )}

                        {resultSummary && (
                            <div className="has-text-success is-size-7 mt-1" style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                                <div>✅ {resultSummary.successCount} Gegenstände erfolgreich hinzugefügt!</div>
                                {resultSummary.unresolved.length > 0 && (
                                    <div className="unresolved-box-prof">
                                        <div style={{ color: '#ffcc88' }}>
                                            ⚠️ Die folgenden {resultSummary.unresolved.length} Zeilen konnten nicht zugeordnet werden:
                                        </div>
                                        <ul className="unresolved-list-prof">
                                            {resultSummary.unresolved.map((line, idx) => (
                                                <li key={idx}>{line}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}

                        <div style={{ display: 'flex', gap: '8px', marginTop: '8px', justifyContent: 'flex-end' }}>
                            <button
                                type="submit"
                                className={`button is-small is-primary ${loading ? 'is-loading' : ''}`}
                                disabled={loading || !pastedText.trim()}
                            >
                                Hinzufügen
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
