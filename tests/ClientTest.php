<?php

namespace Toggley\Tests;

use PHPUnit\Framework\TestCase;
use Toggley\Client;
use Toggley\Summarizer;
use Toggley\ToggleyException;
use Toggley\Tests\Support\ArrayCache;
use Toggley\Tests\Support\Documents;
use Toggley\Tests\Support\FakeTransport;
use Toggley\Transport\Response;

final class ClientTest extends TestCase
{
    private float $now = 1700000000.0;

    private function client(FakeTransport $transport, ?ArrayCache $cache = null, array $options = []): Client
    {
        return new Client(Documents::SERVER_KEY, $cache, $options + ['auto_terminate' => false, 'clock' => fn () => $this->now, 'sapi' => 'fpm-fcgi'], $transport);
    }

    public function testKeys(): void
    {
        $this->expectException(ToggleyException::class);
        new Client('tgly_cli_' . str_repeat('b', 32));
    }

    public function testEmptyCacheFetchesOnceThenServesFromCache(): void
    {
        $cache = new ArrayCache(fn () => $this->now);
        $transport = new FakeTransport(fn () => Documents::ok(Documents::document(['checkout' => Documents::booleanFlag('checkout')])));

        $first = $this->client($transport, $cache);
        $this->assertTrue($first->isEnabled('checkout', ['key' => 'u1']));
        $this->assertTrue($first->isEnabled('checkout', ['key' => 'u2']));
        $this->assertCount(1, $transport->configRequests());
        $this->assertSame('Bearer ' . Documents::SERVER_KEY, $transport->requests[0]['headers']['Authorization']);

        $second = $this->client($transport, $cache);
        $this->assertTrue($second->isEnabled('checkout', ['key' => 'u1']));
        $second->terminate();
        $this->assertCount(1, $transport->configRequests(), 'a fresh cache needs no request, even after the response');
    }

    public function testStaleConfigIsRevalidatedAfterTheResponse(): void
    {
        $cache = new ArrayCache(fn () => $this->now);
        $calls = 0;
        $transport = new FakeTransport(function ($request) use (&$calls) {
            $calls++;
            return ($request['headers']['If-None-Match'] ?? null) === '"4-1"'
                ? new Response(200, ['etag' => '"4-2"'], json_encode(Documents::document(['checkout' => Documents::booleanFlag('checkout', false)], 2)))
                : Documents::ok(Documents::document(['checkout' => Documents::booleanFlag('checkout')]));
        });

        $this->client($transport, $cache)->isEnabled('checkout');
        $this->now += 31;

        $request = $this->client($transport, $cache);
        $this->assertTrue($request->isEnabled('checkout'), 'the stale config still serves during the request');
        $this->assertSame(1, $calls);
        $request->terminate();
        $this->assertSame(2, $calls);

        $this->assertFalse($this->client($transport, $cache)->isEnabled('checkout'), 'the next request sees the update');
    }

    public function testFailuresKeepTheLastGoodConfigThenBootstrapThenDefaults(): void
    {
        $cache = new ArrayCache(fn () => $this->now);
        $replies = [Documents::ok(Documents::document(['checkout' => Documents::booleanFlag('checkout')])), new \RuntimeException('down'), new Response(200, [], '{"schemaVersion":7}')];
        $transport = new FakeTransport(function () use (&$replies) { return array_shift($replies); });
        $messages = [];

        $this->client($transport, $cache)->isEnabled('checkout');
        $this->now += 60;
        $request = $this->client($transport, $cache, ['logger' => function ($m) use (&$messages) { $messages[] = $m; }]);
        $this->assertTrue($request->isEnabled('checkout'), 'a stale config still serves');
        $request->terminate();
        $this->now += 60;
        $another = $this->client($transport, $cache, ['logger' => function ($m) use (&$messages) { $messages[] = $m; }]);
        $this->assertTrue($another->isEnabled('checkout'));
        $another->terminate();
        $this->assertStringContainsString('down', $messages[0]);
        $this->assertStringContainsString('cannot read', $messages[1]);
        $this->assertTrue($this->client($transport, $cache)->isEnabled('checkout'), 'still the last good config');

        $bootstrap = Documents::document(['beta' => Documents::booleanFlag('beta')]);
        $offlineFirst = $this->client(new FakeTransport(fn () => new \RuntimeException('down')), null, ['bootstrap' => $bootstrap]);
        $this->assertTrue($offlineFirst->isEnabled('beta'));

        $nothing = $this->client(new FakeTransport(fn () => new \RuntimeException('down')));
        $detail = $nothing->boolVariationDetail('beta', null, true);
        $this->assertTrue($detail['value']);
        $this->assertSame('CLIENT_NOT_READY', $detail['reason']['errorKind']);
    }

