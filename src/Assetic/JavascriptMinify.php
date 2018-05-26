<?php

namespace AssetCombiner\Assetic;

use MatthiasMullie\Minify;
use Assetic\Asset\AssetInterface;
use Assetic\Filter\FilterInterface;

/**
 * Minify CSS Filter
 * Class used to compress stylesheet css files.
 *
 * @author Tyler Stuyfzand
 */
class JavascriptMinify implements FilterInterface {

    /**
     * Filters an asset after it has been loaded.
     *
     * @param AssetInterface $asset An asset
     */
    public function filterLoad(AssetInterface $asset) {
    }

    /**
     * Filters an asset just before it's dumped.
     *
     * @param AssetInterface $asset An asset
     */
    public function filterDump(AssetInterface $asset) {
        $minify = new Minify\JS($asset->getContent());

        $asset->setContent($minify->minify());
    }
}