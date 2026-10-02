<?php

/*
|--------------------------------------------------------------------------
| Register Namespaces And Routes
|--------------------------------------------------------------------------
|
| When your module starts, this file is executed automatically. By default
| it registers the module routes.
|
*/

if (!app()->routesAreCached() && class_exists(\App\Nostr\Nostr::class)) {
    require __DIR__.'/Http/routes.php';
}
