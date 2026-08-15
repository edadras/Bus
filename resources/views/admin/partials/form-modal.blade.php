<div x-show="form.open" x-cloak
     class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/60 p-4"
     @click.self="closeForm()" @keydown.escape.window="closeForm()">
    <div class="glass-strong card my-auto w-full max-w-2xl">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-bold" x-text="form.title"></h3>
                <p x-show="form.hint" class="mt-1 text-[11px] leading-5 text-ink-400" x-text="form.hint"></p>
            </div>
            <button type="button" class="text-ink-400 hover:text-ink-100" @click="closeForm()">✕</button>
        </div>

        <form class="mt-5 grid gap-3 sm:grid-cols-2" @submit.prevent="submitForm()">
            <template x-for="field in form.fields" :key="field.name">
                <div :class="field.wide && 'sm:col-span-2'">
                    <label class="field-label" :for="`f-${field.name}`">
                        <span x-text="field.label"></span>
                        <span x-show="field.required" class="text-danger">*</span>
                    </label>

                    {{-- select --}}
                    <template x-if="field.type === 'select'">
                        <select class="field text-sm" :id="`f-${field.name}`"
                                x-model="form.data[field.name]" :required="field.required">
                            <option value="" x-show="!field.required">—</option>
                            <template x-for="option in field.options" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </template>

                    {{-- checkbox --}}
                    <template x-if="field.type === 'checkbox'">
                        <label class="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2.5 text-sm">
                            <input type="checkbox" x-model="form.data[field.name]">
                            <span x-text="field.hint || field.label"></span>
                        </label>
                    </template>

                    {{-- textarea --}}
                    <template x-if="field.type === 'textarea'">
                        <textarea class="field text-sm" :id="`f-${field.name}`" rows="3"
                                  x-model="form.data[field.name]" :required="field.required"></textarea>
                    </template>

                    {{-- everything else --}}
                    <template x-if="!['select','checkbox','textarea'].includes(field.type)">
                        <input class="field text-sm" :id="`f-${field.name}`"
                               :type="field.type || 'text'"
                               :inputmode="field.type === 'number' ? 'numeric' : null"
                               :step="field.step || null"
                               :placeholder="field.placeholder || ''"
                               x-model="form.data[field.name]" :required="field.required">
                    </template>

                    {{-- server-side validation, per field --}}
                    <p x-show="form.errors[field.name]" class="field-error"
                       x-text="form.errors[field.name]?.[0] || form.errors[field.name]"></p>
                    <p x-show="field.help && !form.errors[field.name]"
                       class="mt-1 text-[11px] text-ink-500" x-text="field.help"></p>
                </div>
            </template>

            <p x-show="form.error" class="field-error sm:col-span-2" x-text="form.error"></p>

            <div class="mt-2 flex gap-2 sm:col-span-2">
                <button type="submit" class="btn btn-primary flex-1" :disabled="form.busy">
                    <span x-show="!form.busy" x-text="form.submitLabel || $t('admin.common.save')"></span>
                    <span x-show="form.busy" x-cloak>{{ __('admin.common.saving') }}</span>
                </button>
                <button type="button" class="btn btn-ghost" @click="closeForm()">{{ __('admin.common.cancel') }}</button>
            </div>
        </form>
    </div>
</div>
