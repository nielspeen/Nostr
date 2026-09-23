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

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
