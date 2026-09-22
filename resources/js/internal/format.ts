export function formatDate(iso: string, locale?: string): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(
        new Date(iso),
    );
}

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

export function formatRelative(
    iso: string,
    locale?: string,
    now: number = Date.now(),
): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'minute');
}

export function isPast(iso: string, now: number = Date.now()): boolean {
    return new Date(iso).getTime() <= now;
}
