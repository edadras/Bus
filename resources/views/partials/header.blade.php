<header x-data="{ open: false, scrolled: false }"
        @scroll.window="scrolled = window.scrollY > 24"
        class="sticky top-0 z-50 transition-all duration-300"
        :class="scrolled ? 'py-2' : 'py-4'">
    <div class="mx-auto w-full max-w-7xl px-4">
        <nav class="glass card flex items-center gap-4 !py-3 transition-all duration-300"
             :class="scrolled ? '!rounded-2xl' : ''">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 shadow-lg shadow-brand-500/30">
                    <svg viewBox="0 0 24 24" class="size-5 fill-white" aria-hidden="true">
                        <path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/>
                    </svg>
                </span>
                <span class="text-lg font-bold tracking-tight">{{ __('common.app_name') }}</span>
            </a>

            <ul class="ms-6 hidden items-center gap-1 text-sm lg:flex">
                @foreach ([
                    '#features' => 'امکانات',
                    '#live' => 'نقشه زنده',
                    '#wallet' => 'کیف پول',
                    '#merchants' => 'پذیرندگان',
                    '#cities' => 'شهرها',
                    '#faq' => 'پرسش‌های متداول',
                ] as $href => $label)
                    <li>
                        <a href="{{ $href }}"
                           class="rounded-full px-3 py-2 text-ink-300 transition hover:bg-white/5 hover:text-ink-50">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>

            <div class="ms-auto flex items-center gap-2">
                <a href="{{ route('passenger.app') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">
                    ورود به اپلیکیشن
                </a>

                <button type="button" class="btn btn-ghost btn-sm lg:hidden" @click="open = !open"
                        :aria-expanded="open" aria-label="منو">
                    <svg viewBox="0 0 24 24" class="size-5 stroke-current fill-none" stroke-width="2">
                        <path x-show="!open" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/>
                        <path x-show="open" x-cloak d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </nav>

        <div x-show="open" x-cloak x-transition.opacity
             class="glass card mt-2 lg:hidden">
            <ul class="flex flex-col gap-1 text-sm">
                @foreach ([
                    '#features' => 'امکانات', '#live' => 'نقشه زنده', '#wallet' => 'کیف پول',
                    '#merchants' => 'پذیرندگان', '#cities' => 'شهرها', '#faq' => 'پرسش‌های متداول',
                ] as $href => $label)
                    <li><a href="{{ $href }}" @click="open = false"
                           class="block rounded-xl px-3 py-2.5 text-ink-200 hover:bg-white/5">{{ $label }}</a></li>
                @endforeach
                <li class="pt-2">
                    <a href="{{ route('passenger.app') }}" class="btn btn-primary w-full">ورود به اپلیکیشن</a>
                </li>
            </ul>
        </div>
    </div>
</header>
