@extends('layouts.app')

@section('title', 'حمل‌ونقل هوشمند شهری')

@section('content')

{{-- ═══════════════════════════════════════════ HERO ═══════════════════════ --}}
<section class="relative overflow-hidden pt-10 pb-20 sm:pt-16">
    <div class="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 lg:grid-cols-2">
        <div class="animate-rise">
            <span class="badge badge-success">
                <span class="live-dot"></span>
                {{ $city->name }} — هم‌اکنون فعال
            </span>

            <h1 class="mt-6 text-4xl font-bold leading-[1.25] tracking-tight sm:text-5xl lg:text-6xl lg:leading-[1.2]">
                حمل‌ونقل هوشمند،<br>
                <span class="text-gradient">ساده‌تر از همیشه</span>
            </h1>

            <p class="mt-6 max-w-xl text-base leading-8 text-ink-300 sm:text-lg">
                ببینید اتوبوس کجاست، چند دقیقه دیگر می‌رسد و کرایه را بدون اسکناس و بلیت
                پرداخت کنید. همان کیف پول، در استخر، باشگاه و فروشگاه‌های طرف قرارداد هم کار می‌کند.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('passenger.app') }}" class="btn btn-primary btn-lg">
                    <svg viewBox="0 0 24 24" class="size-5 fill-current"><path d="M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z"/></svg>
                    شروع سفر
                </a>
                <a href="#live" class="btn btn-ghost btn-lg">مشاهده نقشه زنده</a>
            </div>

            {{-- Live counters, fed by /api/v1/summary --}}
            <dl class="mt-12 grid max-w-lg grid-cols-3 gap-3" x-data="landingStats()" x-init="load()">
                <div class="glass card !p-4">
                    <dd class="stat-value text-brand-300" x-text="$num(stats.active_buses)">۰</dd>
                    <dt class="stat-label mt-1">اتوبوس فعال</dt>
                </div>
                <div class="glass card !p-4">
                    <dd class="stat-value" x-text="$num(stats.lines)">۰</dd>
                    <dt class="stat-label mt-1">خط فعال</dt>
                </div>
                <div class="glass card !p-4">
                    <dd class="stat-value" x-text="$num(stats.stops)">۰</dd>
                    <dt class="stat-label mt-1">ایستگاه</dt>
                </div>
            </dl>
        </div>

        {{-- Live map preview --}}
        <div class="relative animate-rise" style="animation-delay:120ms">
            <div class="glass-strong card overflow-hidden !p-2">
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <span class="live-dot"></span>
                        نقشه زنده {{ $city->name }}
                    </div>
                    <span class="badge badge-warning text-[10px]">داده نمونه</span>
                </div>
                <div id="hero-map" class="h-[380px] w-full rounded-2xl sm:h-[440px]"></div>
            </div>

            <div class="glass-strong card absolute -bottom-6 inset-inline-start-4 hidden w-64 animate-rise sm:block"
                 style="animation-delay:320ms">
                <p class="stat-label">نزدیک‌ترین اتوبوس</p>
                <p class="mt-1 text-2xl font-bold text-brand-300">۴ دقیقه</p>
                <p class="mt-1 text-xs text-ink-400">خط ۱۰۲ — مقصد دانشگاه هرمزگان</p>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ FEATURES ══════════════════════ --}}
