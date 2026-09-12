<?php

/*
|--------------------------------------------------------------------------
| Feature flags (Pennant) — retired
|--------------------------------------------------------------------------
|
| Product rollout flags are gone. Edge, status pages, billing, signups,
| delivery, deploy contract, and shadow replay are always on.
|
| FeatureServiceProvider still walks this file; keep it an empty map so
| nothing is registered. Use subscription quotas and DPLY_* ops config
| for limits and runtime behaviour — not Pennant.
|
*/

return [];
