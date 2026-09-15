<?php
/**
 * End-to-end check against a local Toggley. Seeds flags into a throwaway environment
 * with featureflag:import-config, runs the checks, then deletes what it created.
 *
 *   TOGGLEY_APP_DIR=../toggleyapp.com TOGGLEY_BASE_URL=http://toggley.test:8001 TOGGLEY_E2E_ORG=1 php e2e/run.php
 */
require __DIR__ . '/../vendor/autoload.php';

use Toggley\Client;
use Toggley\Tests\Support\ArrayCache;
use Toggley\Transport\HttpTransport;
use Toggley\Transport\Response;
use Toggley\Transport\Transport;

$appDir = getenv('TOGGLEY_APP_DIR') ?: realpath(__DIR__ . '/../../toggleyapp.com');
$baseUrl = getenv('TOGGLEY_BASE_URL') ?: 'http://127.0.0.1:8001';
$org = getenv('TOGGLEY_E2E_ORG') ?: '1';
$prefix = 'e2e-php-';
$environment = 'SDK e2e php';

$seedFile = sys_get_temp_dir() . "/toggley-{$prefix}seed.json";
file_put_contents($seedFile, str_replace('e2e-', $prefix, file_get_contents(__DIR__ . '/seed.json')));

$artisan = function (array $args) use ($appDir): string {
    $command = 'cd ' . escapeshellarg($appDir) . ' && php artisan ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
    return (string) shell_exec($command);
};
$lastJson = fn (string $output) => json_decode(array_values(array_filter(explode("\n", trim($output)), fn ($l) => str_starts_with($l, '{')))[0] ?? 'null', true);
$importArgs = ['featureflag:import-config', $seedFile, "--org={$org}", "--environment={$environment}"];

$setup = $lastJson($artisan([...$importArgs, '--issue-keys']));
if (!$setup) {
    fwrite(STDERR, "Seeding failed.\n");
    exit(1);
}

final class SpyTransport implements Transport
{
    public bool $down = false;
    public array $configStatuses = [];

    public function __construct(private readonly Transport $inner)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        if ($this->down) {
            throw new RuntimeException('Toggley is down');
        }
        $response = $this->inner->send($method, $url, $headers, $body, $timeout);
        if (str_ends_with($url, '/api/v2/sdk/config')) {
            $this->configStatuses[] = $response->status;
        }
        return $response;
    }
}

$results = [];
$check = function (string $name, callable $fn) use (&$results) {
    try {
        $fn();
        $results[] = "PASS {$name}";
    } catch (Throwable $e) {
        $results[] = "FAIL {$name}: " . strtok($e->getMessage(), "\n");
    }
};
$assert = function (bool $condition, string $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$now = microtime(true);
$cache = new ArrayCache(function () use (&$now) { return $now; });
$transport = new SpyTransport(new HttpTransport);
$http = new HttpTransport;
// A regular closure: an arrow function would copy $now and freeze the clock.
$makeClient = function () use (&$now, $cache, $setup, $baseUrl, $transport) {
    return new Client($setup['server_key'], $cache, ['base_url' => $baseUrl, 'auto_terminate' => false, 'clock' => function () use (&$now) { return $now; }], $transport);
};
$ours = function (array $values) use ($prefix) {
    $values = array_filter($values, fn ($key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    ksort($values);
    return $values;
};
$contexts = [];
for ($i = 0; $i < 50; $i++) {
    $contexts[] = [
        'key' => "user-{$i}",
        'email' => $i % 3 === 0 ? "u{$i}@example.com" : "u{$i}@other.org",
        'plan' => ['free', 'pro', 'beta'][$i % 3],
        'country' => ['US', 'DE', 'CA', 'JP'][$i % 4],
        'age' => 18 + $i,
        'org' => 'org-' . ($i % 5),
    ];
}

try {
    $client = $makeClient();

    $check('PHP SDK initializes from Toggley', function () use ($client, $ours, $assert) {
        $assert($client->initialized(), 'not initialized');
        $assert(count($ours($client->allFlags(['key' => 'x']))) === 7, 'expected 7 seeded flags');
    });

    $check('50 contexts: PHP SDK equals /client/evaluate', function () use ($client, $contexts, $http, $setup, $baseUrl, $ours, $assert) {
        foreach ($contexts as $context) {
            $response = $http->send('POST', "{$baseUrl}/api/v2/client/evaluate", ['Authorization' => "Bearer {$setup['client_key']}", 'Content-Type' => 'application/json'], json_encode(['context' => $context]), 10);
            $server = $ours(array_map(fn ($flag) => $flag['value'], $response->json()['flags']));
            $assert($ours($client->allFlags($context)) === $server, 'mismatch for ' . json_encode($context) . ': ' . json_encode([$ours($client->allFlags($context)), $server]));
        }
    });

    $check('boolean flags equal the v1 API', function () use ($client, $contexts, $http, $setup, $baseUrl, $prefix, $assert) {
        foreach (array_slice($contexts, 0, 15) as $context) {
            foreach (['bool-on', 'bool-off', 'bool-split', 'legacy-split'] as $flag) {
                $body = $http->send('GET', "{$baseUrl}/api/v1/{$setup['v1_key']}/flags/{$prefix}{$flag}?subject=" . rawurlencode($context['key']), [], null, 10)->json();
                $assert($body['enabled'] === $client->boolVariation("{$prefix}{$flag}", ['key' => $context['key']], !$body['enabled']), "{$flag} {$context['key']}");
            }
        }
    });

    $check('a new request serves from the cache; a stale cache revalidates with 304', function () use (&$now, $makeClient, $transport, $assert) {
        $requestsBefore = count($transport->configStatuses);
        $second = $makeClient();
        $second->isEnabled('anything');
        $assert(count($transport->configStatuses) === $requestsBefore, 'fresh cache should not request');

        $now += 31;
        $third = $makeClient();
        $third->isEnabled('anything');
        $third->terminate();
        $assert(end($transport->configStatuses) === 304, 'statuses ' . json_encode($transport->configStatuses));
    });

    $check('evaluation counts reach hourly usage', function () use ($makeClient, $contexts, $prefix, $artisan, $setup, $assert) {
        $counting = $makeClient();
        foreach (array_slice($contexts, 0, 5) as $context) {
            $counting->stringVariation("{$prefix}text", $context, 'none');
        }
        $counting->flush();
        $output = $artisan(['tinker', '--execute=echo "SUM:" . DB::table("featureflag_usage_hourly")->where("environment_id", ' . (int) $setup['environment_id'] . ')->where("source", 1)->sum("count");']);
        preg_match('/SUM:(\d+)/', $output, $m);
        $assert((int) ($m[1] ?? 0) >= 5, 'usage sum ' . ($m[1] ?? 'none'));
    });

    $check('Toggley down: cached config keeps serving', function () use (&$now, $makeClient, $transport, $contexts, $ours, $client, $assert) {
        $before = $ours($client->allFlags($contexts[1]));
        $transport->down = true;
        $now += 120;
        $offline = $makeClient();
        $assert($ours($offline->allFlags($contexts[1])) === $before, 'values changed while down');
        $offline->terminate();
        $transport->down = false;
    });
} finally {
    $artisan([...$importArgs, '--delete']);
    @unlink($seedFile);
}

echo implode("\n", $results), "\n";
$failures = count(array_filter($results, fn ($r) => str_starts_with($r, 'FAIL')));
echo (count($results) - $failures) . " passed, {$failures} failed\n";
exit($failures ? 1 : 0);
