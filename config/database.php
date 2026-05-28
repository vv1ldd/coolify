<?php

use Illuminate\Support\Str;
use Pdo\Pgsql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'coolify-db'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'coolify'),
            'username' => env('DB_USERNAME', 'coolify'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'options' => [
                (defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES') ? Pgsql::ATTR_DISABLE_PREPARES : PDO::PGSQL_ATTR_DISABLE_PREPARES) => env('DB_DISABLE_PREPARES', false),
            ],
        ],

        'testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        /*
         * ─────────────────────────────────────────────────────────────
         * SOVEREIGN INFRASTRUCTURE LEDGER — Dedicated Connection
         * ─────────────────────────────────────────────────────────────
         *
         * Stage 1 (current): Falls back to the same operational DB.
         *                    Just set LEDGER_DB_DATABASE the same as DB_DATABASE.
         *
         * Stage 2:           Point LEDGER_DB_* to a dedicated PostgreSQL instance
         *                    with an append-only role (INSERT only, no UPDATE/DELETE).
         *                    See: php artisan sovereign:setup-ledger-db
         *
         * Stage 3:           Enable async Merkle root anchoring to Simple L1.
         *                    See: php artisan sovereign:anchor-ledger
         *
         * PostgreSQL append-only role (apply on Stage 2 DB):
         *   CREATE ROLE ledger_writer WITH LOGIN PASSWORD '...';
         *   GRANT CONNECT ON DATABASE infra_ledger TO ledger_writer;
         *   GRANT USAGE ON SCHEMA public TO ledger_writer;
         *   GRANT INSERT, SELECT ON infra_ledger TO ledger_writer;
         *   -- No UPDATE, no DELETE, no DROP — ever.
         */
        'infra_ledger' => [
            'driver' => env('LEDGER_DB_CONNECTION', env('DB_CONNECTION') === 'testing' ? 'sqlite' : env('DB_CONNECTION', 'pgsql')),
            'url' => env('LEDGER_DATABASE_URL'),
            'host' => env('LEDGER_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('LEDGER_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('LEDGER_DB_DATABASE', env('DB_CONNECTION') === 'testing' ? ':memory:' : env('DB_DATABASE', database_path('database.sqlite'))),
            'username' => env('LEDGER_DB_USERNAME', env('DB_USERNAME', '')),
            'password' => env('LEDGER_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('LEDGER_DB_SSLMODE', 'prefer'),
            'foreign_key_constraints' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'coolify-redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'coolify-redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
