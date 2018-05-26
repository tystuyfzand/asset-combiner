<?php

namespace AssetCombiner;

use Illuminate\Support\ServiceProvider;

/**
 * Service Provider for the Asset Combiner.
 *
 * @package AssetCombiner
 */
class AssetCombinerServiceProvider extends ServiceProvider {

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {
        $configPath = __DIR__ . '/config/combiner.php';

        $this->publishes([ $configPath => config_path('combiner.php') ]);

        $this->mergeConfigFrom($configPath, 'combiner');
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register() {

        $this->app->singleton('combiner', function() {
            return new CombineAssets();
        });

        $this->app->alias('combiner', CombineAssets::class);
    }
}