<section id="features" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <div class="max-w-2xl">
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">یک اپلیکیشن، همه‌چیز</h2>
            <p class="mt-4 text-ink-300 leading-8">
                از لحظه‌ای که در ایستگاه منتظرید تا وقتی از اتوبوس پیاده می‌شوید — و حتی بعد از آن.
            </p>
        </div>

        <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $features = [
                    ['ردیابی زنده اتوبوس', 'موقعیت هر اتوبوس روی نقشه، بدون نیاز به تازه‌سازی صفحه. اتصال از طریق WebSocket برقرار می‌ماند.', 'M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z'],
                    ['تخمین هوشمند زمان رسیدن', 'ETA فقط از فاصله محاسبه نمی‌شود؛ سرعت لحظه‌ای، سابقه زمان سفر در همان ساعت و روز، و توقف‌های میان‌راه هم دخیل‌اند.', 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5v5.4l4 2.3-1 1.7-5-2.9V7h2Z'],
                    ['پرداخت با اسکن QR', 'کد داخل اتوبوس هر ۳۰ ثانیه تغییر می‌کند و امضای رمزنگاری‌شده دارد؛ عکس گرفتن از آن به کار کسی نمی‌آید.', 'M3 3h8v8H3V3Zm2 2v4h4V5H5Zm8-2h8v8h-8V3Zm2 2v4h4V5h-4ZM3 13h8v8H3v-8Zm2 2v4h4v-4H5Zm8 0h2v2h-2v-2Zm4 0h4v2h-2v2h2v4h-4v-2h-2v2h-2v-4h4v-2Z'],
                    ['کیف پول یکپارچه شهری', 'یک موجودی برای اتوبوس، استخر، باشگاه، پارکینگ و فروشگاه‌های طرف قرارداد.', 'M3 7a3 3 0 0 1 3-3h11a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H6a3 3 0 0 1-3-3V7Zm14 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'],
                    ['شمارش زنده مسافران', 'راننده لحظه‌به‌لحظه تعداد مسافران داخل اتوبوس را می‌بیند و مدیریت ناوگان بر اساس شلوغی واقعی تصمیم می‌گیرد.', 'M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.7 0-6 1.34-6 4v2h7v-2c0-1.1.44-2.2 1.3-3.1A11 11 0 0 0 8 13Zm8 0c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z'],
                    ['پیگیری شکایات', 'ثبت شکایت همراه با عکس، شماره اتوبوس و موقعیت؛ پاسخ کارشناس در همان گفت‌وگو نمایش داده می‌شود.', 'M12 2 2 7l10 5 10-5-10-5Zm0 20-4-2v-6l4 2 4-2v6l-4 2Z'],
                ];
            @endphp

            @foreach ($features as [$title, $body, $path])
                <article class="glass card card-hover">
                    <span class="grid size-11 place-items-center rounded-xl bg-brand-500/12 text-brand-300">
                        <svg viewBox="0 0 24 24" class="size-6 fill-current"><path d="{{ $path }}"/></svg>
                    </span>
                    <h3 class="mt-4 text-base font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-7 text-ink-400">{{ $body }}</p>
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
                    <h2 class="text-2xl font-bold">اتوبوس‌های در حال حرکت</h2>
                    <p class="mt-3 text-sm leading-7 text-ink-400">
                        هر نشانگر سبز روی نقشه، یک اتوبوس در سرویس است. با انتخاب هر اتوبوس،
                        خط، مقصد، ایستگاه بعدی و زمان تخمینی رسیدن نمایش داده می‌شود.
                    </p>

                    <div class="mt-6 flex flex-col gap-2" x-data="landingLines()" x-init="load()">
                        <template x-for="line in lines.slice(0, 6)" :key="line.id">
                            <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                                <span class="size-2.5 shrink-0 rounded-full" :style="`background:${line.color}`"></span>
                                <span class="text-sm font-semibold" x-text="line.code"></span>
                                <span class="truncate text-xs text-ink-400" x-text="line.destination || line.name"></span>
                            </div>
                        </template>
                        <p x-show="!lines.length" class="text-sm text-ink-500">در حال بارگذاری خطوط…</p>
                    </div>

                    <a href="{{ route('passenger.app') }}" class="btn btn-primary mt-6 w-full">باز کردن نقشه کامل</a>
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
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">کیف پولی که فقط برای اتوبوس نیست</h2>
            <p class="mt-5 leading-8 text-ink-300">
                موجودی خود را یک‌بار شارژ کنید و در سراسر شبکه خدمات شهری از آن استفاده کنید.
                هر تراکنش در دفتر مالی دوطرفه ثبت می‌شود؛ یعنی هر ریال جابه‌جاشده قابل ردیابی و ممیزی است.
            </p>

            <ul class="mt-8 flex flex-col gap-4">
                @foreach ([
                    ['پرداخت کرایه با اسکن', 'کد داخل اتوبوس را اسکن کنید؛ کرایه بر اساس قوانین نرخ‌گذاری شهر محاسبه و کسر می‌شود.'],
                    ['نرخ‌های ویژه', 'تخفیف دانش‌آموزی، سالمندان و جانبازان به‌صورت خودکار روی کرایه اعمال می‌شود.'],
                    ['سابقه کامل تراکنش‌ها', 'هر پرداخت با مبلغ، خط، زمان و موجودی پس از تراکنش ثبت می‌شود.'],
                    ['بازگشت وجه', 'در صورت خطا، تراکنش با یک سند معکوس برگشت می‌خورد — سابقه هرگز پاک نمی‌شود.'],
                ] as [$title, $body])
                    <li class="flex gap-4">
                        <span class="mt-1 grid size-7 shrink-0 place-items-center rounded-full bg-brand-500/15 text-brand-300">
                            <svg viewBox="0 0 24 24" class="size-4 fill-current"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold">{{ $title }}</h3>
                            <p class="mt-1 text-sm leading-7 text-ink-400">{{ $body }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Wallet card mockup --}}
        <div class="relative">
            <div class="glass-strong card mx-auto max-w-sm">
                <div class="flex items-center justify-between">
                    <span class="stat-label">موجودی کیف پول</span>
                    <span class="badge badge-success">فعال</span>
                </div>
                <p class="mt-3 text-3xl font-bold">۵۰۰٬۰۰۰ <span class="text-base font-medium text-ink-400">تومان</span></p>

                <div class="mt-6 flex flex-col gap-2">
                    @foreach ([
                        ['پرداخت کرایه — خط ۱۰۲', '−۵٬۰۰۰', 'danger'],
                        ['استخر ساحل', '−۵۰٬۰۰۰', 'danger'],
                        ['شارژ کیف پول', '+۲۰۰٬۰۰۰', 'success'],
                    ] as [$label, $amount, $tone])
                        <div class="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2.5">
                            <span class="text-xs text-ink-300">{{ $label }}</span>
                            <span class="text-xs font-semibold {{ $tone === 'success' ? 'text-brand-300' : 'text-ink-200' }}">{{ $amount }}</span>
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
        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">برای هر کسی که در شهر نقشی دارد</h2>

        <div class="mt-10 grid gap-4 md:grid-cols-3">
            @foreach ([
                ['مسافران', 'brand', [
                    'دیدن اتوبوس‌های نزدیک و زمان رسیدن',
                    'پرداخت کرایه بدون پول نقد',
                    'مشاهده تاریخچه سفرها و تراکنش‌ها',
                    'ثبت شکایت با عکس و پیگیری پاسخ',
                ]],
                ['رانندگان', 'info', [
                    'شروع شیفت با اسکن کد داخل اتوبوس',
                    'مشاهده مسیر، ایستگاه بعدی و مقصد',
                    'شمارش زنده مسافران و درآمد شیفت',
                    'ارسال هوشمند موقعیت با مصرف کم باتری',
                ]],
                ['کسب‌وکارها', 'warning', [
                    'دریافت پرداخت با QR اختصاصی صندوق',
                    'گزارش فروش روزانه و ماهانه',
                    'بازگشت وجه با سطح دسترسی مشخص',
                    'تسویه حساب دوره‌ای و شفاف',
                ]],
            ] as [$audience, $tone, $items])
                <article class="glass card card-hover">
                    <span class="badge badge-{{ $tone }}">{{ $audience }}</span>
                    <ul class="mt-5 flex flex-col gap-3">
                        @foreach ($items as $item)
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
                    <h2 class="text-3xl font-bold tracking-tight">پذیرندگان خارج از شبکه اتوبوس</h2>
                    <p class="mt-4 max-w-2xl leading-8 text-ink-300">
                        هر کسب‌وکاری می‌تواند به شبکه پرداخت شهری بپیوندد. مشتری کد صندوق را اسکن می‌کند،
                        مبلغ از کیف پول او کسر و پس از کسر کارمزد به حساب پذیرنده منتقل می‌شود — همه در یک سند مالی واحد.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-2">
                        @foreach (['استخر', 'باشگاه بدنسازی', 'مجموعه ورزشی', 'مرکز تفریحی', 'رستوران', 'فروشگاه', 'سینما', 'پارکینگ'] as $type)
                            <span class="badge badge-neutral">{{ $type }}</span>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('merchant.app') }}" class="btn btn-primary btn-lg shrink-0">اپلیکیشن پذیرنده</a>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════════════ CITIES ════════════════════════ --}}
