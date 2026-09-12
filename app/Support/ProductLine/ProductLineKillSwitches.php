<?php

declare(strict_types=1);

namespace App\Support\ProductLine;

use App\Models\Site;

final class ProductLineKillSwitches
{
    public static function vmEnabled(): bool
    {
        return true;
    }

    public static function edgeDeliveryEnabled(): bool
    {
        return true;
    }

    public static function siteIsVmByo(Site $site): bool
    {
        if ($site->usesEdgeRuntime()) {
            return false;
        }

        if ($site->usesFunctionsRuntime()) {
            return false;
        }

        if ($site->usesContainerRuntime()) {
            return false;
        }

        if ($site->usesDockerRuntime()) {
            return false;
        }

        if ($site->usesKubernetesRuntime()) {
            return false;
        }

        return true;
    }

    public static function blocksVmSiteDeploy(Site $site): bool
    {
        return false;
    }

    public static function blocksVmServerCreate(): bool
    {
        return false;
    }

    public static function blocksEdgeDelivery(): bool
    {
        return false;
    }
}