    public function testRejectedKeyIsNotRetriedInTheSameProcess(): void
    {
        $transport = new FakeTransport(fn () => new Response(401, [], '{"error":{"code":"invalid_key","message":"This SDK key is not valid."}}'));
        $client = $this->client($transport, null, ['sapi' => 'cli']);

        $this->assertFalse($client->isEnabled('checkout'));
        $this->now += 100;
        $this->assertFalse($client->isEnabled('checkout'));
        $this->assertCount(1, $transport->configRequests());
    }

    public function testOfflineTypedGetters(): void
    {
        $document = Documents::document([
            'theme' => ['type' => 'string', 'variations' => [['id' => 'light', 'value' => 'light'], ['id' => 'dark', 'value' => 'dark']], 'offVariation' => 'light', 'fallthrough' => ['variation' => 'dark']] + Documents::booleanFlag('theme'),
        ]);
        $transport = new FakeTransport(fn () => throw new \LogicException('offline must not call Toggley'));
        $client = new Client('', null, ['offline' => true, 'bootstrap' => $document, 'auto_terminate' => false], $transport);

        $this->assertSame('dark', $client->stringVariation('theme', ['key' => 'u1'], 'light'));
        $this->assertSame('WRONG_TYPE', $client->boolVariationDetail('theme', null, false)['reason']['errorKind']);
        $this->assertSame(['theme' => 'dark'], $client->allFlags(['key' => 'u1']));
        $client->terminate();
        $this->assertSame([], $transport->requests);
    }

    public function testEventsCollectInTheCacheAndFlushOncePerInterval(): void
    {
        $cache = new ArrayCache(fn () => $this->now);
        $transport = new FakeTransport(fn ($r) => str_ends_with($r['url'], '/events') ? new Response(202, [], '{"accepted":1}') : Documents::ok(Documents::document(['checkout' => Documents::booleanFlag('checkout')])));

        foreach ([1, 2, 3] as $i) {
            $request = $this->client($transport, $cache);
            $request->isEnabled('checkout', ['key' => "private-user-{$i}"]);
            $request->terminate();
            $this->now += 20;
        }
        $this->assertCount(0, $transport->eventRequests(), 'counts wait for the interval');

        $this->now += 30;
        $request = $this->client($transport, $cache);
        $request->isEnabled('missing');
        $request->terminate();

        $events = $transport->eventRequests();
        $this->assertCount(1, $events);
        $payload = json_decode($events[0]['body'], true);
        $this->assertSame([['variation' => 'on', 'count' => 3, 'version' => 3]], $payload['features']['checkout']['counters']);
        $this->assertSame([['variation' => null, 'count' => 1, 'unknown' => true]], $payload['features']['missing']['counters']);
        $this->assertStringNotContainsString('private-user', $events[0]['body']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $events[0]['headers']['X-Toggley-Payload-Id']);
    }

    public function testNoCacheUnderFpmSendsNoEvents(): void
    {
        $transport = new FakeTransport(fn () => Documents::ok(Documents::document(['checkout' => Documents::booleanFlag('checkout')])));
        $client = $this->client($transport);
        $client->isEnabled('checkout');
        $client->terminate();
        $client->flush();
        $this->assertCount(0, $transport->eventRequests());
    }

    public function testSummarizerCapsAndMerges(): void
    {
        $summarizer = new Summarizer(fn () => 1.0);
        for ($i = 0; $i < Summarizer::MAX_COUNTERS_PER_FLAG + 3; $i++) {
            $summarizer->record('versions', ['variation' => 'on', 'flagVersion' => $i]);
        }
        $this->assertSame(3, $summarizer->dropped);

        $other = new Summarizer(fn () => 2.0);
        $other->merge($summarizer->toArray());
        $other->record('versions', ['variation' => 'on', 'flagVersion' => 0]);
        $payload = $other->snapshot(['name' => 't', 'version' => '0']);
        $this->assertSame(2, $payload['features']['versions']['counters'][0]['count']);
        $this->assertSame(1000, $payload['startDate']);
        $this->assertTrue($other->isEmpty());
    }
}
