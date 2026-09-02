import React, { useState, useMemo } from 'react';
import { cleanItemSearch } from '../../utils/itemSearch';

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
            const queryLower = cleanItemSearch(walletSearchQuery).toLowerCase().trim();
            const matchesQuery = queryLower === '' || 
                descriptionLower.includes(queryLower) || 
                reasonLower.includes(queryLower) ||
                typeLower.includes(queryLower);
            return matchesChar && matchesQuery;
        });
    }, [journalEntries, selectedWalletCharId, walletSearchQuery]);

    return (
        <div>
            <div className="flex justify-between items-center flex-wrap gap-4 mb-4">
                <div className="flex-grow">
                    <p className="text-xs text-eve-muted">Verfolge Wallet-Transaktionen deiner EVE Online Charaktere chronologisch.</p>
                </div>
                <div className="flex gap-3 items-center ml-auto">
                    <div className="relative flex items-center">
                        <span className="absolute left-2.5 text-xs text-eve-muted">🔍</span>
                        <input 
                            className="rounded pl-7 pr-3 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-[200px]" 
                            type="text" 
                            placeholder="Beschreibung suchen..." 
                            value={walletSearchQuery} 
                            onChange={(e) => setWalletSearchQuery(cleanItemSearch(e.target.value))} 
                        />
                    </div>
                    <div>
                        <select 
                            value={selectedWalletCharId} 
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedWalletCharId(val === 'all' ? 'all' : parseInt(val, 10));
                            }}
                            className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                        >
                            <option value="all" style={{ background: '#101525' }}>Alle Charaktere</option>
                            {characters.map(char => (
                                <option key={char.id} value={char.id} style={{ background: '#101525' }}>{char.name}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="overflow-auto max-h-[450px] mt-4 border border-eve-border rounded-lg bg-black/10">
                <table className="w-full border-collapse">
                    <thead>
                        <tr className="border-b border-eve-border bg-[#0d121fe6]/50">
                            <th className="text-left font-semibold text-eve-muted p-3 text-xs">Datum</th>
                            <th className="text-left font-semibold text-eve-muted p-3 text-xs">Charakter</th>
                            <th className="text-left font-semibold text-eve-muted p-3 text-xs">Typ</th>
                            <th className="text-left font-semibold text-eve-muted p-3 text-xs">Details</th>
                            <th className="text-right font-semibold text-eve-muted p-3 text-xs">Betrag</th>
                            <th className="text-right font-semibold text-eve-muted p-3 text-xs">Kontostand</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {filteredJournalEntries.length > 0 ? (
                            filteredJournalEntries.map((entry, idx) => (
                                <tr key={idx} className="hover:bg-white/2">
                                    <td className="p-3 text-xs text-[#ccc] vertical-middle whitespace-nowrap">{entry.date}</td>
                                    <td className="p-3 text-xs text-[#ccc] vertical-middle">{entry.characterName}</td>
                                    <td className="p-3 text-xs text-[#ccc] vertical-middle">
                                        <span className="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-white/10 text-eve-muted border border-white/5 capitalize">
                                            {entry.refType.replace(/_/g, ' ')}
                                        </span>
                                    </td>
                                    <td className="p-3 text-xs text-[#ccc] vertical-middle max-w-[300px] break-words">
                                        <div>{entry.description || '-'}</div>
                                        {entry.reason && (
                                            <div className="text-[10px] text-eve-muted mt-0.5 italic">
                                                Grund: {entry.reason}
                                            </div>
                                        )}
                                    </td>
                                    <td className={`p-3 text-xs vertical-middle text-right font-bold font-mono ${
                                        entry.amount > 0 ? 'text-emerald-400' : entry.amount < 0 ? 'text-rose-400' : 'text-[#ccc]'
                                    }`}>
                                        {entry.amount > 0 ? '+' : ''}{entry.amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </td>
                                    <td className="p-3 text-xs vertical-middle text-right text-eve-muted font-mono">
                                        {entry.balance.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={6} className="text-center text-eve-muted py-8 text-xs">
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
