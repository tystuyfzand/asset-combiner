<?php

namespace AssetCombiner\Controllers;

use Illuminate\Routing\Controller;
use AssetCombiner\CombineAssets;

class CombinerController extends Controller {

    /**
     * Combines JavaScript and StyleSheet assets.
     * @param string $name Combined file code
     * @param CombineAssets $combiner
     * @return string Combined content.
     * @throws \Exception
     */
    public function combine($name, CombineAssets $combiner) {
        try {
            if (!strpos($name, '-')) {
                throw new \Exception(Lang::get('system::lang.combiner.not_found', [ 'name' => $name ]));
            }

            $parts = explode('-', $name);
            $cacheId = $parts[0];

            return $combiner->getResponse($cacheId);
        } catch (Exception $ex) {
            return '/* ' . e($ex->getMessage()) . ' */';
        }
    }
}