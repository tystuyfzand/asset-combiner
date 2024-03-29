<?php namespace AssetCombiner;

use AssetCombiner\Cache\FilesystemCache;
use AssetCombiner\Events\BeforePrepare;
use AssetCombiner\Events\GetCacheKey;
use Assetic\Asset\AssetCache;
use Assetic\Asset\AssetCollection;
use Assetic\Asset\FileAsset;
use Assetic\Factory\AssetFactory;
use Config;
use Cache;
use DateTime;
use File;
use Illuminate\Support\Arr;
use Request;
use Response;
use Route;
use Storage;

/**
 * Combiner class used for combining JavaScript and StyleSheet files.
 *
 * This works by taking a collection of asset locations, serializing them,
 * then storing them in the session with a unique ID. The ID is then used
 * to generate a URL to the `/combine` route via the system controller.
 *
 * When the combine route is hit, the unique ID is used to serve up the
 * assets -- minified, compiled or both. Special E-Tags are used to prevent
 * compilation and delivery of cached assets that are unchanged.
 *
 * Use the `CombineAssets::combine` method to combine your own assets.
 *
 * The functionality of this class is controlled by these config items:
 *
 * - cms.enableAssetCache - Cache untouched assets
 * - cms.enableAssetMinify - Compress assets using minification
 * - cms.enableAssetDeepHashing - Advanced caching of imports
 *
 * @see https://octobercms.com/docs/services/session Session service
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class CombineAssets {

    /**
     * @var array A list of known JavaScript extensions.
     */
    protected static $jsExtensions = [ 'js' ];

    /**
     * @var array A list of known StyleSheet extensions.
     */
    protected static $cssExtensions = [ 'css', 'less', 'scss', 'sass' ];

    /**
     * @var array Aliases for asset file paths.
     */
    protected $aliases = [];

    /**
     * @var array Bundles that are compiled to the filesystem.
     */
    protected $bundles = [];

    /**
     * @var array Filters to apply to each file.
     */
    protected $filters = [];

    /**
     * @var string The local path context to find assets.
     */
    protected $localPath;

    /**
     * @var string The output folder for storing combined files.
     */
    protected $storagePath;

    /**
     * @var bool Cache untouched files.
     */
    public $useCache = false;

    /**
     * @var bool Compress (minify) asset files.
     */
    public $useMinify = false;

    /**
     * @var bool When true, cache will be busted when an import is modified.
     * Enabling this feature will make page loading slower.
     */
    public $useDeepHashing = false;

    /**
     * @var array Cache of registration callbacks.
     */
    private static $callbacks = [];

    /**
     * Constructor
     */
    public function __construct() {
        /*
         * Register preferences
         */
        $this->useCache = Config::get('combiner.enableAssetCache', false);
        $this->useMinify = Config::get('combiner.enableAssetMinify', null);
        $this->useDeepHashing = Config::get('combiner.enableAssetDeepHashing', null);

        if ($this->useMinify === null) {
            $this->useMinify = !Config::get('app.debug', false);
        }

        if ($this->useDeepHashing === null) {
            $this->useDeepHashing = Config::get('app.debug', false);
        }

        /*
         * Register JavaScript filters
         */
        $this->registerFilter('js', new Assetic\JavascriptImporter);

        /*
         * Register CSS filters
         */
        $this->registerFilter('css', new \Assetic\Filter\CssImportFilter);
        $this->registerFilter([ 'css', 'less', 'scss' ], new \Assetic\Filter\CssRewriteFilter);

        if (class_exists('Less_Parser')) {
            $this->registerFilter('less', new Assetic\LessCompiler);
        }

        if (class_exists('ScssPhp\ScssPhp\Compiler')) {
            $this->registerFilter('scss', new Assetic\ScssCompiler);
        }

        /*
         * Minification filters
         */
        if ($this->useMinify) {
            $this->registerFilter('js', new Assetic\JavascriptMinify);
            $this->registerFilter([ 'css', 'less', 'scss' ], new Assetic\StylesheetMinify);
        }

        /*
         * Deferred registration
         */
        foreach (static::$callbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Combines JavaScript or StyleSheet file references
     * to produce a page relative URL to the combined contents.
     *
     *     $assets = [
     *         'assets/vendor/mustache/mustache.js',
     *         'assets/js/vendor/jquery.ui.widget.js',
     *         'assets/js/vendor/canvas-to-blob.js',
     *     ];
     *
     *     CombineAssets::combine($assets, base_path('plugins/acme/blog'));
     *
     * @param array $assets Collection of assets
     * @param string $localPath Prefix all assets with this path (optional)
     * @return string URL to contents.
     */
    public static function combine($assets = [], $localPath = null) {
        return app('combiner')->prepareRequest($assets, $localPath);
    }

    /**
     * Combines a collection of assets files to a destination file
     *
     *     $assets = [
     *         'assets/less/header.less',
     *         'assets/less/footer.less',
     *     ];
     *
     *     CombineAssets::combineToFile(
     *         $assets,
     *         base_path('themes/website/assets/theme.less'),
     *         base_path('themes/website')
     *     );
     *
     * @param array $assets Collection of assets
     * @param string $destination Write the combined file to this location
     * @param string $localPath Prefix all assets with this path (optional)
     * @return void
     */
    public function combineToFile($assets = [], $destination, $localPath = null) {
        // Disable cache always
        $this->storagePath = null;

        list($assets, $extension) = $this->prepareAssets($assets);

        $rewritePath = File::localToPublic(dirname($destination));
        $combiner = $this->prepareCombiner($assets, $rewritePath);

        $contents = $combiner->dump();
        File::put($destination, $contents);
    }

    /**
     * Returns the combined contents from a prepared cache identifier.
     * @param string $cacheKey Cache identifier.
     * @return string Combined file contents.
     * @throws \Exception
     */
    public function getContents($cacheKey) {
        $cacheInfo = $this->getCache($cacheKey);
        if (!$cacheInfo) {
            throw new \Exception('Combined file not found');
        }

        $this->localPath = $cacheInfo['path'];
        $this->storagePath = storage_path('combiner/assets');

        $this->setHashOnCombinerFilters($cacheKey);
        $combiner = $this->prepareCombiner($cacheInfo['files']);
        $contents = $combiner->dump();

        return $contents;
    }

    /**
     * Returns the combined contents from a prepared cache identifier.
     * @param string $cacheKey Cache identifier.
     * @return string Combined file contents.
     * @throws \Exception
     */
    public function getResponse($cacheKey) {
        $cacheInfo = $this->getCache($cacheKey);
        if (!$cacheInfo) {
            throw new \Exception('Combined file not found');
        }

        $this->localPath = $cacheInfo['path'];
        $this->storagePath = storage_path('combiner/assets');

        /*
         * Analyse cache information
         */
        $lastModifiedTime = gmdate("D, d M Y H:i:s \G\M\T", Arr::get($cacheInfo, 'lastMod'));
        $etag = Arr::get($cacheInfo, 'etag');
        $mime = (Arr::get($cacheInfo, 'extension') == 'css')
            ? 'text/css'
            : 'application/javascript';

        /*
         * Set 304 Not Modified header, if necessary
         */
        header_remove();
        $response = Response::make();
        $response->header('Content-Type', $mime);
        $response->setLastModified(new DateTime($lastModifiedTime));
        $response->setEtag($etag);
        $response->setPublic();
        $modified = !$response->isNotModified(app('request'));

        /*
         * Request says response is cached, no code evaluation needed
         */
        if ($modified) {
            $this->setHashOnCombinerFilters($cacheKey);
            $combiner = $this->prepareCombiner($cacheInfo['files']);
            $contents = $combiner->dump();
            $response->setContent($contents);
        }

        return $response;
    }

    /**
     * Prepares an array of assets by normalizing the collection
     * and processing aliases.
     * @param array $assets
     * @return array
     */
    protected function prepareAssets(array $assets) {
        if (!is_array($assets)) {
            $assets = [ $assets ];
        }

        /*
         * Split assets in to groups.
         */
        $combineJs = [];
        $combineCss = [];

        foreach ($assets as $asset) {
            /*
             * Allow aliases to go through without an extension
             */
            if (substr($asset, 0, 1) == '@') {
                $combineJs[] = $asset;
                $combineCss[] = $asset;
                continue;
            }

            $extension = File::extension($asset);

            if (in_array($extension, self::$jsExtensions)) {
                $combineJs[] = $asset;
                continue;
            }

            if (in_array($extension, self::$cssExtensions)) {
                $combineCss[] = $asset;
                continue;
            }
        }

        /*
         * Determine which group of assets to combine.
         */
        if (count($combineCss) > count($combineJs)) {
            $extension = 'css';
            $assets = $combineCss;
        } else {
            $extension = 'js';
            $assets = $combineJs;
        }

        /*
         * Apply registered aliases
         */
        if ($aliasMap = $this->getAliases($extension)) {
            foreach ($assets as $key => $asset) {
                if (substr($asset, 0, 1) !== '@') {
                    continue;
                }
                $_asset = substr($asset, 1);

                if (isset($aliasMap[$_asset])) {
                    $assets[$key] = $aliasMap[$_asset];
                }
            }
        }

        return [ $assets, $extension ];
    }

    /**
     * Combines asset file references of a single type to produce
     * a URL reference to the combined contents.
     * @param array $assets List of asset files.
     * @param string $localPath File extension, used for aesthetic purposes only.
     * @return string URL to contents.
     */
    protected function prepareRequest(array $assets, $localPath = null) {
        if (substr($localPath, -1) != '/') {
            $localPath = $localPath . '/';
        }

        $this->localPath = $localPath;
        $this->storagePath = storage_path('combiner/assets');

        list($assets, $extension) = $this->prepareAssets($assets);

        /*
         * Cache and process
         */
        $cacheKey = $this->getCacheKey($assets);
        $cacheInfo = $this->useCache ? $this->getCache($cacheKey) : false;

        if (!$cacheInfo) {
            $this->setHashOnCombinerFilters($cacheKey);

            $combiner = $this->prepareCombiner($assets);

            if ($this->useDeepHashing) {
                $factory = new AssetFactory($this->localPath);
                $lastMod = $factory->getLastModified($combiner);
            } else {
                $lastMod = $combiner->getLastModified();
            }

            $cacheInfo = [
                'version' => $cacheKey . '-' . $lastMod,
                'etag' => $cacheKey,
                'lastMod' => $lastMod,
                'files' => $assets,
                'path' => $this->localPath,
                'extension' => $extension
            ];

            $this->putCache($cacheKey, $cacheInfo);
        }

        return $this->getCombinedUrl($cacheInfo);
    }

    /**
     * Returns the combined contents from a prepared cache identifier.
     * @param array $assets List of asset files.
     * @param string $rewritePath
     * @return string Combined file contents.
     */
    protected function prepareCombiner(array $assets, $rewritePath = null) {
        /*
         * Extensibility
         */
        event(new BeforePrepare($this, $assets));

        $files = [];
        $filesSalt = null;
        foreach ($assets as $asset) {
            $filters = $this->getFilters(File::extension($asset)) ?: [];
            $path = file_exists($asset) ? $asset : $this->localPath . $asset;
            $files[] = new FileAsset($path, $filters, resource_path());
            $filesSalt .= $this->localPath . $asset;
        }
        $filesSalt = md5($filesSalt);

        $collection = new AssetCollection($files, [], $filesSalt);
        $collection->setTargetPath($this->getTargetPath($rewritePath));

        if ($this->storagePath === null) {
            return $collection;
        }

        if (!File::isDirectory($this->storagePath)) {
            @File::makeDirectory($this->storagePath);
        }

        $cache = new FilesystemCache($this->storagePath);

        $cachedFiles = [];
        foreach ($files as $file) {
            $cachedFiles[] = new AssetCache($file, $cache);
        }

        $cachedCollection = new AssetCollection($cachedFiles, [], $filesSalt);
        $cachedCollection->setTargetPath($this->getTargetPath($rewritePath));
        return $cachedCollection;
    }

    /**
     * Busts the cache based on a different cache key.
     * @return void
     */
    protected function setHashOnCombinerFilters($hash) {
        $allFilters = call_user_func_array('array_merge', array_values($this->getFilters()));

        foreach ($allFilters as $filter) {
            if (method_exists($filter, 'setHash')) {
                $filter->setHash($hash);
            }
        }
    }

    /**
     * Returns a deep hash on filters that support it.
     * @param array $assets List of asset files.
     * @return void
     */
    protected function getDeepHashFromAssets($assets) {
        $key = '';

        $assetFiles = array_map(function($file) {
            return file_exists($file) ? $file : $this->localPath . $file;
        }, $assets);

        foreach ($assetFiles as $file) {
            $filters = $this->getFilters(File::extension($file));

            foreach ($filters as $filter) {
                if (method_exists($filter, 'hashAsset')) {
                    $key .= $filter->hashAsset($file, $this->localPath);
                }
            }
        }

        return $key;
    }

    /**
     * Returns the URL used for accessing the combined files.
     * @param string $outputFilename A custom file name to use.
     * @return string
     */
    protected function getCombinedUrl($cacheInfo) {
        // Store to Storage
        $driver = Config::get('combiner.storage.driver', 'controller');

        switch ($driver) {
            case 'controller':
                $combineAction = 'System\CombinerController@combine';
                $actionExists = Route::getRoutes()->getByAction($combineAction) !== null;

                if ($actionExists) {
                    return action($combineAction, [ $cacheInfo['version'] ], false);
                } else {
                    return '/combine/' . $cacheInfo['version'];
                }
                break;
            case 'storage':
                $outputFilename = $cacheInfo['version'] . '.' . $cacheInfo['extension'];

                $storage = Storage::disk(Config::get('combiner.storage.disk', 'default'));

                if (!$storage->exists($outputFilename)) {
                    try {
                        $storage->put($outputFilename, $this->getContents($cacheInfo['etag']));
                    } catch (\Exception $e) {
                        return null;
                    }
                }

                return $storage->url($outputFilename);
        }
    }

    /**
     * Returns the target path for use with the combiner. The target
     * path helps generate relative links within CSS.
     *
     * /combine              returns combine/
     * /index.php/combine    returns index-php/combine/
     *
     * @param string|null $path
     * @return string The new target path
     */
    protected function getTargetPath($path = null) {
        if ($path === null) {
            $baseUri = substr(Request::getBaseUrl(), strlen(Request::getBasePath()));
            $path = $baseUri . '/combine';
        }

        if (strpos($path, '/') === 0) {
            $path = substr($path, 1);
        }

        $path = str_replace('.', '-', $path) . '/';
        return $path;
    }

    //
    // Registration
    //

    /**
     * Registers a callback function that defines bundles.
     * The callback function should register bundles by calling the manager's
     * `registerBundle` method. This instance is passed to the callback
     * function as an argument. Usage:
     *
     *     CombineAssets::registerCallback(function($combiner){
     *         $combiner->registerBundle('~/modules/backend/assets/less/october.less');
     *     });
     *
     * @param callable $callback A callable function.
     */
    public static function registerCallback(callable $callback) {
        self::$callbacks[] = $callback;
    }

    //
    // Filters
    //

    /**
     * Register a filter to apply to the combining process.
     * @param string|array $extension Extension name. Eg: css
     * @param object $filter Collection of files to combine.
     * @return self
     */
    public function registerFilter($extension, $filter) {
        if (is_array($extension)) {
            foreach ($extension as $_extension) {
                $this->registerFilter($_extension, $filter);
            }
            return;
        }

        $extension = strtolower($extension);

        if (!isset($this->filters[$extension])) {
            $this->filters[$extension] = [];
        }

        if ($filter !== null) {
            $this->filters[$extension][] = $filter;
        }

        return $this;
    }

    /**
     * Clears any registered filters.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function resetFilters($extension = null) {
        if ($extension === null) {
            $this->filters = [];
        } else {
            $this->filters[$extension] = [];
        }

        return $this;
    }

    /**
     * Returns filters.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function getFilters($extension = null) {
        if ($extension === null) {
            return $this->filters;
        } elseif (isset($this->filters[$extension])) {
            return $this->filters[$extension];
        } else {
            return null;
        }
    }

    //
    // Bundles
    //

    /**
     * Registers bundle.
     * @param string|array $files Files to be registered to bundle
     * @param string $destination Destination file will be compiled to.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function registerBundle($files, $destination = null, $extension = null) {
        if (!is_array($files)) {
            $files = [ $files ];
        }

        $firstFile = array_values($files)[0];

        if ($extension === null) {
            $extension = File::extension($firstFile);
        }

        $extension = strtolower(trim($extension));

        if ($destination === null) {
            $file = File::name($firstFile);
            $path = dirname($firstFile);
            $preprocessors = array_except(self::$cssExtensions, 'css');

            if (in_array($extension, $preprocessors)) {
                $cssPath = $path . '/../css';
                if (
                    in_array(strtolower(basename($path)), $preprocessors) &&
                    File::isDirectory($cssPath)
                ) {
                    $path = $cssPath;
                }
                $destination = $path . '/' . $file . '.css';
            } else {
                $destination = $path . '/' . $file . '-min.' . $extension;
            }
        }

        $this->bundles[$extension][$destination] = $files;

        return $this;
    }

    /**
     * Returns bundles.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function getBundles($extension = null) {
        if ($extension === null) {
            return $this->bundles;
        } elseif (isset($this->bundles[$extension])) {
            return $this->bundles[$extension];
        } else {
            return null;
        }
    }

    //
    // Aliases
    //

    /**
     * Register an alias to use for a longer file reference.
     * @param string $alias Alias name. Eg: framework
     * @param string $file Path to file to use for alias
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function registerAlias($alias, $file, $extension = null) {
        if ($extension === null) {
            $extension = File::extension($file);
        }

        $extension = strtolower($extension);

        if (!isset($this->aliases[$extension])) {
            $this->aliases[$extension] = [];
        }

        $this->aliases[$extension][$alias] = $file;

        return $this;
    }

    /**
     * Clears any registered aliases.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function resetAliases($extension = null) {
        if ($extension === null) {
            $this->aliases = [];
        } else {
            $this->aliases[$extension] = [];
        }

        return $this;
    }

    /**
     * Returns aliases.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function getAliases($extension = null) {
        if ($extension === null) {
            return $this->aliases;
        } elseif (isset($this->aliases[$extension])) {
            return $this->aliases[$extension];
        } else {
            return null;
        }
    }

    //
    // Cache
    //

    /**
     * Stores information about a asset collection against
     * a cache identifier.
     * @param string $cacheKey Cache identifier.
     * @param array $cacheInfo List of asset files.
     * @return bool Successful
     */
    protected function putCache($cacheKey, array $cacheInfo) {
        $cacheKey = 'combiner.' . $cacheKey;

        if (Cache::has($cacheKey)) {
            return false;
        }

        $this->putCacheIndex($cacheKey);
        Cache::forever($cacheKey, base64_encode(serialize($cacheInfo)));
        return true;
    }

    /**
     * Look up information about a cache identifier.
     * @param string $cacheKey Cache identifier
     * @return array Cache information
     */
    protected function getCache($cacheKey) {
        $cacheKey = 'combiner.' . $cacheKey;

        if (!Cache::has($cacheKey)) {
            return false;
        }

        return @unserialize(@base64_decode(Cache::get($cacheKey)));
    }

    /**
     * Builds a unique string based on assets
     * @param array $assets Asset files
     * @return string Unique identifier
     */
    protected function getCacheKey(array $assets) {
        $cacheKey = $this->localPath . implode('|', $assets);

        /*
         * Deep hashing
         */
        if ($this->useDeepHashing) {
            $cacheKey .= $this->getDeepHashFromAssets($assets);
        }

        /*
         * Extensibility
         */
        $event = new GetCacheKey($this, $cacheKey);
        event($event);
        $cacheKey = $event->cacheKey;

        return md5($cacheKey);
    }

    /**
     * Resets the combiner cache
     * @return void
     */
    public static function resetCache() {
        if (Cache::has('combiner.index')) {
            $index = (array)@unserialize(@base64_decode(Cache::get('combiner.index'))) ?: [];

            foreach ($index as $cacheKey) {
                Cache::forget($cacheKey);
            }

            Cache::forget('combiner.index');
        }
    }

    /**
     * Adds a cache identifier to the index store used for
     * performing a reset of the cache.
     * @param string $cacheKey Cache identifier
     * @return bool Returns false if identifier is already in store
     */
    protected function putCacheIndex($cacheKey) {
        $index = [];

        if (Cache::has('combiner.index')) {
            $index = (array)@unserialize(@base64_decode(Cache::get('combiner.index'))) ?: [];
        }

        if (in_array($cacheKey, $index)) {
            return false;
        }

        $index[] = $cacheKey;

        Cache::forever('combiner.index', base64_encode(serialize($index)));

        return true;
    }
}
