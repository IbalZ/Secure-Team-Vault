<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Vault encryption keys
    |--------------------------------------------------------------------------
    |
    | Vault credentials deliberately use a key that is separate from APP_KEY.
    | Keys are versioned so records can be rotated without making old records
    | unreadable. Each value must be "base64:" followed by exactly 32 bytes.
    |
    */
    'active_key_version' => (int) env('VAULT_ACTIVE_KEY_VERSION', 1),

    'keys' => [
        1 => env('VAULT_KEY_V1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit integrity
    |--------------------------------------------------------------------------
    |
    | This must be a different 32-byte secret from every vault encryption key.
    | Keeping it outside the database prevents a database-only attacker from
    | silently rebuilding the audit HMAC chain after changing an event.
    |
    */
    'audit' => [
        'chain_id' => env('AUDIT_CHAIN_ID', 'main'),
        'hmac_key' => env('AUDIT_HMAC_KEY'),
    ],
];
