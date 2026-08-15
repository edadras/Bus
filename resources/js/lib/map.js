/**
 * Leaflet helpers.
 *
 * The tile provider is never hard coded here: configuration comes from
 * /api/v1/map/config, which the server derives from the MapProvider binding.
 * Swapping OSM for Mapbox or a domestic provider is a config change.
 */

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export async function createMap(element, config, options = {}) {
    const map = L.map(element, {
        zoomControl: false,
        attributionControl: true,
        ...options,
    }).setView([config.center.lat, config.center.lng], config.zoom);

    L.tileLayer(config.provider.tile_url, {
        attribution: config.provider.attribution,
        maxZoom: config.provider.max_zoom,
        ...(config.provider.token ? { accessToken: config.provider.token } : {}),
    }).addTo(map);

    // In RTL the zoom control belongs on the left, away from the thumb.
    L.control.zoom({ position: 'bottomleft' }).addTo(map);

    return map;
}

/** A bus marker that rotates to its heading and pulses while live. */
export function busIcon({ heading = 0, color = '#12b76a', label = '', stale = false } = {}) {
    return L.divIcon({
        className: 'bus-marker',
        iconSize: [38, 38],
        iconAnchor: [19, 19],
        html: `
            <div style="position:relative;width:38px;height:38px;opacity:${stale ? 0.45 : 1}">
              <div style="position:absolute;inset:0;border-radius:50%;background:${color};opacity:.22;${stale ? '' : 'animation:ping 2.2s cubic-bezier(0,0,.2,1) infinite'}"></div>
              <div style="position:absolute;inset:6px;border-radius:50%;background:${color};
                          border:2px solid rgba(255,255,255,.85);display:flex;align-items:center;
                          justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.5);
                          transform:rotate(${heading}deg);transition:transform .5s ease">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="#fff" style="transform:rotate(-${heading}deg)">
                  <path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/>
                </svg>
              </div>
              ${label ? `<div style="position:absolute;top:-9px;inset-inline-start:50%;transform:translateX(50%);
                    background:rgba(5,9,11,.9);color:#fff;font-size:10px;font-weight:700;
                    padding:1px 6px;border-radius:999px;white-space:nowrap;border:1px solid rgba(255,255,255,.15)">${label}</div>` : ''}
            </div>`,
    });
}

export function stopIcon({ isTerminal = false } = {}) {
    const size = isTerminal ? 16 : 12;

    return L.divIcon({
        className: 'stop-marker',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        html: `<div style="width:${size}px;height:${size}px;border-radius:50%;
                    background:${isTerminal ? '#32d583' : '#0e171b'};
                    border:2px solid ${isTerminal ? '#fff' : '#32d583'};
                    box-shadow:0 2px 6px rgba(0,0,0,.5)"></div>`,
    });
}

export function userIcon() {
    return L.divIcon({
        className: 'user-marker',
        iconSize: [18, 18],
        iconAnchor: [9, 9],
        html: `<div style="width:18px;height:18px;border-radius:50%;background:#2e90fa;
                    border:3px solid #fff;box-shadow:0 0 0 6px rgba(46,144,250,.25)"></div>`,
    });
}

/**
 * Keeps a set of markers in sync with a keyed collection, moving existing
 * markers rather than recreating them — recreating makes the map flicker and
 * throws away Leaflet's animation state on every refresh.
 */
export class MarkerLayer {
    constructor(map, { iconFor, popupFor, onClick } = {}) {
        this.map = map;
        this.markers = new Map();
        this.iconFor = iconFor;
        this.popupFor = popupFor;
        this.onClick = onClick;
    }

    sync(items, keyFn) {
        const seen = new Set();

        items.forEach((item) => {
            const key = keyFn(item);
            seen.add(key);

            const position = [item.lat, item.lng];
            let marker = this.markers.get(key);

            if (marker) {
                marker.setLatLng(position);
                if (this.iconFor) marker.setIcon(this.iconFor(item));
            } else {
                marker = L.marker(position, this.iconFor ? { icon: this.iconFor(item) } : {});
                if (this.onClick) marker.on('click', () => this.onClick(item));
                marker.addTo(this.map);
                this.markers.set(key, marker);
            }

            if (this.popupFor) marker.bindPopup(this.popupFor(item));
        });

        // Anything absent from this refresh has stopped reporting.
        for (const [key, marker] of this.markers) {
            if (!seen.has(key)) {
                this.map.removeLayer(marker);
                this.markers.delete(key);
            }
        }
    }

    clear() {
        this.markers.forEach((marker) => this.map.removeLayer(marker));
        this.markers.clear();
    }
}

export function drawRoute(map, geometry, color = '#12b76a') {
    return L.polyline(
        geometry.map((point) => [point.lat, point.lng]),
        { color, weight: 4, opacity: 0.75, lineJoin: 'round' },
    ).addTo(map);
}

export { L };
