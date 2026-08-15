<section x-show="view === 'complaints'" x-cloak class="grid gap-3 lg:grid-cols-[380px_1fr]">
    <div class="glass card flex flex-col">
        <div class="flex flex-wrap items-center gap-2">
            <select class="field !py-2 text-xs" x-model="filters.complaints.status" @change="loadComplaints()">
                <option value="">همه</option>
                <option value="new">جدید</option>
                <option value="reviewing">در حال بررسی</option>
                <option value="in_progress">در حال رسیدگی</option>
                <option value="resolved">حل‌شده</option>
            </select>
            <label class="flex items-center gap-1.5 text-xs text-ink-400">
                <input type="checkbox" x-model="filters.complaints.mine" @change="loadComplaints()">
                فقط موارد من
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
            <p x-show="!complaints.length" class="py-10 text-center text-sm text-ink-500">شکایتی یافت نشد.</p>
        </div>
    </div>

    <div class="glass card">
        <template x-if="!selectedComplaint">
            <p class="py-24 text-center text-sm text-ink-500">برای مشاهده جزئیات، یک شکایت را انتخاب کنید.</p>
        </template>

        <template x-if="selectedComplaint">
            <div class="flex flex-col gap-4">
                <div>
                    <div class="flex items-start gap-3">
                        <h2 class="flex-1 text-base font-bold" x-text="selectedComplaint.complaint?.subject"></h2>
                        <select class="field max-w-[10rem] !py-1.5 text-xs"
                                :value="selectedComplaint.complaint?.status"
                                @change="changeComplaintStatus($event.target.value)">
                            <option value="new">جدید</option>
                            <option value="reviewing">در حال بررسی</option>
                            <option value="in_progress">در حال رسیدگی</option>
                            <option value="resolved">حل‌شده</option>
                            <option value="closed">بسته‌شده</option>
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
                        'اتوبوس': selectedComplaint.context?.bus_number,
                        'راننده': selectedComplaint.context?.driver_name,
                        'خط': selectedComplaint.context?.line,
                        'زمان پاسخ': selectedComplaint.sla?.first_response_minutes,
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
                                <span x-text="message.author_name || (message.author_type === 'system' ? 'سیستم' : '')"></span>
                                <span x-show="message.is_internal" class="text-amber-400"> — یادداشت داخلی</span>
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-7" x-text="message.body"></p>
                            <p class="mt-1 text-[10px] text-ink-600" x-text="$time(message.created_at)"></p>
                        </div>
                    </template>
                </div>

                <form class="flex flex-col gap-2" @submit.prevent="replyToComplaint()">
                    <textarea class="field text-sm" rows="3" placeholder="پاسخ خود را بنویسید…"
                              x-model="reply.body" required></textarea>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 text-xs text-ink-400">
                            <input type="checkbox" x-model="reply.internal">
                            یادداشت داخلی (برای مسافر نمایش داده نمی‌شود)
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm ms-auto" :disabled="busy">ارسال پاسخ</button>
                    </div>
                </form>
            </div>
        </template>
    </div>
</section>