<section id="cities" class="py-20">
    <div class="mx-auto w-full max-w-7xl px-4">
        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">شهرهای تحت پوشش</h2>
        <p class="mt-4 max-w-2xl text-ink-300 leading-8">
            سامانه از ابتدا چندشهری طراحی شده است؛ افزودن شهر تازه نیازی به تغییر معماری ندارد.
        </p>

        <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($cities as $item)
                <article class="glass card card-hover text-center">
                    <h3 class="text-base font-semibold">{{ $item->name }}</h3>
                    <p class="mt-1 text-xs text-ink-500">{{ $item->province }}</p>
                    <span class="badge mt-3 {{ $item->is_launched ? 'badge-success' : 'badge-neutral' }}">
                        {{ $item->is_launched ? 'فعال' : 'به‌زودی' }}
                    </span>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════ FAQ ══════════════════════════ --}}
<section id="faq" class="py-20">
    <div class="mx-auto w-full max-w-3xl px-4">
        <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">پرسش‌های متداول</h2>

        <div class="mt-10 flex flex-col gap-3" x-data="{ open: 0 }">
            @php
                $faqs = [
                    ['برای دیدن نقشه و زمان رسیدن باید ثبت‌نام کنم؟',
                     'خیر. مشاهده ایستگاه‌ها، خطوط، اتوبوس‌های در حال حرکت و زمان تخمینی رسیدن برای همه آزاد است. ثبت‌نام فقط برای استفاده از کیف پول، پرداخت کرایه، تاریخچه سفر و ثبت شکایت لازم است.'],
                    ['زمان رسیدن اتوبوس چقدر دقیق است؟',
                     'محاسبه بر پایه موقعیت لحظه‌ای اتوبوس، سرعت فعلی، میانگین زمان طی‌شده همان مسیر در همان ساعت و روز هفته، و زمان توقف در ایستگاه‌های میانی انجام می‌شود. هر تخمین همراه با میزان اطمینان ارائه می‌شود؛ اگر ارتباط اتوبوس قطع شده باشد، این موضوع به شما اعلام می‌گردد.'],
                    ['اگر کسی از کد QR داخل اتوبوس عکس بگیرد چه می‌شود؟',
                     'کد نمایش‌داده‌شده هر ۳۰ ثانیه تغییر می‌کند و با کلید اختصاصی همان اتوبوس امضا می‌شود. هر کد فقط یک‌بار قابل استفاده است، بنابراین عکس یا اسکرین‌شات آن پس از چند ثانیه بی‌اثر می‌شود.'],
                    ['اگر اشتباهی کرایه دو بار کسر شود؟',
                     'ساختار پرداخت طوری طراحی شده که کسر دوباره رخ ندهد: هر اسکن یک شناسه یکتا دارد و تلاش دوباره با همان شناسه، همان تراکنش قبلی را برمی‌گرداند. در صورت بروز هر مشکل، از بخش شکایات پیگیری کنید؛ برگشت وجه با ثبت سند معکوس انجام می‌شود.'],
                    ['موقعیت مکانی من ذخیره می‌شود؟',
                     'ارسال موقعیت مسافر اختیاری است و تنها برای تشخیص پیاده شدن از اتوبوس در طول همان سفر استفاده می‌شود. این داده‌ها حداکثر تا ۲۴ ساعت نگهداری و سپس حذف می‌شوند و در اختیار سایر کاربران قرار نمی‌گیرند.'],
                    ['داده‌های خطوط و ایستگاه‌ها رسمی است؟',
                     'در نسخه فعلی، داده‌های شبکه بندرعباس «نمونه» هستند و با برچسب مشخص نمایش داده می‌شوند. پس از دریافت داده رسمی از سازمان اتوبوس‌رانی، همان داده‌ها از طریق سامانه ورود اطلاعات جایگزین و به‌عنوان داده رسمی علامت‌گذاری می‌شوند.'],
                ];
            @endphp

            @foreach ($faqs as $index => [$question, $answer])
                <article class="glass card !py-0">
                    <button type="button" class="flex w-full items-center gap-4 py-5 text-start"
                            @click="open = open === {{ $index }} ? null : {{ $index }}"
                            :aria-expanded="open === {{ $index }}">
                        <span class="flex-1 text-sm font-semibold sm:text-base">{{ $question }}</span>
                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-white/5 transition-transform duration-300"
                              :class="open === {{ $index }} && 'rotate-45'">
                            <svg viewBox="0 0 24 24" class="size-4 fill-current"><path d="M11 5h2v14h-2z"/><path d="M5 11h14v2H5z"/></svg>
                        </span>
                    </button>
                    <div x-show="open === {{ $index }}" x-collapse x-cloak>
                        <p class="pb-5 text-sm leading-8 text-ink-400">{{ $answer }}</p>
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
            <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">همین حالا شروع کنید</h2>
            <p class="mx-auto mt-4 max-w-xl leading-8 text-ink-300">
                اپلیکیشن مسافر بدون نصب، مستقیماً در مرورگر اجرا می‌شود. می‌توانید آن را
                به صفحه اصلی گوشی اضافه کنید تا مانند یک اپلیکیشن معمولی باز شود.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('passenger.app') }}" class="btn btn-primary btn-lg">باز کردن اپلیکیشن مسافر</a>
                <a href="{{ route('driver.app') }}" class="btn btn-ghost btn-lg">ورود رانندگان</a>
            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
    @vite('resources/js/landing.js')
@endpush
