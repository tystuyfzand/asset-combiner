<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Determines if the asset caching is enabled.
    |--------------------------------------------------------------------------
    |
    | If the caching is enabled, combined assets are cached. If a asset file
    | is changed on the disk, the old file contents could be still saved in the cache.
    | It is recommended to disable the caching during the development, and enable it
    | in production mode.
    |
    */
    'enableAssetCache' => false,

    /*
    |--------------------------------------------------------------------------
    | Determines if the asset minification is enabled.
    |--------------------------------------------------------------------------
    |
    | If the minification is enabled, combined assets are compressed (minified).
    | It is recommended to disable the minification during development, and
    | enable it in production mode. If set to null, assets are minified
    | when debug mode (app.debug) is disabled.
    |
    */
    'enableAssetMinify' => null,

    /*
    |--------------------------------------------------------------------------
    | Check import timestamps when combining assets
    |--------------------------------------------------------------------------
    |
    | If deep hashing is enabled, the combiner cache will be reset when a change
    | is detected on imported files, in addition to those referenced directly.
    | This will cause slower page performance. If set to null, deep hashing
    | is used when debug mode (app.debug) is enabled.
    |
    */
    'enableAssetDeepHashing' => null,

    /*
    |--------------------------------------------------------------------------
    | Combined Asset Storage
    |--------------------------------------------------------------------------
    |
    | This option controls the combined asset storage method.
    |
    | This can be 'controller' if you have registered a route, or 'storage'
    | to use Laravel's Flysystem integration to push to local or remote
    | storage, and generate a url.
    |
    */
    'storage' => [
        // Storage driver, either 'controller' or 'storage'
        'driver' => 'controller',

        // Storage disk to use (ignored for 'controller')
        'disk' => 'local'
    ]
];