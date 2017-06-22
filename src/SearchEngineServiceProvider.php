<?php

namespace Dbroquin\SearchEngine;

use Illuminate\Support\ServiceProvider;

class SearchEngineServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish Vue.js files
        $this->publishes([
            __DIR__.'/resources/' => resource_path('/assets/js/components/commons')
        ], 'searchEngine');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
