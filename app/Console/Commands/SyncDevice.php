<?php

namespace App\Console\Commands;

use App\Services\DeviceSync;
use Illuminate\Console\Command;

class SyncDevice extends Command
{
    protected $signature = 'zk:sync';

    protected $description = 'Download all employees and attendance logs from the terminal into the database';

    public function handle(DeviceSync $sync): int
    {
        $this->info('Syncing from the terminal...');
        $result = $sync->run();

        if (! $result['ok']) {
            $this->error($result['error'] === 'offline'
                ? 'The terminal did not answer. Nothing was saved.'
                : 'The download was incomplete. Nothing was saved.');

            return self::FAILURE;
        }

        $this->info("Saved {$result['employees']} employees and {$result['logs']} punches.");

        return self::SUCCESS;
    }
}
