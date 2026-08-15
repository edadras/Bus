<section x-show="view === 'occupancy'" x-cloak class="flex flex-col gap-3">
    <div class="glass card">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-sm font-bold">{{ __('admin.occupancy.heading') }}</h2>
            <span class="badge badge-success">
                <span class="live-dot"></span>
                <span x-text="$t('admin.occupancy.buses_in_service', { count: $num(occupancy.totals?.buses_in_service ?? 0) })"></span>
            </span>
            <span class="ms-auto text-[11px] text-ink-500">
                {{ __('admin.occupancy.privacy_note') }}
            </span>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                <p class="stat-value !text-2xl text-brand-300" x-text="$num(occupancy.totals?.passengers_on_board ?? 0)"></p>
                <p class="stat-label mt-1">{{ __('admin.occupancy.passengers_on_board') }}</p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                <p class="stat-value !text-2xl" x-text="$num(occupancy.totals?.total_capacity ?? 0)"></p>
                <p class="stat-label mt-1">{{ __('admin.occupancy.total_capacity') }}</p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                <p class="stat-value !text-2xl"
                   x-text="$t('admin.common.percent', { value: $num(Math.round((occupancy.totals?.network_occupancy ?? 0) * 100)) })"></p>
                <p class="stat-label mt-1">{{ __('admin.occupancy.network_occupancy') }}</p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                <p class="stat-value !text-2xl text-amber-400" x-text="$num(occupancy.totals?.crowded_buses ?? 0)"></p>
                <p class="stat-label mt-1">{{ __('admin.occupancy.crowded_buses') }}</p>
            </div>
        </div>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        <template x-for="bus in occupancyRows" :key="bus.trip_uuid">
            <article class="glass card">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl text-xs font-bold text-white"
                          :style="`background:${bus.line_color || '#12b76a'}`"
                          x-text="$num(bus.line_code)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold" x-text="$t('admin.live.bus_number', { number: $num(bus.bus_number) })"></p>
                        <p class="truncate text-[11px] text-ink-400" x-text="bus.line_name"></p>
                    </div>
                    <span class="badge" :class="{
                        'badge-danger': bus.crowding === 'full',
                        'badge-warning': bus.crowding === 'crowded',
                        'badge-info': bus.crowding === 'moderate',
                        'badge-success': bus.crowding === 'light',
                    }" x-text="crowdingLabel(bus.crowding)"></span>
                </div>

                <div class="mt-4 flex items-end gap-3">
                    <p class="text-3xl font-bold leading-none text-brand-300" x-text="$num(bus.passenger_count)"></p>
                    <p class="pb-0.5 text-xs text-ink-400" x-text="$t('admin.occupancy.of_capacity', { count: $num(bus.capacity) })"></p>
                    <p class="ms-auto pb-0.5 text-[11px] text-ink-500"
                       x-text="$t('admin.occupancy.peak', { count: $num(bus.peak_passenger_count) })"></p>
                </div>

                <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink-700">
                    <div class="h-full rounded-full transition-all duration-500"
                         :class="{
                             'bg-danger': bus.crowding === 'full',
                             'bg-warning': bus.crowding === 'crowded',
                             'bg-brand-400': bus.crowding === 'moderate' || bus.crowding === 'light',
                         }"
                         :style="`width:${Math.min(100, Math.round(bus.occupancy * 100))}%`"></div>
                </div>
            </article>
        </template>
    </div>

    <p x-show="!occupancyRows.length" class="glass card py-12 text-center text-sm text-ink-500">
        {{ __('admin.occupancy.none_in_service') }}
    </p>
</section>
