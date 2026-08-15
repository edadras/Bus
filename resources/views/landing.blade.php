@extends('layouts.app')

@section('title', __('landing.title'))

@section('content')

{{-- ═══════════════════════════════════════════ HERO ═══════════════════════ --}}
<section class="relative overflow-hidden pt-10 pb-20 sm:pt-16">
    <div class="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 lg:grid-cols-2">
        <div class="animate-rise">
            <span class="badge badge-success">
                <span class="live-dot"></span>
                {{ __('landing.hero.live_badge', ['city' => $city->name]) }}
            </span>

            <h1 class="mt-6 text-4xl font-bold leading-[1.25] tracking-tight sm:text-5xl lg:text-6xl lg:leading-[1.2]">
                {{ __('landing.hero.heading_line_one') }}<br>
                <span class="text-gradient">{{ __('landing.hero.heading_line_two') }}</span>
            </h1>

            <p class="mt-6 max-w-xl text-base leading-8 text-ink-300 sm:text-lg">
                {{ __('landing.hero.body') }}
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('passenger.app') }}" class="btn btn-primary btn-lg">
                    <svg viewBox="0 0 24 24" class="size-5 fill-current"><path d="M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z"/></svg>
                    {{ __('landing.hero.start') }}
                </a>
                <a href="#live" class="btn btn-ghost btn-lg">{{ __('landing.hero.see_map') }}</a>
            </div>

            {{-- Live counters, fed by /api/v1/summary --}}
            <dl class="mt-12 grid max-w-lg grid-cols-3 gap-3" x-data="landingStats()" x-init="load()">
                <div class="glass card !p-4">
                    <dd class="stat-value text-brand-300" x-text="$num(stats.active_buses)">{{ \App\Support\Digits::number(0) }}</dd>
                    <dt class="stat-label mt-1">{{ __('landing.hero.active_buses') }}</dt>
                </div>
                <div class="glass card !p-4">
                    <dd class="stat-value" x-text="$num(stats.lines)">{{ \App\Support\Digits::number(0) }}</dd>
                    <dt class="stat-label mt-1">{{ __('landing.hero.active_lines') }}</dt>
                </div>
                <div class="glass card !p-4">
                    <dd class="stat-value" x-text="$num(stats.stops)">{{ \App\Support\Digits::number(0) }}</dd>
                    <dt class="stat-label mt-1">{{ __('landing.hero.stops') }}</dt>
                </div>
            </dl>
        </div>

        {{-- Live map preview --}}
        <div class="relative animate-rise" style="animation-delay:120ms">
            <div class="glass-strong card overflow-hidden !p-2">
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <span class="live-dot"></span>
                        {{ __('landing.hero.map_title', ['city' => $city->name]) }}
                    </div>
                    <span class="badge badge-warning text-[10px]">{{ __('landing.hero.sample_badge') }}</span>
                </div>
                <div id="hero-map" class="h-[380px] w-full rounded-2xl sm:h-[440px]"></div>
            </div>

            <div class="glass-strong card absolute -bottom-6 inset-inline-start-4 hidden w-64 animate-rise sm:block"
                 style="animation-delay:320ms">
                <p class="stat-label">{{ __('landing.hero.nearest_bus') }}</p>
                <p class="mt-1 text-2xl font-bold text-brand-300">{{ __('landing.hero.nearest_bus_eta') }}</p>
                <p class="mt-1 text-xs text-ink-400">{{ __('landing.hero.nearest_bus_line') }}</p>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ FEATURES ══════════════════════ --}}
