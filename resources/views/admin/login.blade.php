@extends('layouts.base')

@section('title', __('admin.login.title'))

@section('body')
<div class="grid min-h-dvh place-items-center px-6" x-data="adminLogin()">
    <div class="w-full max-w-sm">
        <div class="mb-8 flex flex-col items-center">
            <span class="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-600 shadow-lg shadow-brand-500/25">
                <svg viewBox="0 0 24 24" class="size-7 fill-white"><path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10h-1.2a2.5 2.5 0 0 1-4.6 0H9.8a2.5 2.5 0 0 1-4.6 0H4Zm2-9v4h12V7H6Z"/></svg>
            </span>
            <h1 class="mt-5 text-xl font-bold">{{ __('admin.login.heading', ['app' => __('common.app_name')]) }}</h1>
            <p class="mt-1.5 text-sm text-ink-400">{{ __('admin.subtitle') }}</p>
        </div>

        <form class="glass-strong card flex flex-col gap-4" @submit.prevent="submit()">
            <div>
                <label class="field-label" for="mobile">{{ __('admin.login.mobile') }}</label>
                <input id="mobile" type="tel" class="field text-center tracking-widest"
                       inputmode="numeric" x-model="form.mobile" required autofocus
                       placeholder="{{ __('admin.login.mobile_placeholder') }}">
            </div>

            <div>
                <label class="field-label" for="password">{{ __('admin.login.password') }}</label>
                <input id="password" type="password" class="field" x-model="form.password" required>
            </div>

            <p x-show="error" x-cloak class="field-error text-center" x-text="error"></p>

            <button type="submit" class="btn btn-primary w-full" :disabled="busy">
                <span x-show="!busy">{{ __('admin.login.submit') }}</span>
                <span x-show="busy" x-cloak>{{ __('admin.login.checking') }}</span>
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-ink-500">
            {{ __('admin.login.scope_notice') }}
        </p>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/admin-login.js')
@endpush
