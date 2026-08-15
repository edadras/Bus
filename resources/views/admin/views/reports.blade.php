<section x-show="view === 'reports'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <div class="flex gap-1">
            <template x-for="tab in reportTabs" :key="tab.id">
                <button type="button" class="rounded-full px-3.5 py-1.5 text-xs transition"
                        :class="reportTab === tab.id
                            ? 'bg-brand-500/20 text-brand-300 font-semibold'
                            : 'text-ink-400 hover:bg-white/5'"
                        @click="reportTab = tab.id; loadReport()"
                        x-text="tab.label"></button>
            </template>
        </div>
        <div class="ms-auto flex items-center gap-2">
            <input type="date" class="field !w-auto !py-1.5 text-xs" x-model="reportRange.from" @change="loadReport()">
            <span class="text-xs text-ink-500">تا</span>
            <input type="date" class="field !w-auto !py-1.5 text-xs" x-model="reportRange.to" @change="loadReport()">
        </div>
    </div>

    {{-- ── حمل‌ونقل ─────────────────────────────────────────────────── --}}
    <template x-if="reportTab === 'transport' && report">
        <div class="flex flex-col gap-3">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <template x-for="card in transportCards" :key="card.label">
                    <article class="glass card">
                        <p class="stat-value !text-2xl" x-text="card.value"></p>
                        <p class="stat-label mt-1" x-text="card.label"></p>
                    </article>
                </template>
            </div>

            <article class="glass card">
                <h2 class="text-sm font-bold">وقت‌شناسی</h2>
                <p class="mt-2 text-[11px] leading-5 text-ink-500">
                    سفری که بیش از ۲۰٪ طولانی‌تر از زمان اسمی خطش طول کشیده، «با تأخیر» شمرده می‌شود.
                    خطوطی که زمان اسمی ندارند در این محاسبه وارد نمی‌شوند.
                </p>
                <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                        <p class="text-lg font-bold text-brand-300"
                           x-text="report.punctuality.on_time_rate === null
                                ? '—'
                                : `${$num(Math.round(report.punctuality.on_time_rate * 100))}٪`"></p>
                        <p class="stat-label mt-1">به‌موقع</p>
                    </div>
                    <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                        <p class="text-lg font-bold" x-text="$num(report.punctuality.late_trips)"></p>
                        <p class="stat-label mt-1">سفر با تأخیر</p>
                    </div>
                    <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                        <p class="text-lg font-bold"
                           x-text="report.punctuality.avg_delay_minutes === null
                                ? '—'
                                : `${$num(report.punctuality.avg_delay_minutes)} دقیقه`"></p>
                        <p class="stat-label mt-1">متوسط تأخیر</p>
                    </div>
                    <div class="rounded-xl bg-white/[0.03] px-3 py-3">
                        <p class="text-lg font-bold" x-text="$num(report.punctuality.measured_trips)"></p>
                        <p class="stat-label mt-1">سفر قابل سنجش</p>
                    </div>
                </div>
            </article>
        </div>
    </template>

    {{-- ── رانندگان ─────────────────────────────────────────────────── --}}
    <template x-if="reportTab === 'drivers' && report">
        <div class="glass card !p-0">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>راننده</th><th>شیفت</th><th>ساعات فعالیت</th><th>سفر</th>
                            <th>مسافر</th><th>مسافر در ساعت</th><th>درآمد</th><th>شکایت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in report" :key="row.uuid">
                            <tr>
                                <td>
                                    <p class="font-semibold" x-text="row.name"></p>
                                    <p class="text-[11px] text-ink-500" x-text="row.employee_code || '—'"></p>
                                </td>
                                <td class="text-xs" x-text="$num(row.shift_count)"></td>
                                <td class="text-xs" x-text="`${$num(Math.round(row.active_minutes / 60))} ساعت`"></td>
                                <td class="text-xs" x-text="$num(row.trips)"></td>
                                <td class="text-xs" x-text="$num(row.passengers)"></td>
                                <td class="text-xs font-semibold text-brand-300"
                                    x-text="row.passengers_per_hour === null ? '—' : $num(row.passengers_per_hour)"></td>
                                <td class="text-xs" x-text="$money(row.revenue)"></td>
                                <td>
                                    <span class="badge" :class="row.complaints > 0 ? 'badge-warning' : 'badge-neutral'"
                                          x-text="$num(row.complaints)"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="!report.length" class="py-10 text-center text-sm text-ink-500">
                در این بازه شیفتی ثبت نشده است.
            </p>
        </div>
    </template>

    {{-- ── مسافران ──────────────────────────────────────────────────── --}}
    <template x-if="reportTab === 'passengers' && report">
        <div class="flex flex-col gap-3">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <template x-for="card in passengerCards" :key="card.label">
                    <article class="glass card">
                        <p class="stat-value !text-2xl" x-text="card.value"></p>
                        <p class="stat-label mt-1" x-text="card.label"></p>
                    </article>
                </template>
            </div>

            <div class="grid gap-3 lg:grid-cols-3">
                <article class="glass card lg:col-span-2">
                    <h2 class="text-sm font-bold">توزیع سفر در ساعات شبانه‌روز</h2>
                    <div class="mt-4 h-56"><canvas id="chart-by-hour"></canvas></div>
                </article>

                <article class="glass card">
                    <h2 class="text-sm font-bold">الگوی استفاده</h2>
                    <p class="mt-1.5 text-[11px] leading-5 text-ink-500">
                        تعداد کاربران بر اساس دفعات سفر — بدون نمایش هویت هیچ فردی.
                    </p>
                    <div class="mt-4 flex flex-col gap-2">
                        <template x-for="row in frequencyRows" :key="row.label">
                            <div class="flex items-center gap-3 rounded-xl bg-white/[0.03] px-3 py-2.5">
                                <span class="text-xs" x-text="row.label"></span>
                                <span class="ms-auto text-sm font-semibold text-brand-300" x-text="$num(row.value)"></span>
                            </div>
                        </template>
                    </div>
                </article>
            </div>
        </div>
    </template>

    {{-- ── مالی ─────────────────────────────────────────────────────── --}}
    <template x-if="reportTab === 'revenue' && report">
        <div class="grid gap-3 lg:grid-cols-2">
            <article class="glass card !p-0">
                <h2 class="px-4 pt-4 text-sm font-bold">درآمد هر خط</h2>
                <div class="table-scroll mt-3">
                    <table class="table">
                        <thead><tr><th>خط</th><th>سفر</th><th>درآمد</th></tr></thead>
                        <tbody>
                            <template x-for="row in report.by_line" :key="row.id">
                                <tr>
                                    <td>
                                        <span class="inline-flex items-center gap-2">
                                            <span class="size-2 rounded-full" :style="`background:${row.color}`"></span>
                                            <span class="font-semibold" x-text="$num(row.code)"></span>
                                            <span class="text-xs text-ink-400" x-text="row.name"></span>
                                        </span>
                                    </td>
                                    <td class="text-xs" x-text="$num(row.rides)"></td>
                                    <td class="text-xs font-semibold text-brand-300" x-text="$money(row.revenue)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!report.by_line?.length" class="py-8 text-center text-sm text-ink-500">داده‌ای نیست.</p>
            </article>

            <article class="glass card !p-0">
                <h2 class="px-4 pt-4 text-sm font-bold">درآمد هر اتوبوس</h2>
                <div class="table-scroll mt-3">
                    <table class="table">
                        <thead><tr><th>اتوبوس</th><th>سفر</th><th>درآمد</th></tr></thead>
                        <tbody>
                            <template x-for="row in report.by_bus" :key="row.id">
                                <tr>
                                    <td class="font-semibold" x-text="$num(row.bus_number)"></td>
                                    <td class="text-xs" x-text="$num(row.rides)"></td>
                                    <td class="text-xs font-semibold text-brand-300" x-text="$money(row.revenue)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p x-show="!report.by_bus?.length" class="py-8 text-center text-sm text-ink-500">داده‌ای نیست.</p>
            </article>
        </div>
    </template>

    <p x-show="!report" class="glass card py-12 text-center text-sm text-ink-500">در حال بارگذاری گزارش…</p>
</section>
