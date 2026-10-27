/**
 * GET a JSON endpoint with nested query params (items[0][product_id]=1).
 */
export async function getJson<T>(
    url: string,
    params: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<T> {
    const search = new URLSearchParams();

    const add = (key: string, value: unknown) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((v, i) => add(`${key}[${i}]`, v));
        } else if (typeof value === 'object') {
            Object.entries(value as Record<string, unknown>).forEach(([k, v]) =>
                add(`${key}[${k}]`, v),
            );
        } else if (typeof value === 'boolean') {
            search.append(key, value ? '1' : '0');
        } else {
            search.append(key, String(value as string | number | bigint));
        }
    };

    Object.entries(params).forEach(([k, v]) => add(k, v));

    const response = await fetch(`${url}?${search.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return (await response.json()) as T;
}

/** Laravel's CSRF header, from the XSRF-TOKEN cookie. */
function xsrfHeader(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

/**
 * POST JSON in the background (no page visit), with Laravel's CSRF cookie.
 */
export async function postJson(
    url: string,
    body: Record<string, unknown>,
): Promise<Response> {
    return sendJson('POST', url, body);
}

/**
 * Send JSON in the background with any method (no page visit), with
 * Laravel's CSRF cookie.
 */
export async function sendJson(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body: Record<string, unknown>,
): Promise<Response> {
    return fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...xsrfHeader(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        keepalive: true,
    });
}
