<section x-show="view === 'fleet'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="{{ __('admin.fleet.search_placeholder') }}"
               x-model.debounce.400ms="filters.fleet.q" @input="loadFleet()">
        <select class="field max-w-[10rem] !py-2 text-sm" x-model="filters.fleet.status" @change="loadFleet()">
            <option value="">{{ __('admin.common.all_statuses') }}</option>
            <option value="active">{{ __('enums.busstatus.active') }}</option>
            <option value="idle">{{ __('enums.busstatus.idle') }}</option>
            <option value="maintenance">{{ __('enums.busstatus.maintenance') }}</option>
            <option value="out_of_service">{{ __('enums.busstatus.out_of_service') }}</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openBusForm()">{{ __('admin.fleet.new_bus') }}</button>
        <span class="text-xs text-ink-400" x-text="$t('admin.fleet.count', { count: $num(buses.length) })"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('admin.fleet.number') }}</th><th>{{ __('admin.fleet.plate') }}</th><th>{{ __('admin.fleet.capacity') }}</th><th>{{ __('admin.common.status') }}</th>
                        <th>{{ __('admin.fleet.current_driver') }}</th><th>{{ __('admin.fleet.last_position') }}</th><th>{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="bus in buses" :key="bus.uuid">
                        <tr>
                            <td class="font-semibold" x-text="$num(bus.bus_number)"></td>
                            <td class="text-xs text-ink-400" x-text="bus.plate || '—'"></td>
                            <td class="text-xs" x-text="$num(bus.total_capacity)"></td>
                            <td>
                                <span class="badge" :class="`badge-${bus.status_color}`" x-text="bus.status_label"></span>
                            </td>
                            <td class="text-xs" x-text="bus.current_driver || '—'"></td>
                            <td class="text-xs text-ink-400"
                                x-text="bus.last_ping_at ? $time(bus.last_ping_at) : $t('admin.fleet.no_report')"></td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openBusForm(bus)">{{ __('admin.common.edit') }}</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="showQr(bus)">{{ __('admin.fleet.qr_button') }}</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!buses.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.fleet.empty') }}</p>
    </div>

    {{-- QR modal: shows the printable public id and the live rotating token. --}}
    <div x-show="qrModal" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4"
         @click.self="qrModal = null">
        <div class="glass-strong card w-full max-w-sm text-center">
            <h3 class="text-base font-bold" x-text="$t('admin.fleet.qr_title', { number: $num(qrModal?.bus_number) })"></h3>

            <div class="mx-auto mt-5 w-fit rounded-2xl bg-white p-4">
                <canvas id="qr-canvas"></canvas>
            </div>

            <p class="mt-4 font-mono text-xs text-ink-300" dir="ltr" x-text="qrModal?.public_id"></p>
            <p class="mt-1 text-[11px] text-ink-500">
                <span x-text="$t('admin.fleet.qr_countdown', { seconds: $num(qrCountdown) })"></span>
            </p>

            <div class="mt-5 flex gap-2">
                <button type="button" class="btn btn-ghost flex-1" @click="qrModal = null">{{ __('admin.common.close') }}</button>
                <button type="button" class="btn btn-danger flex-1" @click="regenerateQr()">{{ __('admin.fleet.qr_regenerate') }}</button>
            </div>

            <p class="mt-3 text-[11px] leading-5 text-ink-500">
                {{ __('admin.fleet.qr_warning') }}
            </p>
        </div>
    </div>
</section>
