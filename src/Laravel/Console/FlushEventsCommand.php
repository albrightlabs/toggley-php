<?php

namespace Toggley\Laravel\Console;

use Illuminate\Console\Command;
use Toggley\Client;

class FlushEventsCommand extends Command
{
    protected $signature = 'toggley:flush-events';

    protected $description = 'Send the evaluation counts collected in the cache to Toggley now.';

    public function handle(Client $client): int
    {
        $client->flush();
        $this->info('Evaluation counts sent.');

        return self::SUCCESS;
    }
}
