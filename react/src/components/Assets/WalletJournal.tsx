import React, { useState, useMemo } from 'react';

interface JournalEntry {
    characterId: number;
    characterName: string;
    refId: string;
    date: string;
    refType: string;
    amount: number;
    balance: number;
    description: string | null;
    reason: string | null;
}

interface WalletJournalProps {
    journalEntries: JournalEntry[];
    characters: { id: number; name: string }[];
}

export default function WalletJournal({ journalEntries, characters }: WalletJournalProps) {
    const [selectedWalletCharId, setSelectedWalletCharId] = useState<number | 'all'>('all');
    const [walletSearchQuery, setWalletSearchQuery] = useState('');

    const filteredJournalEntries = useMemo(() => {
        if (!journalEntries) return [];
        return journalEntries.filter(entry => {
            const matchesChar = selectedWalletCharId === 'all' || entry.characterId === selectedWalletCharId;
            const descriptionLower = (entry.description || '').toLowerCase();
            const reasonLower = (entry.reason || '').toLowerCase();
            const typeLower = (entry.refType || '').toLowerCase();
            const queryLower = walletSearchQuery.toLowerCase();
            const matchesQuery = walletSearchQuery === '' || 
                descriptionLower.includes(queryLower) || 
                reasonLower.includes(queryLower) ||
                typeLower.includes(queryLower);
            return matchesChar && matchesQuery;
        });
    }, [journalEntries, selectedWalletCharId, walletSearchQuery]);

    return (
        <div>
            <div className="level mb-4">
                <div className="level-left">
                    <p className="is-size-7 has-text-grey-light">Verfolge Wallet-Transaktionen deiner EVE Online Charaktere chronologisch.</p>
                </div>
                <div className="level-right" style={{ display: 'flex', gap: '15px', alignItems: 'center' }}>
                    <div className="field mb-0">
                        <div className="control has-icons-left">
                            <input 
                                className="input is-small" 
                                type="text" 
                                placeholder="Beschreibung suchen..." 
                                value={walletSearchQuery} 
                                onChange={(e) => setWalletSearchQuery(e.target.value)} 
                                style={{ backgroundColor: 'var(--theme-card-bg)', color: 'var(--theme-text)', borderColor: 'var(--theme-card-border)' }}
                            />
                            <span className="icon is-small is-left">🔍</span>
                        </div>
                    </div>
                    <div className="select is-small">
                        <select 
                            value={selectedWalletCharId} 
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedWalletCharId(val === 'all' ? 'all' : parseInt(val, 10));
                            }}
                            style={{ backgroundColor: 'var(--theme-card-bg)', color: 'var(--theme-text)', borderColor: 'var(--theme-card-border)' }}
                        >
                            <option value="all">Alle Charaktere</option>
                            {characters.map(char => (
                                <option key={char.id} value={char.id}>{char.name}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div style={{ overflowX: 'auto', maxHeight: '450px', overflowY: 'auto' }} className="mt-4">
                <table className="table is-fullwidth" style={{ backgroundColor: 'transparent', color: 'var(--theme-text)' }}>
                    <thead>
                        <tr style={{ borderBottom: '2px solid var(--theme-card-border)' }}>
                            <th style={{ color: 'var(--theme-text-muted)', fontSize: '0.85rem' }}>Datum</th>
                            <th style={{ color: 'var(--theme-text-muted)', fontSize: '0.85rem' }}>Charakter</th>
                            <th style={{ color: 'var(--theme-text-muted)', fontSize: '0.85rem' }}>Typ</th>
                            <th style={{ color: 'var(--theme-text-muted)', fontSize: '0.85rem' }}>Details</th>
                            <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right', fontSize: '0.85rem' }}>Betrag</th>
                            <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right', fontSize: '0.85rem' }}>Kontostand</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredJournalEntries.length > 0 ? (
                            filteredJournalEntries.map((entry, idx) => (
                                <tr key={idx} style={{ borderBottom: '1px solid rgba(0, 240, 255, 0.05)' }}>
                                    <td style={{ whiteSpace: 'nowrap', fontSize: '0.85rem' }}>{entry.date}</td>
                                    <td style={{ fontSize: '0.85rem' }}>{entry.characterName}</td>
                                    <td style={{ fontSize: '0.85rem' }}>
                                        <span className="tag is-dark" style={{ fontSize: '0.75rem', textTransform: 'capitalize' }}>
                                            {entry.refType.replace(/_/g, ' ')}
                                        </span>
                                    </td>
                                    <td style={{ fontSize: '0.85rem', maxWidth: '300px', wordBreak: 'break-word' }}>
                                        <div>{entry.description || '-'}</div>
                                        {entry.reason && (
                                            <div className="is-size-7 has-text-grey-light" style={{ fontStyle: 'italic' }}>
                                                Grund: {entry.reason}
                                            </div>
                                        )}
                                    </td>
                                    <td style={{ 
                                        textAlign: 'right', 
                                        fontWeight: 'bold', 
                                        fontSize: '0.85rem',
                                        color: entry.amount > 0 ? '#00ffaa' : entry.amount < 0 ? '#f14668' : 'var(--theme-text)'
                                    }}>
                                        {entry.amount > 0 ? '+' : ''}{entry.amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </td>
                                    <td style={{ textAlign: 'right', fontSize: '0.85rem', color: 'var(--theme-text-muted)' }}>
                                        {entry.balance.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={6} className="has-text-centered has-text-grey-light py-4" style={{ fontSize: '0.85rem' }}>
                                    Keine Transaktionen gefunden.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
