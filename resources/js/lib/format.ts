const moneyFormatters = new Map<string, Intl.NumberFormat>();

export function formatMoney(cents: number, currency = 'CAD'): string {
    let formatter = moneyFormatters.get(currency);

    if (!formatter) {
        formatter = new Intl.NumberFormat('en-CA', {
            style: 'currency',
            currency,
            currencyDisplay: 'narrowSymbol',
        });
        moneyFormatters.set(currency, formatter);
    }

    return formatter.format(cents / 100);
}

/** Cents to a plain "1234.50" for form inputs. */
export function centsToInput(cents: number | null | undefined): string {
    return cents === null || cents === undefined
        ? ''
        : (cents / 100).toFixed(2);
}

/** "12.500" → "12.5", "640.000" → "640". */
export function formatQuantity(quantity: string | number | null): string {
    if (quantity === null || quantity === '') {
        return '';
    }

    return Number(quantity).toLocaleString('en-CA', {
        maximumFractionDigits: 3,
    });
}

export function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('en-CA', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

const unitLabels: Record<string, string> = {
    each: 'each',
    pallet: 'pallet',
    cube: 'cube',
    bag: 'bag',
    bundle: 'bundle',
    tonne: 'tonne',
    cubic_yard: 'cu yd',
    sq_ft: 'sq ft',
    lin_ft: 'lin ft',
};

export function unitLabel(unit: string): string {
    return unitLabels[unit] ?? unit;
}

/** "Thu Oct 29, 7:00 am" in the yard's time zone. */
export function formatDateTime(
    value: string | null,
    timeZone?: string,
): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const day = date.toLocaleDateString('en-CA', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone,
    });
    const time = date
        .toLocaleTimeString('en-CA', {
            hour: 'numeric',
            minute: '2-digit',
            timeZone,
        })
        .replace('a.m.', 'am')
        .replace('p.m.', 'pm');

    return `${day}, ${time}`;
}

/** A datetime-local input value ("2026-10-29T07:00") in a time zone. */
export function toLocalInputIn(value: string | null, timeZone: string): string {
    if (!value) {
        return '';
    }

    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(value));
    const get = (type: string) => parts.find((p) => p.type === type)?.value;

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}
