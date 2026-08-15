/**
 * Translation for the browser surfaces.
 *
 * The dictionary is rendered into the page by Blade rather than fetched, so
 * the first paint is already in the right language — a screen that flashes
 * dotted keys before its strings arrive is worse than no translation at all.
 *
 * Keys match the server's exactly (`admin.fleet.title`, `web.viewer.minutes`),
 * so a string can move between a Blade view and a JS component without being
 * renamed, and the parity test that guards `lang/fa` against `lang/en` guards
 * these too.
 */

const dictionary = window.__I18N__ || {};

function lookup(key) {
    return key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dictionary);
}

/**
 * Translate a key, substituting `:name` placeholders.
 *
 * A missing key returns the key itself — visible in development, and never a
 * blank space in the interface where a label should be.
 *
 * @param {string} key
 * @param {Record<string, string|number>} [replacements]
 */
export function t(key, replacements = {}) {
    const value = lookup(key);

    if (typeof value !== 'string') return key;

    return Object.entries(replacements).reduce(
        (text, [name, replacement]) => text.replaceAll(`:${name}`, String(replacement)),
        value,
    );
}

/** Choose between a singular and a plural form separated by `|`. */
export function tChoice(key, count, replacements = {}) {
    const value = lookup(key);

    if (typeof value !== 'string') return key;

    const [one, many] = value.split('|');

    return t(key, { ...replacements, count }) === key
        ? key
        : (count === 1 ? one : (many ?? one)).replaceAll(':count', String(count));
}

/** Every key under a prefix, for components that render a whole group. */
export function tGroup(prefix) {
    return lookup(prefix) ?? {};
}

export const locale = document.documentElement.lang || 'fa';

export const isRtl = (document.documentElement.dir || 'rtl') === 'rtl';
