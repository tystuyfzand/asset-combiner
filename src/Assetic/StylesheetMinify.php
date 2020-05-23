<?php namespace AssetCombiner\Assetic;

use MatthiasMullie\Minify;
use Assetic\Asset\AssetInterface;
use Assetic\Filter\FilterInterface;

/**
 * Minify CSS Filter
 * Class used to compress stylesheet css files.
 *
 * @author Tyler Stuyfzand
 */
class StylesheetMinify implements FilterInterface {
    public function filterLoad(AssetInterface $asset) {
    }

    public function filterDump(AssetInterface $asset) {
        $minify = new Minify\CSS($asset->getContent());

        $asset->setContent($minify->minify());
    }
}