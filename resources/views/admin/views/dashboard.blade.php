{{-- KPI dashboard: live counters plus the charts that reveal trend. --}}
<section x-show="view === 'dashboard'" x-cloak class="flex flex-col gap-3">

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <template x-for="card in kpiCards" :key="card.label">
            <article class="glass card card-hover">
                <div class="flex items-start justify-between">
                    <span class="grid size-9 place-items-center rounded-xl"
                          :class="`bg-${card.tone}/12`">
                        <svg viewBox="0 0 24 24" class="size-[18px] fill-current"
                             :class="card.tone === 'brand-500' ? 'text-brand-300' : 'text-ink-300'">
                            <path :d="card.icon"></path>
                        </svg>
                    </span>
                    <span x-show="card.live" class="live-dot"></span>
                </div>
                <p class="stat-value mt-3" x-text="card.value">—</p>
                <p class="stat-label mt-1" x-text="card.label"></p>
                <p x-show="card.caption" class="mt-1 text-[11px] text-ink-500" x-text="card.caption"></p>
            </article>
        </template>
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        <article class="glass card lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold">{{ __('admin.dashboard.trips_and_passengers') }}</h2>
                <div class="flex gap-1">
                    <template x-for="option in ranges" :key="option.value">
                        <button type="button" class="rounded-full px-3 py-1 text-xs transition"
                                :class="range === option.value
                                    ? 'bg-brand-500/20 text-brand-300 font-semibold'
                                    : 'text-ink-400 hover:bg-white/5'"
                                @click="range = option.value; loadCharts()"
                                x-text="option.label"></button>
                    </template>
                </div>
            </div>
            <div class="mt-4 h-64"><canvas id="chart-trips"></canvas></div>
        </article>

        <article class="glass card">
            <h2 class="text-sm font-bold">{{ __('admin.dashboard.revenue') }}</h2>
            <div class="mt-4 h-64"><canvas id="chart-revenue"></canvas></div>
        </article>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        <article class="glass card">
            <h2 class="text-sm font-bold">{{ __('admin.dashboard.busiest_lines') }}</h2>
            <div class="mt-4 flex flex-col gap-2">
                <template x-for="line in busiestLines" :key="line.id">
                    <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <span class="size-2.5 shrink-0 rounded-full" :style="`background:${line.color}`"></span>
                        <span class="text-sm font-semibold" x-text="$num(line.code)"></span>
                        <span class="truncate text-xs text-ink-400" x-text="line.name"></span>
                        <span class="ms-auto shrink-0 text-xs font-semibold text-brand-300"
                              x-text="$t('admin.common.passengers', { count: $num(line.boardings) })"></span>
                    </div>
                </template>
                <p x-show="!busiestLines.length" class="py-6 text-center text-sm text-ink-500">{{ __('admin.common.no_data') }}</p>
            </div>
        </article>

        <article class="glass card">
            <h2 class="text-sm font-bold">{{ __('admin.dashboard.busiest_stops') }}</h2>
            <div class="mt-4 flex flex-col gap-2">
                <template x-for="stop in busiestStops" :key="stop.id">
                    <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <span class="truncate text-sm" x-text="stop.name"></span>
                        <span class="ms-auto shrink-0 text-xs font-semibold text-brand-300"
                              x-text="$t('admin.common.boardings', { count: $num(stop.boardings) })"></span>
                    </div>
                </template>
                <p x-show="!busiestStops.length" class="py-6 text-center text-sm text-ink-500">{{ __('admin.common.no_data') }}</p>
            </div>
        </article>
    </div>
</section>
