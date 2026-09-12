<?php

/*
|--------------------------------------------------------------------------
| Platform admin — Pennant flags (retired)
|--------------------------------------------------------------------------
|
| No product or global flags are registered. The admin flag pages stay
| routed so old bookmarks do not 500; they render an empty catalog.
|
*/

return [

    'product_lines' => [],

    'global_groups' => [],

    'legacy_default_group_redirects' => [
        'providers' => 'vm-servers',
        'workspace' => 'vm-servers',
        'surfaces' => 'platform',
        'launch' => 'platform',
    ],

    'legacy_org_tab_redirects' => [
        'providers' => 'vm-servers',
        'workspace' => 'vm-servers',
        'surfaces' => 'platform',
        'launch' => 'platform',
    ],

    'feature_preview_pairs' => [],

];
