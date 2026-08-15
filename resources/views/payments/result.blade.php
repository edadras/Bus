@extends('layouts.base')

@section('title', 'نتیجه پرداخت')

@section('body')
@php
    $succeeded = $payment->status === \App\Domain\Payment\Enums\PaymentStatus::Succeeded;
    $pending = in_array($payment->status, [
        \App\Domain\Payment\Enums\PaymentStatus::Initiated,
        \App\Domain\Payment\Enums\PaymentStatus::Pending,
    ], true);
@endphp

<div class="grid min-h-dvh place-items-center px-6">
    <div class="glass-strong card w-full max-w-sm text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-2xl
                     {{ $succeeded ? 'bg-brand-500/15 text-brand-300' : ($pending ? 'bg-white/5 text-ink-300' : 'bg-danger/15 text-red-300') }}">
            @if ($succeeded)
                <svg viewBox="0 0 24 24" class="size-8 fill-current"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>
            @elseif ($pending)
                <svg viewBox="0 0 24 24" class="size-8 fill-current"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5v5.4l4 2.3-1 1.7-5-2.9V7h2Z"/></svg>
            @else
                <svg viewBox="0 0 24 24" class="size-8 fill-current"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
            @endif
        </span>

        <h1 class="mt-5 text-lg font-bold">
            {{ $succeeded ? 'کیف پول شارژ شد' : ($pending ? 'در انتظار تأیید پرداخت' : 'پرداخت ناموفق بود') }}
        </h1>

        <p class="mt-3 text-2xl font-bold">{{ $payment->formattedAmount() }}</p>

        @if ($payment->failure_reason)
            <p class="mt-3 text-sm text-ink-400">{{ __('errors.'.$payment->failure_reason) }}</p>
        @endif

        <p class="mt-4 font-mono text-[11px] text-ink-500" dir="ltr">{{ $payment->uuid }}</p>

        <p class="mt-6 text-xs leading-6 text-ink-400">
            می‌توانید این صفحه را ببندید و به اپلیکیشن بازگردید؛ موجودی به‌صورت خودکار به‌روزرسانی می‌شود.
        </p>

        <a href="{{ route('home') }}" class="btn btn-ghost mt-6 w-full">بازگشت به صفحه اصلی</a>
    </div>
</div>
@endsection
