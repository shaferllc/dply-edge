<?php

/**
 * dply CLI defaults — device-flow scopes and token naming.
 */
return [
    'token_name' => 'dply CLI',

    /*
    |--------------------------------------------------------------------------
    | CLI distribution (hosted from this app until @dply/cli is on npm)
    |--------------------------------------------------------------------------
    |
    | install.sh and dply-cli.tgz are served at /cli/install.sh and
    | /cli/dply-cli.tgz. Default method is tarball — npm is opt-in only when
    | DPLY_CLI_NPM_PUBLISHED=true after you publish @dply/cli.
    |
    */
    'install_method' => env('DPLY_CLI_INSTALL_METHOD', 'tarball'),
    'npm_published' => filter_var(env('DPLY_CLI_NPM_PUBLISHED', false), FILTER_VALIDATE_BOOLEAN),
    'npm_package' => env('DPLY_CLI_NPM_PACKAGE', '@dply/cli'),

    /*
    |--------------------------------------------------------------------------
    | Fallback API origin for offline / non-HTTP pack builds
    |--------------------------------------------------------------------------
    |
    | Live /cli/install.sh and /cli/dply-cli.tgz bake the *request* origin
    | (the host that was curled). This config is only used when building a
    | package without a request (tests, artisan). Override with
    | DPLY_CLI_DEFAULT_BASE_URL; otherwise APP_URL.
    |
    */
    'default_base_url' => rtrim((string) env('DPLY_CLI_DEFAULT_BASE_URL', env('APP_URL', 'https://dply.io')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Default scopes offered during `dply login` device approval
    |--------------------------------------------------------------------------
    |
    | Use ['*'] to offer the full API-token catalog
    | (config/api_token_permissions.php → categories). A concrete list
    | limits the offer to those abilities only.
    |
    */
    'device_flow_abilities' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Role caps — intersected with device_flow_abilities at approval time
    |--------------------------------------------------------------------------
    |
    | Admin/owner: ['*'] = every catalog ability. Deployer/member stay
    | narrower. Labels fall back to api_token_permissions category copy
    | when a key is missing from device_flow_scope_labels.
    |
    */
    'device_flow_role_caps' => [
        'admin' => ['*'],
        // Must stay a subset of api_token_permissions.deployer_api_allowlist,
        // or the device flow mints scopes ApiToken::allows() then refuses.
        'deployer' => [
            'account.read',
            'account.write',
            'edge.read',
            'edge.deploy',
            'edge.env.read',
            'servers.read',
            'sites.read',
        ],
        'member' => [
            'account.read',
            'account.write',
            'billing.read',
            'edge.read',
            'edge.env.read',
            'servers.read',
            'sites.read',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional richer labels (overrides category labels from API tokens)
    |--------------------------------------------------------------------------
    */
    'device_flow_scope_labels' => [
        'account.read' => 'Read your profile, organizations, and CLI sessions',
        'account.write' => 'Revoke CLI sessions (this machine or others)',
        'billing.read' => 'View plan estimates, breakdown, and invoices',
        'edge.read' => 'Read Edge sites, deployments, and logs',
        'edge.deploy' => 'Deploy, roll back, and promote Edge previews',
        'edge.write' => 'Manage Edge custom domains and cache',
        'edge.env.read' => 'Read Edge environment variable keys',
        'edge.env.write' => 'Create and update Edge environment variables',
        'servers.read' => 'List site hosts (for AI assistants)',
        'sites.read' => 'List and read sites (for AI assistants)',
        'sites.deploy' => 'Trigger BYO site deploys',
        'sites.write' => 'Update BYO site settings and environment variables',
        'sites.delete' => 'Delete BYO sites',
        'commands.run' => 'Run ad-hoc commands on servers over SSH',
        'insights.read' => 'Read server insight findings (health checks)',
        'network.read' => 'Read server firewall rules and templates',
        'network.write' => 'Apply firewall rules and templates on servers',
        'network.delete' => 'Delete firewall rules',
        'system_users.read' => 'List Linux system users on servers',
        'system_users.write' => 'Create and update system users',
        'system_users.delete' => 'Remove system users from servers',
        'projects.read' => 'List projects, health, members, and deploy history',
        'projects.write' => 'Create and update projects, attach servers/sites, variables, runbooks',
        'projects.deploy' => 'Queue project-wide deploys across grouped sites',
        'projects.delete' => 'Delete projects (org admin)',
        'database.read' => 'List and inspect site databases',
        'database.write' => 'Create and update site databases',
        'database.delete' => 'Delete site databases',
        'daemons.read' => 'List Supervisor / daemon programs',
        'daemons.write' => 'Create and update daemon programs',
        'daemons.delete' => 'Delete daemon programs',
        'cronjobs.read' => 'List cron jobs',
        'cronjobs.write' => 'Create and update cron jobs',
        'cronjobs.delete' => 'Delete cron jobs',
        'ssh_keys.read' => 'List SSH keys on servers',
        'ssh_keys.write' => 'Add SSH keys to servers',
        'ssh_keys.delete' => 'Remove SSH keys from servers',
        'certificates.read' => 'List SSL certificates',
        'certificates.write' => 'Issue and renew SSL certificates',
        'certificates.delete' => 'Delete SSL certificates',
        'auth_users.read' => 'List HTTP basic-auth users',
        'auth_users.write' => 'Create and update HTTP basic-auth users',
        'auth_users.delete' => 'Delete HTTP basic-auth users',
        'redirects.read' => 'List site redirects',
        'redirects.write' => 'Create and update site redirects',
        'redirects.delete' => 'Delete site redirects',
        'aliases.read' => 'List domain aliases',
        'aliases.write' => 'Create and update domain aliases',
        'aliases.delete' => 'Delete domain aliases',
        'email.send' => 'Send email via the API',
        'imports.read' => 'Read import and migration status',
    ],
];
