{{-- ── Taxis ────────────────────────────────────────────────────────────────
     Three products share one fleet: a fixed line, a charter agreed in the car,
     and a metered ride. What a car is offering lives on its open shift, not on
     the vehicle, so the fleet table shows what it is licensed to run and the
     live board shows what it is running right now. --}}
<section x-show="view === 'taxis'" x-cloak class="flex flex-col gap-3">

    <div class="glass card flex flex-wrap items-center gap-1.5 !py-2">
        <template x-for="tab in taxiTabs" :key="tab.id">
            <button type="button" class="btn btn-sm"
                    :class="taxiTab === tab.id ? 'btn-primary' : 'btn-ghost'"
                    @click="loadTaxiTab(tab.id)" x-text="tab.label"></button>
        </template>
    </div>

    {{-- ── fleet ──────────────────────────────────────────────────────── --}}
    <template x-if="taxiTab === 'fleet'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <input type="search" class="field max-w-xs !py-2 text-sm"
                       placeholder="{{ __('admin.taxi.search_placeholder') }}"
                       x-model.debounce.400ms="filters.taxis.q" @input="loadTaxis()">
                <select class="field max-w-[10rem] !py-2 text-sm" x-model="filters.taxis.status" @change="loadTaxis()">
                    <option value="">{{ __('admin.common.all_statuses') }}</option>
                    <option value="active">{{ __('enums.taxistatus.active') }}</option>
                    <option value="idle">{{ __('enums.taxistatus.idle') }}</option>
                    <option value="maintenance">{{ __('enums.taxistatus.maintenance') }}</option>
                    <option value="out_of_service">{{ __('enums.taxistatus.out_of_service') }}</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openTaxiForm()">
                    {{ __('admin.taxi.new_taxi') }}
                </button>
                <span class="text-xs text-ink-400" x-text="$t('admin.taxi.count', { count: $num(taxis.length) })"></span>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.taxi.number') }}</th>
                                <th>{{ __('admin.fleet.plate') }}</th>
                                <th>{{ __('admin.taxi.allowed_modes') }}</th>
                                <th>{{ __('admin.taxi.default_line') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.fleet.current_driver') }}</th>
                                <th>{{ __('admin.fleet.last_position') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="taxi in taxis" :key="taxi.uuid">
                                <tr>
                                    <td class="font-semibold" x-text="$num(taxi.taxi_number)"></td>
                                    <td class="text-xs text-ink-400" dir="ltr" x-text="taxi.plate || '—'"></td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="mode in taxi.allowed_modes" :key="mode">
                                                <span class="badge !border-transparent text-[10px]"
                                                      :style="`background:${taxiModeColor(mode)}22;color:${taxiModeColor(mode)}`"
                                                      x-text="$t(`enums.taxiservicetype.${mode}`)"></span>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="text-xs text-ink-400"
                                        x-text="taxi.default_line ? `${taxi.default_line.code} — ${taxi.default_line.name}` : '—'"></td>
                                    <td>
                                        <span class="badge" :class="`badge-${taxi.status_color}`" x-text="taxi.status_label"></span>
                                    </td>
                                    <td class="text-xs" x-text="taxi.current_driver?.name || '—'"></td>
                                    <td class="text-xs text-ink-400"
                                        x-text="taxi.last_ping_at ? $time(taxi.last_ping_at) : $t('admin.fleet.no_report')"></td>
                                    <td>
                                        <div class="flex gap-1">
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openTaxiForm(taxi)">{{ __('admin.common.edit') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openTaxiQr(taxi)">{{ __('admin.fleet.qr_button') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openTaxiAssignments(taxi)">{{ __('admin.fleet.assignments') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!taxis.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.taxi.empty') }}</p>
            </div>
        </div>
    </template>

    {{-- ── lines ──────────────────────────────────────────────────────── --}}
    <template x-if="taxiTab === 'lines'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.taxi.line_hint') }}</p>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openTaxiLineForm()">
                    {{ __('admin.taxi.new_line') }}
                </button>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.common.code') }}</th>
                                <th>{{ __('admin.common.name') }}</th>
                                <th>{{ __('admin.forms.line.origin') }}</th>
                                <th>{{ __('admin.forms.line.destination') }}</th>
                                <th>{{ __('admin.taxi.flat_fare') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="line in taxiLines" :key="line.id">
                                <tr>
                                    <td class="font-semibold">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="size-2.5 rounded-full" :style="`background:${line.color || '#12b76a'}`"></span>
                                            <span x-text="$num(line.code)"></span>
                                        </span>
                                    </td>
                                    <td x-text="line.name"></td>
                                    <td class="text-xs text-ink-400" x-text="line.origin || '—'"></td>
                                    <td class="text-xs text-ink-400" x-text="line.destination || '—'"></td>
                                    <td class="text-xs" x-text="line.formatted_fare"></td>
                                    <td>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span class="badge" :class="line.is_active ? 'badge-success' : 'badge-neutral'"
                                                  x-text="line.is_active ? $t('admin.common.active') : $t('admin.common.inactive')"></span>
                                            {{-- Sample geometry is never presented as the published network. --}}
                                            <span x-show="!line.is_verified_data" class="badge badge-warning text-[10px]"
                                                  x-text="$t(`enums.networkprovenance.${line.provenance}`)"></span>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="openTaxiLineForm(line)">{{ __('admin.common.edit') }}</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!taxiLines.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.taxi.no_lines') }}</p>
            </div>
        </div>
    </template>

    {{-- ── tariffs ────────────────────────────────────────────────────── --}}
    <template x-if="taxiTab === 'tariffs'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <p class="text-xs leading-6 text-ink-400">{{ __('admin.taxi.tariff_hint') }}</p>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openTaxiTariffForm()">
                    {{ __('admin.taxi.new_tariff') }}
                </button>
            </div>

            <div class="glass card !p-0">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('admin.common.name') }}</th>
                                <th>{{ __('admin.taxi.service_type') }}</th>
                                <th>{{ __('admin.taxi.base_fare') }}</th>
                                <th>{{ __('admin.taxi.per_km_fare') }}</th>
                                <th>{{ __('admin.taxi.per_minute_waiting_fare') }}</th>
                                <th>{{ __('admin.taxi.window') }}</th>
                                <th>{{ __('admin.forms.fare_rule.priority') }}</th>
                                <th>{{ __('admin.common.status') }}</th>
                                <th>{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="tariff in taxiTariffs" :key="tariff.id">
                                <tr>
                                    <td class="font-semibold" x-text="tariff.name"></td>
                                    <td>
                                        <span class="badge !border-transparent text-[10px]"
                                              :style="`background:${taxiModeColor(tariff.service_type)}22;color:${taxiModeColor(tariff.service_type)}`"
                                              x-text="tariff.service_type_label"></span>
                                    </td>
                                    <td class="text-xs" x-text="$money(tariff.base_fare)"></td>
                                    <td class="text-xs" x-text="tariff.per_km_fare ? $money(tariff.per_km_fare) : '—'"></td>
                                    <td class="text-xs" x-text="tariff.per_minute_waiting_fare ? $money(tariff.per_minute_waiting_fare) : '—'"></td>
                                    <td class="text-xs text-ink-400" dir="ltr"
                                        x-text="tariff.valid_from_time ? `${tariff.valid_from_time} – ${tariff.valid_to_time || '24:00'}` : $t('admin.taxi.all_day')"></td>
                                    <td class="text-xs" x-text="$num(tariff.priority)"></td>
                                    <td>
                                        <span class="badge" :class="tariff.is_active ? 'badge-success' : 'badge-neutral'"
                                              x-text="tariff.is_active ? $t('admin.common.active') : $t('admin.common.inactive')"></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="openTaxiTariffForm(tariff)">{{ __('admin.common.edit') }}</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!taxiTariffs.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.taxi.no_tariffs') }}</p>
            </div>
        </div>
    </template>

    {{-- ── live board ─────────────────────────────────────────────────────
         The control room's view: every car in the city, with the plate and who
         is aboard. The passenger feed is a different endpoint on purpose and
         carries none of it. --}}
    <template x-if="taxiTab === 'live'">
        <div class="grid gap-3 lg:grid-cols-[1fr_330px]">
            <div class="glass card !p-1.5">
                <div id="admin-taxi-map" class="h-[70vh] w-full rounded-2xl"></div>
            </div>

            <div class="glass card flex flex-col">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold">{{ __('admin.taxi.live_title') }}</h2>
                    <span class="badge badge-success">
                        <span class="live-dot"></span>
                        <span x-text="$num(taxiLiveMeta.count ?? taxiLive.length)"></span>
                    </span>
                </div>

                {{-- The three products, each in its own colour: the legend and
                     the filter are the same control. --}}
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button type="button" class="btn btn-ghost btn-sm"
                            :class="!filters.taxiLive.mode && 'btn-primary'"
                            @click="filters.taxiLive.mode = ''">
                        {{ __('admin.common.all') }}
                    </button>
                    <template x-for="mode in ['line', 'charter', 'meter']" :key="mode">
                        <button type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[11px] font-semibold transition"
                                :style="filters.taxiLive.mode === mode
                                    ? `background:${taxiModeColor(mode)};color:#05090b`
                                    : `background:${taxiModeColor(mode)}1f;color:${taxiModeColor(mode)}`"
                                @click="filters.taxiLive.mode = filters.taxiLive.mode === mode ? '' : mode">
                            <span class="size-2 rounded-full" :style="`background:${taxiModeColor(mode)}`"></span>
                            <span x-text="$t(`enums.taxiservicetype.${mode}`)"></span>
                            <span class="opacity-70" x-text="$num(taxiLiveMeta.by_service_type?.[mode] ?? 0)"></span>
                        </button>
                    </template>
                </div>

                <div class="no-scrollbar mt-3 flex max-h-[56vh] flex-col gap-2 overflow-y-auto">
                    <template x-for="taxi in filteredTaxiLive" :key="taxi.uuid">
                        <button type="button" class="rounded-xl bg-white/[0.03] px-3 py-2.5 text-start transition hover:bg-white/[0.06]"
                                @click="focusTaxi(taxi)">
                            <div class="flex items-center gap-2">
                                <span class="size-2 shrink-0 rounded-full" :style="`background:${taxiModeColor(taxi.service_type)}`"></span>
                                <span class="text-sm font-semibold" x-text="$t('admin.taxi.popup_number', { number: $num(taxi.taxi_number) })"></span>
                                <span class="ms-auto text-[10px] text-ink-500" dir="ltr" x-text="taxi.plate || '—'"></span>
                            </div>
                            <p class="mt-1 truncate text-[11px]" :style="`color:${taxiModeColor(taxi.service_type)}`"
                               x-text="$t(`enums.taxiservicetype.${taxi.service_type}`) + (taxi.line_code ? ` — ${taxi.line_name || taxi.line_code}` : '')"></p>
                            <div class="mt-1.5 flex items-center gap-3 text-[10px] text-ink-500">
                                <span x-text="$t('admin.common.passengers', { count: $num(taxi.onboard_count ?? 0) })"></span>
                                <span x-text="taxi.speed_kmh ? $t('admin.taxi.speed', { speed: $num(Math.round(taxi.speed_kmh)) }) : '—'"></span>
                                <span :class="taxi.is_available ? 'text-emerald-400' : 'text-amber-400'"
                                      x-text="taxi.is_available ? $t('admin.taxi.available') : $t('admin.taxi.occupied')"></span>
                            </div>
                        </button>
                    </template>
                    <p x-show="!filteredTaxiLive.length" class="py-8 text-center text-sm text-ink-500">
                        {{ __('admin.taxi.none_live') }}
                    </p>
                </div>
            </div>
        </div>
    </template>

    {{-- ── settlements ────────────────────────────────────────────────── --}}
    <template x-if="taxiTab === 'settlements'">
        <div class="glass card !p-0">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.finance.reference') }}</th>
                            <th>{{ __('admin.reports.driver') }}</th>
                            <th>{{ __('admin.finance.period') }}</th>
                            <th>{{ __('admin.taxi.ride_count') }}</th>
                            <th>{{ __('admin.finance.gross') }}</th>
                            <th>{{ __('admin.finance.commission') }}</th>
                            <th>{{ __('admin.finance.net') }}</th>
                            <th>{{ __('admin.common.status') }}</th>
                            <th>{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="settlement in taxiSettlements" :key="settlement.uuid">
                            <tr>
                                <td class="font-mono text-xs" dir="ltr" x-text="settlement.reference"></td>
                                <td class="text-xs font-semibold" x-text="settlement.driver?.name || '—'"></td>
                                <td class="text-xs text-ink-400" dir="ltr"
                                    x-text="`${$num(settlement.period_start)} – ${$num(settlement.period_end)}`"></td>
                                <td class="text-xs" x-text="$num(settlement.ride_count)"></td>
                                <td class="text-xs" x-text="$money(settlement.gross_amount)"></td>
                                <td class="text-xs text-ink-400" x-text="$money(settlement.commission_amount)"></td>
                                <td class="text-xs font-semibold" x-text="settlement.formatted_net"></td>
                                <td>
                                    <span class="badge" :class="`badge-${settlement.status_color}`" x-text="settlement.status_label"></span>
                                    <p x-show="settlement.rejection_reason" class="mt-1 text-[10px] text-ink-500"
                                       x-text="settlement.rejection_reason"></p>
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <button type="button" class="btn btn-ghost btn-sm" x-show="settlement.status === 'requested'"
                                                @click="approveTaxiSettlement(settlement)">{{ __('admin.common.approve') }}</button>
                                        <button type="button" class="btn btn-ghost btn-sm" x-show="settlement.status === 'approved'"
                                                @click="payTaxiSettlement(settlement)">{{ __('admin.finance.record_payment') }}</button>
                                        <button type="button" class="btn btn-ghost btn-sm text-red-300"
                                                x-show="settlement.status === 'requested'"
                                                @click="rejectTaxiSettlement(settlement)">{{ __('admin.finance.reject') }}</button>
                                        <span x-show="settlement.payment_reference" class="self-center font-mono text-[10px] text-ink-500"
                                              dir="ltr" x-text="settlement.payment_reference"></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="!taxiSettlements.length" class="py-10 text-center text-sm text-ink-500">
                {{ __('admin.taxi.no_settlements') }}
            </p>
        </div>
    </template>

    {{-- ── report ─────────────────────────────────────────────────────────
         Money that actually moved, split by product. A line fare counts the
         moment it is taken; a metered ride that was never paid is counted
         separately as debt, not as revenue. --}}
    <template x-if="taxiTab === 'report'">
        <div class="flex flex-col gap-3">
            <div class="glass card flex flex-wrap items-center gap-2 !py-3">
                <input type="date" class="field !w-auto !py-1.5 text-xs" x-model="reportRange.from" @change="loadTaxiReport()">
                <span class="text-xs text-ink-500">{{ __('admin.common.to') }}</span>
                <input type="date" class="field !w-auto !py-1.5 text-xs" x-model="reportRange.to" @change="loadTaxiReport()">
            </div>

            <template x-if="taxiReport">
                <div class="flex flex-col gap-3">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="glass card">
                            <p class="text-xs text-ink-400">{{ __('admin.taxi.ride_count') }}</p>
                            <p class="mt-1 text-2xl font-bold" x-text="$num(taxiReport.ride_count)"></p>
                        </div>
                        <div class="glass card">
                            <p class="text-xs text-ink-400">{{ __('admin.finance.gross') }}</p>
                            <p class="mt-1 text-2xl font-bold" x-text="$money(taxiReport.gross)"></p>
                        </div>
                        <div class="glass card">
                            <p class="text-xs text-ink-400">{{ __('admin.finance.commission') }}</p>
                            <p class="mt-1 text-2xl font-bold" x-text="$money(taxiReport.commission)"></p>
                        </div>
                        <div class="glass card">
                            <p class="text-xs text-ink-400">{{ __('admin.taxi.unpaid') }}</p>
                            <p class="mt-1 text-2xl font-bold text-amber-300" x-text="$money(taxiReport.unpaid_amount)"></p>
                            <p class="mt-1 text-[11px] text-ink-500"
                               x-text="$t('admin.taxi.unpaid_count', { count: $num(taxiReport.unpaid_count) })"></p>
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-3">
                        <div class="glass card">
                            <h3 class="text-sm font-bold">{{ __('admin.taxi.by_service_type') }}</h3>
                            <div class="mt-3 flex flex-col gap-2">
                                <template x-for="row in taxiReport.by_service_type" :key="row.service_type">
                                    <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2">
                                        <span class="size-2.5 rounded-full" :style="`background:${taxiModeColor(row.service_type)}`"></span>
                                        <span class="text-xs font-semibold" x-text="row.label"></span>
                                        <span class="ms-auto text-xs" x-text="$money(row.gross)"></span>
                                        <span class="text-[10px] text-ink-500"
                                              x-text="$t('admin.taxi.rides_short', { count: $num(row.ride_count) })"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="glass card">
                            <h3 class="text-sm font-bold">{{ __('admin.taxi.busiest_lines') }}</h3>
                            <div class="mt-3 flex flex-col gap-2">
                                <template x-for="row in taxiReport.busiest_lines" :key="row.line">
                                    <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2">
                                        <span class="text-xs font-semibold" x-text="$num(row.line)"></span>
                                        <span class="truncate text-[11px] text-ink-400" x-text="row.name"></span>
                                        <span class="ms-auto text-xs" x-text="$money(row.gross)"></span>
                                    </div>
                                </template>
                                <p x-show="!taxiReport.busiest_lines.length" class="py-4 text-center text-xs text-ink-500">
                                    {{ __('admin.common.no_data') }}
                                </p>
                            </div>
                        </div>

                        <div class="glass card">
                            <h3 class="text-sm font-bold">{{ __('admin.taxi.top_drivers') }}</h3>
                            <div class="mt-3 flex flex-col gap-2">
                                <template x-for="row in taxiReport.top_drivers" :key="row.driver">
                                    <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2">
                                        <span class="truncate text-xs font-semibold" x-text="row.driver || '—'"></span>
                                        <span class="ms-auto text-xs" x-text="$money(row.gross)"></span>
                                        <span class="text-[10px] text-ink-500"
                                              x-text="$t('admin.taxi.rides_short', { count: $num(row.ride_count) })"></span>
                                    </div>
                                </template>
                                <p x-show="!taxiReport.top_drivers.length" class="py-4 text-center text-xs text-ink-500">
                                    {{ __('admin.common.no_data') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </template>
</section>

{{-- ── QR ───────────────────────────────────────────────────────────────────
     The printed sticker carries only the public id; the token that a phone
     actually validates rotates every thirty seconds and is shown here so an
     operator can check a car's code is alive. --}}
<div x-show="taxiQrModal" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4"
     @click.self="taxiQrModal = null">
    <div class="glass-strong card w-full max-w-sm text-center">
        <h3 class="text-base font-bold" x-text="$t('admin.taxi.qr_title', { number: $num(taxiQrModal?.taxi_number) })"></h3>

        <div class="mx-auto mt-5 w-fit rounded-2xl bg-white p-4">
            <canvas id="taxi-qr-canvas"></canvas>
        </div>

        <p class="mt-4 font-mono text-xs text-ink-300" dir="ltr" x-text="taxiQrModal?.public_id"></p>

        <div class="mt-5 flex gap-2">
            <button type="button" class="btn btn-ghost flex-1" @click="taxiQrModal = null">{{ __('admin.common.close') }}</button>
            <button type="button" class="btn btn-danger flex-1" @click="regenerateTaxiQr()">{{ __('admin.fleet.qr_regenerate') }}</button>
        </div>

        <p class="mt-3 text-[11px] leading-5 text-ink-500">{{ __('admin.taxi.qr_warning') }}</p>
    </div>
</div>

{{-- ── taxi ↔ driver ────────────────────────────────────────────────────────
     No assignment, no shift: this is the step that puts a car on the street. --}}
<div x-show="taxiAssignmentModal" x-cloak
     class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-4"
     @keydown.escape.window="taxiAssignmentModal = null">
    <div class="glass-strong card w-full max-w-2xl" @click.outside="taxiAssignmentModal = null">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-bold"
                x-text="$t('admin.taxi.assignments_title', { number: $num(taxiAssignmentModal?.taxi_number) })"></h3>
            <button type="button" class="ms-auto text-ink-400 hover:text-ink-100" @click="taxiAssignmentModal = null">✕</button>
        </div>

        <p class="mt-2 text-xs leading-6 text-ink-400">{{ __('admin.taxi.assignments_note') }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="table text-sm">
                <thead>
                    <tr>
                        <th>{{ __('admin.reports.driver') }}</th>
                        <th>{{ __('admin.fleet.assignment_from') }}</th>
                        <th>{{ __('admin.fleet.assignment_to') }}</th>
                        <th>{{ __('admin.common.status') }}</th>
                        <th>{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="item in taxiAssignments" :key="item.id">
                        <tr>
                            <td class="font-semibold" x-text="item.driver_name || '—'"></td>
                            <td class="text-xs" x-text="$num(item.starts_on)"></td>
                            <td class="text-xs" x-text="item.ends_on ? $num(item.ends_on) : '—'"></td>
                            <td>
                                <span class="badge" :class="item.is_current ? 'badge-success' : 'badge-neutral'"
                                      x-text="item.is_current
                                          ? $t('admin.fleet.assignment_current')
                                          : $t('admin.fleet.assignment_not_current')"></span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm text-red-300"
                                        x-show="item.is_current" @click="revokeTaxiAssignment(item)">
                                    {{ __('admin.fleet.assignment_revoke') }}
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p x-show="!taxiAssignments.length" class="py-6 text-center text-sm text-ink-500">
                {{ __('admin.fleet.assignments_empty') }}
            </p>
        </div>

        <form class="mt-5 grid gap-3 border-t border-white/5 pt-5 sm:grid-cols-4" @submit.prevent="submitTaxiAssignment()">
            <label class="sm:col-span-2">
                <span class="mb-1.5 block text-xs text-ink-400">{{ __('admin.reports.driver') }}</span>
                <select class="field !py-2 text-sm" x-model="taxiAssignmentForm.driver_uuid" required>
                    <option value="">—</option>
                    <template x-for="driver in drivers.filter((item) => item.status === 'active')" :key="driver.uuid">
                        <option :value="driver.uuid" x-text="driver.name"></option>
                    </template>
                </select>
            </label>
            <label>
                <span class="mb-1.5 block text-xs text-ink-400">{{ __('admin.fleet.assignment_from') }}</span>
                <input type="date" class="field !py-2 text-sm" x-model="taxiAssignmentForm.starts_on" required>
            </label>
            <label>
                <span class="mb-1.5 block text-xs text-ink-400">{{ __('admin.fleet.assignment_to') }}</span>
                <input type="date" class="field !py-2 text-sm" x-model="taxiAssignmentForm.ends_on">
            </label>

            <p x-show="taxiAssignmentForm.error" x-cloak class="text-xs text-red-300 sm:col-span-4"
               x-text="taxiAssignmentForm.error"></p>

            <button type="submit" class="btn btn-primary btn-sm sm:col-span-4" :disabled="taxiAssignmentForm.busy"
                    x-text="taxiAssignmentForm.busy ? $t('admin.common.saving') : $t('admin.fleet.assignment_add')"></button>
        </form>
    </div>
</div>
