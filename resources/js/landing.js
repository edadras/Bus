/**
 * Landing page behaviour: two live maps and the headline counters.
 *
 * Everything here is progressive — if the API is unreachable the page still
 * renders and reads correctly, it simply shows no buses.
 */

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { api } from './lib/api.js';
import { createMap, busIcon, stopIcon, MarkerLayer } from './lib/map.js';
import { subscribe } from './lib/realtime.js';
import { t } from './lib/i18n.js';

Alpine.plugin(collapse);

Alpine.data('landingStats', () => ({
    stats: { active_buses: 0, lines: 0, stops: 0, passengers_on_board: 0 },
    async load() {
        try {
            const { data } = await api.get('/summary');
            this.stats = data;
        } catch {
            // Counters simply stay at zero; the page is still usable.
        }

        setInterval(async () => {
            try {
                const { data } = await api.get('/summary');
                this.stats = data;
            } catch { /* keep the last known values */ }
        }, 30_000);
    },
}));

Alpine.data('landingLines', () => ({
    lines: [],
    async load() {
        try {
            const { data } = await api.get('/lines');
            this.lines = data;
        } catch {
            this.lines = [];
        }
    },
}));

async function bootMap(elementId, { withStops = false } = {}) {
    const element = document.getElementById(elementId);

    if (!element) return;

    try {
        const { data: config } = await api.get('/map/config');
        const map = await createMap(element, config, { scrollWheelZoom: false });

        if (withStops) {
            const { data: stops } = await api.get('/stops', { query: { limit: 100 } });
            const stopLayer = new MarkerLayer(map, {
                iconFor: (stop) => stopIcon({ isTerminal: stop.is_terminal }),
                popupFor: (stop) => `<strong>${stop.name}</strong><br><span style="color:#9db2b9;font-size:12px">${stop.code}</span>`,
            });
            stopLayer.sync(stops, (stop) => `stop-${stop.id}`);
        }

        const busLayer = new MarkerLayer(map, {
            iconFor: (bus) => busIcon({ heading: bus.heading ?? 0, color: bus.line_color || '#12b76a', label: bus.line_code }),
            popupFor: (bus) => `
                <strong>${t('web.viewer.bus_number', { number: bus.bus_number ?? '' })}</strong><br>
                <span style="color:#9db2b9;font-size:12px">${t('web.viewer.popup_line', { code: bus.line_code ?? '—', destination: bus.destination ?? '' })}</span><br>
                <span style="color:#32d583;font-size:12px">${t('web.viewer.popup_next_stop', { stop: bus.next_stop ?? '—' })}</span>`,
        });

        const refresh = async () => {
            try {
                const { data: buses } = await api.get('/buses/live');
                busLayer.sync(buses, (bus) => bus.trip_uuid);
            } catch { /* leave the last known positions on the map */ }
        };

        await refresh();

        // Prefer the socket; fall back to polling when it is unavailable.
        const live = subscribe(`transit.city.${config.city_id ?? ''}.buses`, {
            'bus.location': refresh,
        });

        setInterval(refresh, live.connected ? 30_000 : 12_000);
    } catch (error) {
        element.innerHTML = `<div style="display:grid;place-items:center;height:100%;color:#6b8892;font-size:14px">
            ${t('web.viewer.map_unavailable')}</div>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    bootMap('hero-map');
    bootMap('live-map', { withStops: true });
});
