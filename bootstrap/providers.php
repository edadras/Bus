<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\RouteServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    RouteServiceProvider::class,
    BroadcastServiceProvider::class,
    EventServiceProvider::class,
];
