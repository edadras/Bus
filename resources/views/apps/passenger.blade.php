@extends('layouts.pwa')

@section('title', 'اپلیکیشن مسافر')

@section('app')
<div x-data="passengerApp(@js($prefilledToken ?? null))" x-init="boot()" class="flex flex-1 flex-col">

    {{-- ─────────────────────────────── header ─────────────────────────── --}}
    <header class="sticky top-0 z-30 px-3 pt-3">
        <div class="glass card flex items-center gap-3 !py-3">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600">
                <svg viewBox="0 0 24 24" class="size-5 fill-white"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">{{ __('common.app_name') }}</p>
                <p class="truncate text-[11px] text-ink-400">{{ $city->name }}</p>
            </div>

            <template x-if="signedIn">
                <button type="button" class="rounded-xl bg-white/5 px-3 py-2 text-start" @click="tab = 'wallet'">
                    <p class="text-[10px] text-ink-400">موجودی</p>
                    <p class="text-xs font-bold text-brand-300" x-text="$money(wallet.balance)">—</p>
                </button>
            </template>
            <template x-if="!signedIn">
                <button type="button" class="btn btn-primary btn-sm" @click="tab = 'account'">ورود</button>
            </template>
        </div>
    </header>

    {{-- ──────────────────────────────── MAP ───────────────────────────── --}}
    <section x-show="tab === 'map'" class="flex flex-1 flex-col px-3 pt-3" x-cloak>
        <div class="glass card relative overflow-hidden !p-1.5">
            <div id="passenger-map" class="h-[46vh] w-full rounded-2xl"></div>

            <button type="button"
                    class="glass-strong absolute bottom-4 inset-inline-end-4 grid size-11 place-items-center rounded-xl"
                    @click="locateMe()" aria-label="موقعیت من">
                <svg viewBox="0 0 24 24" class="size-5 fill-brand-300"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0-6a1 1 0 0 1 1 1v1.06A8 8 0 0 1 19.94 11H21a1 1 0 1 1 0 2h-1.06A8 8 0 0 1 13 19.94V21a1 1 0 1 1-2 0v-1.06A8 8 0 0 1 4.06 13H3a1 1 0 1 1 0-2h1.06A8 8 0 0 1 11 4.06V3a1 1 0 0 1 1-1Zm0 4a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z"/></svg>
            </button>

            <div class="absolute top-3 inset-inline-start-3 flex items-center gap-1.5">
                <span class="badge badge-success !text-[10px]">
                    <span class="live-dot"></span><span x-text="`${$num(busCount)} اتوبوس`"></span>
                </span>
                <span class="badge badge-warning !text-[10px]">داده نمونه</span>
            </div>
        </div>

        {{-- Nearest stop + arrival board --}}
        <div class="mt-3 flex flex-col gap-2">
            <div class="flex items-center justify-between px-1">
                <h2 class="text-sm font-bold" x-text="nearestStop ? `ایستگاه ${nearestStop.name}` : 'ایستگاه‌های نزدیک'"></h2>
                <button type="button" class="text-xs text-brand-300" @click="loadArrivals()">به‌روزرسانی</button>
            </div>

            <template x-if="loadingArrivals">
                <div class="flex flex-col gap-2">
                    <div class="skeleton h-16"></div><div class="skeleton h-16"></div>
                </div>
            </template>

            <template x-for="arrival in arrivals" :key="arrival.trip_uuid">
                <article class="glass card card-hover flex items-center gap-3 !py-3"
                         @click="openTrip(arrival.trip_uuid)">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl text-xs font-bold text-white"
                          :style="`background:${arrival.line_color || '#12b76a'}`"
                          x-text="arrival.line_code"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold" x-text="arrival.destination || arrival.line_name"></p>
                        <p class="mt-0.5 truncate text-[11px] text-ink-400">
                            <span x-text="`اتوبوس ${arrival.bus_number ?? '—'}`"></span> ·
                            <span x-text="`${$num(arrival.passenger_count)} مسافر`"></span>
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="text-lg font-bold leading-none"
                           :class="arrival.eta.reliable ? 'text-brand-300' : 'text-ink-300'"
                           x-text="$num(arrival.eta.minutes)"></p>
                        <p class="text-[10px] text-ink-400">دقیقه</p>
                        {{-- A low-confidence estimate is labelled, not hidden. --}}
                        <p x-show="!arrival.eta.reliable" class="text-[9px] text-amber-400">تقریبی</p>
                    </div>
                </article>
            </template>

            <p x-show="!loadingArrivals && !arrivals.length" class="glass card text-center text-sm text-ink-400">
                در حال حاضر اتوبوسی به این ایستگاه نزدیک نیست.
            </p>
        </div>
    </section>

    {{-- ─────────────────────────────── SCAN ──────────────────────────── --}}
    <section x-show="tab === 'scan'" class="flex flex-1 flex-col px-3 pt-3" x-cloak>
        <template x-if="activeRide">
            <div class="glass-strong card">
                <div class="flex items-center gap-2">
                    <span class="live-dot"></span>
                    <h2 class="text-sm font-bold">سفر در حال انجام</h2>
                </div>
                <p class="mt-3 text-2xl font-bold" x-text="activeRide.trip?.line?.name || '—'"></p>
                <p class="mt-1 text-sm text-ink-400">
                    مقصد: <span x-text="activeRide.trip?.destination || '—'"></span>
                </p>

                <dl class="mt-5 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-white/[0.04] py-3">
                        <dd class="text-sm font-bold" x-text="activeRide.trip?.next_stop?.name || '—'"></dd>
                        <dt class="mt-1 text-[10px] text-ink-400">ایستگاه بعدی</dt>
                    </div>
                    <div class="rounded-xl bg-white/[0.04] py-3">
                        <dd class="text-sm font-bold text-brand-300"
                            x-text="$money(activeRide.passenger_trip?.fare?.amount)"></dd>
                        <dt class="mt-1 text-[10px] text-ink-400">کرایه</dt>
                    </div>
                    <div class="rounded-xl bg-white/[0.04] py-3">
                        <dd class="text-sm font-bold" x-text="$num(activeRide.trip?.passenger_count)"></dd>
                        <dt class="mt-1 text-[10px] text-ink-400">مسافر</dt>
                    </div>
                </dl>

                <button type="button" class="btn btn-ghost mt-5 w-full" @click="endRide()" :disabled="busy">
                    پیاده شدم
                </button>
            </div>
        </template>

        <template x-if="!activeRide">
            <div class="glass card flex flex-col items-center text-center">
                <h2 class="text-base font-bold">پرداخت کرایه</h2>
                <p class="mt-2 text-sm leading-7 text-ink-400">
                    کد QR داخل اتوبوس را اسکن کنید. کرایه به‌صورت خودکار محاسبه و از کیف پول شما کسر می‌شود.
                </p>

                <div id="qr-reader" class="mt-5 w-full overflow-hidden rounded-2xl"
                     :class="scanning ? '' : 'hidden'"></div>

                <template x-if="!scanning">
                    <button type="button" class="btn btn-primary btn-lg mt-5 w-full" @click="startScan()"
                            :disabled="!signedIn">
                        <svg viewBox="0 0 24 24" class="size-5 fill-current"><path d="M3 3h8v8H3V3Zm2 2v4h4V5H5Zm8-2h8v8h-8V3Zm2 2v4h4V5h-4ZM3 13h8v8H3v-8Zm2 2v4h4v-4H5Zm8 0h2v2h-2v-2Zm4 0h4v2h-2v2h2v4h-4v-2h-2v2h-2v-4h4v-2Z"/></svg>
                        اسکن کد اتوبوس
                    </button>
                </template>
                <button type="button" x-show="scanning" class="btn btn-ghost mt-4 w-full" @click="stopScan()">لغو</button>

                <p x-show="!signedIn" class="mt-3 text-xs text-amber-400">
                    برای پرداخت کرایه ابتدا وارد حساب کاربری شوید.
                </p>

                <details class="mt-5 w-full text-start">
                    <summary class="cursor-pointer text-xs text-ink-400">وارد کردن دستی کد</summary>
                    <div class="mt-3 flex gap-2">
                        <input type="text" class="field text-xs" placeholder="کد نمایش‌داده‌شده در اتوبوس"
                               x-model="manualToken">
                        <button type="button" class="btn btn-primary btn-sm shrink-0"
                                @click="board(manualToken)" :disabled="busy || !manualToken">تأیید</button>
                    </div>
                </details>
            </div>
        </template>
    </section>

    {{-- ─────────────────────────────── WALLET ────────────────────────── --}}
    <section x-show="tab === 'wallet'" class="flex flex-1 flex-col gap-3 px-3 pt-3" x-cloak>
        <template x-if="signedIn">
            <div class="flex flex-col gap-3">
                <div class="glass-strong card">
                    <div class="flex items-center justify-between">
                        <span class="stat-label">موجودی کیف پول</span>
                        <span class="badge" :class="wallet.can_spend ? 'badge-success' : 'badge-danger'"
                              x-text="wallet.status_label || '—'"></span>
                    </div>
                    <p class="mt-3 text-3xl font-bold" x-text="$money(wallet.balance)">—</p>

                    <div class="mt-5 flex gap-2">
                        <template x-for="amount in [200000, 500000, 1000000]" :key="amount">
                            <button type="button" class="btn btn-ghost btn-sm flex-1"
                                    @click="topup(amount)" :disabled="busy"
                                    x-text="$money(amount, { withSuffix: false })"></button>
                        </template>
                    </div>
                    <button type="button" class="btn btn-primary mt-2 w-full" @click="topupCustom()" :disabled="busy">
                        شارژ کیف پول
                    </button>
                </div>

                <div class="glass card">
                    <h2 class="text-sm font-bold">تراکنش‌های اخیر</h2>
                    <div class="mt-3 flex flex-col gap-1.5">
                        <template x-for="entry in transactions" :key="entry.id">
                            <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg text-xs"
                                      :class="entry.direction === 'credit' ? 'bg-brand-500/15 text-brand-300' : 'bg-white/5 text-ink-300'"
                                      x-text="entry.direction === 'credit' ? '+' : '−'"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium"
                                       x-text="entry.transaction?.type_label || entry.description || '—'"></p>
                                    <p class="text-[10px] text-ink-500" x-text="$time(entry.created_at)"></p>
                                </div>
                                <p class="shrink-0 text-xs font-semibold"
                                   :class="entry.direction === 'credit' ? 'text-brand-300' : 'text-ink-200'"
                                   x-text="$money(entry.amount)"></p>
                            </div>
                        </template>
                        <p x-show="!transactions.length" class="py-6 text-center text-sm text-ink-500">
                            هنوز تراکنشی ثبت نشده است.
                        </p>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="!signedIn">
            <div class="glass card text-center">
                <p class="text-sm text-ink-300">برای استفاده از کیف پول وارد شوید.</p>
                <button type="button" class="btn btn-primary mt-4 w-full" @click="tab = 'account'">ورود با شماره موبایل</button>
            </div>
        </template>
    </section>

    {{-- ─────────────────────────────── TRIPS ─────────────────────────── --}}
    <section x-show="tab === 'trips'" class="flex flex-1 flex-col gap-3 px-3 pt-3" x-cloak>
        <div class="glass card">
            <h2 class="text-sm font-bold">تاریخچه سفرها</h2>
            <div class="mt-3 flex flex-col gap-1.5">
                <template x-for="ride in rides" :key="ride.uuid">
                    <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-[11px] font-bold"
                              x-text="ride.line?.code || '—'"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium" x-text="ride.line?.name || 'سفر'"></p>
                            <p class="text-[10px] text-ink-500" x-text="$time(ride.boarded_at)"></p>
                        </div>
                        <p class="shrink-0 text-xs font-semibold" x-text="ride.fare?.formatted"></p>
                    </div>
                </template>
                <p x-show="!rides.length" class="py-6 text-center text-sm text-ink-500">
                    هنوز سفری ثبت نشده است.
                </p>
            </div>
        </div>

        <div class="glass card">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold">شکایات و پیگیری</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="showComplaintForm = !showComplaintForm"
                        :disabled="!signedIn">ثبت شکایت</button>
            </div>

            <form x-show="showComplaintForm" x-cloak class="mt-4 flex flex-col gap-3" @submit.prevent="submitComplaint()">
                <div>
                    <label class="field-label">دسته‌بندی</label>
                    <select class="field" x-model="complaint.category">
                        <template x-for="option in complaintCategories" :key="option.value">
                            <option :value="option.value" x-text="option.label"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="field-label">موضوع</label>
                    <input type="text" class="field" x-model="complaint.subject" required maxlength="150">
                </div>
                <div>
                    <label class="field-label">شرح</label>
                    <textarea class="field" rows="4" x-model="complaint.body" required minlength="10"></textarea>
                </div>
                <div>
                    <label class="field-label">تصویر (اختیاری)</label>
                    <input type="file" class="field !py-2 text-xs" accept="image/*" multiple
                           @change="complaint.files = Array.from($event.target.files).slice(0, 5)">
                </div>
                <button type="submit" class="btn btn-primary" :disabled="busy">ارسال</button>
            </form>

            <div class="mt-3 flex flex-col gap-1.5">
                <template x-for="item in complaints" :key="item.uuid">
                    <button type="button" class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5 text-start"
                            @click="openComplaint(item)">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium" x-text="item.subject"></p>
                            <p class="text-[10px] text-ink-500" x-text="item.reference"></p>
                        </div>
                        <span class="badge shrink-0" :class="`badge-${item.status_color}`" x-text="item.status_label"></span>
                    </button>
                </template>
                <p x-show="!complaints.length" class="py-4 text-center text-xs text-ink-500">شکایتی ثبت نشده است.</p>
            </div>
        </div>
    </section>

    {{-- ────────────────────────────── ACCOUNT ────────────────────────── --}}
    <section x-show="tab === 'account'" class="flex flex-1 flex-col gap-3 px-3 pt-3" x-cloak>
        <template x-if="!signedIn">
            <div class="glass card">
                <h2 class="text-base font-bold">ورود به حساب</h2>

                <form x-show="otpStage === 'mobile'" class="mt-5 flex flex-col gap-3" @submit.prevent="requestOtp()">
                    <div>
                        <label class="field-label">شماره موبایل</label>
                        <input type="tel" class="field text-center tracking-widest" inputmode="numeric"
                               placeholder="۰۹۱۲۳۴۵۶۷۸۹" x-model="login.mobile" required>
                    </div>
                    <button type="submit" class="btn btn-primary" :disabled="busy">دریافت کد تأیید</button>
                </form>

                <form x-show="otpStage === 'code'" x-cloak class="mt-5 flex flex-col gap-3" @submit.prevent="verifyOtp()">
                    <p class="text-xs text-ink-400">
                        کد ارسال‌شده به <span x-text="login.mobile"></span> را وارد کنید.
                    </p>
                    <input type="text" class="field text-center text-2xl tracking-[0.6em]" inputmode="numeric"
                           maxlength="5" x-model="login.code" required>
                    <button type="submit" class="btn btn-primary" :disabled="busy">ورود</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="otpStage = 'mobile'">تغییر شماره</button>
                    <p x-show="login.debugCode" class="rounded-xl bg-amber-500/10 p-3 text-center text-xs text-amber-300">
                        کد آزمایشی: <span class="font-bold" x-text="login.debugCode"></span>
                    </p>
                </form>
            </div>
        </template>

        <template x-if="signedIn">
            <div class="glass card">
                <h2 class="text-base font-bold" x-text="user.name || 'حساب کاربری'"></h2>
                <p class="mt-1 text-xs text-ink-400" x-text="user.mobile"></p>

                <dl class="mt-5 flex flex-col gap-2 text-xs">
                    <div class="flex justify-between rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <dt class="text-ink-400">شهر</dt><dd x-text="user.city?.name || '—'"></dd>
                    </div>
                    <div class="flex justify-between rounded-xl bg-white/[0.03] px-3 py-2.5">
                        <dt class="text-ink-400">موجودی</dt><dd x-text="$money(wallet.balance)"></dd>
                    </div>
                </dl>

                <button type="button" class="btn btn-ghost mt-5 w-full" @click="signOut()">خروج از حساب</button>
            </div>
        </template>

        <div class="glass card">
            <h3 class="text-sm font-bold">درباره داده‌ها</h3>
            <p class="mt-2 text-xs leading-6 text-ink-400">
                خطوط و ایستگاه‌های نمایش‌داده‌شده در این نسخه «داده نمونه» هستند و مرجع رسمی
                اتوبوس‌رانی محسوب نمی‌شوند. پس از دریافت داده رسمی، این برچسب برداشته می‌شود.
            </p>
        </div>
    </section>
