/**
 * Thin API client shared by every browser surface.
 *
 * It exists so that the envelope, the city header, the auth token and error
 * shape are handled in exactly one place: each screen then deals in data and
 * in a single ApiError type, never in transport details.
 */

const TOKEN_KEY = 'hamsafar.token';
const CITY_KEY = 'hamsafar.city';

export class ApiError extends Error {
    constructor(code, message, status, details = null) {
        super(message || code);
        this.code = code;
        this.status = status;
        this.details = details;
    }

    /** True when re-authenticating would plausibly fix this. */
    get isAuthFailure() {
        return this.status === 401 || this.code === 'unauthenticated';
    }

    get isValidation() {
        return this.code === 'validation_failed';
    }
}

export const auth = {
    get token() {
        return localStorage.getItem(TOKEN_KEY);
    },
    set token(value) {
        value ? localStorage.setItem(TOKEN_KEY, value) : localStorage.removeItem(TOKEN_KEY);
    },
    get isSignedIn() {
        return Boolean(this.token);
    },
    clear() {
        localStorage.removeItem(TOKEN_KEY);
    },
};

export const city = {
    get slug() {
        return localStorage.getItem(CITY_KEY) || document.documentElement.dataset.city || 'bandar-abbas';
    },
    set slug(value) {
        localStorage.setItem(CITY_KEY, value);
    },
};

async function request(method, path, { body, query, signal, headers = {} } = {}) {
    const url = new URL(`/api/v1${path}`, window.location.origin);

    if (query) {
        Object.entries(query)
            .filter(([, value]) => value !== undefined && value !== null && value !== '')
            .forEach(([key, value]) => url.searchParams.set(key, value));
    }

    const isForm = body instanceof FormData;

    const response = await fetch(url, {
        method,
        signal,
        headers: {
            Accept: 'application/json',
            'X-City': city.slug,
            ...(isForm ? {} : body ? { 'Content-Type': 'application/json' } : {}),
            ...(auth.token ? { Authorization: `Bearer ${auth.token}` } : {}),
            ...headers,
        },
        body: isForm ? body : body ? JSON.stringify(body) : undefined,
    });

    if (response.status === 204) return null;

    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new ApiError('server_error', 'پاسخ سرور قابل خواندن نبود.', response.status);
    }

    if (!response.ok || payload.success === false) {
        const error = payload.error || {};
        const apiError = new ApiError(
            error.code || 'server_error',
            error.message,
            response.status,
            error.details,
        );

        // A dead token is not worth propagating to every caller; drop it here
        // so the next screen render sees a signed-out state.
        if (apiError.isAuthFailure) auth.clear();

        throw apiError;
    }

    return { data: payload.data, meta: payload.meta ?? null };
}

export const api = {
    get: (path, options) => request('GET', path, options),
    post: (path, body, options) => request('POST', path, { ...options, body }),
    patch: (path, body, options) => request('PATCH', path, { ...options, body }),
    delete: (path, options) => request('DELETE', path, options),
};

/** Persian-digit money formatting, matching the server's display unit. */
export function formatMoney(minorUnits, { withSuffix = true } = {}) {
    if (minorUnits === null || minorUnits === undefined) return '—';

    const unit = document.documentElement.dataset.displayUnit || 'toman';
    const value = unit === 'toman' ? Math.trunc(minorUnits / 10) : minorUnits;
    const formatted = new Intl.NumberFormat('fa-IR').format(value);

    return withSuffix ? `${formatted} ${unit === 'toman' ? 'تومان' : 'ریال'}` : formatted;
}

export function formatNumber(value) {
    return new Intl.NumberFormat('fa-IR').format(value ?? 0);
}

export function formatMinutes(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return 'کمتر از یک دقیقه';

    return `${formatNumber(Math.round(seconds / 60))} دقیقه`;
}

export function formatTime(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

export function formatDateTime(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('fa-IR', {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}
