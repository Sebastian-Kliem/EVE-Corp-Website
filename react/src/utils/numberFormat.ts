/**
 * Formats a numeric/ISK string with German thousands separators (dot: 1.000.000).
 * Handles integer and decimal values cleanly while typing or pasting.
 */
export function formatThousands(val: string | number): string {
    if (val === null || val === undefined) return '';
    const str = val.toString();
    if (!str.trim()) return '';

    const isNegative = str.trim().startsWith('-');

    let integerPart = str;
    let decimalPart = '';

    if (str.includes(',')) {
        const parts = str.split(',');
        integerPart = parts[0].replace(/\D/g, '');
        decimalPart = parts.slice(1).join('').replace(/\D/g, '');
    } else if (str.includes('.') && typeof val === 'number') {
        const parts = str.split('.');
        integerPart = parts[0].replace(/\D/g, '');
        decimalPart = parts[1].replace(/\D/g, '');
    } else {
        integerPart = str.replace(/\D/g, '');
    }

    if (!integerPart && !decimalPart) {
        return isNegative ? '-' : '';
    }

    const formattedInt = integerPart ? integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
    let result = (isNegative ? '-' : '') + formattedInt;

    if (str.includes(',') && !decimalPart) {
        result += ',';
    } else if (decimalPart) {
        result += ',' + decimalPart;
    }

    return result;
}

export function parseThousands(val: string | number): number {
    if (val === null || val === undefined) return 0;
    if (typeof val === 'number') return val;
    if (!val.trim()) return 0;

    const isNegative = val.trim().startsWith('-');
    let clean = val.replace(/[^\d,.-]/g, '');

    if (clean.includes(',')) {
        const parts = clean.split(',');
        const intPart = parts[0].replace(/\D/g, '');
        const decPart = parts.slice(1).join('').replace(/\D/g, '');
        clean = intPart + '.' + decPart;
    } else {
        // Multiple dots are thousands separators
        const dotCount = (clean.match(/\./g) || []).length;
        if (dotCount > 1) {
            clean = clean.replace(/\./g, '');
        }
    }

    const num = parseFloat(clean);
    if (isNaN(num)) return 0;
    return isNegative ? -Math.abs(num) : Math.abs(num);
}
