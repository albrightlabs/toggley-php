<?php

namespace Toggley\Tests\Support;

use Toggley\Transport\Response;

final class Documents
{
    public const SERVER_KEY = 'tgly_srv_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function booleanFlag(string $key, bool $on = true, array $extra = []): array
    {
        return $extra + [
            'key' => $key,
            'version' => 3,
            'type' => 'boolean',
            'salt' => "salt-{$key}",
            'on' => $on,
            'variations' => [['id' => 'on', 'value' => true], ['id' => 'off', 'value' => false]],
            'offVariation' => 'off',
            'targets' => [],
            'rules' => [],
            'fallthrough' => ['variation' => 'on'],
        ];
    }

    public static function document(array $flags, int $version = 1): array
    {
        return ['schemaVersion' => 1, 'environmentVersion' => $version, 'flags' => $flags, 'segments' => []];
    }

    public static function ok(array $document, string $etag = '"4-1"'): Response
    {
        return new Response(200, ['etag' => $etag], json_encode($document));
    }
}
