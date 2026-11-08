<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AddReverbApp extends Command
{
    protected $signature = 'reverb:add-app
        {name : Short app name, e.g. bayar-siap}
        {--origins= : Comma-separated browser hostnames allowed to connect, e.g. "example.com,*.example.com" (use * for any)}';

    protected $description = 'Create credentials for a new app on this Reverb server and print the .env lines that app needs';

    public function handle(): int
    {
        $name = strtolower($this->argument('name'));

        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            $this->error('Name must be lowercase letters, digits and dashes (e.g. bayar-siap).');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();
        $env = file_exists($path) ? file_get_contents($path) : '';

        preg_match('/^REVERB_APPS=(.*)$/m', $env, $m);
        $apps = array_values(array_filter(array_map('trim', explode(',', trim($m[1] ?? '', '"\'')))));

        if (in_array($name, $apps, true)) {
            $this->error("App [{$name}] already exists in {$path}.");

            return self::FAILURE;
        }

        $prefix = 'REVERB_APP_'.strtoupper(str_replace('-', '_', $name)).'_';
        $id = (string) random_int(100_000, 999_999);
        $key = Str::lower(Str::random(20));
        $secret = Str::lower(Str::random(32));
        $origins = str_replace(' ', '', (string) $this->option('origins'));

        if (! preg_match('/^[A-Za-z0-9.*\-,]*$/', $origins)) {
            $this->error('--origins must be comma-separated hostnames (no scheme or port), e.g. "example.com,*.example.com".');

            return self::FAILURE;
        }

        $appsLine = 'REVERB_APPS='.implode(',', [...$apps, $name]);
        $env = isset($m[0])
            ? str_replace($m[0], $appsLine, $env)
            : rtrim($env)."\n\n".$appsLine;

        $env = rtrim($env)."\n\n# {$name}\n"
            ."{$prefix}ID={$id}\n"
            ."{$prefix}KEY={$key}\n"
            ."{$prefix}SECRET={$secret}\n"
            ."{$prefix}ORIGINS=\"{$origins}\"\n";

        file_put_contents($path, $env);

        ['host' => $host, 'port' => $port, 'scheme' => $scheme] = config('reverb.public');

        $this->info("Added [{$name}] to {$path}. Run `php artisan config:clear` (or config:cache if you cache config), then `php artisan reverb:restart`.");
        if ($origins === '') {
            $this->warn('No --origins given: browsers cannot connect until '.$prefix.'ORIGINS is set. Native phone clients can.');
        }

        $this->newLine();
        $this->line("# --- .env for {$name} ---");
        $this->line(implode("\n", [
            'BROADCAST_CONNECTION=reverb',
            "REVERB_APP_ID={$id}",
            "REVERB_APP_KEY={$key}",
            "REVERB_APP_SECRET={$secret}",
            "REVERB_HOST={$host}",
            "REVERB_PORT={$port}",
            "REVERB_SCHEME={$scheme}",
            'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST="${REVERB_HOST}"',
            'VITE_REVERB_PORT="${REVERB_PORT}"',
            'VITE_REVERB_SCHEME="${REVERB_SCHEME}"',
        ]));
        $this->newLine();
        $this->line("# Phones (Pusher SDK): key={$key}, host={$host}, port={$port}, TLS=".($scheme === 'https' ? 'on' : 'off').', cluster ignored.');

        return self::SUCCESS;
    }
}
