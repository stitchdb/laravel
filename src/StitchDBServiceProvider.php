<?php

namespace StitchDB\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

class StitchDBServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Auto-add the stitchdb connection to config if not already there
        $config = $this->app['config'];

        if (!$config->has('database.connections.stitchdb')) {
            $config->set('database.connections.stitchdb', [
                'driver' => 'stitchdb',
                'url' => env('STITCHDB_URL', 'https://db.stitchdb.com'),
                'api_key' => env('STITCHDB_API_KEY', ''),
                'database' => 'default',
                'prefix' => '',
            ]);
        }

        // If DB_CONNECTION=stitchdb, set it as default
        if (env('DB_CONNECTION') === 'stitchdb') {
            $config->set('database.default', 'stitchdb');
        }

        // Register the connection resolver
        Connection::resolverFor('stitchdb', function ($connection, $database, $prefix, $config) {
            return new StitchDBConnection(null, $database, $prefix, $config);
        });

        // Register the connector
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('stitchdb', function ($config, $name) {
                $config['name'] = $name;
                return new StitchDBConnection(null, $config['database'] ?? '', $config['prefix'] ?? '', $config);
            });
        });
    }

    public function boot(): void
    {
        //
    }
}
