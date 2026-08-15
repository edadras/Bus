{{-- Operations room: the whole fleet, with driver and occupancy detail. --}}
<section x-show="view === 'live'" x-cloak class="flex flex-col gap-3">
    <div class="grid gap-3 lg:grid-cols-[1fr_320px]">
        <div class="glass card !p-1.5">
            <div id="admin-live-map" class="h-[70vh] w-full rounded-2xl"></div>
        </div>

        <div class="glass card flex flex-col">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold">{{ __('admin.live.fleet_moving') }}</h2>
                <span class="badge badge-success">
                    <span class="live-dot"></span>
                    <span x-text="$num(liveBuses.length)"></span>
                </span>
            </div>

            <input type="search" class="field mt-3 !py-2 text-xs" placeholder="{{ __('admin.live.search_placeholder') }}"
                   x-model="busFilter">

            <div class="no-scrollbar mt-3 flex max-h-[58vh] flex-col gap-2 overflow-y-auto">
                <template x-for="bus in filteredBuses" :key="bus.trip_uuid">
                    <button type="button" class="rounded-xl bg-white/[0.03] px-3 py-2.5 text-start transition hover:bg-white/[0.06]"
                            @click="focusBus(bus)">
                        <div class="flex items-center gap-2">
                            <span class="size-2 shrink-0 rounded-full"
                                  :style="`background:${bus.line_color || '#12b76a'}`"></span>
                            <span class="text-sm font-semibold" x-text="$t('admin.live.bus_number', { number: $num(bus.bus_number) })"></span>
                            <span class="ms-auto text-[10px] text-ink-500" x-text="$t('admin.live.line_code', { code: $num(bus.line_code) })"></span>
                        </div>
                        <p class="mt-1 truncate text-[11px] text-ink-400" x-text="bus.driver_name || $t('admin.live.unknown_driver')"></p>
                        <div class="mt-1.5 flex items-center gap-3 text-[10px] text-ink-500">
                            <span x-text="$t('admin.common.passengers', { count: $num(bus.passenger_count) })"></span>
                            <span x-text="bus.speed ? `${$num(Math.round(bus.speed))} km/h` : '—'"></span>
                            <span x-show="bus.is_off_route" class="text-amber-400">{{ __('admin.live.off_route') }}</span>
                            <span x-show="bus.is_idle" class="text-amber-400">{{ __('admin.live.idle') }}</span>
                        </div>
                    </button>
                </template>
                <p x-show="!filteredBuses.length" class="py-8 text-center text-sm text-ink-500">
                    {{ __('admin.live.none_in_service') }}
                </p>
            </div>
        </div>
    </div>
</section>
