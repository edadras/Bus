<section x-show="view === 'network'" x-cloak class="flex flex-col gap-3">
    <div class="grid gap-3 lg:grid-cols-2">
        <article class="glass card">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-bold">{{ __('admin.network.lines') }}</h2>
                <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openLineForm()">{{ __('admin.network.new_line') }}</button>
            </div>
            <div class="mt-4 flex max-h-[60vh] flex-col gap-2 overflow-y-auto">
                <template x-for="line in lines" :key="line.id">
                    <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
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
                        <div class="mt-1.5 flex gap-1">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    @click="openLineForm(line)">{{ __('admin.common.edit') }}</button>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    @click="openRoutes(line)">{{ __('admin.network.routes') }}</button>
                        </div>
                    </div>
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
                    <button type="button"
                            class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5 text-start transition hover:bg-white/[0.06]"
                            @click="openStopForm(stop)">
                        <span class="size-2 shrink-0 rounded-full"
                              :class="stop.is_terminal ? 'bg-brand-400' : 'bg-ink-500'"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm" x-text="stop.name"></p>
                            <p class="text-[10px] text-ink-500" x-text="stop.code"></p>
                        </div>
                        <span x-show="!stop.is_verified_data" class="badge badge-warning !text-[9px]">{{ __('admin.common.sample') }}</span>
                    </button>
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

{{-- A line's routes. The stop sequence is what "next stop" and every ETA are
     computed from, so this is the screen where the network is actually
     defined — not the line record above it. --}}
