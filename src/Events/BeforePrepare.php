<?php

namespace AssetCombiner\Events;

use AssetCombiner\CombineAssets;

/**
 * Event for the beforePrepare action.
 *
 * @package AssetCombiner\Events
 */
class BeforePrepare {
    /**
     * @var CombineAssets The combiner instance.
     */
    public $combiner;

    /**
     * @var array The list of assets
     */
    public $assets;

    /**
     * Create a new event instance.
     *
     * @param CombineAssets $combiner
     * @param array $assets
     */
    public function __construct(CombineAssets $combiner, array $assets) {
        $this->combiner = $combiner;
        $this->assets = $assets;
    }
}