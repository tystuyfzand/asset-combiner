<?php

namespace AssetCombiner\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade CombineAssets
 *
 * @method combine($assets = [], $localPath = null)
 *
 * @package AssetCombiner\Facades\CombineAssets
 */
class CombineAssets extends Facade {
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() {
        return 'combiner';
    }
}