@extends('layouts.base')

@section('manifest', route('pwa.manifest', ['app' => $app]))

@section('body')
    <div class="mx-auto flex min-h-dvh w-full max-w-lg flex-col pb-24">
        @yield('app')
    </div>

    @yield('tabbar')

    <script>
        // Registered from the site root so one worker serves all three apps.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
            });
        }
    </script>
@endsection
