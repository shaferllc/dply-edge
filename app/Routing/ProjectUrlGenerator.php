<?php

declare(strict_types=1);

namespace App\Routing;

use Illuminate\Routing\UrlGenerator;

/**
 * Workspace links are /projects/{site}/… Callers still pass the vestigial
 * server; drop it so it does not land on the query string.
 */
class ProjectUrlGenerator extends UrlGenerator
{
    public function toRoute($route, $parameters, $absolute)
    {
        $name = $route->getName();

        if (is_string($name) && ($name === 'sites.show' || $name === 'sites.preview-comments' || str_starts_with($name, 'sites.edge.'))) {
            unset($parameters['server']);
        }

        return parent::toRoute($route, $parameters, $absolute);
    }
}
