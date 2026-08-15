<footer class="mt-24 border-t border-white/5 py-12">
    <div class="mx-auto grid w-full max-w-7xl gap-10 px-4 md:grid-cols-4">
        <div class="md:col-span-2">
            <div class="flex items-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600">
                    <svg viewBox="0 0 24 24" class="size-5 fill-white"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
                </span>
                <span class="text-lg font-bold">{{ __('common.app_name') }}</span>
            </div>
            <p class="mt-4 max-w-md text-sm leading-7 text-ink-400">
                سامانه یکپارچه حمل‌ونقل شهری و کیف پول دیجیتال؛ از ردیابی زنده اتوبوس و تخمین هوشمند
                زمان رسیدن تا پرداخت کرایه و خرید در مراکز طرف قرارداد، همه با یک حساب.
            </p>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-ink-200">دسترسی سریع</h3>
            <ul class="mt-4 flex flex-col gap-2.5 text-sm text-ink-400">
                <li><a href="{{ route('passenger.app') }}" class="hover:text-brand-300">اپلیکیشن مسافر</a></li>
                <li><a href="{{ route('driver.app') }}" class="hover:text-brand-300">اپلیکیشن راننده</a></li>
                <li><a href="{{ route('merchant.app') }}" class="hover:text-brand-300">اپلیکیشن پذیرنده</a></li>
                <li><a href="{{ route('admin.login') }}" class="hover:text-brand-300">پنل مدیریت</a></li>
            </ul>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-ink-200">تماس</h3>
            <ul class="mt-4 flex flex-col gap-2.5 text-sm text-ink-400">
                <li>پشتیبانی: از طریق بخش شکایات در اپلیکیشن</li>
                <li>شهر فعال: بندرعباس</li>
            </ul>
        </div>
    </div>

    <div class="mx-auto mt-10 w-full max-w-7xl border-t border-white/5 px-4 pt-6">
        <p class="text-xs text-ink-500">
            © {{ now()->year }} {{ __('common.app_name') }}. داده‌های شبکه اتوبوس‌رانی نمایش‌داده‌شده در نسخه فعلی
            <span class="text-amber-400">نمونه</span> هستند و مرجع رسمی محسوب نمی‌شوند.
        </p>
    </div>
</footer>
