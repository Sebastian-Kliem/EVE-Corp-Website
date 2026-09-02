/**
 * Cleans user input for item searches.
 * When copying item names from the EVE Online client, trailing asterisks '*' (e.g. "Tritanium*")
 * or tab-separated data from inventory rows (e.g. "Tritanium*\t1000\t...") are frequently included.
 * This helper removes asterisks and extracts the clean item name so search queries always match properly.
 */
export function cleanItemSearch(value: string): string {
    if (!value) return '';
    let cleaned = value;
    // Extract item name before tab if copied from EVE inventory row
    if (cleaned.includes('\t')) {
        cleaned = cleaned.split('\t')[0];
    }
    // Remove all asterisks (EVE items never contain '*' in their canonical names)
    return cleaned.replace(/\*/g, '');
}
