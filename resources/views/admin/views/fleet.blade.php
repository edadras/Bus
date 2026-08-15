<section x-show="view === 'fleet'" x-cloak class="flex flex-col gap-3">
    <div class="glass card flex flex-wrap items-center gap-2 !py-3">
        <input type="search" class="field max-w-xs !py-2 text-sm" placeholder="شماره اتوبوس یا پلاک"
               x-model.debounce.400ms="filters.fleet.q" @input="loadFleet()">
        <select class="field max-w-[10rem] !py-2 text-sm" x-model="filters.fleet.status" @change="loadFleet()">
            <option value="">همه وضعیت‌ها</option>
            <option value="active">فعال</option>
            <option value="idle">آماده به کار</option>
            <option value="maintenance">در تعمیرگاه</option>
            <option value="out_of_service">خارج از سرویس</option>
        </select>
        <span class="ms-auto text-xs text-ink-400" x-text="`${$num(buses.length)} اتوبوس`"></span>
    </div>

    <div class="glass card !p-0">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>شماره</th><th>پلاک</th><th>ظرفیت</th><th>وضعیت</th>
                        <th>راننده فعلی</th><th>آخرین موقعیت</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="bus in buses" :key="bus.uuid">
                        <tr>
                            <td class="font-semibold" x-text="$num(bus.bus_number)"></td>
                            <td class="text-xs text-ink-400" x-text="bus.plate || '—'"></td>
                            <td class="text-xs" x-text="$num(bus.total_capacity)"></td>
                            <td>
                                <span class="badge" :class="`badge-${bus.status_color}`" x-text="bus.status_label"></span>
                            </td>
                            <td class="text-xs" x-text="bus.current_driver || '—'"></td>
                            <td class="text-xs text-ink-400"
                                x-text="bus.last_ping_at ? $time(bus.last_ping_at) : 'بدون گزارش'"></td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm" @click="showQr(bus)">کد QR</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="!buses.length" class="py-10 text-center text-sm text-ink-500">اتوبوسی ثبت نشده است.</p>
    </div>

    {{-- QR modal: shows the printable public id and the live rotating token. --}}
    <div x-show="qrModal" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4"
         @click.self="qrModal = null">
        <div class="glass-strong card w-full max-w-sm text-center">
            <h3 class="text-base font-bold" x-text="`کد اتوبوس ${$num(qrModal?.bus_number)}`"></h3>

            <div class="mx-auto mt-5 w-fit rounded-2xl bg-white p-4">
                <canvas id="qr-canvas"></canvas>
            </div>

            <p class="mt-4 font-mono text-xs text-ink-300" dir="ltr" x-text="qrModal?.public_id"></p>
            <p class="mt-1 text-[11px] text-ink-500">
                اعتبار کد: <span x-text="$num(qrCountdown)"></span> ثانیه — به‌صورت خودکار تغییر می‌کند
            </p>

            <div class="mt-5 flex gap-2">
                <button type="button" class="btn btn-ghost flex-1" @click="qrModal = null">بستن</button>
                <button type="button" class="btn btn-danger flex-1" @click="regenerateQr()">ابطال و صدور مجدد</button>
            </div>

            <p class="mt-3 text-[11px] leading-5 text-ink-500">
                ابطال کد، برچسب نصب‌شده در اتوبوس را از کار می‌اندازد و باید برچسب جدید چاپ شود.
            </p>
        </div>
    </div>
</section>
