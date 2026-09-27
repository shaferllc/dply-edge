<?php

return [

    /*
    | Public documentation at /docs. Pages are docs/site/<slug>.md; the sidebar
    | (and the allow-list of servable slugs) is docs/site/nav.json. See
    | docs/site/STYLE.md for the writing contract.
    */
    'path' => env('DOCS_PATH', base_path('docs/site')),

];
