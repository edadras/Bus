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
