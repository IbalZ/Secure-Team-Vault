<?php

use App\Services\AuditChainVerifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vault:keys {--force : Replace keys that are already configured}', function () {
    $path = base_path('.env');

    if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
        $this->error('The .env file must exist and be writable.');

        return self::FAILURE;
    }

    $environment = file_get_contents($path);

    if ($environment === false) {
        $this->error('Unable to read the .env file.');

        return self::FAILURE;
    }

    if ($this->option('force')) {
        $credentialCount = Schema::hasTable('vault_credentials')
            ? DB::table('vault_credentials')->count()
            : 0;
        $auditCount = Schema::hasTable('audit_logs')
            ? DB::table('audit_logs')->count()
            : 0;

        if ($credentialCount > 0 || $auditCount > 0) {
            $this->error('Keys cannot be replaced after credentials or audit events exist. Replacing them would make protected data unverifiable or unreadable.');

            return self::FAILURE;
        }
    }

    $values = [
        'VAULT_ACTIVE_KEY_VERSION' => '1',
        'VAULT_KEY_V1' => 'base64:'.base64_encode(random_bytes(32)),
        'AUDIT_HMAC_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    ];

    foreach ($values as $name => $value) {
        $pattern = '/^'.preg_quote($name, '/').'=(.*)$/m';

        if (preg_match($pattern, $environment, $matches) === 1) {
            $existing = trim($matches[1], " \t\n\r\0\x0B\"'");

            if ($existing !== '' && ! $this->option('force') && $name !== 'VAULT_ACTIVE_KEY_VERSION') {
                $this->error("{$name} is already configured. Re-run with --force only if you intend to rotate local keys.");

                return self::FAILURE;
            }

            $environment = preg_replace($pattern, $name.'='.$value, $environment, 1);
        } else {
            $environment = rtrim($environment).PHP_EOL.$name.'='.$value.PHP_EOL;
        }
    }

    if (file_put_contents($path, $environment, LOCK_EX) === false) {
        $this->error('Unable to save the vault keys.');

        return self::FAILURE;
    }

    Artisan::call('config:clear');
    $this->info('Vault encryption and audit integrity keys were configured. Their values were not displayed.');
    $this->warn('Back up the .env file securely. Losing VAULT_KEY_V1 makes stored secrets unrecoverable.');

    return self::SUCCESS;
})->purpose('Generate local SecureTeam Vault encryption keys');

Artisan::command('vault:audit-verify', function (AuditChainVerifier $verifier) {
    $result = $verifier->verify();

    if (! $result['valid']) {
        $this->error('Audit-chain verification failed.');

        foreach ($result['errors'] as $error) {
            $event = $error['event_id'] === null ? 'chain' : 'event '.$error['event_id'];
            $this->line("- {$event}: {$error['message']}");
        }

        return self::FAILURE;
    }

    $this->info("Audit chain verified: {$result['checked_events']} event(s).");

    return self::SUCCESS;
})->purpose('Verify the SecureTeam Vault audit log hash chain');
