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
                            <td class="text-xs" x-text="$t(`enums.merchanttype.${merchant.type}`)"></td>
                            <td class="text-xs" x-text="$t('admin.common.percent', { value: $num((merchant.commission_bps / 100).toFixed(2)) })"></td>
                            <td class="text-xs" x-text="$num(merchant.terminals_count)"></td>
                            <td class="text-xs" x-text="$num(merchant.transactions_count)"></td>
                            <td>
                                <span class="badge"
                                      :class="{ 'badge-success': merchant.status === 'active',
                                                'badge-warning': merchant.status === 'pending_approval',
                                                'badge-danger': merchant.status === 'suspended' }"
                                      x-text="$t(`enums.merchantstatus.${merchant.status}`)"></span>
                            </td>
                            <td class="flex gap-1">
                                <button type="button" class="btn btn-ghost btn-sm"
                                        @click="openMerchant(merchant)">{{ __('admin.merchants.detail') }}</button>
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

{{-- The merchant dossier: the wallet that gets settled, the tills that collect
     into it, and the people allowed to operate them. --}}
<div x-show="merchantModal" x-cloak
     class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4"
     @keydown.escape.window="closeMerchant()">
    <div class="glass-strong card my-auto w-full max-w-3xl" @click.outside="closeMerchant()">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-bold" x-text="merchantModal?.merchant?.name"></h3>
                <p class="mt-0.5 font-mono text-[11px] text-ink-500" dir="ltr"
                   x-text="merchantModal?.merchant?.code"></p>
            </div>
            <span class="badge badge-neutral" x-text="$t(`enums.merchanttype.${merchantModal?.merchant?.type}`)"></span>
            <span class="badge"
                  :class="{ 'badge-success': merchantModal?.merchant?.status === 'active',
                            'badge-warning': merchantModal?.merchant?.status === 'pending_approval',
                            'badge-danger': merchantModal?.merchant?.status === 'suspended' }"
                  x-text="$t(`enums.merchantstatus.${merchantModal?.merchant?.status}`)"></span>
            <button type="button" class="text-ink-400 hover:text-ink-100" @click="closeMerchant()">✕</button>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.finance.balance') }}</p>
                <p class="mt-1 text-sm font-semibold text-brand-300" x-text="$money(merchantModal?.wallet?.balance)"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.merchants.commission') }}</p>
                <p class="mt-1 text-sm"
                   x-text="$t('admin.common.percent', { value: $num(((merchantModal?.merchant?.commission_bps ?? 0) / 100).toFixed(2)) })"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.merchants.settlement_cycle') }}</p>
                <p class="mt-1 text-sm" x-text="$t(`admin.forms.merchant.cycles.${merchantModal?.merchant?.settlement_cycle}`)"></p>
            </div>
            <div class="rounded-xl bg-white/[0.03] px-3 py-2.5">
                <p class="text-[10px] text-ink-500">{{ __('admin.merchants.owner') }}</p>
                <p class="mt-1 truncate text-sm" x-text="merchantModal?.merchant?.owner?.name || '—'"></p>
            </div>
        </div>

        {{-- Enough of the account to recognise which one it is, and never the
             whole number: a settlement cannot be paid without one, so its
             absence is what actually needs surfacing here. --}}
        <p x-show="merchantModal?.bank?.has_iban" x-cloak class="mt-3 text-[11px] text-ink-400">
            <span>{{ __('admin.forms.merchant.iban') }}:</span>
            <span class="font-mono" dir="ltr"
                  x-text="$t('admin.merchants.iban_tail', { digits: merchantModal?.bank?.iban_last4 })"></span>
            <span class="ms-2" x-text="merchantModal?.bank?.account_holder"></span>
        </p>
        <p x-show="merchantModal && !merchantModal?.bank?.has_iban" x-cloak
           class="mt-3 rounded-xl bg-warning/10 px-3 py-2 text-[11px] text-warning">
            {{ __('admin.merchants.no_iban_notice') }}
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-bold">{{ __('admin.merchants.terminals') }}</h4>
                    <button type="button" class="btn btn-ghost btn-sm" @click="openTerminalForm()">{{ __('admin.merchants.terminal_add') }}</button>
                </div>
                <div class="mt-2 flex flex-col gap-2">
                    <template x-for="terminal in merchantModal?.merchant?.terminals ?? []" :key="terminal.id">
                        <div class="rounded-xl bg-white/[0.03] px-3 py-2">
                            <div class="flex items-center gap-2">
                                <span class="text-sm" x-text="terminal.name"></span>
                                <span class="flex-1"></span>
                                <span class="badge" :class="terminal.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="terminal.is_active ? $t('admin.common.active') : $t('admin.common.inactive')"></span>
                            </div>
                            <p class="mt-0.5 font-mono text-[10px] text-ink-500" dir="ltr" x-text="terminal.public_id"></p>
                            <p x-show="terminal.location_label" class="text-[10px] text-ink-500" x-text="terminal.location_label"></p>
                        </div>
                    </template>
                    <p x-show="!(merchantModal?.merchant?.terminals ?? []).length" class="py-3 text-center text-sm text-ink-500">
                        {{ __('admin.merchants.terminals_empty') }}
                    </p>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-bold">{{ __('admin.merchants.staff') }}</h4>
                    <button type="button" class="btn btn-ghost btn-sm" @click="openStaffForm()">{{ __('admin.merchants.staff_add') }}</button>
                </div>
                <div class="mt-2 flex flex-col gap-2">
                    <template x-for="person in merchantModal?.staff ?? []" :key="person.id">
                        <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2">
                            <span class="text-sm" x-text="person.name"></span>
                            <span class="font-mono text-[10px] text-ink-500" dir="ltr" x-text="person.mobile"></span>
                            <span class="flex-1"></span>
                            <span class="badge badge-neutral" x-text="$t(`admin.merchants.roles.${person.role}`)"></span>
                            <span x-show="person.can_refund" class="badge badge-info">{{ __('admin.merchants.can_refund') }}</span>
                        </div>
                    </template>
                    <p x-show="!(merchantModal?.staff ?? []).length" class="py-3 text-center text-sm text-ink-500">
                        {{ __('admin.merchants.staff_empty') }}
                    </p>
                </div>
                <p class="mt-2 text-[10px] leading-5 text-ink-500">{{ __('admin.merchants.staff_privacy_note') }}</p>
            </div>
        </div>
    </div>
</div>
