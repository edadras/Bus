<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\Models\City;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        $city = Cache::remember(
            'landing:city:'.config('transit.default_city'),
            300,
            fn () => City::where('slug', config('transit.default_city'))->firstOrFail(),
        );

        return view('landing', [
            'city' => $city,
            'cities' => Cache::remember(
                'landing:cities',
                300,
                fn () => City::active()->orderByDesc('is_launched')->orderBy('name')->get(),
            ),
        ]);
    }
}