</div>
@endsection

@section('tabbar')
<nav class="tabbar" x-data style="grid-template-columns:repeat(5,1fr)">
    <div class="glass-strong col-span-5 grid grid-cols-5 rounded-2xl p-1"
         x-data="{ get current() { return Alpine.raw(document.querySelector('[x-data^=passengerApp]')?._x_dataStack?.[0]?.tab) } }">
        @foreach ([
            ['map', 'نقشه', 'M12 2a7 7 0 0 1 7 7c0 5.25-7 13-7 13S5 14.25 5 9a7 7 0 0 1 7-7Zm0 4.5A2.5 2.5 0 1 0 12 11a2.5 2.5 0 0 0 0-4.5Z'],
            ['scan', 'اسکن', 'M3 3h8v8H3V3Zm2 2v4h4V5H5Zm8-2h8v8h-8V3Zm2 2v4h4V5h-4ZM3 13h8v8H3v-8Zm2 2v4h4v-4H5Zm8 0h8v8h-8v-8Zm2 2v4h4v-4h-4Z'],
            ['wallet', 'کیف پول', 'M3 7a3 3 0 0 1 3-3h11a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H6a3 3 0 0 1-3-3V7Zm14 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'],
            ['trips', 'سفرها', 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h10v2H4v-2Z'],
            ['account', 'حساب', 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5Z'],
        ] as [$key, $label, $path])
            <button type="button" class="tabbar-item"
                    :class="$root.__x?.$data?.tab === '{{ $key }}' && 'is-active'"
                    @click="$dispatch('set-tab', '{{ $key }}')">
                <svg viewBox="0 0 24 24" class="size-5 fill-current"><path d="{{ $path }}"/></svg>
                {{ $label }}
            </button>
        @endforeach
    </div>
</nav>
@endsection

@push('scripts')
    @vite('resources/js/passenger.js')
@endpush
