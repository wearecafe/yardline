<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * A VAPID key pair for web push alerts. Set it once per environment and
 * keep it: changing it cuts off every device that turned alerts on (they
 * re-subscribe the next time someone opens the app).
 */
class MakePushKeys extends Command
{
    protected $signature = 'yardrunner:push:keys';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:push:keys'];

    protected $description = 'Make a VAPID key pair for web push alerts';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Add these to the environment (Laravel Cloud → Environment variables, or .env):');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->line('VAPID_SUBJECT=mailto:support@yardrunner.ca');

        if (filled(config('yardrunner.push.public_key'))) {
            $this->newLine();
            $this->warn('Keys are already set here. Replacing them stops alerts on every device until each one opens the app again.');
        }

        return self::SUCCESS;
    }
}
