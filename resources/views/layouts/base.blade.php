<!DOCTYPE html>
<html lang="{{ $locale ?? 'fa' }}" dir="{{ $direction ?? 'rtl' }}"
      data-city="{{ $city?->slug ?? config('transit.default_city') }}"
      data-display-unit="{{ config('wallet.display_unit') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#05090b">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', __('common.app_name')) — {{ __('common.app_tagline') }}</title>
    <meta name="description" content="@yield('description', __('common.app_description'))">

    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    @hasSection('manifest')
        <link rel="manifest" href="@yield('manifest')">
    @endif

    <link rel="preload" href="/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/Vazirmatn-SemiBold.woff2" as="font" type="font/woff2" crossorigin>

    {{-- Broadcast connection details, read by resources/js/lib/realtime.js.
         Assembled in a raw PHP block rather than passed inline, because
         Blade's directive argument parser mis-reads a multi-line array
         literal that contains a type cast. --}}
    @php
        $reverb = [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('broadcasting.connections.reverb.options.host'),
            'port' => (int) config('broadcasting.connections.reverb.options.port'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme'),
        ];
    @endphp
    <script>
        window.__REVERB__ = @json($reverb);
    </script>

    {{-- Strings for the JavaScript surfaces, rendered rather than fetched so
         the first paint is already in the right language. `@stack('i18n')`
         lets a page add its own groups — the admin shell adds `admin`. --}}
    @php
        $strings = ['common' => __('common'), 'web' => __('web')];
        // The HEX flags are Blade's own @json defaults and must be kept: they
        // are what stops a translated string containing "</script>" from
        // closing this tag. UNESCAPED_UNICODE is added on top because the page
        // is UTF-8 and \uXXXX escapes would triple the size of Persian copy.
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
    @endphp
    <script>
        window.__I18N__ = @json($strings, $jsonFlags);
    </script>
    @stack('i18n')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-dvh antialiased">
    @yield('body')

    {{-- Toasts: one host element for the whole app. --}}
    <div class="fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4 pointer-events-none"
         x-data x-cloak>
        <template x-for="item in $store.toasts.items" :key="item.id">
            <div class="glass-strong card pointer-events-auto flex w-full max-w-sm items-start gap-3 !py-3 animate-rise"
                 :class="{
                     'border-emerald-400/40': item.type === 'success',
                     'border-red-400/40': item.type === 'error',
                     'border-amber-400/40': item.type === 'warning',
                 }">
                <span class="mt-1 size-2 shrink-0 rounded-full"
                      :class="{
                          'bg-emerald-400': item.type === 'success',
                          'bg-red-400': item.type === 'error',
                          'bg-amber-400': item.type === 'warning',
                          'bg-sky-400': item.type === 'info',
                      }"></span>
                <p class="flex-1 text-sm leading-6" x-text="item.message"></p>
                <button type="button" class="text-ink-400 hover:text-ink-100"
                        @click="$store.toasts.dismiss(item.id)" aria-label="{{ __('common.close') }}">✕</button>
            </div>
        </template>
    </div>

    <style>[x-cloak]{display:none!important}</style>
    @stack('scripts')
</body>
</html>
