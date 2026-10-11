import type { Order, OrderItem } from './OrderListManager';

export interface FulfillerShare {
    fulfillerId: number;
    displayName: string;
    items: OrderItem[];
    totalPrice: number;
    totalVolume: number;
}

// Items accepted without an own fulfiller (e.g. closed by contract sync) belong to the order's fulfiller
export function getItemFulfiller(order: Order, item: OrderItem): { id: number; displayName: string } | null {
    if (!item.isFulfilled) {
        return null;
    }
    return item.fulfiller ?? order.fulfiller;
}

export function sumItemPrices(items: OrderItem[]): number {
    let total = 0;
    for (const item of items) {
        total += item.totalPrice;
    }
    return total;
}

export function sumItemVolumes(items: OrderItem[]): number {
    let total = 0;
    for (const item of items) {
        total += item.totalVolume;
    }
    return total;
}

// One entry per user that accepted items of the order, sorted by name
export function getFulfillerShares(order: Order): FulfillerShare[] {
    const sharesById = new Map<number, FulfillerShare>();
    for (const item of order.items) {
        const fulfiller = getItemFulfiller(order, item);
        if (!fulfiller) {
            continue;
        }
        let share = sharesById.get(fulfiller.id);
        if (!share) {
            share = { fulfillerId: fulfiller.id, displayName: fulfiller.displayName, items: [], totalPrice: 0, totalVolume: 0 };
            sharesById.set(fulfiller.id, share);
        }
        share.items.push(item);
        share.totalPrice += item.totalPrice;
        share.totalVolume += item.totalVolume;
    }

    const shares = Array.from(sharesById.values());
    shares.sort((first, second) => first.displayName.localeCompare(second.displayName));
    return shares;
}

export function getOpenItems(order: Order): OrderItem[] {
    const openItems: OrderItem[] = [];
    for (const item of order.items) {
        if (!getItemFulfiller(order, item)) {
            openItems.push(item);
        }
    }
    return openItems;
}

// Narrows an order to the items the given user delivers, prices and volume follow the items
export function toDeliveryView(order: Order, userId: number | null): Order {
    if (!userId) {
        return order;
    }
    const ownItems: OrderItem[] = [];
    for (const item of order.items) {
        if (getItemFulfiller(order, item)?.id === userId) {
            ownItems.push(item);
        }
    }
    if (ownItems.length === 0 || ownItems.length === order.items.length) {
        return order;
    }
    return {
        ...order,
        items: ownItems,
        totalPrice: sumItemPrices(ownItems),
        totalVolume: sumItemVolumes(ownItems),
    };
}
