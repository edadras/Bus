<section x-show="view === 'merchants'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="نام پذیرنده"
               x-model.debounce.400ms="filters.merchants.q" @input="loadMerchants()">
        <select class="field max-w-[11rem] !py-2 text-sm" x-model="filters.merchants.status" @change="loadMerchants()">
            <option value="">همه وضعیت‌ها</option>
            <option value="pending_approval">در انتظار تأیید</option>
            <option value="active">فعال</option>
            <option value="suspended">تعلیق‌شده</option>
        </select>
        <span class="ms-auto text-xs text-ink-400" x-text="`${$num(merchants.length)} پذیرنده`"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>نام</th><th>کد</th><th>نوع</th><th>کارمزد</th><th>صندوق</th><th>تراکنش</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <template x-for="merchant in merchants" :key="merchant.uuid">
                        <tr>
                            <td class="text-sm font-semibold" x-text="merchant.name"></td>
                            <td class="font-mono text-[11px] text-ink-400" dir="ltr" x-text="merchant.code"></td>
                            <td class="text-xs" x-text="merchant.type"></td>
                            <td class="text-xs" x-text="`${$num((merchant.commission_bps / 100).toFixed(2))}٪`"></td>
                            <td class="text-xs" x-text="$num(merchant.terminals_count)"></td>
                            <td class="text-xs" x-text="$num(merchant.transactions_count)"></td>
                            <td><span class="badge badge-info" x-text="merchant.status"></span></td>
                            <td>
                                <button type="button" x-show="merchant.status === 'pending_approval'"
                                        class="btn btn-primary btn-sm"
                                        @click="changeMerchantStatus(merchant, 'active')">تأیید</button>
                                <button type="button" x-show="merchant.status === 'active'"
                                        class="btn btn-ghost btn-sm"
                                        @click="changeMerchantStatus(merchant, 'suspended')">تعلیق</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!merchants.length" class="py-10 text-center text-sm text-ink-500">پذیرنده‌ای ثبت نشده است.</p>
    </div>
</section>
