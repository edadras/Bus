<section x-show="view === 'drivers'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="{{ __('admin.drivers.search_placeholder') }}"
               x-model.debounce.400ms="filters.drivers.q" @input="loadDrivers()">
        <select class="field max-w-[11rem] !py-2 text-sm" x-model="filters.drivers.status" @change="loadDrivers()">
            <option value="">{{ __('admin.common.all_statuses') }}</option>
            <option value="pending_approval">{{ __('enums.driverstatus.pending_approval') }}</option>
            <option value="active">{{ __('enums.driverstatus.active') }}</option>
            <option value="suspended">{{ __('enums.driverstatus.suspended') }}</option>
            <option value="inactive">{{ __('enums.driverstatus.inactive') }}</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openDriverForm()">{{ __('admin.drivers.new_driver') }}</button>
        <span class="text-xs text-ink-400" x-text="$t('admin.drivers.count', { count: $num(drivers.length) })"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('admin.common.name') }}</th><th>{{ __('admin.drivers.employee_code') }}</th><th>{{ __('admin.drivers.license') }}</th><th>{{ __('admin.common.status') }}</th>
                        <th>{{ __('admin.drivers.trips') }}</th><th>{{ __('admin.drivers.active_hours') }}</th><th>{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="driver in drivers" :key="driver.uuid">
                        <tr>
                            <td>
                                <p class="font-semibold" x-text="driver.name"></p>
                                <p class="text-[11px] text-ink-500" x-text="$num(driver.mobile)"></p>
                            </td>
                            <td class="text-xs" x-text="driver.employee_code || '—'"></td>
                            <td class="text-xs">
                                <span x-text="$num(driver.license_number)"></span>
                                <span x-show="driver.license_expires_at" class="block text-[10px] text-ink-500"
                                      x-text="$t('admin.drivers.expires', { date: $num(driver.license_expires_at) })"></span>
                            </td>
                            <td>
                                <span class="badge" :class="`badge-${driver.status_color}`"
                                      x-text="driver.status_label"></span>
                            </td>
                            <td class="text-xs" x-text="$num(driver.total_trips)"></td>
                            <td class="text-xs" x-text="$t('admin.common.hours', { count: $num(Math.round(driver.total_shift_minutes / 60)) })"></td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            @click="openDriver(driver)">{{ __('admin.drivers.detail') }}</button>
                                    <button type="button" x-show="driver.status === 'pending_approval'"
                                            class="btn btn-primary btn-sm"
                                            @click="changeDriverStatus(driver, 'active')">{{ __('admin.common.approve') }}</button>
                                    <button type="button" x-show="driver.status === 'active'"
                                            class="btn btn-ghost btn-sm"
                                            @click="changeDriverStatus(driver, 'suspended')">{{ __('admin.common.suspend') }}</button>
                                    <button type="button" x-show="driver.status === 'suspended'"
                                            class="btn btn-ghost btn-sm"
                                            @click="changeDriverStatus(driver, 'active')">{{ __('admin.common.unsuspend') }}</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!drivers.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.drivers.empty') }}</p>
    </div>

    <p class="px-1 text-[11px] leading-6 text-ink-500">
            {{ __('admin.drivers.suspend_notice') }}
    </p>
</section>

{{-- The driver dossier. Approving somebody to carry passengers means reading
     the licence, the documents behind it, what they are assigned to and what
     they have been driving — so all four live on one screen. --}}
<div x-show="driverModal" x-cloak
     class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4"
     @keydown.escape.window="closeDriver()">
    <div class="glass-strong card my-auto w-full max-w-3xl" @click.outside="closeDriver()">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-bold" x-text="driverModal?.driver?.name"></h3>
                <p class="mt-0.5 font-mono text-[11px] text-ink-500" dir="ltr"
                   x-text="driverModal?.driver?.mobile"></p>
            </div>
            <span class="badge" :class="`badge-${driverModal?.driver?.status_color}`"
                  x-text="driverModal?.driver?.status_label"></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openDriverEditForm()">{{ __('admin.common.edit') }}</button>
            <button type="button" class="text-ink-400 hover:text-ink-100" @click="closeDriver()">✕</button>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.drivers.license') }}</p>
                <p class="mt-1 text-sm" x-text="$num(driverModal?.driver?.license_number)"></p>
                <p class="text-[10px]" :class="driverModal?.driver?.license_is_expired ? 'text-danger' : 'text-ink-500'"
                   x-show="driverModal?.driver?.license_expires_at"
                   x-text="$t('admin.drivers.expires', { date: $num(driverModal?.driver?.license_expires_at) })"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.drivers.employee_code') }}</p>
                <p class="mt-1 text-sm" x-text="driverModal?.driver?.employee_code || '—'"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.drivers.trips') }}</p>
                <p class="mt-1 text-sm" x-text="$num(driverModal?.driver?.total_trips)"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.drivers.active_hours') }}</p>
                <p class="mt-1 text-sm"
                   x-text="$t('admin.common.hours', { count: $num(Math.round((driverModal?.driver?.total_shift_minutes ?? 0) / 60)) })"></p>
            </div>
        </div>

        {{-- A driver whose licence or contract has lapsed cannot start a shift
             however active their status says they are, so it is said plainly. --}}
        <p x-show="driverModal?.driver?.license_is_expired || driverModal?.driver?.contract_has_ended" x-cloak
           class="mt-3 rounded-xl bg-danger/10 px-3 py-2 text-[11px] text-danger">
            {{ __('admin.drivers.blocked_notice') }}
        </p>

        <h4 class="mt-5 text-sm font-bold">{{ __('admin.drivers.documents') }}</h4>
        <div class="mt-2 flex flex-col gap-2">
            <template x-for="doc in driverModal?.documents ?? []" :key="doc.id">
                <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2.5">
                    <span class="text-sm" x-text="doc.type_label"></span>
                    <span class="truncate text-[11px] text-ink-500" x-text="doc.original_name"></span>
                    <span class="flex-1"></span>
                    <span x-show="doc.expires_at" class="text-[11px]"
                          :class="doc.is_expired ? 'text-danger' : 'text-ink-500'"
                          x-text="$t('admin.drivers.expires', { date: $num(doc.expires_at) })"></span>
                    <span class="badge" :class="doc.is_verified ? 'badge-success' : 'badge-neutral'"
                          x-text="doc.is_verified ? $t('admin.drivers.verified') : $t('admin.drivers.unverified')"></span>
                    <button type="button" class="btn btn-ghost btn-sm"
                            @click="viewDriverDocument(doc)">{{ __('admin.drivers.view_document') }}</button>
                </div>
            </template>
            <p x-show="!(driverModal?.documents ?? []).length" class="py-3 text-center text-sm text-ink-500">
                {{ __('admin.drivers.documents_empty') }}
            </p>
        </div>

        <form class="mt-3 grid gap-3 border-t border-white/5 pt-4 sm:grid-cols-4"
              @submit.prevent="uploadDriverDocument($event)">
            <div>
                <label class="field-label">{{ __('admin.drivers.document_type') }}</label>
                <select class="field text-sm" x-model="driverUpload.type" required>
                    @foreach (['license', 'national_card', 'medical_certificate', 'contract', 'background_check', 'other'] as $type)
                        <option value="{{ $type }}">{{ __('enums.driverdocumenttype.'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label">{{ __('admin.drivers.issued_at') }}</label>
                <input type="date" class="field text-sm" x-model="driverUpload.issued_at">
            </div>
            <div>
                <label class="field-label">{{ __('admin.drivers.expires_at') }}</label>
                <input type="date" class="field text-sm" x-model="driverUpload.expires_at">
            </div>
            <div>
                <label class="field-label">{{ __('admin.drivers.file') }}</label>
                <input type="file" class="field text-xs" accept=".jpg,.jpeg,.png,.pdf" required>
            </div>

            <p x-show="driverUpload.error" x-cloak class="field-error sm:col-span-4" x-text="driverUpload.error"></p>

            <div class="sm:col-span-4">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="driverUpload.busy">
                    <span x-show="!driverUpload.busy">{{ __('admin.drivers.upload') }}</span>
                    <span x-show="driverUpload.busy" x-cloak>{{ __('admin.common.saving') }}</span>
                </button>
                <span class="ms-2 text-[11px] text-ink-500">{{ __('admin.drivers.file_hint') }}</span>
            </div>
        </form>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <h4 class="text-sm font-bold">{{ __('admin.drivers.assignments') }}</h4>
                <div class="mt-2 flex flex-col gap-2">
                    <template x-for="item in driverModal?.assignments ?? []" :key="item.id">
                        <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 text-xs">
                            <span x-text="$t('admin.live.bus_number', { number: $num(item.bus_number) })"></span>
                            <span x-show="item.line" class="text-ink-500"
                                  x-text="$t('admin.live.line_code', { code: $num(item.line) })"></span>
                            <span class="flex-1"></span>
                            <span class="text-[10px] text-ink-500"
                                  x-text="item.ends_on
                                      ? $t('admin.finance.period_range', { from: $num(item.starts_on), to: $num(item.ends_on) })
                                      : $num(item.starts_on)"></span>
                        </div>
                    </template>
                    <p x-show="!(driverModal?.assignments ?? []).length" class="py-3 text-center text-sm text-ink-500">
                        {{ __('admin.fleet.assignments_empty') }}
                    </p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold">{{ __('admin.drivers.recent_shifts') }}</h4>
                <div class="mt-2 flex flex-col gap-2">
                    <template x-for="shift in driverModal?.recent_shifts ?? []" :key="shift.started_at">
                        <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 text-xs">
                            <span x-text="$datetime(shift.started_at)"></span>
                            <span class="flex-1"></span>
                            <span class="text-ink-500"
                                  x-text="$t('admin.common.minutes', { count: $num(shift.duration_minutes) })"></span>
                            <span class="text-ink-500"
                                  x-text="$t('admin.common.passengers', { count: $num(shift.passengers) })"></span>
                        </div>
                    </template>
                    <p x-show="!(driverModal?.recent_shifts ?? []).length" class="py-3 text-center text-sm text-ink-500">
                        {{ __('admin.drivers.shifts_empty') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
