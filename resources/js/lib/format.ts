const currencySymbols: Record<string, string> = {
    NGN: '₦',
    USD: '$',
    EUR: '€',
    GBP: '£',
    CAD: 'C$',
    AUD: 'A$',
    SGD: 'S$',
    INR: '₹',
    AED: 'د.إ',
};

export function getCurrencySymbol(code: string): string {
    return currencySymbols[code] ?? code + ' ';
}

export function formatCurrency(cents: number, symbol = '₦', decimals = 2): string {
    return `${symbol}${(cents / 100).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })}`;
}

export function formatCurrencyWithCode(cents: number, currencyCode: string, decimals = 2): string {
    const symbol = getCurrencySymbol(currencyCode);
    return formatCurrency(cents, symbol, decimals);
}

export function formatNumber(value: number): string {
    return value.toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
}
