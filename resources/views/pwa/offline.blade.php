@extends('layouts.base')

@section('title', 'اتصال برقرار نیست')

@section('body')
<div class="grid min-h-dvh place-items-center px-6">
    <div class="glass card max-w-sm text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white/5 text-ink-300">
            <svg viewBox="0 0 24 24" class="size-7 fill-current"><path d="M12 3c4.97 0 9 4.03 9 9s-4.03 9-9 9-9-4.03-9-9 4.03-9 9-9Zm0 2a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm-1 3h2v6h-2V8Zm0 8h2v2h-2v-2Z"/></svg>
        </span>
        <h1 class="mt-5 text-lg font-bold">اتصال اینترنت برقرار نیست</h1>
        <p class="mt-3 text-sm leading-7 text-ink-400">
            برای مشاهده موقعیت اتوبوس‌ها و موجودی کیف پول، به اینترنت نیاز است.
            اطلاعات ذخیره‌شده نمایش داده نمی‌شود تا عدد نادرستی به شما نشان داده نشود.
        </p>
        <button type="button" class="btn btn-primary mt-6 w-full" onclick="location.reload()">تلاش دوباره</button>
    </div>
</div>
@endsection
