import React from 'react';
import { formatThousands } from '../../utils/numberFormat';
import type { Order, OrderItem } from './OrderListManager';
import { getFulfillerShares, getOpenItems, sumItemPrices, sumItemVolumes } from './orderShares';

interface OrderAmountsListProps {
    order: Order;
    currentUserId: number | null;
    onCopyAmount: (amount: number, label: string) => void;
    onCopyMultibuy: (items: OrderItem[], label: string) => void;
}

interface AmountRowProps {
    label: React.ReactNode;
    items: OrderItem[];
    amount: number;
    volume: number;
    copyLabel: string;
    rowClassName?: string;
    amountClassName?: string;
    onCopyAmount: (amount: number, label: string) => void;
    onCopyMultibuy: (items: OrderItem[], label: string) => void;
}

const buttonClass = 'px-2 py-0.5 rounded bg-[#0a0f1d] border border-white/10 hover:bg-white/5 text-slate-300 hover:text-white cursor-pointer';

function AmountRow({ label, items, amount, volume, copyLabel, rowClassName = '', amountClassName = 'text-eve-primary', onCopyAmount, onCopyMultibuy }: AmountRowProps) {
    return (
        <div className={`flex flex-wrap items-center justify-between gap-3 px-3 py-2 ${rowClassName}`}>
            <div className="min-w-[160px]">
                {label}
                <span className="text-eve-muted ml-2">
                    {items.length} Posten • {formatThousands(volume)} m³
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <span className={`font-mono font-bold ${amountClassName}`}>{formatThousands(amount)} ISK</span>
                <button type="button" onClick={() => onCopyAmount(amount, copyLabel)} className={buttonClass}>
                    Betrag kopieren
                </button>
                <button type="button" onClick={() => onCopyMultibuy(items, copyLabel)} className={buttonClass}>
                    Multibuy kopieren
                </button>
            </div>
        </div>
    );
}

// All amounts of an order in one list: one row per fulfiller, the unaccepted rest and the total
export default function OrderAmountsList({ order, currentUserId, onCopyAmount, onCopyMultibuy }: OrderAmountsListProps) {
    const shares = getFulfillerShares(order);
    const openItems = getOpenItems(order);
    const isSplit = shares.length > 1 || (shares.length === 1 && openItems.length > 0);

    return (
        <div className="divide-y divide-white/5 border-t border-eve-border/40">
            {isSplit && shares.map(share => {
                const isCurrentUser = share.fulfillerId === currentUserId;
                return (
                    <AmountRow
                        key={share.fulfillerId}
                        label={
                            <span className={`font-semibold ${isCurrentUser ? 'text-eve-primary' : 'text-blue-300'}`}>
                                Anteil {share.displayName}{isCurrentUser ? ' (du)' : ''}
                            </span>
                        }
                        items={share.items}
                        amount={share.totalPrice}
                        volume={share.totalVolume}
                        copyLabel={`Anteil von ${share.displayName}`}
                        rowClassName={isCurrentUser ? 'bg-eve-primary/5' : ''}
                        onCopyAmount={onCopyAmount}
                        onCopyMultibuy={onCopyMultibuy}
                    />
                );
            })}
            {isSplit && openItems.length > 0 && (
                <AmountRow
                    label={<span className="font-semibold text-slate-400">Noch nicht angenommen</span>}
                    items={openItems}
                    amount={sumItemPrices(openItems)}
                    volume={sumItemVolumes(openItems)}
                    copyLabel="Offene Posten"
                    amountClassName="text-slate-400"
                    onCopyAmount={onCopyAmount}
                    onCopyMultibuy={onCopyMultibuy}
                />
            )}
            <AmountRow
                label={<span className="font-semibold text-white">Gesamt ({order.percentToJita}%)</span>}
                items={order.items}
                amount={order.totalPrice}
                volume={order.totalVolume}
                copyLabel="Gesamtbetrag"
                rowClassName={isSplit ? 'bg-white/[0.03]' : ''}
                onCopyAmount={onCopyAmount}
                onCopyMultibuy={onCopyMultibuy}
            />
        </div>
    );
}
