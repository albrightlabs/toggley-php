<?php

namespace Toggley\Tests\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Toggley\Client;
use Toggley\Laravel\Facades\Toggley;
use Toggley\Laravel\ToggleyServiceProvider;
use Toggley\Tests\Support\Documents;
use Toggley\Tests\Support\FakeTransport;
use Toggley\Transport\Transport;

final class LaravelTest extends TestCase
{
    private FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [ToggleyServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Toggley' => Toggley::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('toggley.server_key', Documents::SERVER_KEY);
        $app['config']->set('toggley.base_url', 'https://toggley.test');
        $app['config']->set('cache.default', 'array');

        $this->transport = new FakeTransport(fn ($request) => Documents::ok(Documents::document([
            'new-checkout' => Documents::booleanFlag('new-checkout', true, [
                'targets' => [['variation' => 'off', 'values' => ['42']]],
            ]),
            'dark-launch' => Documents::booleanFlag('dark-launch', false),
        ])));
        $app->instance(Transport::class, $this->transport);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/checkout', fn () => 'new checkout')->middleware('toggley:new-checkout');
        Route::get('/secret', fn () => 'secret')->middleware('toggley:dark-launch');
    }

    public function testFacadeUsesTheSignedInUserAsContext(): void
    {
        $this->assertTrue(Toggley::isEnabled('new-checkout'));
        $this->assertFalse(Toggley::isEnabled('dark-launch'));

        $user = new \Illuminate\Foundation\Auth\User;
        $user->id = 42;
        $this->actingAs($user);
        $this->assertFalse(Toggley::isEnabled('new-checkout'), 'user 42 is individually targeted off');
        $this->assertSame('https://toggley.test/api/v2/sdk/config', $this->transport->requests[0]['url']);
        $this->assertCount(1, $this->transport->configRequests(), 'config fetched once, then cached');
    }

    public function testMiddlewareAndBlade(): void
    {
        $this->get('/checkout')->assertOk()->assertSee('new checkout');
        $this->get('/secret')->assertNotFound();

        $this->assertSame('on', trim(Blade::render("@toggley('new-checkout') on @else off @endtoggley")));
        $this->assertSame('off', trim(Blade::render("@feature('dark-launch') on @else off @endfeature")));
    }

    public function testFake(): void
    {
        Toggley::fake(['dark-launch' => true, 'theme' => 'dark']);

        $this->assertTrue(Toggley::isEnabled('dark-launch'));
        $this->assertSame('dark', Toggley::string('theme', 'light'));
        $this->get('/secret')->assertOk();
        $this->assertSame([], $this->transport->requests);
    }

    public function testCommandsAndConfigBinding(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
        $this->artisan('toggley:check')->expectsOutputToContain('Connected. 2 flag(s).')->assertSuccessful();
    }
}
