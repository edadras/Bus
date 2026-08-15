/**
 * The public web viewer.
 *
 * Deliberately read-only: map, stops and arrival times, with no account and no
 * wallet. Anything that moves money belongs in the native app, which can hold
 * a credential in the platform keystore rather than in browser storage.
 */

import Alpine from 'alpinejs';
import { api } from './lib/api.js';
import { createMap, busIcon, stopIcon, MarkerLayer } from './lib/map.js';
import { subscribe } from './lib/realtime.js';
import { t } from './lib/i18n.js';

Alpine.data('webViewer', () => ({
    buses: [],
    stops: [],
    arrivals: [],
    selectedStopId: null,
    loading: true,

    _map: null,
    _busLayer: null,

    async boot() {
        try {
            const [{ data: config }, { data: stops }] = await Promise.all([
                api.get('/map/config'),
                api.get('/stops', { query: { limit: 100 } }),
            ]);

            this.stops = stops;
            this.selectedStopId = stops[0]?.id ?? null;

            await this.initMap(config, stops);
            await this.loadArrivals();
            await this.refreshBuses();

            const live = subscribe(`transit.city.${config.city_id ?? ''}.buses`, {
                'bus.location': () => this.refreshBuses(),
            });

            setInterval(() => this.refreshBuses(), live.connected ? 25_000 : 12_000);
            setInterval(() => this.loadArrivals(), 25_000);
        } catch {
            // A failed boot leaves the shell rendered and the message visible;
            // nothing here is essential enough to warrant an error screen.
        } finally {
            this.loading = false;
        }
    },

    async initMap(config, stops) {
        const element = document.getElementById('viewer-map');

        if (!element) return;

        this._map = await createMap(element, config);

        const stopLayer = new MarkerLayer(this._map, {
            iconFor: (stop) => stopIcon({ isTerminal: stop.is_terminal }),
            popupFor: (stop) => `<strong>${stop.name}</strong>`,
            onClick: (stop) => {
                this.selectedStopId = stop.id;
                this.loadArrivals();
            },
        });

        stopLayer.sync(stops, (stop) => `stop-${stop.id}`);

        this._busLayer = new MarkerLayer(this._map, {
            iconFor: (bus) => busIcon({
                heading: bus.heading ?? 0,
                color: bus.line_color || '#12b76a',
                label: bus.line_code,
            }),
            popupFor: (bus) => `
                <strong>${t('web.viewer.bus_number', { number: bus.bus_number ?? '—' })}</strong><br>
                <span style="color:#9db2b9;font-size:12px">${t('web.viewer.popup_line', { code: bus.line_code ?? '—', destination: bus.destination ?? '' })}</span>`,
        });
    },

    async refreshBuses() {
        try {
            const { data } = await api.get('/buses/live');
            this.buses = data;
            this._busLayer?.sync(data, (bus) => bus.trip_uuid);
        } catch {
            // Keep the last known positions on the map.
        }
    },

    async loadArrivals() {
        if (!this.selectedStopId) return;

        try {
            const { data } = await api.get(`/stops/${this.selectedStopId}/arrivals`);
            this.arrivals = data;
        } catch {
            this.arrivals = [];
        }
    },
}));
