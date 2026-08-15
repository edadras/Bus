@extends('layouts.base')

@section('title', 'درگاه آزمایشی')

@section('body')
<div class="grid min-h-dvh place-items-center px-6">
    <div class="glass-strong card w-full max-w-sm text-center">
        <span class="badge badge-warning mx-auto">محیط توسعه</span>

        <h1 class="mt-5 text-lg font-bold">درگاه پرداخت آزمایشی</h1>
        <p class="mt-3 text-sm leading-7 text-ink-400">
            این صفحه جایگزین درگاه واقعی در محیط توسعه است و مسیر کامل پرداخت
            (آغاز، بازگشت و تأیید سمت سرور) را اجرا می‌کند.
        </p>

        <p class="mt-6 text-2xl font-bold">{{ \App\Support\Money::format($payment->amount) }}</p>
        <p class="mt-1 font-mono text-[11px] text-ink-500" dir="ltr">{{ $payment->uuid }}</p>

        <div class="mt-8 flex flex-col gap-2">
            <a class="btn btn-primary"
               href="{{ route('payments.callback', ['payment' => $payment->uuid, 'status' => 'ok', 'authority' => $payment->gateway_authority]) }}">
                پرداخت موفق
            </a>
            <a class="btn btn-ghost"
               href="{{ route('payments.callback', ['payment' => $payment->uuid, 'status' => 'cancelled']) }}">
                انصراف از پرداخت
            </a>
        </div>
    </div>
</div>
@endsection
