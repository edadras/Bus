<section x-show="view === 'drivers'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="نام، کد ملی یا شماره موبایل"
               x-model.debounce.400ms="filters.drivers.q" @input="loadDrivers()">
        <select class="field max-w-[11rem] !py-2 text-sm" x-model="filters.drivers.status" @change="loadDrivers()">
            <option value="">همه وضعیت‌ها</option>
            <option value="pending_approval">در انتظار تأیید</option>
            <option value="active">فعال</option>
            <option value="suspended">تعلیق‌شده</option>
            <option value="inactive">غیرفعال</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm ms-auto" @click="openDriverForm()">+ راننده جدید</button>
        <span class="text-xs text-ink-400" x-text="`${$num(drivers.length)} راننده`"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>نام</th><th>کد پرسنلی</th><th>گواهینامه</th><th>وضعیت</th>
                        <th>سفرها</th><th>ساعات فعالیت</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="driver in drivers" :key="driver.uuid">
                        <tr>
                            <td>
                                <p class="font-semibold" x-text="driver.name"></p>
                                <p class="text-[11px] text-ink-500" x-text="$num(driver.mobile)"></p>
                            </td>
                            <td class="text-xs" x-text="driver.employee_code || '—'"></td>
                            <td class="text-xs">
                                <span x-text="$num(driver.license_number)"></span>
                                <span x-show="driver.license_expires_at" class="block text-[10px] text-ink-500"
                                      x-text="`انقضا: ${$num(driver.license_expires_at)}`"></span>
                            </td>
                            <td>
                                <span class="badge" :class="`badge-${driver.status_color}`"
                                      x-text="driver.status_label"></span>
                            </td>
                            <td class="text-xs" x-text="$num(driver.total_trips)"></td>
                            <td class="text-xs" x-text="`${$num(Math.round(driver.total_shift_minutes / 60))} ساعت`"></td>
                            <td>
                                <div class="flex gap-1">
                                    <button type="button" x-show="driver.status === 'pending_approval'"
                                            class="btn btn-primary btn-sm"
                                            @click="changeDriverStatus(driver, 'active')">تأیید</button>
                                    <button type="button" x-show="driver.status === 'active'"
                                            class="btn btn-ghost btn-sm"
                                            @click="changeDriverStatus(driver, 'suspended')">تعلیق</button>
                                    <button type="button" x-show="driver.status === 'suspended'"
                                            class="btn btn-ghost btn-sm"
                                            @click="changeDriverStatus(driver, 'active')">رفع تعلیق</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!drivers.length" class="py-10 text-center text-sm text-ink-500">راننده‌ای ثبت نشده است.</p>
    </div>

    <p class="px-1 text-[11px] leading-6 text-ink-500">
        تعلیق راننده، نشست‌های فعال او را نیز باطل می‌کند؛ توکن در دست راننده بلافاصله از کار می‌افتد.
    </p>
</section>
