/**
 * The admin panel.
 *
 * A single Alpine component drives the whole shell: navigation is client-side
 * (the URL is kept in sync so a page can be bookmarked and reloaded), and every
 * screen loads only when it becomes visible, so opening the panel does not
 * fetch seven datasets at once.
 *
 * Navigation entries are filtered by the signed-in user's permissions, which
 * come from the API — the sidebar is a mirror of server-side authorisation,
 * never the thing enforcing it.
 */

import Alpine from 'alpinejs';
import { Chart, registerables } from 'chart.js';
import QRCode from 'qrcode';
import { api, auth, formatNumber } from './lib/api.js';
import { createMap, busIcon, MarkerLayer } from './lib/map.js';
import { subscribe } from './lib/realtime.js';

Chart.register(...registerables);

// Chart defaults matching the design system, set once.
Chart.defaults.font.family = 'Vazirmatn, sans-serif';
Chart.defaults.color = '#6b8892';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.usePointStyle = true;

const NAV = [
    { id: 'dashboard', label: 'داشبورد', permission: 'dashboard.view', icon: 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z' },
    { id: 'live', label: 'نقشه زنده', permission: 'operations.live_map', icon: 'M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z' },
    { id: 'fleet', label: 'ناوگان', permission: 'fleet.manage', icon: 'M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z' },
    { id: 'drivers', label: 'رانندگان', permission: 'drivers.manage', icon: 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5Z' },
    { id: 'network', label: 'خطوط و ایستگاه‌ها', permission: 'network.manage', icon: 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h10v2H4v-2Z' },
    { id: 'finance', label: 'مالی', permission: 'finance.manage', icon: 'M3 7a3 3 0 0 1 3-3h11a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H6a3 3 0 0 1-3-3V7Zm14 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z' },
    { id: 'merchants', label: 'پذیرندگان', permission: 'merchants.manage', icon: 'M4 4h16l-1 5H5L4 4Zm1 7h14v9H5v-9Zm3 2v5h8v-5H8Z' },
    { id: 'complaints', label: 'شکایات', permission: 'support.manage', icon: 'M12 2 2 7l10 5 10-5-10-5Zm0 20-4-2v-6l4 2 4-2v6l-4 2Z' },
];

Alpine.data('adminShell', () => ({
    // ── state ───────────────────────────────────────────────────────────
    view: 'dashboard',
    sidebarOpen: false,
    loading: false,
    busy: false,
    realtimeConnected: false,

    user: {},
    city: {},
    permissions: [],

    kpis: {},
    range: 'week',
    ranges: [
        { value: 'today', label: 'امروز' },
        { value: 'week', label: 'هفته' },
        { value: 'month', label: 'ماه' },
        { value: 'year', label: 'سال' },
    ],
    busiestLines: [],
    busiestStops: [],

    liveBuses: [],
    busFilter: '',

    buses: [],
    drivers: [],
    lines: [],
    stops: [],
    merchants: [],
    complaints: [],
    selectedComplaint: null,
    financeSummary: [],
    fareRules: [],
    settlements: [],

    filters: {
        fleet: { q: '', status: '' },
        drivers: { q: '', status: '' },
        stops: { q: '' },
        merchants: { q: '', status: '' },
        complaints: { status: '', mine: false },
    },

    reply: { body: '', internal: false },

    qrModal: null,
    qrCountdown: 0,

    _charts: {},
    _map: null,
    _busLayer: null,
    _live: null,
    _qrTimer: null,

    // ── lifecycle ───────────────────────────────────────────────────────
    get visibleNav() {
        return NAV.filter((item) => this.can(item.permission));
    },

    get currentTitle() {
        return NAV.find((item) => item.id === this.view)?.label ?? '';
    },

    get kpiCards() {
        const k = this.kpis;

        return [
            { label: 'اتوبوس در حال حرکت', value: formatNumber(k.buses_moving), tone: 'brand-500', live: true, icon: NAV[2].icon, caption: `از ${formatNumber(k.total_buses)} اتوبوس` },
            { label: 'مسافر داخل اتوبوس‌ها', value: formatNumber(k.passengers_on_board), tone: 'brand-500', live: true, icon: NAV[3].icon },
            { label: 'سفر امروز', value: formatNumber(k.trips_today), tone: 'white/5', icon: NAV[4].icon },
            { label: 'سوار شدن امروز', value: formatNumber(k.boardings_today), tone: 'white/5', icon: NAV[3].icon, caption: `${formatNumber(k.unique_passengers_today)} مسافر یکتا` },
            { label: 'راننده فعال', value: formatNumber(k.active_drivers), tone: 'white/5', icon: NAV[3].icon, caption: `${formatNumber(k.drivers_on_shift)} در شیفت` },
            { label: 'درآمد کرایه امروز', value: this.$money(k.fare_revenue_today), tone: 'brand-500', icon: NAV[5].icon },
            { label: 'شارژ کیف پول امروز', value: this.$money(k.topup_amount_today), tone: 'white/5', icon: NAV[5].icon },
            { label: 'شکایت باز', value: formatNumber(k.open_complaints), tone: 'white/5', icon: NAV[7].icon, caption: `${formatNumber(k.complaints_today)} امروز` },
        ];
    },

    get filteredBuses() {
        const term = this.busFilter.trim();

        if (!term) return this.liveBuses;

        return this.liveBuses.filter((bus) =>
            String(bus.bus_number ?? '').includes(term) || String(bus.line_code ?? '').includes(term));
    },

    can(permission) {
        return this.permissions.includes('*') || this.permissions.includes(permission);
    },

    async boot() {
        if (!auth.isSignedIn) {
            window.location.href = '/admin/login';

            return;
        }

        try {
            const { data } = await api.get('/auth/me');
            this.user = data.user;
            this.city = data.user.city ?? {};
            // The server sends the role names; permissions are derived from the
            // endpoints that actually answer, so an unauthorised tab is simply
            // absent rather than present-and-broken.
            this.permissions = await this.resolvePermissions();
        } catch {
            auth.clear();
            window.location.href = '/admin/login';

            return;
        }

        // Restore the view from the URL so a reload keeps the operator in place.
        const path = window.location.pathname.replace('/admin', '').replace(/\//g, '');
        if (path && NAV.some((item) => item.id === path)) this.view = path;

        if (!this.can(NAV.find((item) => item.id === this.view)?.permission)) {
            this.view = this.visibleNav[0]?.id ?? 'dashboard';
        }

        this.$watch('view', (value) => {
            window.history.replaceState({}, '', `/admin/${value}`);
            this.sidebarOpen = false;
            this.load(value);
        });

        await this.load(this.view);
    },

    /**
     * Probe which admin areas this user can reach. Cheaper and more honest
     * than duplicating the role→permission map in the client: whatever the
     * server authorises is what the sidebar shows.
     */
    async resolvePermissions() {
        const roles = this.user.roles ?? [];

        if (roles.includes('super_admin')) return ['*'];

        const checks = NAV.map(async (item) => {
            try {
                await api.get(this.probeEndpoint(item.id), { query: { per_page: 1 } });

                return item.permission;
            } catch (error) {
                return error.status === 403 ? null : item.permission;
            }
        });

        return (await Promise.all(checks)).filter(Boolean);
    },

    probeEndpoint(view) {
        return {
            dashboard: '/admin/dashboard/kpis',
            live: '/admin/live/map',
            fleet: '/admin/fleet/buses',
            drivers: '/admin/drivers',
            network: '/admin/finance/fare-rules',
            finance: '/admin/finance/summary',
            merchants: '/admin/merchants',
            complaints: '/admin/complaints',
        }[view];
    },

    go(view) {
        this.view = view;
    },

    async refresh() {
        await this.load(this.view, { force: true });
    },

    async load(view, { force = false } = {}) {
        this.loading = true;

        try {
            switch (view) {
                case 'dashboard': await Promise.all([this.loadKpis(), this.loadCharts()]); break;
                case 'live': await this.initLiveMap(); break;
                case 'fleet': await this.loadFleet(); break;
                case 'drivers': await this.loadDrivers(); break;
                case 'network': await Promise.all([this.loadLines(), this.loadStops()]); break;
                case 'finance': await Promise.all([this.loadFinance(), this.loadFareRules(), this.loadSettlements()]); break;
                case 'merchants': await this.loadMerchants(); break;
                case 'complaints': await this.loadComplaints(); break;
            }
        } catch (error) {
            window.toast?.(error.message, 'error');
        } finally {
            this.loading = false;
        }
    },

    // ── dashboard ───────────────────────────────────────────────────────
    async loadKpis() {
        const { data } = await api.get('/admin/dashboard/kpis');
        this.kpis = data;
    },

    async loadCharts() {
        const { data } = await api.get('/admin/dashboard/charts', { query: { range: this.range } });

        this.busiestLines = data.lines?.busiest ?? [];
        this.busiestStops = data.stops ?? [];

        this.$nextTick(() => this.renderCharts(data.series ?? []));
    },

    renderCharts(series) {
        const labels = series.map((row) => new Intl.DateTimeFormat('fa-IR', {
            month: 'short', day: 'numeric',
        }).format(new Date(row.date)));

        this.drawChart('chart-trips', {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'سفر',
                        data: series.map((row) => row.trips),
                        borderColor: '#32d583',
                        backgroundColor: 'rgba(50,213,131,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'مسافر',
                        data: series.map((row) => row.boardings),
                        borderColor: '#2e90fa',
                        backgroundColor: 'rgba(46,144,250,.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });

        this.drawChart('chart-revenue', {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'کرایه',
                    // The API returns rial; the chart shows toman, like the rest
                    // of the interface.
                    data: series.map((row) => Math.trunc(row.fare_revenue / 10)),
                    backgroundColor: 'rgba(18,183,106,.65)',
                    borderRadius: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } },
            },
        });
    },

    drawChart(id, config) {
        const canvas = document.getElementById(id);

        if (!canvas) return;

        // Chart.js leaks its canvas if an old instance is left attached.
        this._charts[id]?.destroy();
        this._charts[id] = new Chart(canvas, config);
    },

    // ── live map ────────────────────────────────────────────────────────
    async initLiveMap() {
        const { data: buses } = await api.get('/admin/live/map');
        this.liveBuses = buses;

        if (this._map) {
            this._busLayer.sync(this.liveBuses, (bus) => bus.trip_uuid);

            return;
        }

        this.$nextTick(async () => {
            const element = document.getElementById('admin-live-map');

            if (!element) return;

            const { data: config } = await api.get('/map/config');
            this._map = await createMap(element, config);

            this._busLayer = new MarkerLayer(this._map, {
                iconFor: (bus) => busIcon({
                    heading: bus.heading ?? 0,
                    color: bus.line_color || '#12b76a',
                    label: bus.line_code,
                    stale: bus.is_off_route,
                }),
                popupFor: (bus) => `
                    <strong>اتوبوس ${bus.bus_number ?? '—'}</strong><br>
                    <span style="color:#9db2b9;font-size:12px">راننده: ${bus.driver_name ?? '—'}</span><br>
                    <span style="color:#9db2b9;font-size:12px">خط ${bus.line_code ?? '—'} — ${bus.destination ?? ''}</span><br>
                    <span style="color:#32d583;font-size:12px">${bus.passenger_count ?? 0} مسافر · ${Math.round(bus.speed ?? 0)} km/h</span><br>
                    <span style="color:#9db2b9;font-size:12px">ایستگاه بعدی: ${bus.next_stop ?? '—'}</span>`,
            });

            this._busLayer.sync(this.liveBuses, (bus) => bus.trip_uuid);

            // Prefer the socket; keep a slow poll as the safety net.
            this._live = subscribe(`transit.city.${this.city.id ?? ''}.buses`, {
                'bus.location': () => this.refreshLiveBuses(),
                'passenger.boarded': () => this.refreshLiveBuses(),
            });

            this.realtimeConnected = this._live.connected;

            setInterval(() => this.refreshLiveBuses(), this._live.connected ? 25_000 : 10_000);
        });
    },

    async refreshLiveBuses() {
        try {
            const { data } = await api.get('/admin/live/map');
            this.liveBuses = data;
            this._busLayer?.sync(data, (bus) => bus.trip_uuid);
        } catch {
            // Keep the last known fleet on screen rather than blanking it.
        }
    },

    focusBus(bus) {
        this._map?.setView([bus.lat, bus.lng], 16);
    },

    // ── fleet ───────────────────────────────────────────────────────────
    async loadFleet() {
        const { data } = await api.get('/admin/fleet/buses', { query: this.filters.fleet });
        this.buses = data;
    },

    async showQr(bus) {
        try {
            const { data } = await api.get(`/admin/fleet/buses/${bus.uuid}/qr`);
            this.qrModal = data;
            this.qrCountdown = data.current.expires_in;

            this.$nextTick(() => this.renderQr(data.current.token));
            this.startQrRotation(bus.uuid);
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    renderQr(token) {
        const canvas = document.getElementById('qr-canvas');

        if (!canvas) return;

        QRCode.toCanvas(canvas, token, { width: 224, margin: 1 });
    },

    /** The displayed token is short-lived, so the modal refreshes it in place. */
    startQrRotation(busUuid) {
        clearInterval(this._qrTimer);

        this._qrTimer = setInterval(async () => {
            if (!this.qrModal) {
                clearInterval(this._qrTimer);

                return;
            }

            this.qrCountdown -= 1;

            if (this.qrCountdown > 0) return;

            try {
                const { data } = await api.get(`/admin/fleet/buses/${busUuid}/qr`);
                this.qrCountdown = data.current.expires_in;
                this.renderQr(data.current.token);
            } catch {
                clearInterval(this._qrTimer);
            }
        }, 1000);
    },

    async regenerateQr() {
        if (!confirm('کد فعلی باطل می‌شود و برچسب نصب‌شده در اتوبوس دیگر کار نخواهد کرد. ادامه می‌دهید؟')) {
            return;
        }

        const reason = prompt('دلیل ابطال کد:');

        if (!reason) return;

        try {
            await api.post(`/admin/fleet/buses/${this.qrModal.bus_uuid ?? ''}/qr/regenerate`, { reason });
            window.toast?.('کد جدید صادر شد. برچسب تازه را چاپ و نصب کنید.', 'success');
            this.qrModal = null;
            await this.loadFleet();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── drivers ─────────────────────────────────────────────────────────
    async loadDrivers() {
        const { data } = await api.get('/admin/drivers', { query: this.filters.drivers });
        this.drivers = data;
    },

    async changeDriverStatus(driver, status) {
        const reason = status === 'suspended' ? prompt('دلیل تعلیق:') : null;

        if (status === 'suspended' && !reason) return;

        try {
            await api.post(`/admin/drivers/${driver.uuid}/status`, { status, reason });
            window.toast?.('وضعیت راننده به‌روزرسانی شد.', 'success');
            await this.loadDrivers();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── network ─────────────────────────────────────────────────────────
    async loadLines() {
        const { data } = await api.get('/lines');
        this.lines = data;
    },

    async loadStops() {
        const { data } = await api.get('/stops', { query: { ...this.filters.stops, limit: 100 } });
        this.stops = data;
    },

    // ── finance ─────────────────────────────────────────────────────────
    async loadFinance() {
        const { data } = await api.get('/admin/finance/summary');
        this.financeSummary = data.by_type ?? [];
    },

    async loadFareRules() {
        const { data } = await api.get('/admin/finance/fare-rules');
        this.fareRules = data;
    },

    async loadSettlements() {
        const { data } = await api.get('/admin/finance/settlements');
        this.settlements = data;
    },

    async approveSettlement(settlement) {
        if (!confirm(`تسویه ${settlement.reference} تأیید و از کیف پول پذیرنده کسر شود؟`)) return;

        try {
            await api.post(`/admin/finance/settlements/${settlement.uuid}/approve`);
            window.toast?.('تسویه تأیید شد.', 'success');
            await this.loadSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async paySettlement(settlement) {
        const reference = prompt('شماره پیگیری انتقال بانکی:');

        if (!reference) return;

        try {
            await api.post(`/admin/finance/settlements/${settlement.uuid}/pay`, { payment_reference: reference });
            window.toast?.('پرداخت ثبت شد.', 'success');
            await this.loadSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── merchants ───────────────────────────────────────────────────────
    async loadMerchants() {
        const { data } = await api.get('/admin/merchants', { query: this.filters.merchants });
        this.merchants = data;
    },

    async changeMerchantStatus(merchant, status) {
        try {
            await api.post(`/admin/merchants/${merchant.uuid}/status`, { status });
            window.toast?.('وضعیت پذیرنده به‌روزرسانی شد.', 'success');
            await this.loadMerchants();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── complaints ──────────────────────────────────────────────────────
    async loadComplaints() {
        const { data } = await api.get('/admin/complaints', {
            query: {
                status: this.filters.complaints.status,
                mine: this.filters.complaints.mine ? 1 : undefined,
            },
        });

        this.complaints = data;
    },

    async openComplaint(complaint) {
        try {
            const { data } = await api.get(`/admin/complaints/${complaint.uuid}`);
            this.selectedComplaint = { ...data, uuid: complaint.uuid };
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async replyToComplaint() {
        if (!this.reply.body.trim()) return;

        this.busy = true;

        try {
            await api.post(`/admin/complaints/${this.selectedComplaint.uuid}/reply`, {
                body: this.reply.body,
                internal: this.reply.internal,
            });

            this.reply.body = '';
            await this.openComplaint(this.selectedComplaint);
            window.toast?.('پاسخ ارسال شد.', 'success');
        } catch (error) {
            window.toast?.(error.message, 'error');
        } finally {
            this.busy = false;
        }
    },

    async changeComplaintStatus(status) {
        try {
            await api.post(`/admin/complaints/${this.selectedComplaint.uuid}/status`, { status });
            await this.openComplaint(this.selectedComplaint);
            await this.loadComplaints();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── session ─────────────────────────────────────────────────────────
    async signOut() {
        try {
            await api.post('/auth/logout');
        } catch {
            // The local session ends regardless.
        }

        auth.clear();
        window.location.href = '/admin/login';
    },
}));
