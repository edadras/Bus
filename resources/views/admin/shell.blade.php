@extends('layouts.base')

@section('title', __('admin.title'))

@section('body')
<div x-data="adminShell()" x-init="boot()" class="flex min-h-dvh">

    {{-- ─────────────────────────────── sidebar ────────────────────────── --}}
    <aside class="fixed inset-y-0 z-40 w-64 shrink-0 transition-transform duration-300 lg:static lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'">
        <div class="glass-strong m-3 flex h-[calc(100dvh-1.5rem)] flex-col rounded-3xl p-4">
            <a href="/admin" class="flex items-center gap-2.5 px-2 py-1">
                <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600">
                    <svg viewBox="0 0 24 24" class="size-5 fill-white"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold">{{ __('common.app_name') }}</p>
                    <p class="truncate text-[10px] text-ink-400">{{ __('admin.title') }}</p>
                </div>
            </a>

            <nav class="no-scrollbar mt-6 flex-1 overflow-y-auto">
                <ul class="flex flex-col gap-1">
                    <template x-for="item in visibleNav" :key="item.id">
                        <li>
                            <button type="button"
                                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm transition"
                                    :class="view === item.id
                                        ? 'bg-brand-500/15 text-brand-300 font-semibold'
                                        : 'text-ink-300 hover:bg-white/5'"
                                    @click="go(item.id)">
                                <svg viewBox="0 0 24 24" class="size-[18px] shrink-0 fill-current">
                                    <path :d="item.icon"></path>
                                </svg>
                                <span x-text="item.label"></span>
                                <span x-show="item.badge" x-cloak
                                      class="ms-auto rounded-full bg-danger/20 px-2 py-0.5 text-[10px] font-bold text-red-300"
                                      x-text="$num(item.badge)"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </nav>

            <div class="mt-4 border-t border-white/5 pt-4">
                <div class="flex items-center gap-2.5 px-2">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-white/5 text-sm font-bold"
                          x-text="(user.name || $t('admin.shell.unknown_initial')).charAt(0)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-semibold" x-text="user.name"></p>
                        <p class="truncate text-[10px] text-ink-500" x-text="(user.roles || []).join($t('admin.shell.role_separator'))"></p>
                    </div>
                    <button type="button" class="text-ink-400 hover:text-danger" @click="signOut()" title="{{ __('admin.shell.sign_out') }}">
                        <svg viewBox="0 0 24 24" class="size-[18px] fill-current"><path d="M10 17v-2h4v-2h-4v-2l-4 3 4 3Zm2-15a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </aside>

    <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-30 bg-black/50 lg:hidden" @click="sidebarOpen = false"></div>

    {{-- ─────────────────────────────── content ────────────────────────── --}}
    <main class="min-w-0 flex-1 p-3 lg:ps-0">
        <header class="glass card mb-3 flex items-center gap-3 !py-3">
            <button type="button" class="btn btn-ghost btn-sm lg:hidden" @click="sidebarOpen = true">☰</button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-bold" x-text="currentTitle"></h1>
                <p class="truncate text-[11px] text-ink-400" x-text="city.name || ''"></p>
            </div>

            <span class="badge badge-success hidden sm:inline-flex">
                <span class="live-dot"></span>
                <span x-text="realtimeConnected ? $t('admin.shell.realtime_connected') : $t('admin.shell.realtime_polling')"></span>
            </span>

            <button type="button" class="btn btn-ghost btn-sm" @click="refresh()" :disabled="loading">
                <svg viewBox="0 0 24 24" class="size-4 fill-current" :class="loading && 'animate-spin'">
                    <path d="M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/>
                </svg>
            </button>
        </header>

        <div class="animate-fade" :key="view">
            @include('admin.views.dashboard')
            @include('admin.views.live-map')
            @include('admin.views.fleet')
            @include('admin.views.drivers')
            @include('admin.views.network')
            @include('admin.views.finance')
            @include('admin.views.merchants')
            @include('admin.views.occupancy')
            @include('admin.views.reports')
            @include('admin.views.complaints')
            @include('admin.views.taxis')
            @include('admin.views.school')
        </div>
    </main>

    @include('admin.partials.form-modal')
</div>
@endsection

@push('i18n')
    {{-- The panel's own strings, plus the enum labels it renders in tables.
         Same keys as the server uses, so a string can move between a Blade
         view and a JS component without being renamed. --}}
    <script>
        Object.assign(window.__I18N__, @json(
            ['admin' => __('admin'), 'enums' => __('enums')],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE,
        ));
    </script>
@endpush

@push('scripts')
    @vite('resources/js/admin.js')
@endpush