<div x-show="routeModal" x-cloak
     class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4"
     @keydown.escape.window="closeRoutes()">
    <div class="glass-strong card my-auto w-full max-w-3xl" @click.outside="closeRoutes()">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-bold"
                x-text="$t('admin.network.routes_of', { line: $num(routeModal?.line?.code) })"></h3>
            <button type="button" class="btn btn-primary btn-sm ms-auto"
                    x-show="!routeBuilder" @click="startRouteBuilder()">{{ __('admin.network.new_route') }}</button>
            <button type="button" class="text-ink-400 hover:text-ink-100" @click="closeRoutes()">✕</button>
        </div>

        <p class="mt-2 text-xs leading-6 text-ink-400">{{ __('admin.network.routes_note') }}</p>

        <div x-show="!routeBuilder" class="mt-4 flex flex-col gap-2">
            <template x-for="route in routeModal?.routes ?? []" :key="route.id">
                <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold" x-text="route.name"></span>
                        <span class="badge badge-neutral" x-text="route.direction_label"></span>
                        <span class="text-[11px] text-ink-500"
                              x-text="$t('admin.common.kilometres', { count: $num(((route.distance_meters ?? 0) / 1000).toFixed(1)) })"></span>
                        <span class="flex-1"></span>
                        <button type="button" class="btn btn-ghost btn-sm"
                                @click="openSequence(route)">{{ __('admin.network.sequence') }}</button>
                        <button type="button" class="btn btn-ghost btn-sm" :disabled="busy"
                                @click="recalculateRoute(route)">{{ __('admin.network.recalculate') }}</button>
                    </div>
                    <p class="mt-1 truncate text-[11px] text-ink-500"
                       x-text="`${route.origin?.name || '—'} ← ${route.destination?.name || '—'}`"></p>

                    {{-- The sequence, once asked for. Offsets are shown because
                         an offset of zero on a mid-route stop is exactly the
                         symptom that says a recalculation is overdue. --}}
                    <div x-show="sequence?.id === route.id" x-cloak class="mt-3 border-t border-white/5 pt-3">
                        <template x-for="item in sequence?.stops ?? []" :key="item.sequence">
                            <div class="flex items-center gap-2 py-1 text-xs">
                                <span class="w-6 text-ink-500" x-text="$num(item.sequence)"></span>
                                <span class="flex-1 truncate" x-text="item.stop?.name"></span>
                                <span x-show="item.is_timepoint" class="badge badge-info !text-[9px]">{{ __('admin.network.timepoint') }}</span>
                                <span class="text-[10px]"
                                      :class="item.distance_from_start ? 'text-ink-500' : 'text-amber-400'"
                                      x-text="$t('admin.network.offset', { metres: $num(item.distance_from_start ?? 0) })"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <p x-show="!routeModal?.loading && !(routeModal?.routes ?? []).length"
               class="py-8 text-center text-sm text-ink-500">{{ __('admin.network.routes_empty') }}</p>
        </div>

        {{-- Building a sequence. Order is the entire content of a route, so the
             chosen stops are a reorderable list rather than a multi-select. --}}
        <div x-show="routeBuilder" x-cloak class="mt-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="field-label">{{ __('admin.network.route_name') }}</label>
                    <input class="field text-sm" x-model="routeBuilder.name" required>
                </div>
                <div>
                    <label class="field-label">{{ __('admin.network.direction') }}</label>
                    <select class="field text-sm" x-model="routeBuilder.direction">
                        <option value="outbound">{{ __('enums.routedirection.outbound') }}</option>
                        <option value="inbound">{{ __('enums.routedirection.inbound') }}</option>
                        <option value="loop">{{ __('enums.routedirection.loop') }}</option>
                    </select>
                </div>
            </div>

            <label class="mt-3 flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2.5 text-sm">
                <input type="checkbox" x-model="routeBuilder.is_default">
                <span>{{ __('admin.network.is_default') }}</span>
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="field-label">{{ __('admin.network.chosen_stops') }}</p>
                    <div class="flex max-h-[38vh] flex-col gap-1 overflow-y-auto">
                        <template x-for="(stop, index) in routeBuilder.stops" :key="stop.id">
                            <div class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-2.5 py-1.5 text-xs">
                                <span class="w-5 text-ink-500" x-text="$num(index + 1)"></span>
                                <span class="flex-1 truncate" x-text="stop.name"></span>
                                <button type="button" class="text-ink-400 hover:text-ink-100"
                                        @click="moveBuilderStop(index, -1)" aria-label="{{ __('admin.network.move_up') }}">↑</button>
                                <button type="button" class="text-ink-400 hover:text-ink-100"
                                        @click="moveBuilderStop(index, 1)" aria-label="{{ __('admin.network.move_down') }}">↓</button>
                                <button type="button" class="text-danger"
                                        @click="removeBuilderStop(index)" aria-label="{{ __('admin.common.cancel') }}">✕</button>
                            </div>
                        </template>
                        <p x-show="!routeBuilder.stops.length" class="py-6 text-center text-xs text-ink-500">
                            {{ __('admin.network.route_needs_two_stops') }}
                        </p>
                    </div>
                </div>

                <div>
                    <p class="field-label">{{ __('admin.network.add_stops') }}</p>
                    <input type="search" class="field !py-2 text-xs" placeholder="{{ __('admin.network.search_stop') }}"
                           x-model="routeBuilder.query">
                    <div class="mt-2 flex max-h-[32vh] flex-col gap-1 overflow-y-auto">
                        <template x-for="stop in builderCandidates" :key="stop.id">
                            <button type="button"
                                    class="rounded-xl bg-white/[0.03] px-2.5 py-1.5 text-start text-xs hover:bg-white/[0.06]"
                                    @click="addBuilderStop(stop)">
                                <span x-text="stop.name"></span>
                                <span class="text-[10px] text-ink-500" x-text="stop.code"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <p x-show="routeBuilder.error" x-cloak class="field-error" x-text="routeBuilder.error"></p>

            <div class="mt-4 flex gap-2">
                <button type="button" class="btn btn-primary flex-1" :disabled="routeBuilder.busy"
                        @click="submitRoute()">
                    <span x-show="!routeBuilder.busy">{{ __('admin.common.save') }}</span>
                    <span x-show="routeBuilder.busy" x-cloak>{{ __('admin.common.saving') }}</span>
                </button>
                <button type="button" class="btn btn-ghost" @click="routeBuilder = null">{{ __('admin.common.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
