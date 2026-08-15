<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'webpush:vapid {--show : Print the current public key instead of generating}';

    protected $description = 'Generate the VAPID key pair used to sign Web Push notifications';

    public function handle(): int
    {
        if ($this->option('show')) {
            $key = config('webpush.vapid.public_key');

            if (blank($key)) {
                $this->error('No VAPID key configured. Run this command without --show.');

                return self::FAILURE;
            }

            $this->line($key);

            return self::SUCCESS;
        }

        if (filled(config('webpush.vapid.private_key'))) {
            $this->warn('A VAPID key pair already exists.');
            $this->warn('Replacing it invalidates every stored push subscription.');

            if (! $this->confirm('Generate a new pair anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $keys = VAPID::createVapidKeys();

        $this->info('Add these to your .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('WEBPUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('WEBPUSH_VAPID_SUBJECT='.config('app.url'));
        $this->newLine();
        $this->warn('The private key signs every push. Keep it out of version control.');

        return self::SUCCESS;
    }
}