<section id="features" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <div class="max-w-2xl">
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.features.heading') }}</h2>
            <p class="mt-4 text-ink-300 leading-8">
                {{ __('landing.features.subheading') }}
            </p>
        </div>

        <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @php
                // Icon paths stay here because they are geometry, not copy;
                // the words live in lang/*/landing.php with everything else.
                $features = [
                    'tracking' => 'M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z',
                    'eta' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5v5.4l4 2.3-1 1.7-5-2.9V7h2Z',
                    'qr' => 'M3 3h8v8H3V3Zm2 2v4h4V5H5Zm8-2h8v8h-8V3Zm2 2v4h4V5h-4ZM3 13h8v8H3v-8Zm2 2v4h4v-4H5Zm8 0h2v2h-2v-2Zm4 0h4v2h-2v2h2v4h-4v-2h-2v2h-2v-4h4v-2Z',
                    'wallet' => 'M3 7a3 3 0 0 1 3-3h11a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H6a3 3 0 0 1-3-3V7Zm14 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
                    'counting' => 'M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.7 0-6 1.34-6 4v2h7v-2c0-1.1.44-2.2 1.3-3.1A11 11 0 0 0 8 13Zm8 0c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z',
                    'complaints' => 'M12 2 2 7l10 5 10-5-10-5Zm0 20-4-2v-6l4 2 4-2v6l-4 2Z',
                ];
            @endphp

            @foreach ($features as $key => $path)
                <article class="glass card card-hover">
                    <span class="grid size-11 place-items-center rounded-xl bg-brand-500/12 text-brand-300">
                        <svg viewBox="0 0 24 24" class="size-6 fill-current"><path d="{{ $path }}"/></svg>
                    </span>
                    <h3 class="mt-4 text-base font-semibold">{{ __("landing.features.items.$key.title") }}</h3>
                    <p class="mt-2 text-sm leading-7 text-ink-400">{{ __("landing.features.items.$key.body") }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════════════════════════════ LIVE MAP ═══════════════════════ --}}
<section id="live" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <div class="glass-strong card overflow-hidden !p-0">
            <div class="grid lg:grid-cols-[380px_1fr]">
                <div class="border-b border-white/5 p-6 lg:border-b-0 lg:border-e">
                    <h2 class="text-2xl font-bold">{{ __('landing.live.heading') }}</h2>
                    <p class="mt-3 text-sm leading-7 text-ink-400">
                        {{ __('landing.live.body') }}
                    </p>

                    <div class="mt-6 flex flex-col gap-2" x-data="landingLines()" x-init="load()">
                        <template x-for="line in lines.slice(0, 6)" :key="line.id">
                            <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                                <span class="size-2.5 shrink-0 rounded-full" :style="`background:${line.color}`"></span>
                                <span class="text-sm font-semibold" x-text="line.code"></span>
                                <span class="truncate text-xs text-ink-400" x-text="line.destination || line.name"></span>
                            </div>
                        </template>
                        <p x-show="!lines.length" class="text-sm text-ink-500">{{ __('landing.live.loading_lines') }}</p>
                    </div>

                    <a href="{{ route('passenger.app') }}" class="btn btn-primary mt-6 w-full">{{ __('landing.live.open_full_map') }}</a>
                </div>

                <div id="live-map" class="h-[420px] w-full lg:h-[560px]"></div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ WALLET ════════════════════════ --}}
<section id="wallet" class="py-20">
    <div class="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 lg:grid-cols-2">
        <div>
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.wallet.heading') }}</h2>
            <p class="mt-5 leading-8 text-ink-300">
                {{ __('landing.wallet.body') }}
            </p>

            <ul class="mt-8 flex flex-col gap-4">
                @foreach (['scan', 'concessions', 'history', 'refund'] as $point)
                    <li class="flex gap-4">
                        <span class="mt-1 grid size-7 shrink-0 place-items-center rounded-full bg-brand-500/15 text-brand-300">
                            <svg viewBox="0 0 24 24" class="size-4 fill-current"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold">{{ __("landing.wallet.points.$point.title") }}</h3>
                            <p class="mt-1 text-sm leading-7 text-ink-400">{{ __("landing.wallet.points.$point.body") }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Wallet card mockup --}}
        <div class="relative">
            <div class="glass-strong card mx-auto max-w-sm">
                <div class="flex items-center justify-between">
                    <span class="stat-label">{{ __('landing.wallet.card_balance') }}</span>
                    <span class="badge badge-success">{{ __('landing.wallet.card_active') }}</span>
                </div>
                <p class="mt-3 text-3xl font-bold">{{ \App\Support\Money::format(5_000_000, false) }} <span class="text-base font-medium text-ink-400">{{ __('common.currency_toman') }}</span></p>

                <div class="mt-6 flex flex-col gap-2">
                    {{-- Illustrative figures, formatted by the same helper as
                         real money so the mockup cannot drift from the app. --}}
                    @foreach ([
                        ['fare', -50_000, 'danger'],
                        ['pool', -500_000, 'danger'],
                        ['topup', 2_000_000, 'success'],
                    ] as [$row, $amount, $tone])
                        <div class="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2.5">
                            <span class="text-xs text-ink-300">{{ __("landing.wallet.sample_rows.$row") }}</span>
                            <span class="text-xs font-semibold {{ $tone === 'success' ? 'text-brand-300' : 'text-ink-200' }}" dir="ltr">{{ $amount > 0 ? '+' : '−' }}{{ \App\Support\Money::format(abs($amount), false) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═════════════════════════════════════ AUDIENCES ══════════════════════ --}}
