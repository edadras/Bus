<section x-show="view === 'finance'" x-cloak class="flex flex-col gap-3">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <template x-for="row in financeSummary" :key="row.type">
            <article class="glass card">
                <p class="stat-label" x-text="row.label"></p>
                <p class="stat-value mt-2 !text-xl" x-text="row.formatted"></p>
                <p class="mt-1 text-[11px] text-ink-500" x-text="`${$num(row.count)} تراکنش`"></p>
            </article>
        </template>
        <p x-show="!financeSummary.length" class="glass card col-span-full py-8 text-center text-sm text-ink-500">
            تراکنشی در این بازه ثبت نشده است.
        </p>
    </div>

    <div class="glass card">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold">قوانین کرایه</h2>
            <span class="text-[11px] text-ink-500">بالاترین اولویت برنده است</span>
        </div>
        <div class="table-scroll mt-4">
            <table class="table">
                <thead>
                    <tr><th>نام</th><th>کد</th><th>نوع مسافر</th><th>کرایه پایه</th><th>ضریب</th><th>اولویت</th><th>وضعیت</th></tr>
                </thead>
                <tbody>
                    <template x-for="rule in fareRules" :key="rule.id">
                        <tr>
                            <td class="text-sm" x-text="rule.name"></td>
                            <td class="font-mono text-[11px] text-ink-400" dir="ltr" x-text="rule.code"></td>
                            <td class="text-xs" x-text="rule.passenger_type || 'همه'"></td>
                            <td class="text-sm font-semibold" x-text="$money(rule.base_fare)"></td>
                            <td class="text-xs" x-text="$num(rule.multiplier)"></td>
                            <td class="text-xs" x-text="$num(rule.priority)"></td>
                            <td>
                                <span class="badge" :class="rule.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="rule.is_active ? 'فعال' : 'غیرفعال'"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!fareRules.length" class="py-8 text-center text-sm text-ink-500">قانون کرایه‌ای تعریف نشده است.</p>
    </div>

    <div class="glass card">
        <h2 class="text-sm font-bold">تسویه پذیرندگان</h2>
        <div class="table-scroll mt-4">
            <table class="table">
                <thead>
                    <tr><th>شماره</th><th>پذیرنده</th><th>دوره</th><th>ناخالص</th><th>کارمزد</th><th>خالص</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <template x-for="item in settlements" :key="item.uuid">
                        <tr>
                            <td class="font-mono text-[11px]" dir="ltr" x-text="item.reference"></td>
                            <td class="text-sm" x-text="item.merchant?.name"></td>
                            <td class="text-[11px] text-ink-400"
                                x-text="`${$num(item.period_start)} تا ${$num(item.period_end)}`"></td>
                            <td class="text-xs" x-text="$money(item.gross_amount)"></td>
                            <td class="text-xs text-ink-400" x-text="$money(item.commission_amount)"></td>
                            <td class="text-sm font-semibold text-brand-300" x-text="$money(item.net_amount)"></td>
                            <td><span class="badge badge-info" x-text="item.status"></span></td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" x-show="['draft','requested'].includes(item.status)"
                                            class="btn btn-primary btn-sm"
                                            @click="approveSettlement(item)">تأیید</button>
                                    <button type="button" x-show="item.status === 'approved'"
                                            class="btn btn-ghost btn-sm"
                                            @click="paySettlement(item)">ثبت پرداخت</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!settlements.length" class="py-8 text-center text-sm text-ink-500">تسویه‌ای ثبت نشده است.</p>
    </div>

    <div class="glass card">
        <h2 class="text-sm font-bold">صحت دفتر مالی</h2>
        <p class="mt-2 text-xs leading-6 text-ink-400">
            دفتر مالی دوطرفه است: مجموع اسناد هر تراکنش و مجموع کل سامانه باید دقیقاً صفر باشد.
            این بررسی هر شب به‌صورت خودکار اجرا می‌شود و از خط فرمان نیز قابل اجراست.
        </p>
        <pre dir="ltr" class="mt-3 overflow-x-auto rounded-xl bg-black/30 p-4 text-[11px] text-ink-300"><code>php artisan transit:ledger:audit</code></pre>
    </div>
</section>
