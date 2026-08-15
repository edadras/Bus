@extends('layouts.pwa')

@section('title', __('web.viewer.title'))

@section('app')
<div x-data="webViewer()" x-init="boot()" class="flex flex-1 flex-col">

    <header class="sticky top-0 z-30 px-3 pt-3">
        <div class="glass card flex items-center gap-3 !py-3">
            <a href="{{ route('home') }}" class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600">
                <svg viewBox="0 0 24 24" class="size-5 fill-white"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
            </a>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">{{ __('common.app_name') }}</p>
                <p class="truncate text-[11px] text-ink-400">{{ $city->name }} — {{ __('web.viewer.public_view') }}</p>
            </div>
            <span class="badge badge-success">
                <span class="live-dot"></span>
                <span x-text="`${$num(buses.length)}`"></span>
            </span>
        </div>
    </header>

    <section class="flex flex-1 flex-col px-3 pt-3">
        <div class="glass card relative overflow-hidden !p-1.5">
            <div id="viewer-map" class="h-[44vh] w-full rounded-2xl"></div>
            <div class="absolute top-3 inset-inline-start-3">
                <span class="badge badge-warning !text-[10px]">{{ __('web.viewer.sample_badge') }}</span>
            </div>
        </div>

        <div class="mt-3">
            <label class="field-label">{{ __('web.viewer.choose_stop') }}</label>
            <select class="field text-sm" x-model.number="selectedStopId" @change="loadArrivals()">
                <template x-for="stop in stops" :key="stop.id">
                    <option :value="stop.id" x-text="stop.name"></option>
                </template>
            </select>
        </div>

        <div class="mt-3 flex flex-col gap-2">
            <template x-for="arrival in arrivals" :key="arrival.trip_uuid">
                <article class="glass card flex items-center gap-3 !py-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl text-xs font-bold text-white"
                          :style="`background:${arrival.line_color || '#12b76a'}`"
                          x-text="$num(arrival.line_code)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold" x-text="arrival.destination || arrival.line_name"></p>
                        <p class="mt-0.5 text-[11px] text-ink-400"
                           x-text="$t('viewer.bus_with_passengers', { bus: $num(arrival.bus_number), count: $num(arrival.passenger_count) })"></p>
                    </div>
                    <div class="text-end">
                        <p class="text-lg font-bold leading-none"
                           :class="arrival.eta.reliable ? 'text-brand-300' : 'text-ink-300'"
                           x-text="$num(arrival.eta.minutes)"></p>
                        <p class="text-[10px] text-ink-400">{{ __('web.viewer.minutes') }}</p>
                        <p x-show="!arrival.eta.reliable" class="text-[9px] text-amber-400">{{ __('web.viewer.approximate') }}</p>
                    </div>
                </article>
            </template>

            <p x-show="!arrivals.length && !loading" class="glass card text-center text-sm text-ink-400">
                {{ __('web.viewer.no_arrivals') }}
            </p>
        </div>

        {{-- The web viewer is deliberately read-only; anything that moves money
             lives in the native app, which can hold a token securely. --}}
        <div class="glass-strong card my-4">
            <h2 class="text-sm font-bold">{{ __('web.viewer.wallet_heading') }}</h2>
            <p class="mt-2 text-xs leading-6 text-ink-400">{{ __('web.viewer.wallet_body') }}</p>
            <a href="{{ url('/downloads/hamsafar-passenger.apk') }}" class="btn btn-primary mt-4 w-full">
                {{ __('web.viewer.download_app') }}
            </a>
        </div>
    </section>
</div>
@endsection

@push('scripts')
    @vite('resources/js/viewer.js')
@endpush
