<section x-show="view === 'finance'" x-cloak class="flex flex-col gap-3">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <template x-for="row in financeSummary" :key="row.type">
            <article class="glass card">
                <p class="stat-label" x-text="row.label"></p>
                <p class="stat-value mt-2 !text-xl" x-text="row.formatted"></p>
                <p class="mt-1 text-[11px] text-ink-500" x-text="$t('admin.finance.transaction_count', { count: $num(row.count) })"></p>
            </article>
        </template>
        <p x-show="!financeSummary.length" class="glass card col-span-full py-8 text-center text-sm text-ink-500">
            {{ __('admin.finance.no_transactions') }}
        </p>
    </div>

    <div class="glass card">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold">{{ __('admin.finance.fare_rules') }}</h2>
            <div class="flex items-center gap-3">
                <span class="text-[11px] text-ink-500">{{ __('admin.finance.highest_priority_wins') }}</span>
                <button type="button" class="btn btn-primary btn-sm" @click="openFareRuleForm()">{{ __('admin.finance.new_rule') }}</button>
            </div>
        </div>
        <div class="table-scroll mt-4">
            <table class="table">
                <thead>
                    <tr><th>{{ __('admin.common.name') }}</th><th>{{ __('admin.common.code') }}</th><th>{{ __('admin.finance.passenger_type') }}</th><th>{{ __('admin.finance.base_fare') }}</th><th>{{ __('admin.finance.multiplier') }}</th><th>{{ __('admin.finance.priority') }}</th><th>{{ __('admin.common.status') }}</th></tr>
                </thead>
                <tbody>
                    <template x-for="rule in fareRules" :key="rule.id">
                        <tr class="cursor-pointer" @click="openFareRuleForm(rule)">
                            <td class="text-sm" x-text="rule.name"></td>
                            <td class="font-mono text-[11px] text-ink-400" dir="ltr" x-text="rule.code"></td>
                            <td class="text-xs" x-text="rule.passenger_type || $t('admin.common.all')"></td>
                            <td class="text-sm font-semibold" x-text="$money(rule.base_fare)"></td>
                            <td class="text-xs" x-text="$num(rule.multiplier)"></td>
                            <td class="text-xs" x-text="$num(rule.priority)"></td>
                            <td>
                                <span class="badge" :class="rule.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="rule.is_active ? $t('admin.common.active') : $t('admin.common.inactive')"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!fareRules.length" class="py-8 text-center text-sm text-ink-500">{{ __('admin.finance.rules_empty') }}</p>
    </div>

    <div class="glass card">
        <h2 class="text-sm font-bold">{{ __('admin.finance.settlements') }}</h2>
        <div class="table-scroll mt-4">
            <table class="table">
                <thead>
                    <tr><th>{{ __('admin.finance.reference') }}</th><th>{{ __('admin.finance.merchant') }}</th><th>{{ __('admin.finance.period') }}</th><th>{{ __('admin.finance.gross') }}</th><th>{{ __('admin.finance.commission') }}</th><th>{{ __('admin.finance.net') }}</th><th>{{ __('admin.common.status') }}</th><th>{{ __('admin.common.actions') }}</th></tr>
                </thead>
                <tbody>
                    <template x-for="item in settlements" :key="item.uuid">
                        <tr>
                            <td class="font-mono text-[11px]" dir="ltr" x-text="item.reference"></td>
                            <td class="text-sm" x-text="item.merchant?.name"></td>
                            <td class="text-[11px] text-ink-400"
                                x-text="$t('admin.finance.period_range', { from: $num(item.period_start), to: $num(item.period_end) })"></td>
                            <td class="text-xs" x-text="$money(item.gross_amount)"></td>
                            <td class="text-xs text-ink-400" x-text="$money(item.commission_amount)"></td>
                            <td class="text-sm font-semibold text-brand-300" x-text="$money(item.net_amount)"></td>
                            <td><span class="badge badge-info" x-text="item.status"></span></td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" x-show="['draft','requested'].includes(item.status)"
                                            class="btn btn-primary btn-sm"
                                            @click="approveSettlement(item)">{{ __('admin.common.approve') }}</button>
                                    <button type="button" x-show="item.status === 'approved'"
                                            class="btn btn-ghost btn-sm"
                                            @click="paySettlement(item)">{{ __('admin.finance.record_payment') }}</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!settlements.length" class="py-8 text-center text-sm text-ink-500">{{ __('admin.finance.settlements_empty') }}</p>
    </div>

    <div class="glass card">
        <h2 class="text-sm font-bold">{{ __('admin.finance.ledger_integrity') }}</h2>
        <p class="mt-2 text-xs leading-6 text-ink-400">
            {{ __('admin.finance.ledger_note_one') }}
            {{ __('admin.finance.ledger_note_two') }}
        </p>
        <pre dir="ltr" class="mt-3 overflow-x-auto rounded-xl bg-black/30 p-4 text-[11px] text-ink-300"><code>php artisan transit:ledger:audit</code></pre>
    </div>
</section>
