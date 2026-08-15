/**
 * WebSocket subscriptions via Laravel Echo + Reverb.
 *
 * Every live surface degrades to polling rather than breaking: if the socket
 * cannot connect (blocked network, Reverb down), `subscribe` reports the
 * failure and the caller keeps its existing refresh interval. A live map that
 * silently freezes is worse than one that quietly polls.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echo = null;

export function connect() {
    if (echo) return echo;

    const config = window.__REVERB__;

    if (!config?.key) return null;

    echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('hamsafar.token') || ''}`,
                Accept: 'application/json',
            },
        },
    });

    return echo;
}

/**
 * @returns {{stop: () => void, connected: boolean}}
 */
export function subscribe(channel, events, { private: isPrivate = false } = {}) {
    const instance = connect();

    if (!instance) return { stop: () => {}, connected: false };

    try {
        const subscription = isPrivate ? instance.private(channel) : instance.channel(channel);

        Object.entries(events).forEach(([event, handler]) => {
            subscription.listen(`.${event}`, handler);
        });

        return {
            connected: true,
            stop: () => instance.leave(channel),
        };
    } catch (error) {
        console.warn('Realtime subscription failed; falling back to polling.', error);

        return { stop: () => {}, connected: false };
    }
}

export function disconnect() {
    echo?.disconnect();
    echo = null;
}
