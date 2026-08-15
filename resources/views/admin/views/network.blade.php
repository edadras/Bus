<section x-show="view === 'network'" x-cloak class="flex flex-col gap-3">
    <div class="grid gap-3 lg:grid-cols-2">
        <article class="glass card">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-bold">{{ __('admin.network.lines') }}</h2>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openLineForm()">{{ __('admin.network.new_line') }}</button>
            </div>
            <div class="mt-4 flex max-h-[60vh] flex-col gap-2 overflow-y-auto">
                <template x-for="line in lines" :key="line.id">
                    <button type="button" class="rounded-xl bg-white/[0.03] px-3 py-2.5 text-start transition hover:bg-white/[0.06]"
                            @click="openLineForm(line)">
                        <div class="flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full" :style="`background:${line.color}`"></span>
                            <span class="text-sm font-semibold" x-text="$num(line.code)"></span>
                            <span class="truncate text-xs text-ink-300" x-text="line.name"></span>
                            <span class="ms-auto badge"
                                  :class="line.is_verified_data ? 'badge-success' : 'badge-warning'"
                                  x-text="line.is_verified_data ? $t('admin.common.official') : $t('admin.common.sample')"></span>
                        </div>
                        <p class="mt-1 truncate text-[11px] text-ink-500"
                           x-text="`${line.origin || '—'} ← ${line.destination || '—'}`"></p>
                    </button>
                </template>
                <p x-show="!lines.length" class="py-8 text-center text-sm text-ink-500">{{ __('admin.network.lines_empty') }}</p>
            </div>
        </article>

        <article class="glass card">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-bold">{{ __('admin.network.stops') }}</h2>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openStopForm()">{{ __('admin.network.new_stop') }}</button>
            </div>
            <input type="search" class="field mt-3 !py-2 text-xs" placeholder="{{ __('admin.network.search_stop') }}"
                   x-model.debounce.400ms="filters.stops.q" @input="loadStops()">
            <div class="mt-3 flex max-h-[54vh] flex-col gap-2 overflow-y-auto">
                <template x-for="stop in stops" :key="stop.id">
                    <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <span class="size-2 shrink-0 rounded-full"
                              :class="stop.is_terminal ? 'bg-brand-400' : 'bg-ink-500'"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm" x-text="stop.name"></p>
                            <p class="text-[10px] text-ink-500" x-text="stop.code"></p>
                        </div>
                        <span x-show="!stop.is_verified_data" class="badge badge-warning !text-[9px]">{{ __('admin.common.sample') }}</span>
                    </div>
                </template>
                <p x-show="!stops.length" class="py-8 text-center text-sm text-ink-500">{{ __('admin.network.stops_empty') }}</p>
            </div>
        </article>
    </div>

    <div class="glass card">
            <h2 class="text-sm font-bold">{{ __('admin.network.import_heading') }}</h2>
        <p class="mt-2 text-xs leading-6 text-ink-400">
                {{ __('admin.network.import_note') }}
        </p>
        <pre dir="ltr" class="mt-4 overflow-x-auto rounded-xl bg-black/30 p-4 text-[11px] leading-6 text-ink-300"><code>php artisan transit:import stops    stops.csv        --city=bandar-abbas --provenance=official
php artisan transit:import lines    lines.csv        --city=bandar-abbas --provenance=official
php artisan transit:import routes   routes.csv       --city=bandar-abbas --provenance=official
php artisan transit:import geometry shapes.geojson   --city=bandar-abbas --provenance=official</code></pre>
    </div>
</section>
