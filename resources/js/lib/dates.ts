/**
 * Shared date formatting utilities.
 * All functions use 'en-US' locale for consistency.
 */

/** Format a date string to full month name: "January 5, 2024" */
export function formatDate(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

/** Format a date string to short month + time: "Jan 5, 2024 at 2:30 PM" */
export function formatDateTime(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).replace(',', ' at');
}

/** Format a date string to short month + day: "Jan 5" (for chart axes) */
export function formatDateShort(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

/** Format a date string to abbreviated weekday: "Mon" */
export function formatWeekday(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('en-US', {
        weekday: 'short',
    });
}

/** Check if a date string is today */
export function isToday(dateStr: string): boolean {
    if (!dateStr) return false;
    const date = new Date(dateStr);
    const today = new Date();
    return (
        date.getFullYear() === today.getFullYear() &&
        date.getMonth() === today.getMonth() &&
        date.getDate() === today.getDate()
    );
}
