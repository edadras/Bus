<section x-show="view === 'complaints'" x-cloak class="grid gap-3 lg:grid-cols-[380px_1fr]">
    <div class="glass card flex flex-col">
        <div class="flex flex-wrap items-center gap-2">
            <select class="field !py-2 text-xs" x-model="filters.complaints.status" @change="loadComplaints()">
                <option value="">{{ __('admin.common.all') }}</option>
                <option value="new">{{ __('enums.complaintstatus.new') }}</option>
                <option value="reviewing">{{ __('enums.complaintstatus.reviewing') }}</option>
                <option value="in_progress">{{ __('enums.complaintstatus.in_progress') }}</option>
                <option value="resolved">{{ __('enums.complaintstatus.resolved') }}</option>
            </select>
            <label class="flex items-center gap-1.5 text-xs text-ink-400">
                <input type="checkbox" x-model="filters.complaints.mine" @change="loadComplaints()">
                {{ __('admin.complaints.only_mine') }}
            </label>
        </div>

        <div class="no-scrollbar mt-3 flex max-h-[68vh] flex-col gap-2 overflow-y-auto">
            <template x-for="item in complaints" :key="item.uuid">
                <button type="button" class="rounded-xl px-3 py-2.5 text-start transition"
                        :class="selectedComplaint?.uuid === item.uuid
                            ? 'bg-brand-500/12 ring-1 ring-brand-500/30'
                            : 'bg-white/[0.03] hover:bg-white/[0.06]'"
                        @click="openComplaint(item)">
                    <div class="flex items-start gap-2">
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="item.subject"></span>
                        <span class="badge shrink-0" :class="`badge-${item.status_color}`"
                              x-text="item.status_label"></span>
                    </div>
                    <p class="mt-1 text-[10px] text-ink-500">
                        <span x-text="$num(item.reference)"></span> ·
                        <span x-text="item.category_label"></span>
                    </p>
                </button>
            </template>
            <p x-show="!complaints.length" class="py-10 text-center text-sm text-ink-500">{{ __('admin.complaints.empty') }}</p>
        </div>
    </div>

    <div class="glass card">
        <template x-if="!selectedComplaint">
            <p class="py-24 text-center text-sm text-ink-500">{{ __('admin.complaints.select_prompt') }}</p>
        </template>

        <template x-if="selectedComplaint">
            <div class="flex flex-col gap-4">
                <div>
                    <div class="flex items-start gap-3">
                        <h2 class="flex-1 text-base font-bold" x-text="selectedComplaint.complaint?.subject"></h2>
                        <select class="field max-w-[10rem] !py-1.5 text-xs"
                                :value="selectedComplaint.complaint?.status"
                                @change="changeComplaintStatus($event.target.value)">
                            <option value="new">{{ __('enums.complaintstatus.new') }}</option>
                            <option value="reviewing">{{ __('enums.complaintstatus.reviewing') }}</option>
                            <option value="in_progress">{{ __('enums.complaintstatus.in_progress') }}</option>
                            <option value="resolved">{{ __('enums.complaintstatus.resolved') }}</option>
                            <option value="closed">{{ __('enums.complaintstatus.closed') }}</option>
                        </select>
                    </div>
                    <p class="mt-1.5 text-[11px] text-ink-500">
                        <span x-text="$num(selectedComplaint.complaint?.reference)"></span> ·
                        <span x-text="selectedComplaint.reporter?.name"></span> ·
                        <span x-text="$num(selectedComplaint.reporter?.mobile)"></span>
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <template x-for="[label, value] in Object.entries({
                        [$t('admin.complaints.context_bus')]: selectedComplaint.context?.bus_number,
                        [$t('admin.complaints.context_driver')]: selectedComplaint.context?.driver_name,
                        [$t('admin.complaints.context_line')]: selectedComplaint.context?.line,
                        [$t('admin.complaints.context_response_time')]: selectedComplaint.sla?.first_response_minutes,
                    })" :key="label">
                        <div class="rounded-xl bg-white/[0.03] px-3 py-2">
                            <p class="text-[10px] text-ink-500" x-text="label"></p>
                            <p class="mt-0.5 truncate text-xs" x-text="value ? $num(value) : '—'"></p>
                        </div>
                    </template>
                </div>

                <div class="no-scrollbar flex max-h-[42vh] flex-col gap-2 overflow-y-auto">
                    <template x-for="message in selectedComplaint.thread" :key="message.id">
                        <div class="rounded-xl px-3 py-2.5"
                             :class="{
                                 'bg-brand-500/10 ms-8': message.author_type === 'agent',
                                 'bg-white/[0.04] me-8': message.author_type === 'passenger',
                                 'bg-white/[0.02] text-center': message.author_type === 'system',
                             }">
                            <p class="text-[10px] text-ink-500">
                                <span x-text="message.author_name || (message.author_type === 'system' ? $t('admin.complaints.system_author') : '')"></span>
                                <span x-show="message.is_internal" class="text-amber-400" x-text="$t('admin.complaints.internal_note_tag')"></span>
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-7" x-text="message.body"></p>
                            <p class="mt-1 text-[10px] text-ink-600" x-text="$time(message.created_at)"></p>
                        </div>
                    </template>
                </div>

                <form class="flex flex-col gap-2" @submit.prevent="replyToComplaint()">
                    <textarea class="field text-sm" rows="3" placeholder="{{ __('admin.complaints.reply_placeholder') }}"
                              x-model="reply.body" required></textarea>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 text-xs text-ink-400">
                            <input type="checkbox" x-model="reply.internal">
                            {{ __('admin.complaints.internal_note_label') }}
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm ms-auto" :disabled="busy">{{ __('admin.complaints.send_reply') }}</button>
                    </div>
                </form>
            </div>
        </template>
    </div>
</section>
