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
import { createMap, busIcon, MarkerLayer } from './lib/map.js';
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
    },

    reply: { body: '', internal: false },

    qrModal: null,

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
            { label: t('admin.dashboard.kpi.buses_moving'), value: formatNumber(k.buses_moving), tone: 'brand-500', live: true, icon: NAV[2].icon, caption: t('admin.dashboard.kpi.buses_moving_caption', { total: formatNumber(k.total_buses) }) },
            { label: t('admin.dashboard.kpi.passengers_on_board'), value: formatNumber(k.passengers_on_board), tone: 'brand-500', live: true, icon: NAV[3].icon },
            { label: t('admin.dashboard.kpi.trips_today'), value: formatNumber(k.trips_today), tone: 'white/5', icon: NAV[4].icon },
            { label: t('admin.dashboard.kpi.boardings_today'), value: formatNumber(k.boardings_today), tone: 'white/5', icon: NAV[3].icon, caption: t('admin.dashboard.kpi.boardings_today_caption', { count: formatNumber(k.unique_passengers_today) }) },
            { label: t('admin.dashboard.kpi.active_drivers'), value: formatNumber(k.active_drivers), tone: 'white/5', icon: NAV[3].icon, caption: t('admin.dashboard.kpi.active_drivers_caption', { count: formatNumber(k.drivers_on_shift) }) },
            { label: t('admin.dashboard.kpi.fare_revenue_today'), value: this.$money(k.fare_revenue_today), tone: 'brand-500', icon: NAV[5].icon },
            { label: t('admin.dashboard.kpi.topup_today'), value: this.$money(k.topup_amount_today), tone: 'white/5', icon: NAV[5].icon },
            { label: t('admin.dashboard.kpi.open_complaints'), value: formatNumber(k.open_complaints), tone: 'white/5', icon: NAV[7].icon, caption: t('admin.dashboard.kpi.open_complaints_caption', { count: formatNumber(k.complaints_today) }) },
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
                case 'finance': await Promise.all([this.loadFinance(), this.loadFareRules(), this.loadSettlements(), this.loadTransactions()]); break;
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

    openStopForm() {
        this.openForm({
            title: t('admin.forms.stop.add'),
            hint: t('admin.forms.stop.hint'),
            fields: [
                { name: 'code', label: t('admin.forms.stop.code'), required: true },
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
            data: { geofence_radius: 60 },
            submit: (data) => api.post('/admin/network/stops', data),
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
