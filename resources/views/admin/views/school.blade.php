{{-- ── School transport ─────────────────────────────────────────────────────
     Two audiences share this screen. A city administrator holds `school.admin`
     and sees every company; a company manager holds only `school.manage` and
     the server narrows each query to their own company. The markup is the same
     either way — the difference is in what comes back. --}}
<section x-show="view === 'school'" x-cloak class="flex flex-col gap-3">

    <div class="glass card flex flex-wrap items-center gap-1.5 !py-2">
        <template x-for="tab in schoolTabs" :key="tab.id">
            <button type="button" class="btn btn-sm"
                    :class="schoolTab === tab.id ? 'btn-primary' : 'btn-ghost'"
                    @click="loadSchoolTab(tab.id)" x-text="tab.label"></button>
        </template>
    </div>

    {{-- ── companies ──────────────────────────────────────────────────────
         Nobody appears to a parent until an administrator has approved them:
         this table is that gate. --}}
    <template x-if="schoolTab === 'companies'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <select class="field max-w-[12rem] !py-2 text-sm" x-model="filters.school.status" @change="loadSchoolCompanies()">
                    <option value="">{{ __('admin.common.all_statuses') }}</option>
                    <option value="pending_approval">{{ __('enums.schoolcompanystatus.pending_approval') }}</option>
                    <option value="active">{{ __('enums.schoolcompanystatus.active') }}</option>
                    <option value="suspended">{{ __('enums.schoolcompanystatus.suspended') }}</option>
                    <option value="rejected">{{ __('enums.schoolcompanystatus.rejected') }}</option>
                </select>
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.school.companies_hint') }}</p>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.common.name') }}</th>
                                <th>{{ __('admin.school.license') }}</th>
                                <th>{{ __('admin.forms.merchant.phone') }}</th>
                                <th>{{ __('admin.school.fleet') }}</th>
                                <th>{{ __('admin.school.contracts') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="company in schoolCompanies" :key="company.uuid">
                                <tr>
                                    <td>
                                        <p class="font-semibold" x-text="company.name"></p>
                                        <p class="text-[11px] text-ink-500" x-text="company.legal_name || '—'"></p>
                                    </td>
                                    <td class="text-xs text-ink-400">
                                        <span dir="ltr" x-text="company.license_number || '—'"></span>
                                        <span x-show="company.license_expires_at" class="block text-[10px]"
                                              x-text="$t('admin.school.license_expires', { date: $num(company.license_expires_at) })"></span>
                                    </td>
                                    <td class="text-xs" dir="ltr" x-text="company.phone || '—'"></td>
                                    <td class="text-xs" x-text="$num(company.vehicle_count ?? 0)"></td>
                                    <td class="text-xs" x-text="$num(company.contract_count ?? 0)"></td>
                                    <td>
                                        <span class="badge" :class="`badge-${company.status_color}`" x-text="company.status_label"></span>
                                    </td>
                                    <td>
                                        <div class="flex gap-1">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    x-show="company.status === 'pending_approval'"
                                                    @click="approveSchoolCompany(company)">{{ __('admin.common.approve') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm text-red-300"
                                                    x-show="company.status === 'pending_approval'"
                                                    @click="rejectSchoolCompany(company)">{{ __('admin.finance.reject') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm text-amber-300"
                                                    x-show="company.status === 'active'"
                                                    @click="suspendSchoolCompany(company)">{{ __('admin.common.suspend') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!schoolCompanies.length" class="py-10 text-center text-sm text-ink-500">
                    {{ __('admin.school.no_companies') }}
                </p>
            </div>
        </div>
    </template>

    {{-- ── contracts ──────────────────────────────────────────────────────
         A family's request travels: requested → accepted with a fee named →
         given a seat on a route → billed. Each step is one button here. --}}
    <template x-if="schoolTab === 'contracts'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <select class="field max-w-[12rem] !py-2 text-sm" x-model="filters.school.status" @change="loadSchoolContracts()">
                    <option value="">{{ __('admin.common.all_statuses') }}</option>
                    <option value="requested">{{ __('enums.schoolcontractstatus.requested') }}</option>
                    <option value="approved">{{ __('enums.schoolcontractstatus.approved') }}</option>
                    <option value="active">{{ __('enums.schoolcontractstatus.active') }}</option>
                    <option value="ended">{{ __('enums.schoolcontractstatus.ended') }}</option>
                    <option value="rejected">{{ __('enums.schoolcontractstatus.rejected') }}</option>
                </select>
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.school.contracts_hint') }}</p>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.finance.reference') }}</th>
                                <th>{{ __('admin.school.student') }}</th>
                                <th>{{ __('admin.school.school') }}</th>
                                <th>{{ __('admin.school.pickup') }}</th>
                                <th>{{ __('admin.school.direction') }}</th>
                                <th>{{ __('admin.school.fee_amount') }}</th>
                                <th>{{ __('admin.school.route') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="contract in schoolContracts" :key="contract.uuid">
                                <tr>
                                    <td class="font-mono text-xs" dir="ltr" x-text="contract.reference"></td>
                                    <td>
                                        <p class="text-xs font-semibold" x-text="contract.student?.name || '—'"></p>
                                        <p class="text-[11px] text-ink-500" x-text="contract.student?.grade || ''"></p>
                                    </td>
                                    <td class="text-xs text-ink-400" x-text="contract.school?.name || '—'"></td>
                                    <td class="max-w-[16rem] truncate text-[11px] text-ink-400" x-text="contract.pickup_address || '—'"></td>
                                    <td class="text-xs" x-text="contract.direction_label"></td>
                                    <td class="text-xs" x-text="contract.fee_amount ? contract.formatted_fee : '—'"></td>
                                    <td class="text-xs">
                                        <span x-show="contract.route" x-text="contract.route?.name"></span>
                                        <span x-show="contract.route?.vehicle_plate" class="block text-[10px] text-ink-500"
                                              dir="ltr" x-text="contract.route?.vehicle_plate"></span>
                                        <span x-show="!contract.route" class="text-ink-500">—</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="`badge-${contract.status_color}`" x-text="contract.status_label"></span>
                                        <p x-show="contract.rejection_reason" class="mt-1 text-[10px] text-ink-500"
                                           x-text="contract.rejection_reason"></p>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    x-show="contract.status === 'requested'"
                                                    @click="openContractAcceptForm(contract)">{{ __('admin.common.approve') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm text-red-300"
                                                    x-show="contract.status === 'requested'"
                                                    @click="rejectSchoolContract(contract)">{{ __('admin.finance.reject') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    x-show="contract.status === 'approved' || (contract.status === 'active' && !contract.route)"
                                                    @click="openContractRouteForm(contract)">{{ __('admin.school.assign_route') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    x-show="contract.status === 'active'"
                                                    @click="billSchoolContract(contract)">{{ __('admin.school.bill') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!schoolContracts.length" class="py-10 text-center text-sm text-ink-500">
                    {{ __('admin.school.no_contracts') }}
                </p>
            </div>
        </div>
    </template>

    {{-- ── routes ─────────────────────────────────────────────────────────
         A route is only ready when it has a van, a driver and paperwork in
         date. The server answers that as one blocker rather than leaving the
         panel to compare four fields. --}}
    <template x-if="schoolTab === 'routes'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.school.route_hint') }}</p>
                <button type="button" class="btn btn-ghost btn-sm ms-auto" @click="openSchoolForm()">
                    {{ __('admin.school.new_school') }}
                </button>
                <button type="button" class="btn btn-primary btn-sm" @click="openSchoolRouteForm()">
                    {{ __('admin.school.new_route') }}
                </button>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.common.name') }}</th>
                                <th>{{ __('admin.school.school') }}</th>
                                <th>{{ __('admin.school.shift') }}</th>
                                <th>{{ __('admin.school.seats') }}</th>
                                <th>{{ __('admin.school.vehicle') }}</th>
                                <th>{{ __('admin.reports.driver') }}</th>
                                <th>{{ __('admin.school.times') }}</th>
                                <th>{{ __('admin.school.readiness') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="route in schoolRoutes" :key="route.uuid">
                                <tr>
                                    <td>
                                        <p class="font-semibold" x-text="route.name"></p>
                                        <p class="text-[11px] text-ink-500" x-text="route.company?.name || ''"></p>
                                    </td>
                                    <td class="text-xs text-ink-400" x-text="route.school?.name || '—'"></td>
                                    <td class="text-xs" x-text="$t(`admin.school.shifts.${route.shift}`)"></td>
                                    <td class="text-xs">
                                        <span x-text="$num(route.seats_taken)"></span><span class="text-ink-500">/</span><span x-text="$num(route.capacity)"></span>
                                        <span class="block text-[10px]"
                                              :class="route.seats_free > 0 ? 'text-emerald-400' : 'text-amber-400'"
                                              x-text="$t('admin.school.seats_free', { count: $num(route.seats_free) })"></span>
                                    </td>
                                    <td class="text-xs" dir="ltr" x-text="route.vehicle?.plate || '—'"></td>
                                    <td class="text-xs" x-text="route.driver?.name || '—'"></td>
                                    <td class="text-xs text-ink-400" dir="ltr"
                                        x-text="`${route.pickup_starts_at || '—'} / ${route.dropoff_starts_at || '—'}`"></td>
                                    <td>
                                        <span class="badge" :class="route.readiness_blocker ? 'badge-warning' : 'badge-success'"
                                              x-text="route.readiness_blocker
                                                  ? $t(`admin.school.blockers.${route.readiness_blocker}`)
                                                  : $t('admin.school.ready')"></span>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openSchoolRouteForm(route)">{{ __('admin.common.edit') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openRouteCrewForm(route)">{{ __('admin.school.crew') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openRouteSeats(route)">{{ __('admin.school.manifest') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!schoolRoutes.length" class="py-10 text-center text-sm text-ink-500">
                    {{ __('admin.school.no_routes') }}
                </p>
            </div>
        </div>
    </template>

    {{-- ── vehicles ───────────────────────────────────────────────────── --}}
    <template x-if="schoolTab === 'vehicles'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.school.vehicles_hint') }}</p>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openSchoolVehicleForm()">
                    {{ __('admin.school.new_vehicle') }}
                </button>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.fleet.plate') }}</th>
                                <th>{{ __('admin.forms.bus.model') }}</th>
                                <th>{{ __('admin.fleet.capacity') }}</th>
                                <th>{{ __('admin.school.insurance_expires_at') }}</th>
                                <th>{{ __('admin.school.inspection_due_at') }}</th>
                                <th>{{ __('admin.school.safety') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="vehicle in schoolVehicles" :key="vehicle.uuid">
                                <tr>
                                    <td class="font-semibold" dir="ltr" x-text="vehicle.plate"></td>
                                    <td class="text-xs text-ink-400" x-text="vehicle.model || '—'"></td>
                                    <td class="text-xs" x-text="$num(vehicle.capacity)"></td>
                                    <td class="text-xs" x-text="vehicle.insurance_expires_at ? $num(vehicle.insurance_expires_at) : '—'"></td>
                                    <td class="text-xs" x-text="vehicle.inspection_due_at ? $num(vehicle.inspection_due_at) : '—'"></td>
                                    <td class="text-[11px] text-ink-400">
                                        <span x-show="vehicle.has_supervisor">{{ __('admin.school.has_supervisor') }}</span>
                                        <span x-show="vehicle.has_seatbelts" class="block">{{ __('admin.school.has_seatbelts') }}</span>
                                    </td>
                                    <td>
                                        {{-- Paperwork out of date keeps a van off the road, and that is a
                                             louder answer than the status column. --}}
                                        <span class="badge" :class="vehicle.compliance_blocker ? 'badge-warning' : 'badge-success'"
                                              x-text="vehicle.compliance_blocker
                                                  ? $t(`admin.school.blockers.${vehicle.compliance_blocker}`)
                                                  : $t('admin.common.active')"></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="openSchoolVehicleForm(vehicle)">{{ __('admin.common.edit') }}</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!schoolVehicles.length" class="py-10 text-center text-sm text-ink-500">
                    {{ __('admin.school.no_vehicles') }}
                </p>
            </div>
        </div>
    </template>

    {{-- ── runs ───────────────────────────────────────────────────────── --}}
    <template x-if="schoolTab === 'trips'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.school.trips_hint') }}</p>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="scheduleSchoolTrips()" :disabled="busy">
                    {{ __('admin.school.schedule_runs') }}
                </button>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.school.service_date') }}</th>
                                <th>{{ __('admin.school.route') }}</th>
                                <th>{{ __('admin.school.direction') }}</th>
                                <th>{{ __('admin.school.vehicle') }}</th>
                                <th>{{ __('admin.school.attendance') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="trip in schoolTrips" :key="trip.uuid">
                                <tr>
                                    <td class="text-xs font-semibold" x-text="$num(trip.service_date)"></td>
                                    <td class="text-xs">
                                        <span x-text="trip.route?.name || '—'"></span>
                                        <span class="block text-[10px] text-ink-500" x-text="trip.route?.school?.name || ''"></span>
                                    </td>
                                    <td class="text-xs" x-text="trip.direction_label"></td>
                                    <td class="text-xs" dir="ltr" x-text="trip.vehicle?.plate || '—'"></td>
                                    <td class="text-xs">
                                        <span x-text="$t('admin.school.attendance_summary', {
                                            picked: $num(trip.picked_up_count),
                                            dropped: $num(trip.dropped_off_count),
                                            expected: $num(trip.expected_count),
                                        })"></span>
                                        <span x-show="trip.absent_count" class="block text-[10px] text-amber-400"
                                              x-text="$t('admin.school.absent_count', { count: $num(trip.absent_count) })"></span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="`badge-${trip.status_color}`" x-text="trip.status_label"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!schoolTrips.length" class="py-10 text-center text-sm text-ink-500">
                    {{ __('admin.school.no_trips') }}
                </p>
            </div>
        </div>
    </template>

    {{-- ── live ───────────────────────────────────────────────────────────
         Operations sees every van that is running. A parent sees exactly one,
         only while their own child is aboard — a different endpoint, and a
         different rule. --}}
    <template x-if="schoolTab === 'live'">
        <div class="grid gap-3 lg:grid-cols-[1fr_330px]">
            <div class="glass card !p-1.5">
                <div id="admin-school-map" class="h-[70vh] w-full rounded-2xl"></div>
            </div>

            <div class="glass card flex flex-col">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold">{{ __('admin.school.live_title') }}</h2>
                    <span class="badge badge-success">
                        <span class="live-dot"></span>
                        <span x-text="$num(schoolLive.length)"></span>
                    </span>
                </div>

                <div class="no-scrollbar mt-3 flex max-h-[62vh] flex-col gap-2 overflow-y-auto">
                    <template x-for="van in schoolLive" :key="van.trip_uuid">
                        <button type="button" class="rounded-xl bg-white/[0.03] px-3 py-2.5 text-start transition hover:bg-white/[0.06]"
                                @click="focusSchoolVan(van)">
                            <div class="flex items-center gap-2">
                                <span class="size-2 shrink-0 rounded-full"
                                      :style="`background:${van.direction === 'to_school' ? '#f79009' : '#12b76a'}`"></span>
                                <span class="truncate text-sm font-semibold" x-text="van.route_name || '—'"></span>
                                <span class="ms-auto text-[10px] text-ink-500" dir="ltr" x-text="van.vehicle_plate || '—'"></span>
                            </div>
                            <p class="mt-1 truncate text-[11px] text-ink-400" x-text="van.school_name || ''"></p>
                            <div class="mt-1.5 flex items-center gap-3 text-[10px] text-ink-500">
                                <span x-text="$t(`enums.schoolservicedirection.${van.direction}`)"></span>
                                <span x-text="$t('admin.school.popup_aboard', {
                                    aboard: $num(van.aboard_count ?? 0),
                                    expected: $num(van.expected_count ?? 0),
                                })"></span>
                            </div>
                        </button>
                    </template>
                    <p x-show="!schoolLive.length" class="py-8 text-center text-sm text-ink-500">
                        {{ __('admin.school.none_live') }}
                    </p>
                </div>
            </div>
        </div>
    </template>
</section>

{{-- ── route manifest ───────────────────────────────────────────────────────
     Who is on this van, in the order the driver will collect them. --}}
<div x-show="schoolRouteModal" x-cloak
     class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-4"
     @keydown.escape.window="schoolRouteModal = null">
    <div class="glass-strong card w-full max-w-2xl" @click.outside="schoolRouteModal = null">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-bold" x-text="$t('admin.school.manifest_title', { name: schoolRouteModal?.name })"></h3>
            <button type="button" class="ms-auto text-ink-400 hover:text-ink-100" @click="schoolRouteModal = null">✕</button>
        </div>

        <p class="mt-2 text-xs leading-6 text-ink-400">{{ __('admin.school.manifest_note') }}</p>

        <div class="mt-4 max-h-[60vh] overflow-y-auto">
            <table class="table text-sm">
                <thead>
                    <tr>
                        <th>{{ __('admin.school.student') }}</th>
                        <th>{{ __('admin.school.pickup') }}</th>
                        <th>{{ __('admin.school.direction') }}</th>
                        <th>{{ __('admin.school.fee_amount') }}</th>
                        <th>{{ __('admin.common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="seat in schoolRouteSeats" :key="seat.uuid">
                        <tr>
                            <td>
                                <p class="text-xs font-semibold" x-text="seat.student?.name || '—'"></p>
                                <p class="text-[11px] text-ink-500" x-text="seat.student?.grade || ''"></p>
                            </td>
                            <td class="max-w-[16rem] truncate text-[11px] text-ink-400" x-text="seat.pickup_address || '—'"></td>
                            <td class="text-xs" x-text="seat.direction_label"></td>
                            <td class="text-xs" x-text="seat.fee_amount ? seat.formatted_fee : '—'"></td>
                            <td>
                                <span class="badge" :class="`badge-${seat.status_color}`" x-text="seat.status_label"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p x-show="!schoolRouteSeats.length" class="py-6 text-center text-sm text-ink-500">
                {{ __('admin.school.manifest_empty') }}
            </p>
        </div>
    </div>
</div>
