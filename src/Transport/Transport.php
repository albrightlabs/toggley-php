<?php

namespace Toggley\Transport;

interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws \RuntimeException when the request can't be made
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
