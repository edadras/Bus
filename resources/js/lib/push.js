/**
 * Web Push enrolment for the browser surfaces.
 *
 * Two rules shape this module:
 *
 *  1. The permission prompt is only ever raised from a real user gesture
 *     (`enablePush()`), never on page load. A prompt fired at a rider who has
 *     not asked for alerts is usually answered with "block", and a blocked
 *     origin can never be asked again.
 *  2. Everything degrades to a no-op. An unsupported browser, a deployment
 *     with no VAPID keys, a denied permission — each returns a status string
 *     rather than throwing, so no screen has to guard its own calls.
 */

import { api, auth } from './api.js';

const SUPPORTED = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/** VAPID keys travel as base64url; the subscribe() call wants raw bytes. */
function urlBase64ToUint8Array(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const raw = window.atob(padded);
    const output = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);

    return output;
}

function encodeKey(subscription, name) {
    const key = subscription.getKey(name);

    return key ? window.btoa(String.fromCharCode(...new Uint8Array(key))) : null;
}

export function pushSupported() {
    return SUPPORTED;
}

export function pushPermission() {
    return SUPPORTED ? Notification.permission : 'unsupported';
}

/**
 * Ask for permission, subscribe, and register the subscription server-side.
 *
 * @returns {Promise<'enabled'|'unsupported'|'denied'|'not-configured'|'signed-out'|'failed'>}
 */
export async function enablePush() {
    if (!SUPPORTED) return 'unsupported';
    if (!auth.isSignedIn) return 'signed-out';

    let vapidKey;
    try {
        const { data } = await api.get('/push/key');

        if (!data?.enabled || !data?.vapid_public_key) return 'not-configured';

        vapidKey = data.vapid_public_key;
    } catch {
        return 'failed';
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') return 'denied';

    try {
        const registration = await navigator.serviceWorker.ready;

        // An existing subscription is reused: re-subscribing would mint a new
        // endpoint and orphan the one the server already knows about.
        const subscription =
            (await registration.pushManager.getSubscription()) ||
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            }));

        await api.post('/push/subscriptions', {
            endpoint: subscription.endpoint,
            keys: {
                p256dh: encodeKey(subscription, 'p256dh'),
                auth: encodeKey(subscription, 'auth'),
            },
            platform: 'web',
            device_name: navigator.userAgent.slice(0, 120),
        });

        return 'enabled';
    } catch {
        return 'failed';
    }
}

/** Unsubscribe this device and forget it server-side. */
export async function disablePush() {
    if (!SUPPORTED) return 'unsupported';

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) return 'disabled';

        const { endpoint } = subscription;
        await subscription.unsubscribe();

        // Best effort: the local subscription is already gone, and a stale row
        // is cleaned up by the server the first time it answers 410.
        try {
            await api.delete(`/push/subscriptions?endpoint=${encodeURIComponent(endpoint)}`);
        } catch {
            /* ignore */
        }

        return 'disabled';
    } catch {
        return 'failed';
    }
}

/**
 * Re-register a subscription the browser already granted — endpoints rotate,
 * and a signed-in device that was enrolled on another session needs its row
 * back. Silent by design: it never prompts.
 */
export async function syncPush() {
    if (!SUPPORTED || Notification.permission !== 'granted' || !auth.isSignedIn) return;

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) return;

        await api.post('/push/subscriptions', {
            endpoint: subscription.endpoint,
            keys: {
                p256dh: encodeKey(subscription, 'p256dh'),
                auth: encodeKey(subscription, 'auth'),
            },
            platform: 'web',
            device_name: navigator.userAgent.slice(0, 120),
        });
    } catch {
        /* A failed refresh is harmless; the next enable() fixes it. */
    }
}
