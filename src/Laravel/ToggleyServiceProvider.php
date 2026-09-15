<?php

namespace Toggley\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Toggley\Client;
use Toggley\Transport\Transport;

class ToggleyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/toggley.php', 'toggley');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']['toggley'];

            return new Client(
                (string) $config['server_key'],
                $app['cache']->store($config['cache_store']),
                [
                    'base_url' => $config['base_url'],
                    'poll_interval' => $config['poll_interval'],
                    'timeout' => $config['timeout'],
                    'events' => $config['events'],
                    'bootstrap' => $config['bootstrap'],
                    'offline' => $config['offline'],
                    'auto_terminate' => false,
                    'logger' => fn (string $message) => $app['log']->warning($message),
                ],
                $app->bound(Transport::class) ? $app->make(Transport::class) : null,
            );
        });

        $this->app->singleton(Toggley::class, fn ($app) => new Toggley(
            $app->make(Client::class),
            $app['auth'],
            $app['config']['toggley.context_resolver'],
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/toggley.php' => config_path('toggley.php')], 'toggley-config');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\CheckCommand::class, Console\SyncCommand::class, Console\FlushEventsCommand::class]);
        }

        $this->app['router']->aliasMiddleware('toggley', Http\EnsureFlagEnabled::class);

        Blade::if('toggley', fn (string $flag) => $this->app->make(Toggley::class)->isEnabled($flag));

        if (!class_exists(\Laravel\Pennant\Feature::class)) {
            Blade::if('feature', fn (string $flag) => $this->app->make(Toggley::class)->isEnabled($flag));
        }

        $this->app->terminating(function () {
            if ($this->app->resolved(Client::class)) {
                $this->app->make(Client::class)->terminate();
            }
        });
    }
}
