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

    {{-- Transactions. The ledger is the record; this is the window onto it,
         and the only write it offers is a reversal — never an edit. --}}
    <div class="glass card">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-bold">{{ __('admin.finance.transactions') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                <select class="field w-auto !py-2 text-xs" x-model="filters.transactions.type"
                        @change="applyTransactionFilters()">
                    <option value="">{{ __('admin.finance.all_types') }}</option>
                    @foreach (['topup', 'fare_payment', 'merchant_payment', 'refund', 'settlement', 'commission', 'adjustment', 'reversal'] as $type)
                        <option value="{{ $type }}">{{ __('enums.transactiontype.'.$type) }}</option>
                    @endforeach
                </select>
                <select class="field w-auto !py-2 text-xs" x-model="filters.transactions.status"
                        @change="applyTransactionFilters()">
                    <option value="">{{ __('admin.common.all_statuses') }}</option>
                    @foreach (['pending', 'completed', 'failed', 'reversed'] as $status)
                        <option value="{{ $status }}">{{ __('enums.transactionstatus.'.$status) }}</option>
                    @endforeach
                </select>
                <input type="date" class="field w-auto !py-2 text-xs" x-model="filters.transactions.from"
                       @change="applyTransactionFilters()" aria-label="{{ __('admin.finance.settlement_from') }}">
                <input type="date" class="field w-auto !py-2 text-xs" x-model="filters.transactions.to"
                       @change="applyTransactionFilters()" aria-label="{{ __('admin.finance.settlement_to') }}">
            </div>
        </div>

        <div class="table-scroll mt-4">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('admin.finance.transaction_uuid') }}</th>
                        <th>{{ __('admin.common.name') }}</th>
                        <th>{{ __('admin.finance.amount') }}</th>
                        <th>{{ __('admin.common.status') }}</th>
                        <th>{{ __('admin.finance.date') }}</th>
                        <th>{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in transactions" :key="row.uuid">
                        <tr>
                            <td class="font-mono text-[11px] text-ink-400" dir="ltr" x-text="row.uuid?.slice(0, 8)"></td>
                            <td class="text-xs" x-text="$t(`enums.transactiontype.${row.type}`)"></td>
                            <td class="text-sm font-semibold" x-text="$money(row.amount)"></td>
                            <td>
                                <span class="badge"
                                      :class="{ 'badge-success': row.status === 'completed', 'badge-warning': row.status === 'pending',
                                                'badge-danger': row.status === 'failed', 'badge-neutral': row.status === 'reversed' }"
                                      x-text="$t(`enums.transactionstatus.${row.status}`)"></span>
                            </td>
                            <td class="text-[11px] text-ink-400" x-text="$datetime(row.created_at)"></td>
                            <td>
                                {{-- Only a completed posting can be mirrored; anything else has
                                     nothing to reverse, and saying so beats a server error. --}}
                                <button type="button" x-show="row.status === 'completed'"
                                        class="btn btn-ghost btn-sm"
                                        @click="openReversalForm(row)">{{ __('admin.finance.reverse') }}</button>
                                <span x-show="row.status === 'reversed'"
                                      class="text-[11px] text-ink-500">{{ __('admin.finance.already_reversed') }}</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p x-show="!transactions.length" class="py-8 text-center text-sm text-ink-500">{{ __('admin.finance.transactions_empty') }}</p>

        <div x-show="transactionPages.last_page > 1" class="mt-3 flex items-center justify-between text-[11px] text-ink-400">
            <span x-text="$t('admin.finance.transactions_total', { count: $num(transactionPages.total) })"></span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-ghost btn-sm" :disabled="transactionPages.current_page <= 1"
                        @click="goToTransactionPage(transactionPages.current_page - 1)">‹</button>
                <span x-text="$t('admin.finance.page_of', { page: $num(transactionPages.current_page), pages: $num(transactionPages.last_page) })"></span>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="!transactionPages.has_more"
                        @click="goToTransactionPage(transactionPages.current_page + 1)">›</button>
            </div>
        </div>
    </div>

    {{-- Wallet tools. A balance is never written directly: both actions here
         go through the ledger, and both are audited. --}}
    <div class="glass card">
        <h2 class="text-sm font-bold">{{ __('admin.finance.wallet_tools') }}</h2>
        <p class="mt-1 text-[11px] text-ink-400">{{ __('admin.finance.wallet_tools_hint') }}</p>

        <form class="mt-4 flex gap-2" @submit.prevent="searchWalletUsers()">
            <input class="field flex-1 text-sm" x-model="wallet.query" minlength="3"
                   placeholder="{{ __('admin.finance.user_search_placeholder') }}">
            <button type="submit" class="btn btn-primary" :disabled="wallet.searching">{{ __('admin.finance.search') }}</button>
        </form>

        <div x-show="wallet.results.length" class="mt-3 flex flex-col gap-2">
            <template x-for="person in wallet.results" :key="person.uuid">
                <button type="button"
                        class="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2.5 text-start hover:bg-white/[0.06]"
                        :class="wallet.selected?.uuid === person.uuid && 'ring-1 ring-brand-500'"
                        @click="selectWalletUser(person)">
                    <span class="min-w-0">
                        <span class="block truncate text-sm" x-text="person.name"></span>
                        <span class="block font-mono text-[11px] text-ink-500" dir="ltr" x-text="person.mobile"></span>
                    </span>
                    <span class="text-sm font-semibold text-brand-300" x-text="$money(person.balance)"></span>
                </button>
            </template>
            <p x-show="wallet.results.some((person) => person.mobile_is_masked)"
               class="text-[11px] text-ink-500">{{ __('admin.finance.mobile_masked_note') }}</p>
        </div>

        <p x-show="wallet.searched && !wallet.results.length" class="mt-3 text-sm text-ink-500">
            {{ __('admin.finance.user_search_empty') }}
        </p>

        <div x-show="wallet.selected" x-cloak class="mt-4 border-t border-white/5 pt-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-ink-400">{{ __('admin.finance.balance') }}:</span>
                <span class="text-sm font-semibold" x-text="$money(wallet.selected?.balance)"></span>
                <span class="flex-1"></span>
                <button type="button" class="btn btn-primary btn-sm" @click="openAdjustmentForm()">{{ __('admin.finance.adjust') }}</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="auditWallet()">{{ __('admin.finance.audit_wallet') }}</button>
            </div>

            <p x-show="wallet.audit" x-cloak class="mt-3 text-xs"
               :class="wallet.audit?.integrity?.ok ? 'text-brand-300' : 'text-danger'"
               x-text="wallet.audit?.integrity?.ok
                   ? $t('admin.finance.audit_balanced')
                   : $t('admin.finance.audit_mismatch', { difference: $money(wallet.audit?.integrity?.drift) })"></p>
            <p x-show="wallet.audit" x-cloak class="mt-1 text-[11px] text-ink-500"
               x-text="$t('admin.finance.audit_entries', { count: $num(wallet.audit?.entry_count) })"></p>
        </div>
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
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold">{{ __('admin.finance.settlements') }}</h2>
            <button type="button" class="btn btn-primary btn-sm" @click="openSettlementForm()">{{ __('admin.finance.new_settlement') }}</button>
        </div>
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
                            <td>
                                <span class="badge"
                                      :class="{ 'badge-success': item.status === 'paid', 'badge-info': item.status === 'approved',
                                                'badge-warning': ['draft','requested'].includes(item.status),
                                                'badge-danger': item.status === 'rejected' }"
                                      x-text="$t(`enums.settlementstatus.${item.status}`)"></span>
                            </td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" x-show="['draft','requested'].includes(item.status)"
                                            class="btn btn-primary btn-sm"
                                            @click="approveSettlement(item)">{{ __('admin.common.approve') }}</button>
                                    <button type="button" x-show="item.status === 'approved'"
                                            class="btn btn-ghost btn-sm"
                                            @click="paySettlement(item)">{{ __('admin.finance.record_payment') }}</button>
                                    <button type="button" x-show="['draft','requested'].includes(item.status)"
                                            class="btn btn-ghost btn-sm text-danger"
                                            @click="rejectSettlement(item)">{{ __('admin.finance.reject') }}</button>
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
