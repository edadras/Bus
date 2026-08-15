import Alpine from 'alpinejs';
import { api, auth, city, formatMoney, formatNumber, formatMinutes, formatTime, formatDateTime, ApiError } from './lib/api.js';

window.Alpine = Alpine;

// Shared helpers, available to every Alpine component without re-importing.
window.hamsafar = { api, auth, city, formatMoney, formatNumber, formatMinutes, formatTime, formatDateTime, ApiError };

Alpine.magic('money', () => formatMoney);
Alpine.magic('num', () => formatNumber);
Alpine.magic('time', () => formatTime);

/** Toast notifications, driven by `window.dispatchEvent(new CustomEvent('toast', ...))`. */
Alpine.store('toasts', {
    items: [],
    push(message, type = 'info', timeout = 4500) {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        setTimeout(() => this.dismiss(id), timeout);
    },
    dismiss(id) {
        this.items = this.items.filter((item) => item.id !== id);
    },
});

window.addEventListener('toast', (event) => {
    Alpine.store('toasts').push(event.detail.message, event.detail.type);
});

export function toast(message, type = 'info') {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
}

window.toast = toast;

Alpine.start();
