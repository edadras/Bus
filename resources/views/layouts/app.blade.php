@extends('layouts.base')

@section('body')
    <div class="flex min-h-dvh flex-col">
        @include('partials.header')

        <main class="flex-1">
            @yield('content')
        </main>

        @include('partials.footer')
    </div>
@endsection
