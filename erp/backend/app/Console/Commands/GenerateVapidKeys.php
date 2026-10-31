<?php

namespace App\Console\Commands;

use App\Services\Push\WebPush;
use Illuminate\Console\Command;

/** Prints a new VAPID key pair for .env (erp/docs/NOTIFICATIONS.md); it never writes files. */
class GenerateVapidKeys extends Command
{
    protected $signature = 'erp:vapid';

    protected $description = 'Print a new VAPID key pair for Web Push, to put in .env.';

    public function handle(): int
    {
        $keys = WebPush::generateKeys();
        $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
        $this->warn('Keep the private key secret. Changing the keys later turns push off on every device until people turn it on again.');

        return self::SUCCESS;
    }
}
