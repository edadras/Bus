<?php

namespace App\Http\Controllers;

use App\Domain\Network\Models\City;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /** The city resolved for this request by ResolveTenantCity. */
    protected function city(): City
    {
        return app(City::class);
    }
}