<section class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.audiences.heading') }}</h2>

        <div class="mt-10 grid gap-4 md:grid-cols-3">
            @foreach ([
                'passengers' => 'brand',
                'drivers' => 'info',
                'businesses' => 'warning',
            ] as $audience => $tone)
                <article class="glass card card-hover">
                    <span class="badge badge-{{ $tone }}">{{ __("landing.audiences.$audience.title") }}</span>
                    <ul class="mt-5 flex flex-col gap-3">
                        @foreach (__("landing.audiences.$audience.items") as $item)
                            <li class="flex gap-2.5 text-sm leading-7 text-ink-300">
                                <span class="mt-2.5 size-1.5 shrink-0 rounded-full bg-brand-400"></span>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ═════════════════════════════════════ MERCHANTS ══════════════════════ --}}
<section id="merchants" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <div class="glass-strong card">
            <div class="grid items-center gap-10 lg:grid-cols-[1fr_auto]">
                <div>
                    <h2 class="text-3xl font-bold tracking-tight">{{ __('landing.merchants.heading') }}</h2>
                    <p class="mt-4 max-w-2xl leading-8 text-ink-300">
                        {{ __('landing.merchants.body') }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-2">
                        {{-- Merchant categories come from the enum, so the badges here
                             can never drift from what a merchant can actually be. --}}
                        @foreach (\App\Domain\Merchant\Enums\MerchantType::options() as $type)
                            @continue($type['value'] === 'other')
                            <span class="badge badge-neutral">{{ $type['label'] }}</span>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('merchant.app') }}" class="btn btn-primary btn-lg shrink-0">{{ __('landing.merchants.cta') }}</a>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════════════ CITIES ════════════════════════ --}}
<section id="cities" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.cities.heading') }}</h2>
        <p class="mt-4 max-w-2xl text-ink-300 leading-8">
            {{ __('landing.cities.body') }}
        </p>

        <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($cities as $item)
                <article class="glass card card-hover text-center">
                    <h3 class="text-base font-semibold">{{ $item->name }}</h3>
                    <p class="mt-1 text-xs text-ink-500">{{ $item->province }}</p>
                    <span class="badge mt-3 {{ $item->is_launched ? 'badge-success' : 'badge-neutral' }}">
                        {{ $item->is_launched ? __('landing.cities.launched') : __('landing.cities.soon') }}
                    </span>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ FAQ ══════════════════════════ --}}
<section id="faq" class="py-20">
    <div class="mx-auto w-full max-w-3xl px-4">
        <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.faq.heading') }}</h2>

        <div class="mt-10 flex flex-col gap-3" x-data="{ open: 0 }">
            @php($faqs = ['signup', 'accuracy', 'qr_photo', 'double_charge', 'location', 'official_data'])

            @foreach ($faqs as $index => $faq)
                <article class="glass card !py-0">
                    <button type="button" class="flex w-full items-center gap-4 py-5 text-start"
                            @click="open = open === {{ $index }} ? null : {{ $index }}"
                            :aria-expanded="open === {{ $index }}">
                        <span class="flex-1 text-sm font-semibold sm:text-base">{{ __("landing.faq.items.$faq.question") }}</span>
                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-white/5 transition-transform duration-300"
                              :class="open === {{ $index }} && 'rotate-45'">
                            <svg viewBox="0 0 24 24" class="size-4 fill-current"><path d="M11 5h2v14h-2z"/><path d="M5 11h14v2H5z"/></svg>
                        </span>
                    </button>
                    <div x-show="open === {{ $index }}" x-collapse x-cloak>
                        <p class="pb-5 text-sm leading-8 text-ink-400">{{ __("landing.faq.items.$faq.answer") }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ CTA ══════════════════════════ --}}
<section class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <div class="glass-strong card overflow-hidden text-center !py-14">
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('landing.cta.heading') }}</h2>
            <p class="mx-auto mt-4 max-w-xl leading-8 text-ink-300">
                {{ __('landing.cta.body') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('passenger.app') }}" class="btn btn-primary btn-lg">{{ __('landing.cta.passenger') }}</a>
                <a href="{{ route('driver.app') }}" class="btn btn-ghost btn-lg">{{ __('landing.cta.driver') }}</a>
            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
    @vite('resources/js/landing.js')
@endpush
