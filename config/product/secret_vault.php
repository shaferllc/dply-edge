<?php

declare(strict_types=1);

use App\Models\Server;

/**
 * Configuration for the SecretVault DR system (see deploy/SECRETS.md).
 *
 * The bash guards (deploy/secrets/*.sh) and this PHP service share three
 * contracts — the age recipient file, the blob key naming, and the store
 * layout — so a blob written by either side is readable by the other.
 */
return [
    // Path to the pinned `age` binary. deploy/secrets/install-cron.sh pins a
    // known version+sha under shared/secrets/bin; locally just use PATH.
    'age_bin' => env('SECRET_VAULT_AGE_BIN', 'age'),

    // Path to the `age-keygen` binary (generates per-org keypairs). Pinned next
    // to `age` by deploy/secrets/install-cron.sh; locally just use PATH.
    'age_keygen_bin' => env('SECRET_VAULT_AGE_KEYGEN_BIN', 'age-keygen'),

    // Public age recipients (one per line). Encryption needs only this; it is
    // safe on every box. The matching private identity is held OFFLINE.
    'recipients_path' => env('SECRET_VAULT_RECIPIENTS_PATH', '/home/dply/shared/secrets/age-recipients.txt'),

    // Private age identity — present ONLY where a restore/drill runs (the
    // isolated drill host), never on prod web/worker boxes.
    'identity_path' => env('SECRET_VAULT_IDENTITY_PATH'),

    // Object-key prefix; bumping the version segment starts a new layout.
    'key_prefix' => env('SECRET_VAULT_KEY_PREFIX', 'secret-vault/v1'),

    'stores' => [
        // Versioned + object-locked bucket in a SEPARATE cloud account.
        // Key names mirror DatabaseBackupS3ClientFactory so the S3 wiring is identical.
        'object' => [
            'enabled' => (bool) env('SECRET_VAULT_OBJECT_ENABLED', false),
            'bucket' => env('SECRET_VAULT_OBJECT_BUCKET'),
            'region' => env('SECRET_VAULT_OBJECT_REGION', 'us-east-1'),
            'endpoint' => env('SECRET_VAULT_OBJECT_ENDPOINT'),
            'access_key' => env('SECRET_VAULT_OBJECT_ACCESS_KEY'),
            'secret' => env('SECRET_VAULT_OBJECT_SECRET'),
            'use_path_style' => (bool) env('SECRET_VAULT_OBJECT_PATH_STYLE', false),
            'path' => env('SECRET_VAULT_OBJECT_PATH', ''),
        ],
        // Private ops git repo (ciphertext only). The bash side pushes via ssh;
        // PHP shells the same `git` against a working clone.
        'git' => [
            'enabled' => (bool) env('SECRET_VAULT_GIT_ENABLED', false),
            'repo' => env('SECRET_VAULT_GIT_REPO'),
            'branch' => env('SECRET_VAULT_GIT_BRANCH', 'main'),
            'work_dir' => env('SECRET_VAULT_GIT_WORKDIR', storage_path('app/secret-vault/git')),
        ],
        // 1Password documents via the `op` CLI (emergency human access).
        'onepassword' => [
            'enabled' => (bool) env('SECRET_VAULT_OP_ENABLED', false),
            'vault' => env('SECRET_VAULT_OP_VAULT'),
            'op_bin' => env('SECRET_VAULT_OP_BIN', 'op'),
        ],
    ],

    'reencrypt' => [
        // Auto-discovered encrypted-cast models are scanned from here.
        'models_path' => app_path('Models'),
        // FQCNs to force-include / exclude beyond the auto-scan.
        'extra_models' => [],
        'exclude_models' => [],
        // Whole-column ciphertext written with raw Crypt::encryptString / encrypt()
        // (NOT `encrypted` casts, so not auto-discoverable). Keep in lockstep with
        // the codebase — SecretReencryptCoverageTest fails if a new raw Crypt
        // usage appears without being covered here. `key` defaults to 'id'.
        'raw_crypt' => [
            ['connection' => null, 'table' => 'users', 'key' => 'id', 'columns' => ['two_factor_secret', 'two_factor_recovery_codes']],
            ['connection' => null, 'table' => 'site_deployment_ephemeral_credentials', 'key' => 'id', 'columns' => ['private_key_encrypted']],
            ['connection' => null, 'table' => 'import_server_migrations', 'key' => 'id', 'columns' => ['ssh_key_private_encrypted']],
        ],

        // Encrypted values nested inside PLAIN `array`-cast JSON columns
        // (sites.meta / servers.meta) at the given dot-paths. These are NOT
        // encrypted-cast columns and NOT whole-column ciphertext — they must be
        // re-encrypted in place within the JSON. Verified paths (data_set keys).
        'json_crypt' => [
            ['connection' => null, 'table' => 'sites', 'key' => 'id', 'column' => 'meta', 'paths' => [
                'scaffold.admin_password',
                'scaffold.database.password',
                // Vector search REST tokens: one encrypted JSON map (EdgeContainerConnections::vectorRestToken).
                'edge.vector_rest_tokens',
            ]],
            ['connection' => null, 'table' => 'servers', 'key' => 'id', 'column' => 'meta', 'paths' => [
                'cache_server.password_encrypted',
                'database_server.password_encrypted',
                'server_event_webhook_secret',
                'monitoring_guest_push_cipher',
            ]],
        ],
        'checkpoint_disk' => 'local',
        'checkpoint_path' => 'secret-vault/reencrypt-progress.json',
    ],

    // Dead-man's-switch ping URLs (per guard) + explicit-failure webhook.
    'dms' => [
        'escrow' => env('SECRET_VAULT_DMS_ESCROW'),
        'db' => env('SECRET_VAULT_DMS_DB'),
        'drill' => env('SECRET_VAULT_DMS_DRILL'),
    ],
    'alert_webhook' => env('SECRET_VAULT_ALERT_WEBHOOK'),

    'db' => [
        'retention_days' => (int) env('SECRET_VAULT_DB_RETENTION_DAYS', 30),
    ],

    // Customer-facing per-key secret residency (see project_secret_residency).
    'residency' => [
        // On-box external resolution (Tier 3+, true end-to-end ZK): when a site
        // has external secrets marked resolution=onbox, the server itself fetches
        // the values via a shipped shim using the box's own credentials, and dply
        // never sees them. OFF by default — until enabled (and validated on a live
        // box), a site with on-box secrets FAILS CLOSED on push rather than
        // shipping an unresolved directive. Flipping this on is a deliberate,
        // box-validated step (the shim needs curl/jq, or the AWS CLI / instance
        // IAM, present on the target server).
        'onbox_enabled' => (bool) env('SECRET_RESIDENCY_ONBOX_ENABLED', false),
    ],

    // Box-to-box APP_KEY drift check (W5). Targets are the adopted control-plane
    // Servers (web + worker); the check SSHes each (via dply's own connection),
    // hashes the APP_KEY value on the box, and alerts if the hashes diverge. The
    // value never leaves the server — only its digest is compared.
    'drift' => [
        // [ ['server_id' => '<ulid>', 'env_path' => '/home/dply/.../shared/.env'], ... ]
        'targets' => [],
    ],

    // A known-present encrypted column used to prove APP_KEY round-trips.
    'canary' => [
        'model' => Server::class,
        'column' => 'ssh_private_key',
    ],

    // Fast break-glass bundle (W1): the prod box's recovery SSH key + Postgres
    // superuser, age-encrypted off-box so recovery doesn't require restoring the
    // whole DB. Operator-provided; escrow is skipped if unset.
    'critical_keys' => [
        'ssh_recovery_key_path' => env('SECRET_VAULT_CRITICAL_SSH_KEY_PATH'),
        'pg_superuser' => env('SECRET_VAULT_CRITICAL_PG_SUPERUSER', 'postgres'),
        'pg_password' => env('SECRET_VAULT_CRITICAL_PG_PASSWORD'),
    ],

    // Restore drill (runs on the ISOLATED drill host, which alone holds the age
    // identity). Imports the newest db-dump into this scratch connection and
    // proves the canary decrypts. Connection must NOT be the live control-plane DB.
    'drill' => [
        // Only the isolated drill host sets this true (it alone has the identity).
        'enabled' => (bool) env('SECRET_VAULT_DRILL_ENABLED', false),
        'connection' => env('SECRET_VAULT_DRILL_CONNECTION', 'scratch'),
    ],
];
