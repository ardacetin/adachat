/** Display formatting; amounts arrive as decimal strings from the server. */

export function formatUsd(value: string, locale: string): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value));
}

/** A calendar date "YYYY-MM-DD" (already in the institution's time zone). */
export function formatDate(date: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

/** A month "YYYY-MM". */
export function formatMonth(month: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${month}-01T00:00:00Z`));
}

export function formatNumber(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}
