@extends('layouts.base')

@section('title', 'اپلیکیشن راننده')

@section('body')
<div class="grid min-h-dvh place-items-center px-6 py-12">
    <div class="glass-strong card w-full max-w-md text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-sky-500/15 text-sky-300">
            <svg viewBox="0 0 24 24" class="size-7 fill-current"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
        </span>
        <h1 class="mt-5 text-xl font-bold">اپلیکیشن راننده</h1>
        <p class="mt-3 text-sm leading-7 text-ink-400">
            اپلیکیشن راننده به‌صورت نیتیو با Flutter توسعه یافته و برای اندروید و iOS منتشر می‌شود.
            ردیابی موقعیت در پس‌زمینه و اسکن کد اتوبوس نیازمند نسخه نصبی است.
        </p>

        <div class="mt-6 flex flex-col gap-2">
            <a href="{{ url('/downloads/hamsafar-driver.apk') }}" class="btn btn-primary">دریافت نسخه اندروید</a>
            <a href="{{ route('home') }}" class="btn btn-ghost">بازگشت به صفحه اصلی</a>
        </div>

        <p class="mt-5 text-xs text-ink-500">
            کد منبع اپلیکیشن در مسیر <span dir="ltr" class="font-mono">apps/driver</span> مخزن قرار دارد.
        </p>
    </div>
</div>
@endsection
