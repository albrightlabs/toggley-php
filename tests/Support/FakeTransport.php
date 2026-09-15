<?php

namespace Toggley\Tests\Support;

use Toggley\Transport\Response;
use Toggley\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];

    /** @param callable(array): (Response|\Throwable) $responder */
    public function __construct(private $responder)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $request = compact('method', 'url', 'headers', 'body');
        $this->requests[] = $request;
        $reply = ($this->responder)($request);

        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    public function configRequests(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_ends_with($r['url'], '/api/v2/sdk/config')));
    }

    public function eventRequests(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_ends_with($r['url'], '/api/v2/sdk/events')));
    }
}
