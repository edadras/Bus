@extends('layouts.base')

@section('title', __('web.native_app.driver_title'))

@section('body')
<div class="grid min-h-dvh place-items-center px-6 py-12">
    <div class="glass-strong card w-full max-w-md text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-sky-500/15 text-sky-300">
            <svg viewBox="0 0 24 24" class="size-7 fill-current"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
        </span>
        <h1 class="mt-5 text-xl font-bold">{{ __('web.native_app.driver_title') }}</h1>
        <p class="mt-3 text-sm leading-7 text-ink-400">{{ __('web.native_app.driver_body') }}</p>

        <div class="mt-6 flex flex-col gap-2">
            <a href="{{ url('/downloads/hamsafar-driver.apk') }}" class="btn btn-primary">{{ __('web.native_app.download_android') }}</a>
            <a href="{{ route('home') }}" class="btn btn-ghost">{{ __('web.native_app.back_home') }}</a>
        </div>

        <p class="mt-5 text-xs text-ink-500">
            {{ __('web.native_app.source_path_before') }} <span dir="ltr" class="font-mono">apps/driver</span> {{ __('web.native_app.source_path_after') }}
        </p>
    </div>
</div>
@endsection
