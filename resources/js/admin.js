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
import { api, auth, formatNumber, formatChartDate } from './lib/api.js';
import { createMap, busIcon, taxiIcon, vanIcon, MarkerLayer } from './lib/map.js';
import { subscribe } from './lib/realtime.js';
import { t } from './lib/i18n.js';

Chart.register(...registerables);

// Chart defaults matching the design system, set once.
Chart.defaults.font.family = 'Vazirmatn, sans-serif';
Chart.defaults.color = '#6b8892';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.usePointStyle = true;

const NAV = [
    { id: 'dashboard', label: t('admin.nav.dashboard'), permission: 'dashboard.view', icon: 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z' },
    { id: 'live', label: t('admin.nav.live'), permission: 'operations.live_map', icon: 'M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z' },
    { id: 'occupancy', label: t('admin.nav.occupancy'), permission: 'operations.live_map', icon: 'M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.7 0-6 1.34-6 4v2h7v-2c0-1.1.44-2.2 1.3-3.1A11 11 0 0 0 8 13Zm8 0c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z' },
    { id: 'reports', label: t('admin.nav.reports'), permission: 'dashboard.view', icon: 'M3 13h2v8H3v-8Zm4-5h2v13H7V8Zm4-6h2v19h-2V2Zm4 9h2v10h-2V11Zm4-4h2v14h-2V7Z' },
    { id: 'fleet', label: t('admin.nav.fleet'), permission: 'fleet.manage', icon: 'M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z' },
    { id: 'drivers', label: t('admin.nav.drivers'), permission: 'drivers.manage', icon: 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5Z' },
    { id: 'taxis', label: t('admin.nav.taxis'), permission: 'taxi.manage', icon: 'M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11h1v7h-2v2h-2v-2H8v2H6v-2H4v-7h1Zm2.2 0h9.6l-1-3H8.2l-1 3ZM7 13.5A1.5 1.5 0 1 0 7 16.5a1.5 1.5 0 0 0 0-3Zm10 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z' },
    { id: 'school', label: t('admin.nav.school'), permission: 'school.admin', altPermission: 'school.manage', icon: 'M12 3 1 9l11 6 9-4.91V17h2V9L12 3ZM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82Z' },
    { id: 'network', label: t('admin.nav.network'), permission: 'network.manage', icon: 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h10v2H4v-2Z' },
    { id: 'finance', label: t('admin.nav.finance'), permission: 'finance.manage', icon: 'M3 7a3 3 0 0 1 3-3h11a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H6a3 3 0 0 1-3-3V7Zm14 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z' },
    { id: 'merchants', label: t('admin.nav.merchants'), permission: 'merchants.manage', icon: 'M4 4h16l-1 5H5L4 4Zm1 7h14v9H5v-9Zm3 2v5h8v-5H8Z' },
    { id: 'complaints', label: t('admin.nav.complaints'), permission: 'support.manage', icon: 'M12 2 2 7l10 5 10-5-10-5Zm0 20-4-2v-6l4 2 4-2v6l-4 2Z' },
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
        { value: 'today', label: t('admin.range.today') },
        { value: 'week', label: t('admin.range.week') },
        { value: 'month', label: t('admin.range.month') },
        { value: 'year', label: t('admin.range.year') },
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
    assignees: [],
    financeSummary: [],
    fareRules: [],
    settlements: [],
    transactions: [],
    transactionPages: {},

    // Wallet tools. A balance is only ever changed through the ledger, so this
    // panel is a search plus two audited actions — never a direct edit.
    wallet: { query: '', results: [], selected: null, audit: null, searching: false, searched: false },

    filters: {
        fleet: { q: '', status: '' },
        drivers: { q: '', status: '' },
        stops: { q: '' },
        merchants: { q: '', status: '' },
        complaints: { status: '', mine: false },
        transactions: { type: '', status: '', from: '', to: '', page: 1 },
        taxis: { q: '', status: '' },
        taxiLive: { mode: '' },
        school: { status: '', company_uuid: '' },
    },

    reply: { body: '', internal: false },

    qrModal: null,

    // ── taxis ───────────────────────────────────────────────────────────
    taxiTab: 'fleet',
    taxiTabs: [
        { id: 'fleet', label: t('admin.taxi.tabs.fleet') },
        { id: 'lines', label: t('admin.taxi.tabs.lines') },
        { id: 'tariffs', label: t('admin.taxi.tabs.tariffs') },
        { id: 'live', label: t('admin.taxi.tabs.live') },
        { id: 'settlements', label: t('admin.taxi.tabs.settlements') },
        { id: 'report', label: t('admin.taxi.tabs.report') },
    ],
    taxis: [],
    taxiLines: [],
    taxiTariffs: [],
    taxiLive: [],
    taxiLiveMeta: {},
    taxiSettlements: [],
    taxiReport: null,
    taxiQrModal: null,
    taxiAssignmentModal: null,
    taxiAssignments: [],
    taxiAssignmentForm: { driver_uuid: '', starts_on: '', ends_on: '', busy: false, error: null },

    // ── school transport ────────────────────────────────────────────────
    schoolTab: 'companies',
    schoolTabs: [
        { id: 'companies', label: t('admin.school.tabs.companies') },
        { id: 'contracts', label: t('admin.school.tabs.contracts') },
        { id: 'routes', label: t('admin.school.tabs.routes') },
        { id: 'vehicles', label: t('admin.school.tabs.vehicles') },
        { id: 'trips', label: t('admin.school.tabs.trips') },
        { id: 'live', label: t('admin.school.tabs.live') },
    ],
    schoolCompanies: [],
    schoolContracts: [],
    schoolRoutes: [],
    schoolVehicles: [],
    schoolSchools: [],
    schoolTrips: [],
    schoolLive: [],
    schoolRouteModal: null,
    schoolRouteSeats: [],
    schoolContractModal: null,

    // The merchant dossier: wallet, tills and who may use them.
    merchantModal: null,

    // A line's routes, one route's stop sequence, and the builder for a new
    // one. Sequences are what every ETA is computed from.
    routeModal: null,
    sequence: null,
    routeBuilder: null,

    // The driver dossier: licence, documents, assignments and recent shifts.
    // Approving someone to carry passengers means reading all four.
    driverModal: null,
    driverUpload: { type: 'license', issued_at: '', expires_at: '', busy: false, error: null },

    // Bus↔driver assignments. Without one a driver cannot open a shift, so
    // this is the step that actually puts a bus on the road.
    assignmentModal: null,
    assignments: [],
    assignmentForm: { driver_uuid: '', starts_on: '', ends_on: '', busy: false, error: null },
    qrCountdown: 0,

    occupancy: {},
    occupancyRows: [],

    reportTab: 'transport',
    report: null,
    reportRange: { from: '', to: '' },
    reportTabs: [
        { id: 'transport', label: t('admin.reports.tabs.transport') },
        { id: 'drivers', label: t('admin.reports.tabs.drivers') },
        { id: 'passengers', label: t('admin.reports.tabs.passengers') },
        { id: 'revenue', label: t('admin.reports.tabs.revenue') },
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
    _taxiLiveTimer: null,
    _schoolLiveTimer: null,
    _taxiMap: null,
    _taxiLayer: null,
    _schoolMap: null,
    _schoolLayer: null,

    _charts: {},
    _map: null,
    _busLayer: null,
    _live: null,
    _qrTimer: null,

    // ── lifecycle ───────────────────────────────────────────────────────
    get visibleNav() {
        // Some entries are reachable by either of two permissions — the school
        // screens are shared by a city administrator and a company manager —
        // so an entry is shown when the user holds any of them.
        return NAV.filter((item) => this.can(item.permission) || this.can(item.altPermission));
    },

    get currentTitle() {
        return NAV.find((item) => item.id === this.view)?.label ?? '';
    },

    get kpiCards() {
        const k = this.kpis;
        // By id, not by index: NAV grows as modules are added, and a card
        // wearing another module's icon is quiet misinformation.
        const icon = (id) => NAV.find((item) => item.id === id)?.icon ?? NAV[0].icon;

        return [
            { label: t('admin.dashboard.kpi.buses_moving'), value: formatNumber(k.buses_moving), tone: 'brand-500', live: true, icon: icon('fleet'), caption: t('admin.dashboard.kpi.buses_moving_caption', { total: formatNumber(k.total_buses) }) },
            { label: t('admin.dashboard.kpi.passengers_on_board'), value: formatNumber(k.passengers_on_board), tone: 'brand-500', live: true, icon: icon('occupancy') },
            { label: t('admin.dashboard.kpi.trips_today'), value: formatNumber(k.trips_today), tone: 'white/5', icon: icon('reports') },
            { label: t('admin.dashboard.kpi.boardings_today'), value: formatNumber(k.boardings_today), tone: 'white/5', icon: icon('occupancy'), caption: t('admin.dashboard.kpi.boardings_today_caption', { count: formatNumber(k.unique_passengers_today) }) },
            { label: t('admin.dashboard.kpi.active_drivers'), value: formatNumber(k.active_drivers), tone: 'white/5', icon: icon('drivers'), caption: t('admin.dashboard.kpi.active_drivers_caption', { count: formatNumber(k.drivers_on_shift) }) },
            { label: t('admin.dashboard.kpi.fare_revenue_today'), value: this.$money(k.fare_revenue_today), tone: 'brand-500', icon: icon('finance') },
            { label: t('admin.dashboard.kpi.topup_today'), value: this.$money(k.topup_amount_today), tone: 'white/5', icon: icon('finance') },
            { label: t('admin.dashboard.kpi.open_complaints'), value: formatNumber(k.open_complaints), tone: 'white/5', icon: icon('complaints'), caption: t('admin.dashboard.kpi.open_complaints_caption', { count: formatNumber(k.complaints_today) }) },
            { label: t('admin.dashboard.kpi.taxis_on_shift'), value: formatNumber(k.taxis_on_shift), tone: 'brand-500', live: true, icon: icon('taxis'), caption: t('admin.dashboard.kpi.taxis_on_shift_caption', { count: formatNumber(k.taxi_rides_today) }) },
            { label: t('admin.dashboard.kpi.taxi_revenue_today'), value: this.$money(k.taxi_revenue_today), tone: 'white/5', icon: icon('taxis') },
            { label: t('admin.dashboard.kpi.school_runs_live'), value: formatNumber(k.school_runs_live), tone: 'brand-500', live: true, icon: icon('school'), caption: t('admin.dashboard.kpi.school_runs_live_caption', { total: formatNumber(k.school_runs_today) }) },
            { label: t('admin.dashboard.kpi.school_children_aboard'), value: formatNumber(k.school_children_aboard), tone: 'white/5', live: true, icon: icon('school'), caption: t('admin.dashboard.kpi.school_children_aboard_caption', { count: formatNumber(k.school_contracts_active) }) },
        ];
    },

    get filteredBuses() {
        const term = this.busFilter.trim();

        if (!term) return this.liveBuses;

        return this.liveBuses.filter((bus) =>
            String(bus.bus_number ?? '').includes(term) || String(bus.line_code ?? '').includes(term));
    },

    can(permission) {
        if (!permission) return false;

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

                // An entry reachable by either permission is granted the one
                // the endpoint actually answered for, plus its alternative:
                // the probe cannot tell which of the two let it through, and
                // guessing wrong would hide a screen the user can use.
                return item.altPermission ? [item.permission, item.altPermission] : item.permission;
            } catch (error) {
                return error.status === 403 ? null : item.permission;
            }
        });

        return (await Promise.all(checks)).filter(Boolean).flat();
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
            taxis: '/admin/taxi/taxis',
            school: '/admin/school/companies',
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
                case 'finance': await Promise.all([this.loadFinance(), this.loadFareRules(), this.loadSettlements(), this.loadTransactions()]); break;
                case 'merchants': await this.loadMerchants(); break;
                case 'complaints': await this.loadComplaints(); break;
                case 'taxis': await this.loadTaxiTab(); break;
                case 'school': await this.loadSchoolTab(); break;
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
        const labels = series.map((row) => formatChartDate(row.date));

        this.drawChart('chart-trips', {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: t('admin.dashboard.series.trips'),
                        data: series.map((row) => row.trips),
                        borderColor: '#32d583',
                        backgroundColor: 'rgba(50,213,131,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: t('admin.dashboard.series.passengers'),
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
                    label: t('admin.dashboard.series.fares'),
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
                    <strong>${t('admin.live.bus_number', { number: bus.bus_number ?? '—' })}</strong><br>
                    <span style="color:#9db2b9;font-size:12px">${t('admin.live.popup_driver', { name: bus.driver_name ?? '—' })}</span><br>
                    <span style="color:#9db2b9;font-size:12px">${t('admin.live.popup_line', { code: bus.line_code ?? '—', destination: bus.destination ?? '' })}</span><br>
                    <span style="color:#32d583;font-size:12px">${t('admin.live.popup_load', { count: bus.passenger_count ?? 0, speed: Math.round(bus.speed ?? 0) })}</span><br>
                    <span style="color:#9db2b9;font-size:12px">${t('admin.live.popup_next_stop', { stop: bus.next_stop ?? '—' })}</span>`,
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
        if (!confirm(t('admin.fleet.qr_confirm'))) {
            return;
        }

        const reason = prompt(t('admin.fleet.qr_reason_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/fleet/buses/${this.qrModal.bus_uuid ?? ''}/qr/regenerate`, { reason });
            window.toast?.(t('admin.fleet.qr_regenerated'), 'success');
            this.qrModal = null;
            await this.loadFleet();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    // ── drivers ─────────────────────────────────────────────────────────
    async openAssignments(bus) {
        this.assignmentModal = bus;
        this.assignments = [];
        this.assignmentForm = {
            driver_uuid: '',
            // Defaulting to today is what an operator means nine times in ten,
            // and a future date is a deliberate choice rather than a typo.
            starts_on: new Date().toISOString().slice(0, 10),
            ends_on: '',
            busy: false,
            error: null,
        };

        // The driver list may not have been loaded yet if the operator came
        // straight to Fleet.
        if (!this.drivers.length) await this.loadDrivers().catch(() => {});

        await this.loadAssignments();
    },

    async loadAssignments() {
        if (!this.assignmentModal) return;

        const { data } = await api.get(`/admin/fleet/buses/${this.assignmentModal.uuid}/assignments`);
        this.assignments = data;
    },

    /** Only drivers who could actually take a bus are offered. */
    get assignableDrivers() {
        return this.drivers.filter((driver) => driver.status === 'active');
    },

    async submitAssignment() {
        this.assignmentForm.busy = true;
        this.assignmentForm.error = null;

        try {
            await api.post(`/admin/fleet/buses/${this.assignmentModal.uuid}/assignments`, {
                driver_uuid: this.assignmentForm.driver_uuid,
                starts_on: this.assignmentForm.starts_on,
                ends_on: this.assignmentForm.ends_on || null,
            });

            window.toast?.(t('admin.fleet.assignment_added'), 'success');

            this.assignmentForm.driver_uuid = '';
            this.assignmentForm.ends_on = '';

            await this.loadAssignments();
            // The bus row shows its current driver, so it is now stale.
            await this.loadFleet();
        } catch (error) {
            this.assignmentForm.error = error.message;
        } finally {
            this.assignmentForm.busy = false;
        }
    },

    async revokeAssignment(assignment) {
        if (!confirm(t('admin.fleet.assignment_revoke_confirm'))) return;

        try {
            await api.delete(`/admin/fleet/assignments/${assignment.id}`);

            window.toast?.(t('admin.fleet.assignment_revoked'), 'success');

            await this.loadAssignments();
            await this.loadFleet();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async loadDrivers() {
        const { data } = await api.get('/admin/drivers', { query: this.filters.drivers });
        this.drivers = data;
    },

    async openDriver(driver) {
        this.driverUpload = { type: 'license', issued_at: '', expires_at: '', busy: false, error: null };

        try {
            const { data } = await api.get(`/admin/drivers/${driver.uuid}`);
            this.driverModal = { ...data, uuid: driver.uuid };
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    closeDriver() {
        this.driverModal = null;
    },

    openDriverEditForm() {
        const driver = this.driverModal?.driver;

        if (!driver) return;

        this.openForm({
            title: t('admin.drivers.edit_title', { name: driver.name }),
            fields: [
                { name: 'license_number', label: t('admin.forms.driver.license_number') },
                { name: 'license_class', label: t('admin.forms.driver.license_class') },
                { name: 'license_expires_at', label: t('admin.forms.driver.license_expires_at'), type: 'date' },
                { name: 'employee_code', label: t('admin.forms.driver.employee_code') },
                { name: 'contract_ends_at', label: t('admin.forms.driver.contract_ends_at'), type: 'date' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
            ],
            data: {
                license_number: driver.license_number,
                license_class: driver.license_class,
                license_expires_at: driver.license_expires_at,
                employee_code: driver.employee_code,
                contract_ends_at: driver.contract_ends_at,
                notes: driver.notes,
            },
            submit: (data) => api.patch(`/admin/drivers/${this.driverModal.uuid}`, data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await Promise.all([
                    this.openDriver({ uuid: this.driverModal.uuid }),
                    this.loadDrivers(),
                ]);
            },
        });
    },

    /**
     * Documents go up as multipart, so this is a hand-rolled form rather than
     * the schema-driven modal, which speaks JSON.
     */
    async uploadDriverDocument(event) {
        const input = event.target.querySelector('input[type="file"]');
        const file = input?.files?.[0];

        if (!file) return;

        const body = new FormData();
        body.append('type', this.driverUpload.type);
        body.append('file', file);
        if (this.driverUpload.issued_at) body.append('issued_at', this.driverUpload.issued_at);
        if (this.driverUpload.expires_at) body.append('expires_at', this.driverUpload.expires_at);

        this.driverUpload.busy = true;
        this.driverUpload.error = null;

        try {
            await api.post(`/admin/drivers/${this.driverModal.uuid}/documents`, body);

            window.toast?.(t('admin.drivers.document_uploaded'), 'success');

            input.value = '';
            this.driverUpload.issued_at = '';
            this.driverUpload.expires_at = '';

            await this.openDriver({ uuid: this.driverModal.uuid });
        } catch (error) {
            this.driverUpload.error = error.details
                ? Object.values(error.details).flat().join(' ')
                : error.message;
        } finally {
            this.driverUpload.busy = false;
        }
    },

    /**
     * Documents live on the private disk, so viewing one means asking the
     * server for a link that expires — never a guessable path.
     */
    async viewDriverDocument(document_) {
        try {
            const { data } = await api.get(`/admin/drivers/${this.driverModal.uuid}/documents/${document_.id}`);
            window.open(data.url, '_blank', 'noopener');
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async changeDriverStatus(driver, status) {
        const reason = status === 'suspended' ? prompt(t('admin.drivers.suspend_reason_prompt')) : null;

        if (status === 'suspended' && !reason) return;

        try {
            await api.post(`/admin/drivers/${driver.uuid}/status`, { status, reason });
            window.toast?.(t('admin.drivers.status_updated'), 'success');
            await this.loadDrivers();

            // The dossier may be open on this very driver, in which case its
            // badge is now wrong.
            if (this.driverModal?.uuid === driver.uuid) await this.openDriver(driver);
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

    /** A line's routes: the stop sequences that ETAs and "next stop" run on. */
    async openRoutes(line) {
        this.routeModal = { line, routes: [], loading: true };

        try {
            const { data } = await api.get(`/lines/${line.id}`);
            this.routeModal = { line, routes: data.routes ?? [], loading: false };
        } catch (error) {
            this.routeModal = null;
            window.toast?.(error.message, 'error');
        }
    },

    closeRoutes() {
        this.routeModal = null;
        this.sequence = null;
        this.routeBuilder = null;
    },

    async openSequence(route) {
        try {
            const { data } = await api.get(`/routes/${route.id}`);
            this.sequence = data;
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    /**
     * Re-snap every stop onto the route's geometry.
     *
     * Offsets are what "next stop" and every ETA are computed from, so after a
     * geometry import or a stop being moved they have to be rebuilt — and the
     * operator needs a button for it, not a shell.
     */
    async recalculateRoute(route) {
        this.busy = true;

        try {
            const { data } = await api.post(`/admin/network/routes/${route.id}/recalculate`);

            window.toast?.(t('admin.network.recalculated', {
                count: formatNumber(data.stops_updated),
            }), 'success');

            await this.openRoutes(this.routeModal.line);
        } catch (error) {
            window.toast?.(error.message, 'error');
        } finally {
            this.busy = false;
        }
    },

    /** Start building a stop sequence for a new route on this line. */
    startRouteBuilder() {
        this.routeBuilder = {
            name: '',
            direction: 'outbound',
            is_default: false,
            stops: [],
            query: '',
            busy: false,
            error: null,
        };
    },

    /** Stops matching the builder's search, minus the ones already added. */
    get builderCandidates() {
        if (!this.routeBuilder) return [];

        const term = this.routeBuilder.query.trim();
        const chosen = new Set(this.routeBuilder.stops.map((stop) => stop.id));

        return this.stops
            .filter((stop) => !chosen.has(stop.id))
            .filter((stop) => !term || stop.name.includes(term) || String(stop.code).includes(term))
            .slice(0, 12);
    },

    addBuilderStop(stop) {
        this.routeBuilder.stops.push({ id: stop.id, name: stop.name, code: stop.code });
    },

    removeBuilderStop(index) {
        this.routeBuilder.stops.splice(index, 1);
    },

    /** Order is the whole point of a sequence, so it has to be adjustable. */
    moveBuilderStop(index, delta) {
        const target = index + delta;

        if (target < 0 || target >= this.routeBuilder.stops.length) return;

        const stops = this.routeBuilder.stops;
        [stops[index], stops[target]] = [stops[target], stops[index]];
    },

    async submitRoute() {
        if (this.routeBuilder.stops.length < 2) {
            this.routeBuilder.error = t('admin.network.route_needs_two_stops');

            return;
        }

        this.routeBuilder.busy = true;
        this.routeBuilder.error = null;

        try {
            await api.post(`/admin/network/lines/${this.routeModal.line.id}/routes`, {
                name: this.routeBuilder.name,
                direction: this.routeBuilder.direction,
                is_default: this.routeBuilder.is_default,
                stops: this.routeBuilder.stops.map((stop) => ({ bus_stop_id: stop.id })),
            });

            window.toast?.(t('admin.network.route_created'), 'success');

            this.routeBuilder = null;
            await this.openRoutes(this.routeModal.line);
        } catch (error) {
            this.routeBuilder.error = error.isValidation
                ? Object.values(error.details ?? {}).flat().join(' ')
                : error.message;
        } finally {
            if (this.routeBuilder) this.routeBuilder.busy = false;
        }
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
        if (!confirm(t('admin.finance.settlement_confirm', { reference: settlement.reference }))) return;

        try {
            await api.post(`/admin/finance/settlements/${settlement.uuid}/approve`);
            window.toast?.(t('admin.finance.settlement_approved'), 'success');
            await this.loadSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async loadTransactions() {
        const { data, meta } = await api.get('/admin/finance/transactions', {
            query: this.filters.transactions,
        });

        this.transactions = data;
        this.transactionPages = meta?.pagination ?? {};
    },

    async goToTransactionPage(page) {
        if (page < 1 || page > (this.transactionPages.last_page ?? 1)) return;

        this.filters.transactions.page = page;

        try {
            await this.loadTransactions();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async applyTransactionFilters() {
        // A filter change with the old page number can land on an empty page.
        this.filters.transactions.page = 1;

        try {
            await this.loadTransactions();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    /**
     * Reversal, not correction. The reason is mandatory server-side and ends
     * up in the audit trail, so it is asked for in a form rather than assumed.
     */
    openReversalForm(transaction) {
        this.openForm({
            title: t('admin.finance.reverse_title', { uuid: transaction.uuid.slice(0, 8) }),
            hint: t('admin.finance.reverse_hint'),
            submitLabel: t('admin.finance.reverse'),
            fields: [
                { name: 'reason', label: t('admin.finance.reverse_reason'), type: 'textarea', required: true,
                  wide: true, help: t('admin.finance.reverse_reason_help') },
            ],
            submit: (data) => api.post(`/admin/finance/transactions/${transaction.uuid}/reverse`, data),
            onDone: async () => {
                window.toast?.(t('admin.finance.reversed'), 'success');
                await Promise.all([this.loadTransactions(), this.loadFinance()]);
            },
        });
    },

    // ── wallet tools ────────────────────────────────────────────────────
    async searchWalletUsers() {
        const query = this.wallet.query.trim();

        if (query.length < 3) return;

        this.wallet.searching = true;

        try {
            const { data } = await api.get('/admin/users/lookup', { query: { q: query } });
            this.wallet.results = data;
            this.wallet.searched = true;
        } catch (error) {
            window.toast?.(error.message, 'error');
        } finally {
            this.wallet.searching = false;
        }
    },

    selectWalletUser(user) {
        this.wallet.selected = user;
        // The previous integrity check described a different wallet.
        this.wallet.audit = null;
    },

    openAdjustmentForm() {
        const user = this.wallet.selected;

        if (!user) return;

        this.openForm({
            title: t('admin.finance.adjust_title', { name: user.name }),
            hint: t('admin.finance.adjust_hint'),
            fields: [
                { name: 'amount', label: t('admin.finance.adjust_amount'), type: 'number', required: true },
                { name: 'reason', label: t('admin.finance.adjust_reason'), type: 'textarea', required: true, wide: true },
            ],
            data: { user_uuid: user.uuid },
            submit: (data) => api.post('/admin/finance/wallets/adjust', data),
            onDone: async (response) => {
                window.toast?.(t('admin.finance.adjusted'), 'success');

                // Show the new balance without making the operator search again.
                this.wallet.selected = { ...user, balance: response?.data?.balance ?? user.balance };
                this.wallet.audit = null;
                await this.loadTransactions().catch(() => {});
            },
        });
    },

    async auditWallet() {
        if (!this.wallet.selected) return;

        try {
            const { data } = await api.get('/admin/finance/wallets/audit', {
                query: { user_uuid: this.wallet.selected.uuid },
            });

            this.wallet.audit = data;
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async openSettlementForm() {
        // The operator may have come straight to Finance, in which case the
        // merchant list has never been fetched and the select would be empty.
        if (!this.merchants.length) await this.loadMerchants().catch(() => {});

        const today = new Date();
        const start = new Date(today.getTime() - 7 * 86_400_000);

        this.openForm({
            title: t('admin.finance.settlement_form_title'),
            hint: t('admin.finance.settlement_form_hint'),
            fields: [
                { name: 'merchant_uuid', label: t('admin.finance.settlement_merchant'), type: 'select', required: true,
                  options: this.merchants.map((merchant) => ({ value: merchant.uuid, label: merchant.name })) },
                { name: 'from', label: t('admin.finance.settlement_from'), type: 'date', required: true },
                { name: 'to', label: t('admin.finance.settlement_to'), type: 'date', required: true },
            ],
            data: { from: start.toISOString().slice(0, 10), to: today.toISOString().slice(0, 10) },
            submit: (data) => api.post('/admin/finance/settlements', data),
            onDone: async () => {
                window.toast?.(t('admin.finance.settlement_created'), 'success');
                await this.loadSettlements();
            },
        });
    },

    async rejectSettlement(settlement) {
        const reason = prompt(t('admin.finance.settlement_reject_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/finance/settlements/${settlement.uuid}/reject`, { reason });
            window.toast?.(t('admin.finance.settlement_rejected'), 'success');
            await this.loadSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async paySettlement(settlement) {
        const reference = prompt(t('admin.finance.transfer_reference_prompt'));

        if (!reference) return;

        try {
            await api.post(`/admin/finance/settlements/${settlement.uuid}/pay`, { payment_reference: reference });
            window.toast?.(t('admin.finance.payment_recorded'), 'success');
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
            window.toast?.(t('admin.merchants.status_updated'), 'success');
            await this.loadMerchants();

            if (this.merchantModal?.uuid === merchant.uuid) await this.openMerchant(merchant);
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async openMerchant(merchant) {
        try {
            const { data } = await api.get(`/admin/merchants/${merchant.uuid}`);
            this.merchantModal = { ...data, uuid: merchant.uuid };
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    closeMerchant() {
        this.merchantModal = null;
    },

    openTerminalForm() {
        if (!this.merchantModal) return;

        this.openForm({
            title: t('admin.merchants.terminal_add'),
            hint: t('admin.merchants.terminal_hint'),
            fields: [
                { name: 'name', label: t('admin.common.name'), required: true },
                { name: 'location_label', label: t('admin.merchants.terminal_location') },
            ],
            submit: (data) => api.post(`/admin/merchants/${this.merchantModal.uuid}/terminals`, data),
            onDone: async () => {
                window.toast?.(t('admin.merchants.terminal_added'), 'success');
                await Promise.all([
                    this.openMerchant({ uuid: this.merchantModal.uuid }),
                    this.loadMerchants(),
                ]);
            },
        });
    },

    openStaffForm() {
        if (!this.merchantModal) return;

        this.openForm({
            title: t('admin.merchants.staff_add'),
            hint: t('admin.merchants.staff_hint'),
            fields: [
                { name: 'first_name', label: t('admin.forms.driver.first_name'), required: true },
                { name: 'last_name', label: t('admin.forms.driver.last_name'), required: true },
                { name: 'mobile', label: t('admin.forms.driver.mobile'), required: true,
                  placeholder: t('admin.forms.driver.mobile_placeholder') },
                { name: 'role', label: t('admin.merchants.staff_role'), type: 'select', required: true, options: [
                    { value: 'cashier', label: t('admin.merchants.roles.cashier') },
                    { value: 'manager', label: t('admin.merchants.roles.manager') },
                ] },
                { name: 'can_refund', label: t('admin.merchants.can_refund'), type: 'checkbox' },
                { name: 'can_view_reports', label: t('admin.merchants.can_view_reports'), type: 'checkbox' },
            ],
            data: { role: 'cashier', can_view_reports: true },
            submit: (data) => api.post(`/admin/merchants/${this.merchantModal.uuid}/staff`, data),
            onDone: async () => {
                window.toast?.(t('admin.merchants.staff_added'), 'success');
                await this.openMerchant({ uuid: this.merchantModal.uuid });
            },
        });
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

        // Fetched once and kept: the roster of colleagues does not change
        // between two complaints.
        if (!this.assignees.length) {
            try {
                const { data } = await api.get('/admin/complaints/assignees');
                this.assignees = data;
            } catch {
                // Assignment is one action on this screen; losing the roster
                // must not take the thread down with it.
            }
        }
    },

    async assignComplaint(userUuid) {
        if (!userUuid) return;

        try {
            await api.post(`/admin/complaints/${this.selectedComplaint.uuid}/assign`, {
                user_uuid: userUuid,
            });

            window.toast?.(t('admin.complaints.assigned'), 'success');

            await Promise.all([
                this.openComplaint(this.selectedComplaint),
                this.loadComplaints(),
            ]);
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    /**
     * Complaint photos sit on the private disk — they routinely show faces,
     * plates and interiors — so viewing one means asking for a link that
     * expires rather than following a path anyone could guess.
     */
    async viewAttachment(attachment) {
        try {
            const { data } = await api.get(
                `/admin/complaints/${this.selectedComplaint.uuid}/attachments/${attachment.id}`,
            );

            window.open(data.url, '_blank', 'noopener');
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
            window.toast?.(t('admin.complaints.reply_sent'), 'success');
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
        return t(`admin.occupancy.crowding.${level}`, {}) === `admin.occupancy.crowding.${level}`
                ? '—'
                : t(`admin.occupancy.crowding.${level}`);
    },

    // ── reports (spec 25) ───────────────────────────────────────────────
    get transportCards() {
        const r = this.report ?? {};

        return [
            { label: t('admin.reports.transport_cards.trips'), value: formatNumber(r.trips) },
            { label: t('admin.reports.transport_cards.boardings'), value: formatNumber(r.boardings) },
            { label: t('admin.reports.transport_cards.avg_passengers'), value: formatNumber(r.avg_passengers_per_trip) },
            { label: t('admin.reports.transport_cards.buses_used'), value: formatNumber(r.buses_used) },
            { label: t('admin.reports.transport_cards.drivers_used'), value: formatNumber(r.drivers_used) },
            { label: t('admin.reports.transport_cards.distance'), value: t('admin.common.kilometres', { count: formatNumber(Math.round((r.distance_meters ?? 0) / 1000)) }) },
            { label: t('admin.reports.transport_cards.avg_speed'), value: r.avg_speed_kmh === null || r.avg_speed_kmh === undefined
                ? '—' : `${formatNumber(r.avg_speed_kmh)} km/h` },
            { label: t('admin.reports.transport_cards.avg_delay'), value: r.punctuality?.avg_delay_minutes === null ||
                r.punctuality?.avg_delay_minutes === undefined
                ? '—' : t('admin.common.minutes', { count: formatNumber(r.punctuality.avg_delay_minutes) }) },
        ];
    },

    get passengerCards() {
        const r = this.report ?? {};

        return [
            { label: t('admin.reports.passenger_cards.active_users'), value: formatNumber(r.active_users) },
            { label: t('admin.reports.passenger_cards.new_users'), value: formatNumber(r.new_users) },
            { label: t('admin.reports.passenger_cards.rides'), value: formatNumber(r.rides) },
            { label: t('admin.reports.passenger_cards.avg_rides'), value: formatNumber(r.avg_rides_per_user) },
        ];
    },

    get frequencyRows() {
        const f = this.report?.frequency ?? {};

        return [
            { label: t('admin.reports.frequency.once'), value: f.once ?? 0 },
            { label: t('admin.reports.frequency.occasional'), value: f.occasional ?? 0 },
            { label: t('admin.reports.frequency.regular'), value: f.regular ?? 0 },
            { label: t('admin.reports.frequency.frequent'), value: f.frequent ?? 0 },
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
                    label: t('admin.dashboard.series.trips'),
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
            submitLabel: t('admin.common.save'),
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
            const response = await this.form.submit(this.form.data);
            const done = this.form.onDone;

            this.form.open = false;

            // A form may own what happens next — a specific message, a figure
            // to show, one list to refresh. Only when it does not do we fall
            // back to the blunt instrument of reloading the whole screen.
            if (done) {
                await done(response);
            } else {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.load(this.view, { force: true });
            }
        } catch (error) {
            // Field-level messages come back from the server's validator; a
            // non-validation failure is shown once at the foot of the form.
            if (error.isValidation) {
                this.form.errors = error.details ?? {};
                this.form.error = t('admin.common.invalid_fields');
            } else {
                this.form.error = error.message;
            }
        } finally {
            this.form.busy = false;
        }
    },

    openBusForm(bus = null) {
        this.openForm({
            title: bus ? t('admin.forms.bus.edit', { number: bus.bus_number }) : t('admin.forms.bus.add'),
            hint: bus ? null : t('admin.forms.bus.hint'),
            fields: [
                { name: 'bus_number', label: t('admin.forms.bus.bus_number'), required: !bus, type: 'text' },
                { name: 'plate', label: t('admin.forms.bus.plate'), type: 'text' },
                { name: 'model', label: t('admin.forms.bus.model'), type: 'text' },
                { name: 'manufacture_year', label: t('admin.forms.bus.manufacture_year'), type: 'number' },
                { name: 'capacity_seated', label: t('admin.forms.bus.capacity_seated'), type: 'number', required: true },
                { name: 'capacity_standing', label: t('admin.forms.bus.capacity_standing'), type: 'number', required: true },
                { name: 'status', label: t('admin.common.status'), type: 'select', options: [
                    { value: 'idle', label: t('enums.busstatus.idle') },
                    { value: 'active', label: t('enums.busstatus.active') },
                    { value: 'maintenance', label: t('enums.busstatus.maintenance') },
                    { value: 'out_of_service', label: t('enums.busstatus.out_of_service') },
                ] },
                { name: 'default_line_id', label: t('admin.forms.bus.default_line'), type: 'select',
                  options: this.lines.map((line) => ({ value: line.id, label: `${line.code} — ${line.name}` })) },
                { name: 'has_air_conditioning', label: t('admin.forms.bus.air_conditioning'), type: 'checkbox' },
                { name: 'is_accessible', label: t('admin.forms.bus.accessible'), type: 'checkbox' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
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
            title: t('admin.forms.driver.add'),
            hint: t('admin.forms.driver.hint'),
            fields: [
                { name: 'first_name', label: t('admin.forms.driver.first_name'), required: true },
                { name: 'last_name', label: t('admin.forms.driver.last_name'), required: true },
                { name: 'mobile', label: t('admin.forms.driver.mobile'), required: true, placeholder: t('admin.forms.driver.mobile_placeholder') },
                { name: 'national_code', label: t('admin.forms.driver.national_code'), required: true },
                { name: 'license_number', label: t('admin.forms.driver.license_number'), required: true },
                { name: 'license_class', label: t('admin.forms.driver.license_class'), placeholder: t('admin.forms.driver.license_class_placeholder') },
                { name: 'license_expires_at', label: t('admin.forms.driver.license_expires_at'), type: 'date' },
                { name: 'employee_code', label: t('admin.forms.driver.employee_code') },
                { name: 'hired_at', label: t('admin.forms.driver.hired_at'), type: 'date' },
                { name: 'contract_ends_at', label: t('admin.forms.driver.contract_ends_at'), type: 'date' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
            ],
            submit: (data) => api.post('/admin/drivers', data),
        });
    },

    openStopForm(stop = null) {
        this.openForm({
            title: stop ? t('admin.forms.stop.edit', { name: stop.name }) : t('admin.forms.stop.add'),
            hint: stop ? t('admin.forms.stop.move_hint') : t('admin.forms.stop.hint'),
            fields: [
                // The code identifies the stop on printed signage; changing it
                // is not an edit, it is a different stop.
                ...(stop ? [] : [{ name: 'code', label: t('admin.forms.stop.code'), required: true }]),
                { name: 'name', label: t('admin.forms.stop.name'), required: true },
                { name: 'name_en', label: t('admin.forms.stop.name_en') },
                { name: 'lat', label: t('admin.forms.stop.lat'), required: true, type: 'number', step: 'any' },
                { name: 'lng', label: t('admin.forms.stop.lng'), required: true, type: 'number', step: 'any' },
                { name: 'geofence_radius', label: t('admin.forms.stop.geofence_radius'), type: 'number',
                  help: t('admin.forms.stop.geofence_help') },
                { name: 'address', label: t('admin.forms.stop.address'), wide: true },
                { name: 'is_terminal', label: t('admin.forms.stop.is_terminal'), type: 'checkbox' },
                { name: 'is_accessible', label: t('admin.forms.bus.accessible'), type: 'checkbox' },
                { name: 'has_shelter', label: t('admin.forms.stop.has_shelter'), type: 'checkbox' },
                { name: 'description', label: t('admin.forms.line.description'), type: 'textarea', wide: true },
            ],
            data: stop
                ? {
                    name: stop.name, name_en: stop.name_en,
                    lat: stop.lat, lng: stop.lng,
                    geofence_radius: stop.geofence_radius, address: stop.address,
                    is_terminal: stop.is_terminal, is_accessible: stop.is_accessible,
                    has_shelter: stop.has_shelter, description: stop.description,
                }
                : { geofence_radius: 60 },
            submit: (data) => stop
                ? api.patch(`/admin/network/stops/${stop.id}`, data)
                : api.post('/admin/network/stops', data),
        });
    },

    openLineForm(line = null) {
        this.openForm({
            title: line ? t('admin.forms.line.edit', { code: line.code }) : t('admin.forms.line.add'),
            fields: [
                { name: 'code', label: t('admin.forms.line.code'), required: !line },
                { name: 'name', label: t('admin.forms.line.name'), required: true },
                { name: 'color', label: t('admin.forms.line.color'), type: 'color' },
                { name: 'origin_label', label: t('admin.forms.line.origin') },
                { name: 'destination_label', label: t('admin.forms.line.destination') },
                { name: 'typical_duration_minutes', label: t('admin.forms.line.typical_duration'), type: 'number' },
                { name: 'headway_minutes', label: t('admin.forms.line.headway'), type: 'number' },
                { name: 'service_start', label: t('admin.forms.line.service_start'), type: 'time' },
                { name: 'service_end', label: t('admin.forms.line.service_end'), type: 'time' },
                { name: 'description', label: t('admin.forms.line.description'), type: 'textarea', wide: true },
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
            title: t('admin.forms.merchant.add'),
            hint: t('admin.forms.merchant.hint'),
            fields: [
                { name: 'name', label: t('admin.forms.merchant.name'), required: true },
                { name: 'legal_name', label: t('admin.forms.merchant.legal_name') },
                { name: 'type', label: t('admin.forms.merchant.type'), required: true, type: 'select', options: [
                    { value: 'swimming_pool', label: t('enums.merchanttype.swimming_pool') },
                    { value: 'gym', label: t('enums.merchanttype.gym') },
                    { value: 'sports_center', label: t('enums.merchanttype.sports_center') },
                    { value: 'entertainment', label: t('enums.merchanttype.entertainment') },
                    { value: 'restaurant', label: t('enums.merchanttype.restaurant') },
                    { value: 'store', label: t('enums.merchanttype.store') },
                    { value: 'cinema', label: t('enums.merchanttype.cinema') },
                    { value: 'parking', label: t('enums.merchanttype.parking') },
                    { value: 'other', label: t('enums.merchanttype.other') },
                ] },
                { name: 'owner_first_name', label: t('admin.forms.merchant.owner_first_name'), required: true },
                { name: 'owner_last_name', label: t('admin.forms.merchant.owner_last_name'), required: true },
                { name: 'owner_mobile', label: t('admin.forms.merchant.owner_mobile'), required: true },
                { name: 'phone', label: t('admin.forms.merchant.phone') },
                { name: 'email', label: t('admin.forms.merchant.email'), type: 'email' },
                { name: 'commission_bps', label: t('admin.forms.merchant.commission_bps'), type: 'number',
                  help: t('admin.forms.merchant.commission_help') },
                { name: 'settlement_cycle', label: t('admin.forms.merchant.settlement_cycle'), type: 'select', options: [
                    { value: 'daily', label: t('admin.forms.merchant.cycles.daily') },
                    { value: 'weekly', label: t('admin.forms.merchant.cycles.weekly') },
                    { value: 'monthly', label: t('admin.forms.merchant.cycles.monthly') },
                ] },
                { name: 'iban', label: t('admin.forms.merchant.iban') },
                { name: 'bank_account_holder', label: t('admin.forms.merchant.account_holder') },
                { name: 'address', label: t('admin.forms.stop.address'), wide: true },
            ],
            data: { type: 'other', commission_bps: 150, settlement_cycle: 'weekly' },
            submit: (data) => api.post('/admin/merchants', data),
        });
    },

    openFareRuleForm(rule = null) {
        this.openForm({
            title: rule ? t('admin.forms.fare_rule.edit', { code: rule.code }) : t('admin.forms.fare_rule.add'),
            hint: t('admin.forms.fare_rule.hint'),
            fields: [
                { name: 'name', label: t('admin.forms.fare_rule.name'), required: true },
                ...(rule ? [] : [{ name: 'code', label: t('admin.forms.fare_rule.code'), required: true }]),
                ...(rule ? [] : [{ name: 'context', label: t('admin.forms.fare_rule.context'), required: true, type: 'select', options: [
                    { value: 'bus', label: t('admin.forms.fare_rule.context_bus') },
                    { value: 'merchant', label: t('admin.forms.fare_rule.context_merchant') },
                ] }]),
                ...(rule ? [] : [{ name: 'passenger_type', label: t('admin.forms.fare_rule.passenger_type'), type: 'select', options: [
                    { value: 'regular', label: t('admin.forms.fare_rule.passenger_types.regular') },
                    { value: 'student', label: t('admin.forms.fare_rule.passenger_types.student') },
                    { value: 'senior', label: t('admin.forms.fare_rule.passenger_types.senior') },
                    { value: 'disabled', label: t('admin.forms.fare_rule.passenger_types.disabled') },
                    { value: 'child', label: t('admin.forms.fare_rule.passenger_types.child') },
                ] }]),
                { name: 'base_fare', label: t('admin.forms.fare_rule.base_fare'), type: 'number', required: true },
                { name: 'per_km_fare', label: t('admin.forms.fare_rule.per_km_fare'), type: 'number' },
                { name: 'min_fare', label: t('admin.forms.fare_rule.min_fare'), type: 'number' },
                { name: 'max_fare', label: t('admin.forms.fare_rule.max_fare'), type: 'number' },
                { name: 'multiplier', label: t('admin.forms.fare_rule.multiplier'), type: 'number', step: '0.01',
                  help: t('admin.forms.fare_rule.multiplier_help') },
                { name: 'priority', label: t('admin.forms.fare_rule.priority'), type: 'number' },
                ...(rule ? [{ name: 'is_active', label: t('admin.forms.fare_rule.is_active'), type: 'checkbox' }] : []),
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


    // ── taxis ───────────────────────────────────────────────────────────

    /**
     * One tab loads at a time.
     *
     * Six datasets behind one screen; fetching all of them because somebody
     * opened the fleet list would make the screen slow for no reason.
     */
    async loadTaxiTab(tab = null) {
        if (tab) this.taxiTab = tab;

        switch (this.taxiTab) {
            case 'fleet': await Promise.all([this.loadTaxis(), this.loadTaxiLines()]); break;
            case 'lines': await this.loadTaxiLines(); break;
            case 'tariffs': await this.loadTaxiTariffs(); break;
            case 'live': await this.loadTaxiLive(); break;
            case 'settlements': await this.loadTaxiSettlements(); break;
            case 'report': await this.loadTaxiReport(); break;
        }
    },

    async loadTaxis() {
        const { data } = await api.get('/admin/taxi/taxis', { query: this.filters.taxis });
        this.taxis = data;
    },

    async loadTaxiLines() {
        const { data } = await api.get('/admin/taxi/lines');
        this.taxiLines = data;
    },

    async loadTaxiTariffs() {
        const { data } = await api.get('/admin/taxi/tariffs');
        this.taxiTariffs = data;
    },

    /**
     * The dispatcher's board: every car in the city, coloured by what it is
     * offering. The colour is the point — a line taxi, a charter and a metered
     * car are three different products and a control room has to tell them
     * apart at a glance, not by reading a table.
     */
    async loadTaxiLive() {
        const { data, meta } = await api.get('/admin/taxi/live');
        this.taxiLive = data;
        this.taxiLiveMeta = meta ?? {};

        await this.initTaxiMap();

        clearInterval(this._taxiLiveTimer);
        this._taxiLiveTimer = setInterval(async () => {
            if (this.view !== 'taxis' || this.taxiTab !== 'live') {
                clearInterval(this._taxiLiveTimer);

                return;
            }

            try {
                const refreshed = await api.get('/admin/taxi/live');
                this.taxiLive = refreshed.data;
                this.taxiLiveMeta = refreshed.meta ?? {};
                this._taxiLayer?.sync(this.taxiLive, (taxi) => taxi.uuid);
            } catch {
                // Keep the last known positions rather than blanking the board.
            }
        }, 10_000);
    },

    async initTaxiMap() {
        if (this._taxiMap) {
            this._taxiLayer.sync(this.taxiLive, (taxi) => taxi.uuid);

            return;
        }

        await this.$nextTick();

        const element = document.getElementById('admin-taxi-map');

        if (!element) return;

        const { data: config } = await api.get('/map/config');
        this._taxiMap = await createMap(element, config);

        this._taxiLayer = new MarkerLayer(this._taxiMap, {
            iconFor: (taxi) => taxiIcon({
                color: this.taxiModeColor(taxi.service_type),
                label: taxi.taxi_number,
                available: taxi.is_available !== false,
            }),
            popupFor: (taxi) => `
                <strong>${t('admin.taxi.popup_number', { number: taxi.taxi_number ?? '—' })}</strong><br>
                <span style="color:#9db2b9;font-size:12px">${t('admin.taxi.popup_plate', { plate: taxi.plate ?? '—' })}</span><br>
                <span style="color:${this.taxiModeColor(taxi.service_type)};font-size:12px">${t(`enums.taxiservicetype.${taxi.service_type}`)}${taxi.line_code ? ` — ${taxi.line_code}` : ''}</span><br>
                <span style="color:#9db2b9;font-size:12px">${t('admin.taxi.popup_load', { aboard: taxi.onboard_count ?? 0, free: taxi.seats_free ?? 0 })}</span>`,
        });

        this._taxiLayer.sync(this.taxiLive, (taxi) => taxi.uuid);
    },

    focusTaxi(taxi) {
        this._taxiMap?.setView([taxi.lat, taxi.lng], 16);
    },

    /** Rows the board shows, after the mode filter the operator picked. */
    get filteredTaxiLive() {
        return this.filters.taxiLive.mode
            ? this.taxiLive.filter((taxi) => taxi.service_type === this.filters.taxiLive.mode)
            : this.taxiLive;
    },

    async loadTaxiSettlements() {
        const { data } = await api.get('/admin/taxi/settlements');
        this.taxiSettlements = data;
    },

    async loadTaxiReport() {
        const { data } = await api.get('/admin/taxi/report', { query: this.reportRange });
        this.taxiReport = data;
    },

    /** How a taxi is drawn on the map: by what it is offering, not by status. */
    taxiModeColor(mode) {
        return {
            line: '#12b76a',
            charter: '#f79009',
            meter: '#2e90fa',
        }[mode] ?? '#6b8892';
    },

    async openTaxiQr(taxi) {
        this.taxiQrModal = { uuid: taxi.uuid, taxi_number: taxi.taxi_number, token: null };

        try {
            const { data } = await api.get(`/admin/taxi/taxis/${taxi.uuid}/qr`);
            this.taxiQrModal = { ...this.taxiQrModal, ...data };
            this.$nextTick(() => this.renderTaxiQr(data.token));
        } catch (error) {
            window.toast?.(error.message, 'error');
            this.taxiQrModal = null;
        }
    },

    async renderTaxiQr(token) {
        const canvas = document.getElementById('taxi-qr-canvas');

        if (!canvas || !token) return;

        await QRCode.toCanvas(canvas, token, {
            width: 240,
            margin: 1,
            // A quiet zone on white: a code drawn on the panel's dark ground
            // is unreadable to half the phones that will try.
            color: { dark: '#05090b', light: '#ffffff' },
        });
    },

    async regenerateTaxiQr() {
        const reason = prompt(t('admin.taxi.qr_reason_prompt'));

        if (!reason) return;

        try {
            const { data } = await api.post(`/admin/taxi/taxis/${this.taxiQrModal.uuid}/qr/regenerate`, { reason });
            this.taxiQrModal = { ...this.taxiQrModal, ...data };
            this.$nextTick(() => this.renderTaxiQr(data.token));
            window.toast?.(t('admin.taxi.qr_regenerated'), 'success');
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async openTaxiAssignments(taxi) {
        this.taxiAssignmentModal = taxi;
        this.taxiAssignments = [];
        this.taxiAssignmentForm = {
            driver_uuid: '',
            starts_on: new Date().toISOString().slice(0, 10),
            ends_on: '',
            busy: false,
            error: null,
        };

        if (!this.drivers.length) await this.loadDrivers().catch(() => {});

        try {
            const { data } = await api.get(`/admin/taxi/taxis/${taxi.uuid}/assignments`);
            this.taxiAssignments = data;
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async submitTaxiAssignment() {
        this.taxiAssignmentForm.busy = true;
        this.taxiAssignmentForm.error = null;

        try {
            await api.post(`/admin/taxi/taxis/${this.taxiAssignmentModal.uuid}/assignments`, {
                driver_uuid: this.taxiAssignmentForm.driver_uuid,
                starts_on: this.taxiAssignmentForm.starts_on,
                ends_on: this.taxiAssignmentForm.ends_on || null,
            });

            window.toast?.(t('admin.fleet.assignment_added'), 'success');
            await this.openTaxiAssignments(this.taxiAssignmentModal);
            await this.loadTaxis();
        } catch (error) {
            this.taxiAssignmentForm.error = error.message;
        } finally {
            this.taxiAssignmentForm.busy = false;
        }
    },

    async revokeTaxiAssignment(assignment) {
        if (!confirm(t('admin.fleet.assignment_revoke_confirm'))) return;

        try {
            await api.delete(`/admin/taxi/assignments/${assignment.id}`);
            await this.openTaxiAssignments(this.taxiAssignmentModal);
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    openTaxiForm(taxi = null) {
        this.openForm({
            title: taxi ? t('admin.taxi.edit_title', { number: taxi.taxi_number }) : t('admin.taxi.new_taxi'),
            hint: taxi ? null : t('admin.taxi.new_hint'),
            fields: [
                ...(taxi ? [] : [{ name: 'taxi_number', label: t('admin.taxi.number'), required: true }]),
                { name: 'plate', label: t('admin.fleet.plate') },
                { name: 'model', label: t('admin.forms.bus.model') },
                { name: 'color', label: t('admin.taxi.color') },
                { name: 'capacity', label: t('admin.fleet.capacity'), type: 'number' },
                { name: 'status', label: t('admin.common.status'), type: 'select', options: [
                    { value: 'idle', label: t('enums.taxistatus.idle') },
                    { value: 'active', label: t('enums.taxistatus.active') },
                    { value: 'maintenance', label: t('enums.taxistatus.maintenance') },
                    { value: 'out_of_service', label: t('enums.taxistatus.out_of_service') },
                ] },
                { name: 'default_taxi_line_id', label: t('admin.taxi.default_line'), type: 'select',
                  options: this.taxiLines.map((line) => ({ value: line.id, label: `${line.code} — ${line.name}` })) },
                { name: 'commission_bps', label: t('admin.taxi.commission_bps'), type: 'number',
                  help: t('admin.taxi.commission_help') },
                { name: 'is_accessible', label: t('admin.forms.bus.accessible'), type: 'checkbox' },
                { name: 'has_air_conditioning', label: t('admin.forms.bus.air_conditioning'), type: 'checkbox' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
            ],
            data: taxi
                ? {
                    plate: taxi.plate, model: taxi.model, color: taxi.color,
                    capacity: taxi.capacity, status: taxi.status,
                    commission_bps: taxi.commission_bps,
                    is_accessible: taxi.is_accessible,
                    has_air_conditioning: taxi.has_air_conditioning,
                    notes: taxi.notes,
                }
                : { capacity: 4, status: 'idle', color: 'yellow', has_air_conditioning: true },
            submit: (data) => taxi
                ? api.patch(`/admin/taxi/taxis/${taxi.uuid}`, data)
                : api.post('/admin/taxi/taxis', data),
        });
    },

    openTaxiLineForm(line = null) {
        this.openForm({
            title: line ? t('admin.taxi.line_edit', { code: line.code }) : t('admin.taxi.new_line'),
            hint: t('admin.taxi.line_hint'),
            fields: [
                ...(line ? [] : [{ name: 'code', label: t('admin.forms.line.code'), required: true }]),
                { name: 'name', label: t('admin.forms.line.name'), required: true },
                { name: 'origin_label', label: t('admin.forms.line.origin') },
                { name: 'destination_label', label: t('admin.forms.line.destination') },
                { name: 'flat_fare', label: t('admin.taxi.flat_fare'), type: 'number', required: true },
                { name: 'typical_duration_minutes', label: t('admin.forms.line.typical_duration'), type: 'number' },
                { name: 'color', label: t('admin.forms.line.color'), type: 'color' },
                ...(line ? [{ name: 'is_active', label: t('admin.forms.fare_rule.is_active'), type: 'checkbox' }] : []),
            ],
            data: line
                ? {
                    name: line.name, origin_label: line.origin, destination_label: line.destination,
                    flat_fare: line.flat_fare, typical_duration_minutes: line.typical_duration_minutes,
                    color: line.color, is_active: line.is_active,
                }
                : { color: '#12b76a' },
            submit: (data) => line
                ? api.patch(`/admin/taxi/lines/${line.id}`, data)
                : api.post('/admin/taxi/lines', data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadTaxiLines();
            },
        });
    },

    openTaxiTariffForm(tariff = null) {
        this.openForm({
            title: tariff ? t('admin.taxi.tariff_edit', { name: tariff.name }) : t('admin.taxi.new_tariff'),
            hint: t('admin.taxi.tariff_hint'),
            fields: [
                { name: 'name', label: t('admin.common.name'), required: true },
                { name: 'service_type', label: t('admin.taxi.service_type'), type: 'select', required: !tariff, options: [
                    { value: 'meter', label: t('enums.taxiservicetype.meter') },
                    { value: 'charter', label: t('enums.taxiservicetype.charter') },
                    { value: 'line', label: t('enums.taxiservicetype.line') },
                ] },
                { name: 'base_fare', label: t('admin.taxi.base_fare'), type: 'number', required: !tariff },
                { name: 'per_km_fare', label: t('admin.taxi.per_km_fare'), type: 'number' },
                { name: 'per_minute_waiting_fare', label: t('admin.taxi.per_minute_waiting_fare'), type: 'number',
                  help: t('admin.taxi.waiting_help') },
                { name: 'minimum_fare', label: t('admin.forms.fare_rule.min_fare'), type: 'number' },
                { name: 'maximum_fare', label: t('admin.forms.fare_rule.max_fare'), type: 'number' },
                { name: 'waiting_speed_kmh', label: t('admin.taxi.waiting_speed'), type: 'number',
                  help: t('admin.taxi.waiting_speed_help') },
                { name: 'multiplier', label: t('admin.forms.fare_rule.multiplier'), type: 'number', step: '0.01' },
                { name: 'valid_from_time', label: t('admin.forms.line.service_start'), type: 'time' },
                { name: 'valid_to_time', label: t('admin.forms.line.service_end'), type: 'time' },
                { name: 'priority', label: t('admin.forms.fare_rule.priority'), type: 'number' },
                ...(tariff ? [{ name: 'is_active', label: t('admin.forms.fare_rule.is_active'), type: 'checkbox' }] : []),
            ],
            data: tariff ?? {
                service_type: 'meter', multiplier: 1, priority: 10, waiting_speed_kmh: 5,
            },
            submit: (data) => tariff
                ? api.patch(`/admin/taxi/tariffs/${tariff.id}`, data)
                : api.post('/admin/taxi/tariffs', data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadTaxiTariffs();
            },
        });
    },

    async approveTaxiSettlement(settlement) {
        if (!confirm(t('admin.taxi.settlement_confirm', { reference: settlement.reference }))) return;

        try {
            await api.post(`/admin/taxi/settlements/${settlement.uuid}/approve`);
            window.toast?.(t('admin.finance.settlement_approved'), 'success');
            await this.loadTaxiSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async payTaxiSettlement(settlement) {
        const reference = prompt(t('admin.finance.transfer_reference_prompt'));

        if (!reference) return;

        try {
            await api.post(`/admin/taxi/settlements/${settlement.uuid}/pay`, { payment_reference: reference });
            window.toast?.(t('admin.finance.payment_recorded'), 'success');
            await this.loadTaxiSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async rejectTaxiSettlement(settlement) {
        const reason = prompt(t('admin.finance.settlement_reject_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/taxi/settlements/${settlement.uuid}/reject`, { reason });
            window.toast?.(t('admin.finance.settlement_rejected'), 'success');
            await this.loadTaxiSettlements();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },


    // ── school transport ────────────────────────────────────────────────

    async loadSchoolTab(tab = null) {
        if (tab) this.schoolTab = tab;

        switch (this.schoolTab) {
            case 'companies': await this.loadSchoolCompanies(); break;
            case 'contracts': await Promise.all([this.loadSchoolContracts(), this.loadSchoolRoutes()]); break;
            case 'routes': await Promise.all([this.loadSchoolRoutes(), this.loadSchoolVehicles(), this.loadSchools()]); break;
            case 'vehicles': await Promise.all([this.loadSchoolVehicles(), this.loadSchoolCompanies()]); break;
            case 'trips': await this.loadSchoolTrips(); break;
            case 'live': await this.loadSchoolLive(); break;
        }
    },

    async loadSchoolCompanies() {
        const { data } = await api.get('/admin/school/companies', { query: this.filters.school });
        this.schoolCompanies = data;
    },

    async loadSchoolContracts() {
        const { data } = await api.get('/admin/school/contracts', { query: this.filters.school });
        this.schoolContracts = data;
    },

    async loadSchoolRoutes() {
        const { data } = await api.get('/admin/school/routes');
        this.schoolRoutes = data;
    },

    async loadSchoolVehicles() {
        const { data } = await api.get('/admin/school/vehicles');
        this.schoolVehicles = data;
    },

    async loadSchools() {
        const { data } = await api.get('/admin/school/schools');
        this.schoolSchools = data;
    },

    async loadSchoolTrips() {
        const { data } = await api.get('/admin/school/trips');
        this.schoolTrips = data;
    },

    async loadSchoolLive() {
        const { data } = await api.get('/admin/school/live');
        this.schoolLive = data;

        await this.initSchoolMap();

        clearInterval(this._schoolLiveTimer);
        this._schoolLiveTimer = setInterval(async () => {
            if (this.view !== 'school' || this.schoolTab !== 'live') {
                clearInterval(this._schoolLiveTimer);

                return;
            }

            try {
                const refreshed = await api.get('/admin/school/live');
                this.schoolLive = refreshed.data;
                this._schoolLayer?.sync(this.schoolLive, (van) => van.trip_uuid);
            } catch {
                // Last known positions beat a blank board.
            }
        }, 10_000);
    },

    async initSchoolMap() {
        if (this._schoolMap) {
            this._schoolLayer.sync(this.schoolLive, (van) => van.trip_uuid);

            return;
        }

        await this.$nextTick();

        const element = document.getElementById('admin-school-map');

        if (!element) return;

        const { data: config } = await api.get('/map/config');
        this._schoolMap = await createMap(element, config);

        this._schoolLayer = new MarkerLayer(this._schoolMap, {
            iconFor: (van) => vanIcon({
                color: van.direction === 'to_school' ? '#f79009' : '#12b76a',
                label: van.vehicle_plate,
            }),
            popupFor: (van) => `
                <strong>${van.route_name ?? '—'}</strong><br>
                <span style="color:#9db2b9;font-size:12px">${van.school_name ?? '—'}</span><br>
                <span style="color:#9db2b9;font-size:12px">${t(`enums.schoolservicedirection.${van.direction}`)}</span><br>
                <span style="color:#32d583;font-size:12px">${t('admin.school.popup_aboard', { aboard: van.aboard_count ?? 0, expected: van.expected_count ?? 0 })}</span>`,
        });

        this._schoolLayer.sync(this.schoolLive, (van) => van.trip_uuid);
    },

    focusSchoolVan(van) {
        this._schoolMap?.setView([van.lat, van.lng], 16);
    },

    async approveSchoolCompany(company) {
        if (!confirm(t('admin.school.approve_confirm', { name: company.name }))) return;

        try {
            await api.post(`/admin/school/companies/${company.uuid}/approve`);
            window.toast?.(t('admin.school.company_approved'), 'success');
            await this.loadSchoolCompanies();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async rejectSchoolCompany(company) {
        const reason = prompt(t('admin.school.reject_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/school/companies/${company.uuid}/reject`, { reason });
            await this.loadSchoolCompanies();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    async suspendSchoolCompany(company) {
        const reason = prompt(t('admin.school.suspend_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/school/companies/${company.uuid}/suspend`, { reason });
            window.toast?.(t('admin.school.company_suspended'), 'success');
            await this.loadSchoolCompanies();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    /** Accept a family, and name the fee in the same breath. */
    openContractAcceptForm(contract) {
        this.openForm({
            title: t('admin.school.accept_title', { student: contract.student?.name }),
            hint: t('admin.school.accept_hint'),
            fields: [
                { name: 'fee_amount', label: t('admin.school.fee_amount'), type: 'number', required: true },
                { name: 'payment_cycle', label: t('admin.school.payment_cycle'), type: 'select', options: [
                    { value: 'monthly', label: t('admin.forms.merchant.cycles.monthly') },
                    { value: 'termly', label: t('admin.school.cycles.termly') },
                    { value: 'yearly', label: t('admin.school.cycles.yearly') },
                ] },
                { name: 'note', label: t('admin.school.company_note'), type: 'textarea', wide: true },
            ],
            data: { payment_cycle: 'monthly' },
            submit: (data) => api.post(`/admin/school/contracts/${contract.uuid}/accept`, data),
            onDone: async () => {
                window.toast?.(t('admin.school.contract_accepted'), 'success');
                await this.loadSchoolContracts();
            },
        });
    },

    async rejectSchoolContract(contract) {
        const reason = prompt(t('admin.school.contract_reject_prompt'));

        if (!reason) return;

        try {
            await api.post(`/admin/school/contracts/${contract.uuid}/reject`, { reason });
            await this.loadSchoolContracts();
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    /**
     * Give the child a seat.
     *
     * Only routes serving the same school are offered: a van that never goes
     * there cannot carry this child, and letting an operator pick one would
     * only produce an error a moment later.
     */
    async openContractRouteForm(contract) {
        if (!this.schoolRoutes.length) await this.loadSchoolRoutes().catch(() => {});

        const eligible = this.schoolRoutes.filter(
            (route) => route.school?.uuid === contract.school?.uuid && route.seats_free > 0,
        );

        this.openForm({
            title: t('admin.school.assign_title', { student: contract.student?.name }),
            hint: eligible.length ? t('admin.school.assign_hint') : t('admin.school.no_eligible_route'),
            fields: [
                { name: 'route_uuid', label: t('admin.school.route'), type: 'select', required: true,
                  options: eligible.map((route) => ({
                      value: route.uuid,
                      label: `${route.name} — ${t('admin.school.seats_free', { count: formatNumber(route.seats_free) })}`,
                  })) },
            ],
            submit: (data) => api.post(`/admin/school/contracts/${contract.uuid}/route`, data),
            onDone: async () => {
                window.toast?.(t('admin.school.contract_assigned'), 'success');
                await Promise.all([this.loadSchoolContracts(), this.loadSchoolRoutes()]);
            },
        });
    },

    async billSchoolContract(contract) {
        try {
            await api.post(`/admin/school/contracts/${contract.uuid}/bill`);
            window.toast?.(t('admin.school.invoice_issued'), 'success');
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    openSchoolRouteForm(route = null) {
        this.openForm({
            title: route ? t('admin.school.route_edit', { name: route.name }) : t('admin.school.new_route'),
            hint: t('admin.school.route_hint'),
            fields: [
                ...(route ? [] : [
                    { name: 'company_uuid', label: t('admin.school.company'), type: 'select', required: true,
                      options: this.schoolCompanies
                          .filter((company) => company.is_approved)
                          .map((company) => ({ value: company.uuid, label: company.name })) },
                    { name: 'school_uuid', label: t('admin.school.school'), type: 'select', required: true,
                      options: this.schoolSchools.map((school) => ({ value: school.uuid, label: school.name })) },
                ]),
                { name: 'name', label: t('admin.common.name'), required: true },
                { name: 'shift', label: t('admin.school.shift'), type: 'select', options: [
                    { value: 'both', label: t('enums.schoolservicedirection.both') },
                    { value: 'morning', label: t('enums.schoolservicedirection.to_school') },
                    { value: 'afternoon', label: t('enums.schoolservicedirection.from_school') },
                ] },
                { name: 'capacity', label: t('admin.fleet.capacity'), type: 'number', required: true },
                { name: 'pickup_starts_at', label: t('admin.school.pickup_starts_at'), type: 'time' },
                { name: 'dropoff_starts_at', label: t('admin.school.dropoff_starts_at'), type: 'time' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
                ...(route ? [{ name: 'is_active', label: t('admin.forms.fare_rule.is_active'), type: 'checkbox' }] : []),
            ],
            data: route
                ? {
                    name: route.name, shift: route.shift, capacity: route.capacity,
                    pickup_starts_at: route.pickup_starts_at, dropoff_starts_at: route.dropoff_starts_at,
                    notes: route.notes, is_active: route.is_active,
                }
                : { shift: 'both', capacity: 15 },
            submit: (data) => route
                ? api.patch(`/admin/school/routes/${route.uuid}`, data)
                : api.post('/admin/school/routes', data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadSchoolRoutes();
            },
        });
    },

    /**
     * A van and a driver, together.
     *
     * One action on purpose: assigning a van and forgetting the driver leaves
     * a route that looks ready and is not.
     */
    async openRouteCrewForm(route) {
        if (!this.schoolVehicles.length) await this.loadSchoolVehicles().catch(() => {});
        if (!this.drivers.length) await this.loadDrivers().catch(() => {});

        this.openForm({
            title: t('admin.school.crew_title', { name: route.name }),
            hint: t('admin.school.crew_hint'),
            fields: [
                { name: 'vehicle_uuid', label: t('admin.school.vehicle'), type: 'select',
                  options: this.schoolVehicles.map((vehicle) => ({
                      value: vehicle.uuid,
                      label: `${vehicle.plate}${vehicle.compliance_blocker ? ' ⚠' : ''}`,
                  })) },
                { name: 'driver_uuid', label: t('admin.reports.driver'), type: 'select',
                  options: this.drivers
                      .filter((driver) => driver.status === 'active')
                      .map((driver) => ({ value: driver.uuid, label: driver.name })) },
            ],
            data: { vehicle_uuid: route.vehicle?.uuid, driver_uuid: route.driver?.uuid },
            submit: (data) => api.post(`/admin/school/routes/${route.uuid}/crew`, data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadSchoolRoutes();
            },
        });
    },

    async openRouteSeats(route) {
        this.schoolRouteModal = route;
        this.schoolRouteSeats = [];

        try {
            const { data } = await api.get(`/admin/school/routes/${route.uuid}/contracts`);
            this.schoolRouteSeats = data;
        } catch (error) {
            window.toast?.(error.message, 'error');
        }
    },

    openSchoolVehicleForm(vehicle = null) {
        this.openForm({
            title: vehicle ? t('admin.school.vehicle_edit', { plate: vehicle.plate }) : t('admin.school.new_vehicle'),
            fields: [
                ...(vehicle ? [] : [
                    { name: 'company_uuid', label: t('admin.school.company'), type: 'select', required: true,
                      options: this.schoolCompanies
                          .filter((company) => company.is_approved)
                          .map((company) => ({ value: company.uuid, label: company.name })) },
                    { name: 'plate', label: t('admin.fleet.plate'), required: true },
                ]),
                { name: 'model', label: t('admin.forms.bus.model') },
                { name: 'color', label: t('admin.taxi.color') },
                { name: 'capacity', label: t('admin.fleet.capacity'), type: 'number', required: true },
                ...(vehicle ? [{ name: 'status', label: t('admin.common.status'), type: 'select', options: [
                    { value: 'active', label: t('admin.common.active') },
                    { value: 'maintenance', label: t('enums.taxistatus.maintenance') },
                    { value: 'out_of_service', label: t('enums.taxistatus.out_of_service') },
                ] }] : []),
                { name: 'insurance_expires_at', label: t('admin.school.insurance_expires_at'), type: 'date',
                  help: t('admin.school.insurance_help') },
                { name: 'inspection_due_at', label: t('admin.school.inspection_due_at'), type: 'date' },
                { name: 'has_supervisor', label: t('admin.school.has_supervisor'), type: 'checkbox' },
                { name: 'has_seatbelts', label: t('admin.school.has_seatbelts'), type: 'checkbox' },
                { name: 'notes', label: t('admin.forms.bus.notes'), type: 'textarea', wide: true },
            ],
            data: vehicle ?? { capacity: 15, has_seatbelts: true },
            submit: (data) => vehicle
                ? api.patch(`/admin/school/vehicles/${vehicle.uuid}`, data)
                : api.post('/admin/school/vehicles', data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadSchoolVehicles();
            },
        });
    },

    openSchoolForm() {
        this.openForm({
            title: t('admin.school.new_school'),
            fields: [
                { name: 'name', label: t('admin.common.name'), required: true },
                { name: 'gender', label: t('admin.school.gender'), type: 'select', options: [
                    { value: 'mixed', label: t('admin.school.genders.mixed') },
                    { value: 'girls', label: t('admin.school.genders.girls') },
                    { value: 'boys', label: t('admin.school.genders.boys') },
                ] },
                { name: 'level', label: t('admin.school.level'), type: 'select', options: [
                    { value: 'primary', label: t('admin.school.levels.primary') },
                    { value: 'middle', label: t('admin.school.levels.middle') },
                    { value: 'high', label: t('admin.school.levels.high') },
                ] },
                { name: 'address', label: t('admin.forms.stop.address'), wide: true },
                { name: 'lat', label: t('admin.forms.stop.lat'), type: 'number', step: 'any' },
                { name: 'lng', label: t('admin.forms.stop.lng'), type: 'number', step: 'any' },
                { name: 'starts_at', label: t('admin.school.starts_at'), type: 'time' },
                { name: 'ends_at', label: t('admin.school.ends_at'), type: 'time' },
                { name: 'phone', label: t('admin.forms.merchant.phone') },
            ],
            data: { gender: 'mixed', level: 'primary' },
            submit: (data) => api.post('/admin/school/schools', data),
            onDone: async () => {
                window.toast?.(t('admin.common.saved'), 'success');
                await this.loadSchools();
            },
        });
    },

    async scheduleSchoolTrips() {
        this.busy = true;

        try {
            const { data } = await api.post('/admin/school/trips/schedule');
            window.toast?.(t('admin.school.runs_created', { count: formatNumber(data.runs_created) }), 'success');
            await this.loadSchoolTrips();
        } catch (error) {
            window.toast?.(error.message, 'error');
        } finally {
            this.busy = false;
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
