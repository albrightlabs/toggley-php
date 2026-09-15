<?php

namespace Toggley\Transport;

final class Response
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
