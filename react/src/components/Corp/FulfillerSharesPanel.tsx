import React from 'react';
import { formatThousands } from '../../utils/numberFormat';
import type { Order, OrderItem } from './OrderListManager';
import { getFulfillerShares, getOpenItems, sumItemPrices, sumItemVolumes } from './orderShares';

interface FulfillerSharesPanelProps {
    order: Order;
    currentUserId: number | null;
    onCopyAmount: (amount: number, label: string) => void;
    onCopyMultibuy: (items: OrderItem[], label: string) => void;
}

// Splits an order served by several users into one amount per user, each pilot contracts only their part
export default function FulfillerSharesPanel({ order, currentUserId, onCopyAmount, onCopyMultibuy }: FulfillerSharesPanelProps) {
    const shares = getFulfillerShares(order);
    const openItems = getOpenItems(order);
    if (shares.length === 0 || (shares.length === 1 && openItems.length === 0)) {
        return null;
    }

    const buttonClass = 'px-2 py-0.5 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer';

    return (
        <div className="mb-4 rounded-lg border border-eve-border/40 bg-black/40 text-xs overflow-hidden">
            <div className="px-3 py-2 border-b border-eve-border/40 text-eve-muted font-semibold">
                Beträge pro Lieferant (jeder stellt einen eigenen Vertrag über seinen Anteil)
            </div>
            <div className="divide-y divide-white/5">
                {shares.map(share => {
                    const isCurrentUser = share.fulfillerId === currentUserId;
                    return (
                        <div key={share.fulfillerId} className={`flex flex-wrap items-center justify-between gap-3 px-3 py-2 ${isCurrentUser ? 'bg-eve-primary/5' : ''}`}>
                            <div className="min-w-[160px]">
                                <span className={`font-semibold ${isCurrentUser ? 'text-eve-primary' : 'text-blue-300'}`}>
                                    {share.displayName}{isCurrentUser ? ' (du)' : ''}
                                </span>
                                <span className="text-eve-muted ml-2">
                                    {share.items.length} Posten • {formatThousands(share.totalVolume)} m³
                                </span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono font-bold text-eve-primary">{formatThousands(share.totalPrice)} ISK</span>
                                <button type="button" onClick={() => onCopyAmount(share.totalPrice, share.displayName)} className={buttonClass}>
                                    Betrag kopieren
                                </button>
                                <button type="button" onClick={() => onCopyMultibuy(share.items, share.displayName)} className={buttonClass}>
                                    Multibuy kopieren
                                </button>
                            </div>
                        </div>
                    );
                })}
                {openItems.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                        <div className="min-w-[160px]">
                            <span className="font-semibold text-slate-400">Noch nicht angenommen</span>
                            <span className="text-eve-muted ml-2">
                                {openItems.length} Posten • {formatThousands(sumItemVolumes(openItems))} m³
                            </span>
                        </div>
                        <span className="font-mono font-bold text-slate-400">{formatThousands(sumItemPrices(openItems))} ISK</span>
                    </div>
                )}
            </div>
        </div>
    );
}
