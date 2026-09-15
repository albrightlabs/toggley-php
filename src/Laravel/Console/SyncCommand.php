<?php

namespace Toggley\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Toggley\Client;

class SyncCommand extends Command
{
    protected $signature = 'toggley:sync {--out= : Also write the config to this file, for use as TOGGLEY_BOOTSTRAP}';

    protected $description = 'Refresh the cached Toggley config now (for example during deploys).';

    public function handle(Client $client): int
    {
        if (!$client->refresh()) {
            $this->error("Couldn't refresh the Toggley config.");
            return self::FAILURE;
        }

        if ($out = $this->option('out')) {
            $response = Http::withToken((string) config('toggley.server_key'))->timeout(10)->get(rtrim((string) config('toggley.base_url'), '/') . '/api/v2/sdk/config');
            if (!$response->ok() || !Client::isConfigDocument($response->json())) {
                $this->error("Couldn't download the config for {$out}.");
                return self::FAILURE;
            }
            file_put_contents($out, json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $this->info("Wrote {$out}.");
        }

        $this->info('Toggley config refreshed.');

        return self::SUCCESS;
    }
}
