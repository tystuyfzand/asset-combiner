<?php

namespace AssetCombiner\Events;

use AssetCombiner\CombineAssets;

/**
 * Event to modify/override the cache key.
 *
 * @package AssetCombiner\Events
 */
class GetCacheKey {
    /**
     * @var CombineAssets The asset combiner instance
     */
    public $combiner;

    /**
     * @var string The cache key.
     */
    public $cacheKey;

    /**
     * Create a new event instance.
     *
     * @param CombineAssets $combiner
     * @param $cacheKey
     */
    public function __construct(CombineAssets $combiner, $cacheKey) {
        $this->combiner = $combiner;
        $this->cacheKey = $cacheKey;
    }
}