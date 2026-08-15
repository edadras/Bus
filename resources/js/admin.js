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
    { id: 'occupancy', label: 'شلوغی زنده', permission: 'operations.live_map', icon: 'M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.7 0-6 1.34-6 4v2h7v-2c0-1.1.44-2.2 1.3-3.1A11 11 0 0 0 8 13Zm8 0c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z' },
    { id: 'reports', label: 'گزارش‌ها', permission: 'dashboard.view', icon: 'M3 13h2v8H3v-8Zm4-5h2v13H7V8Zm4-6h2v19h-2V2Zm4 9h2v10h-2V11Zm4-4h2v14h-2V7Z' },
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

    occupancy: {},
    occupancyRows: [],

    reportTab: 'transport',
    report: null,
    reportRange: { from: '', to: '' },
    reportTabs: [
        { id: 'transport', label: 'حمل‌ونقل' },
        { id: 'drivers', label: 'رانندگان' },
        { id: 'passengers', label: 'مسافران' },
        { id: 'revenue', label: 'مالی' },
    ],

    // One generic modal serves every entity; a form is a descriptor, not a
    // template, so adding a field is a one-line change.
    form: {
        open: false,
        title: '',
        hint: '',
        submitLabel: '',
        fields: [],
        data: {},
        errors: {},
        error: null,
        busy: false,
        submit: null,
    },

    _occupancyTimer: null,

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
            occupancy: '/admin/live/occupancy',
            reports: '/admin/reports/transport',
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
                case 'occupancy': await this.loadOccupancy(); break;
                case 'reports': await this.loadReport(); break;
                case 'fleet': await Promise.all([this.loadFleet(), this.loadLines()]); break;
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
            // Carry the bus uuid through: regeneration needs it, and the QR
            // payload itself only identifies the code.
            this.qrModal = { ...data, bus_uuid: bus.uuid };
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

    // ── occupancy (spec 24) ─────────────────────────────────────────────
    async loadOccupancy() {
        const { data, meta } = await api.get('/admin/live/occupancy');

        this.occupancyRows = data;
        this.occupancy = meta;

        clearInterval(this._occupancyTimer);
        this._occupancyTimer = setInterval(async () => {
            if (this.view !== 'occupancy') {
                clearInterval(this._occupancyTimer);

                return;
            }

            try {
                const refreshed = await api.get('/admin/live/occupancy');
                this.occupancyRows = refreshed.data;
                this.occupancy = refreshed.meta;
            } catch {
                // Keep the last known figures rather than blanking the screen.
            }
        }, 10_000);
    },

    crowdingLabel(level) {
        return { full: 'پر', crowded: 'شلوغ', moderate: 'متوسط', light: 'خلوت' }[level] ?? '—';
    },

    // ── reports (spec 25) ───────────────────────────────────────────────
    get transportCards() {
        const r = this.report ?? {};

        return [
            { label: 'تعداد سفر', value: formatNumber(r.trips) },
            { label: 'تعداد سوار شدن', value: formatNumber(r.boardings) },
            { label: 'متوسط مسافر هر سفر', value: formatNumber(r.avg_passengers_per_trip) },
            { label: 'اتوبوس به‌کاررفته', value: formatNumber(r.buses_used) },
            { label: 'راننده فعال', value: formatNumber(r.drivers_used) },
            { label: 'مسافت طی‌شده', value: `${formatNumber(Math.round((r.distance_meters ?? 0) / 1000))} کیلومتر` },
            { label: 'متوسط سرعت', value: r.avg_speed_kmh === null || r.avg_speed_kmh === undefined
                ? '—' : `${formatNumber(r.avg_speed_kmh)} km/h` },
            { label: 'متوسط تأخیر', value: r.punctuality?.avg_delay_minutes === null ||
                r.punctuality?.avg_delay_minutes === undefined
                ? '—' : `${formatNumber(r.punctuality.avg_delay_minutes)} دقیقه` },
        ];
    },

    get passengerCards() {
        const r = this.report ?? {};

        return [
            { label: 'کاربران فعال', value: formatNumber(r.active_users) },
            { label: 'کاربران جدید', value: formatNumber(r.new_users) },
            { label: 'تعداد سفر', value: formatNumber(r.rides) },
            { label: 'متوسط سفر هر کاربر', value: formatNumber(r.avg_rides_per_user) },
        ];
    },

    get frequencyRows() {
        const f = this.report?.frequency ?? {};

        return [
            { label: 'فقط یک سفر', value: f.once ?? 0 },
            { label: '۲ تا ۵ سفر', value: f.occasional ?? 0 },
            { label: '۶ تا ۲۰ سفر', value: f.regular ?? 0 },
            { label: 'بیش از ۲۰ سفر', value: f.frequent ?? 0 },
        ];
    },

    async loadReport() {
        this.report = null;

        const { data } = await api.get(`/admin/reports/${this.reportTab}`, {
            query: { from: this.reportRange.from, to: this.reportRange.to },
        });

        this.report = data;

        if (this.reportTab === 'passengers') {
            this.$nextTick(() => this.renderHourChart(data.by_hour ?? []));
        }
    },

    renderHourChart(rows) {
        // Every hour of the day, so a quiet hour reads as a gap rather than
        // being silently omitted from the axis.
        const byHour = new Map(rows.map((row) => [row.hour, row.rides]));

        this.drawChart('chart-by-hour', {
            type: 'bar',
            data: {
                labels: Array.from({ length: 24 }, (_, hour) => formatNumber(hour)),
                datasets: [{
                    label: 'سفر',
                    data: Array.from({ length: 24 }, (_, hour) => byHour.get(hour) ?? 0),
                    backgroundColor: 'rgba(50,213,131,.6)',
                    borderRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    },

    // ── forms ───────────────────────────────────────────────────────────
    openForm(config) {
        this.form = {
            open: true,
            errors: {},
            error: null,
            busy: false,
            submitLabel: 'ذخیره',
            hint: '',
            ...config,
            data: { ...(config.data ?? {}) },
        };
    },

    closeForm() {
        this.form.open = false;
    },

    async submitForm() {
        this.form.busy = true;
        this.form.errors = {};
        this.form.error = null;

        try {
            await this.form.submit(this.form.data);

            this.form.open = false;
            window.toast?.('با موفقیت ذخیره شد.', 'success');

            await this.load(this.view, { force: true });
        } catch (error) {
            // Field-level messages come back from the server's validator; a
            // non-validation failure is shown once at the foot of the form.
            if (error.isValidation) {
                this.form.errors = error.details ?? {};
                this.form.error = 'برخی فیلدها معتبر نیستند.';
            } else {
                this.form.error = error.message;
            }
        } finally {
            this.form.busy = false;
        }
    },

    openBusForm(bus = null) {
        this.openForm({
            title: bus ? `ویرایش اتوبوس ${bus.bus_number}` : 'افزودن اتوبوس',
            hint: bus ? null : 'با ثبت اتوبوس، یک کد QR اختصاصی به‌صورت خودکار صادر می‌شود.',
            fields: [
                { name: 'bus_number', label: 'شماره اتوبوس', required: !bus, type: 'text' },
                { name: 'plate', label: 'پلاک', type: 'text' },
                { name: 'model', label: 'مدل', type: 'text' },
                { name: 'manufacture_year', label: 'سال ساخت', type: 'number' },
                { name: 'capacity_seated', label: 'ظرفیت نشسته', type: 'number', required: true },
                { name: 'capacity_standing', label: 'ظرفیت ایستاده', type: 'number', required: true },
                { name: 'status', label: 'وضعیت', type: 'select', options: [
                    { value: 'idle', label: 'آماده به کار' },
                    { value: 'active', label: 'فعال' },
                    { value: 'maintenance', label: 'در تعمیرگاه' },
                    { value: 'out_of_service', label: 'خارج از سرویس' },
                ] },
                { name: 'default_line_id', label: 'خط پیش‌فرض', type: 'select',
                  options: this.lines.map((line) => ({ value: line.id, label: `${line.code} — ${line.name}` })) },
                { name: 'has_air_conditioning', label: 'تهویه مطبوع', type: 'checkbox' },
                { name: 'is_accessible', label: 'مناسب معلولان', type: 'checkbox' },
                { name: 'notes', label: 'یادداشت', type: 'textarea', wide: true },
            ],
            data: bus
                ? {
                    plate: bus.plate, model: bus.model,
                    capacity_seated: bus.capacity_seated, capacity_standing: bus.capacity_standing,
                    status: bus.status, has_air_conditioning: bus.has_air_conditioning,
                    is_accessible: bus.is_accessible,
                }
                : { capacity_seated: 30, capacity_standing: 20, status: 'idle', has_air_conditioning: true },
            submit: (data) => bus
                ? api.patch(`/admin/fleet/buses/${bus.uuid}`, data)
                : api.post('/admin/fleet/buses', data),
        });
    },

    openDriverForm() {
        this.openForm({
            title: 'افزودن راننده',
            hint: 'راننده پس از ثبت در وضعیت «در انتظار تأیید» قرار می‌گیرد و تا زمان تأیید نمی‌تواند شیفت باز کند.',
            fields: [
                { name: 'first_name', label: 'نام', required: true },
                { name: 'last_name', label: 'نام خانوادگی', required: true },
                { name: 'mobile', label: 'شماره موبایل', required: true, placeholder: '۰۹۱۲۳۴۵۶۷۸۹' },
                { name: 'national_code', label: 'کد ملی', required: true },
                { name: 'license_number', label: 'شماره گواهینامه', required: true },
                { name: 'license_class', label: 'نوع گواهینامه', placeholder: 'پایه یکم' },
                { name: 'license_expires_at', label: 'تاریخ انقضای گواهینامه', type: 'date' },
                { name: 'employee_code', label: 'کد پرسنلی' },
                { name: 'hired_at', label: 'تاریخ شروع همکاری', type: 'date' },
                { name: 'contract_ends_at', label: 'تاریخ پایان قرارداد', type: 'date' },
                { name: 'notes', label: 'یادداشت', type: 'textarea', wide: true },
            ],
            submit: (data) => api.post('/admin/drivers', data),
        });
    },

    openStopForm() {
        this.openForm({
            title: 'افزودن ایستگاه',
            hint: 'ایستگاه ثبت‌شده از این طریق با برچسب «داده رسمی» ذخیره می‌شود.',
            fields: [
                { name: 'code', label: 'کد ایستگاه', required: true },
                { name: 'name', label: 'نام', required: true },
                { name: 'name_en', label: 'نام لاتین' },
                { name: 'lat', label: 'عرض جغرافیایی', required: true, type: 'number', step: 'any' },
                { name: 'lng', label: 'طول جغرافیایی', required: true, type: 'number', step: 'any' },
                { name: 'geofence_radius', label: 'شعاع تشخیص (متر)', type: 'number',
                  help: 'فاصله‌ای که اتوبوس در آن «رسیده به ایستگاه» شمرده می‌شود.' },
                { name: 'address', label: 'آدرس', wide: true },
                { name: 'is_terminal', label: 'پایانه است', type: 'checkbox' },
                { name: 'is_accessible', label: 'مناسب معلولان', type: 'checkbox' },
                { name: 'has_shelter', label: 'سرپناه دارد', type: 'checkbox' },
                { name: 'description', label: 'توضیحات', type: 'textarea', wide: true },
            ],
            data: { geofence_radius: 60 },
            submit: (data) => api.post('/admin/network/stops', data),
        });
    },

    openLineForm(line = null) {
        this.openForm({
            title: line ? `ویرایش خط ${line.code}` : 'افزودن خط',
            fields: [
                { name: 'code', label: 'کد خط', required: !line },
                { name: 'name', label: 'نام خط', required: true },
                { name: 'color', label: 'رنگ', type: 'color' },
                { name: 'origin_label', label: 'مبدأ' },
                { name: 'destination_label', label: 'مقصد' },
                { name: 'typical_duration_minutes', label: 'زمان تقریبی سفر (دقیقه)', type: 'number' },
                { name: 'headway_minutes', label: 'سرفاصله حرکت (دقیقه)', type: 'number' },
                { name: 'service_start', label: 'شروع سرویس', type: 'time' },
                { name: 'service_end', label: 'پایان سرویس', type: 'time' },
                { name: 'description', label: 'توضیحات', type: 'textarea', wide: true },
            ],
            data: line
                ? {
                    name: line.name, color: line.color,
                    origin_label: line.origin, destination_label: line.destination,
                    typical_duration_minutes: line.typical_duration_minutes,
                    headway_minutes: line.headway_minutes,
                }
                : { color: '#16a34a' },
            submit: (data) => line
                ? api.patch(`/admin/network/lines/${line.id}`, data)
                : api.post('/admin/network/lines', data),
        });
    },

    openMerchantForm() {
        this.openForm({
            title: 'افزودن پذیرنده',
            hint: 'برای مالک، حساب کاربری و کیف پول و یک صندوق پیش‌فرض به‌صورت خودکار ساخته می‌شود.',
            fields: [
                { name: 'name', label: 'نام پذیرنده', required: true },
                { name: 'legal_name', label: 'نام حقوقی' },
                { name: 'type', label: 'نوع', required: true, type: 'select', options: [
                    { value: 'swimming_pool', label: 'استخر' },
                    { value: 'gym', label: 'باشگاه بدنسازی' },
                    { value: 'sports_center', label: 'مجموعه ورزشی' },
                    { value: 'entertainment', label: 'مرکز تفریحی' },
                    { value: 'restaurant', label: 'رستوران' },
                    { value: 'store', label: 'فروشگاه' },
                    { value: 'cinema', label: 'سینما' },
                    { value: 'parking', label: 'پارکینگ' },
                    { value: 'other', label: 'سایر' },
                ] },
                { name: 'owner_first_name', label: 'نام مالک', required: true },
                { name: 'owner_last_name', label: 'نام خانوادگی مالک', required: true },
                { name: 'owner_mobile', label: 'موبایل مالک', required: true },
                { name: 'phone', label: 'تلفن' },
                { name: 'email', label: 'ایمیل', type: 'email' },
                { name: 'commission_bps', label: 'کارمزد (صدم درصد)', type: 'number',
                  help: '۱۵۰ یعنی ۱٫۵ درصد.' },
                { name: 'settlement_cycle', label: 'دوره تسویه', type: 'select', options: [
                    { value: 'daily', label: 'روزانه' },
                    { value: 'weekly', label: 'هفتگی' },
                    { value: 'monthly', label: 'ماهانه' },
                ] },
                { name: 'iban', label: 'شماره شبا' },
                { name: 'bank_account_holder', label: 'صاحب حساب' },
                { name: 'address', label: 'آدرس', wide: true },
            ],
            data: { type: 'other', commission_bps: 150, settlement_cycle: 'weekly' },
            submit: (data) => api.post('/admin/merchants', data),
        });
    },

    openFareRuleForm(rule = null) {
        this.openForm({
            title: rule ? `ویرایش قانون ${rule.code}` : 'افزودن قانون کرایه',
            hint: 'مبالغ به ریال وارد می‌شوند. وقتی چند قانون همزمان صدق کنند، بالاترین اولویت اعمال می‌شود.',
            fields: [
                { name: 'name', label: 'نام قانون', required: true },
                ...(rule ? [] : [{ name: 'code', label: 'کد یکتا', required: true }]),
                ...(rule ? [] : [{ name: 'context', label: 'حوزه', required: true, type: 'select', options: [
                    { value: 'bus', label: 'اتوبوس' },
                    { value: 'merchant', label: 'پذیرنده' },
                ] }]),
                ...(rule ? [] : [{ name: 'passenger_type', label: 'نوع مسافر', type: 'select', options: [
                    { value: 'regular', label: 'عادی' },
                    { value: 'student', label: 'دانش‌آموز/دانشجو' },
                    { value: 'senior', label: 'سالمند' },
                    { value: 'disabled', label: 'جانباز/معلول' },
                    { value: 'child', label: 'کودک' },
                ] }]),
                { name: 'base_fare', label: 'کرایه پایه (ریال)', type: 'number', required: true },
                { name: 'per_km_fare', label: 'کرایه هر کیلومتر (ریال)', type: 'number' },
                { name: 'min_fare', label: 'حداقل کرایه (ریال)', type: 'number' },
                { name: 'max_fare', label: 'حداکثر کرایه (ریال)', type: 'number' },
                { name: 'multiplier', label: 'ضریب', type: 'number', step: '0.01',
                  help: '۰٫۵ یعنی نصف کرایه.' },
                { name: 'priority', label: 'اولویت', type: 'number' },
                ...(rule ? [{ name: 'is_active', label: 'فعال', type: 'checkbox' }] : []),
            ],
            data: rule
                ? {
                    name: rule.name, base_fare: rule.base_fare, per_km_fare: rule.per_km_fare,
                    min_fare: rule.min_fare, max_fare: rule.max_fare,
                    multiplier: rule.multiplier, priority: rule.priority, is_active: rule.is_active,
                }
                : { context: 'bus', passenger_type: 'regular', multiplier: 1, priority: 10, per_km_fare: 0 },
            submit: (data) => rule
                ? api.patch(`/admin/finance/fare-rules/${rule.id}`, data)
                : api.post('/admin/finance/fare-rules', data),
        });
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
