<section x-show="view === 'merchants'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="{{ __('admin.merchants.search_placeholder') }}"
               x-model.debounce.400ms="filters.merchants.q" @input="loadMerchants()">
        <select class="field max-w-[11rem] !py-2 text-sm" x-model="filters.merchants.status" @change="loadMerchants()">
            <option value="">{{ __('admin.common.all_statuses') }}</option>
            <option value="pending_approval">{{ __('enums.merchantstatus.pending_approval') }}</option>
            <option value="active">{{ __('enums.merchantstatus.active') }}</option>
            <option value="suspended">{{ __('enums.merchantstatus.suspended') }}</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openMerchantForm()">{{ __('admin.merchants.new_merchant') }}</button>
        <span class="text-xs text-ink-400" x-text="$t('admin.merchants.count', { count: $num(merchants.length) })"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>{{ __('admin.common.name') }}</th><th>{{ __('admin.common.code') }}</th><th>{{ __('admin.merchants.type') }}</th><th>{{ __('admin.merchants.commission') }}</th><th>{{ __('admin.merchants.terminals') }}</th><th>{{ __('admin.merchants.transactions') }}</th><th>{{ __('admin.common.status') }}</th><th>{{ __('admin.common.actions') }}</th></tr>
                </thead>
                <tbody>
                    <template x-for="merchant in merchants" :key="merchant.uuid">
                        <tr>
                            <td class="text-sm font-semibold" x-text="merchant.name"></td>
                            <td class="font-mono text-[11px] text-ink-400" dir="ltr" x-text="merchant.code"></td>
                            <td class="text-xs" x-text="merchant.type"></td>
                            <td class="text-xs" x-text="$t('admin.common.percent', { value: $num((merchant.commission_bps / 100).toFixed(2)) })"></td>
                            <td class="text-xs" x-text="$num(merchant.terminals_count)"></td>
                            <td class="text-xs" x-text="$num(merchant.transactions_count)"></td>
                            <td><span class="badge badge-info" x-text="merchant.status"></span></td>
                            <td>
                                <button type="button" x-show="merchant.status === 'pending_approval'"
                                        class="btn btn-primary btn-sm"
                                        @click="changeMerchantStatus(merchant, 'active')">{{ __('admin.common.approve') }}</button>
                                <button type="button" x-show="merchant.status === 'active'"
                                        class="btn btn-ghost btn-sm"
                                        @click="changeMerchantStatus(merchant, 'suspended')">{{ __('admin.common.suspend') }}</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!merchants.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.merchants.empty') }}</p>
    </div>
</section>
