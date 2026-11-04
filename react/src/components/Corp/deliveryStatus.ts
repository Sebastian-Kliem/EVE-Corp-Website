import type { Order, OrderItem } from './OrderListManager';

export type DeliveryStatus = 'ACCEPTED' | 'BUY_ORDER_PLACED' | 'MANUFACTURING' | 'DELIVERED' | 'RECEIVED';

export const DELIVERY_STATUS_LABELS: Record<DeliveryStatus, string> = {
    ACCEPTED: 'Zugesagt',
    BUY_ORDER_PLACED: 'Kaufauftrag erstellt',
    MANUFACTURING: 'Wird hergestellt',
    DELIVERED: 'Geliefert',
    RECEIVED: 'Erhalten',
};

export const DELIVERY_STATUS_STYLES: Record<DeliveryStatus, string> = {
    ACCEPTED: 'bg-blue-500/15 text-blue-300 border-blue-500/30',
    BUY_ORDER_PLACED: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    MANUFACTURING: 'bg-purple-500/15 text-purple-300 border-purple-500/30',
    DELIVERED: 'bg-teal-500/15 text-teal-300 border-teal-500/30',
    RECEIVED: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
};

// Statuses the supplier can choose, intermediate steps only for BUY orders
export function getSupplierStatusOptions(order: Order): DeliveryStatus[] {
    if (order.type === 'BUY') {
        return ['ACCEPTED', 'BUY_ORDER_PLACED', 'MANUFACTURING', 'DELIVERED'];
    }
    return ['ACCEPTED', 'DELIVERED'];
}

export function isDelivered(item: OrderItem): boolean {
    return item.deliveryStatus === 'DELIVERED' || item.deliveryStatus === 'RECEIVED';
}

// Customers only see accepted, delivered and received
export function getCustomerStatus(item: OrderItem): DeliveryStatus | null {
    if (item.deliveryStatus === 'BUY_ORDER_PLACED' || item.deliveryStatus === 'MANUFACTURING') {
        return 'ACCEPTED';
    }
    return item.deliveryStatus;
}

export function hasOpenOwnDelivery(order: Order, userId: number): boolean {
    for (const item of order.items) {
        if (item.isFulfilled && item.fulfiller?.id === userId && !isDelivered(item)) {
            return true;
        }
    }
    return false;
}
