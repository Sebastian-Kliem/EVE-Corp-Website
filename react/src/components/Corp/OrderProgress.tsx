import React from 'react';
import type { Order } from './OrderListManager';

interface OrderProgressProps {
    order: Order;
}

function _toPercent(count: number, total: number): number {
    return total > 0 ? (count / total) * 100 : 0;
}

// Stacked bar of an order: received, delivered (not yet received) and only accepted items
export default function OrderProgress({ order }: OrderProgressProps) {
    const total = order.totalItemCount;
    const deliveredOnlyCount = order.deliveredItemCount - order.receivedItemCount;
    const acceptedOnlyCount = order.fulfilledItemCount - order.deliveredItemCount;

    return (
        <div className="min-w-[170px]">
            <div className="flex justify-between gap-2 text-[10px] text-eve-muted mb-1">
                <span>
                    Zugesagt <span className="text-slate-300 font-semibold">{order.fulfilledItemCount}</span>
                    {' · '}Geliefert <span className="text-teal-300 font-semibold">{order.deliveredItemCount}</span>
                    {' · '}Erhalten <span className="text-emerald-300 font-semibold">{order.receivedItemCount}</span>
                </span>
                <span className="font-semibold text-slate-300">/ {total}</span>
            </div>
            <div className="w-full bg-black/50 rounded-full h-1.5 overflow-hidden border border-white/10 flex">
                <div className="h-full bg-emerald-400 transition-all duration-300" style={{ width: `${_toPercent(order.receivedItemCount, total)}%` }}></div>
                <div className="h-full bg-teal-500/70 transition-all duration-300" style={{ width: `${_toPercent(deliveredOnlyCount, total)}%` }}></div>
                <div className="h-full bg-eve-primary/60 transition-all duration-300" style={{ width: `${_toPercent(acceptedOnlyCount, total)}%` }}></div>
            </div>
        </div>
    );
}
