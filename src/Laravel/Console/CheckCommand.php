<?php

namespace Toggley\Laravel\Console;

use Illuminate\Console\Command;
use Toggley\Laravel\Toggley;

class CheckCommand extends Command
{
    protected $signature = 'toggley:check';

    protected $description = 'Check the Toggley connection and list flag values for an empty context.';

    public function handle(Toggley $toggley): int
    {
        $client = $toggley->client();

        if (!$client->refresh() && !$client->initialized()) {
            $this->error("Couldn't load the Toggley config. Check TOGGLEY_SERVER_KEY and TOGGLEY_BASE_URL.");
            return self::FAILURE;
        }

        $flags = $client->allFlags(null);
        $this->info('Connected. ' . count($flags) . ' flag(s).');
        $this->table(['Flag', 'Value (no context)'], array_map(fn ($key, $value) => [$key, json_encode($value)], array_keys($flags), $flags));

        return self::SUCCESS;
    }
}
