<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Models\User;

final class DeployContractContext
{
    /**
     * @param  list<Site>  $linkedByoSites
     * @param  array<int, Site>  $linkedBackendSites  dply.yaml `site.<name>` backend refs
     */
    public function __construct(
        public Site $parent,
        public Site $preview,
        public ?EdgeDeployment $previewDeployment,
        public ?User $triggeredBy,
        public DeployContractPolicy $policy = new DeployContractPolicy,
        public ?Site $linkedCloudSite = null,
        public array $linkedByoSites = [],
        public array $linkedBackendSites = [],
    ) {}
}
