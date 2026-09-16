<?php

declare(strict_types=1);

namespace App\Support\Livewire;

use Livewire\Features\SupportPageComponents\HandlesPageComponents;
use Livewire\Features\SupportPageComponents\PageComponentConfig;
use Livewire\Features\SupportPageComponents\SupportPageComponents;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mount a full-page Livewire component and wrap it in its #[Layout] the same way
 * {@see HandlesPageComponents} does for
 * Route::livewire — used when a controller must resolve params (e.g. Server from
 * Site) before mounting.
 */
final class RendersLivewirePage
{
    /**
     * @param  class-string  $component
     * @param  array<string, mixed>  $params
     */
    public static function render(string $component, array $params): Response
    {
        $html = null;

        $layoutConfig = SupportPageComponents::interceptTheRenderOfTheComponentAndRetreiveTheLayoutConfiguration(
            function () use (&$html, $component, $params): void {
                $html = Livewire::mount($component, $params);
            },
        );

        $layoutConfig = $layoutConfig ?: new PageComponentConfig;

        $layoutConfig->normalizeViewNameAndParamsForBladeComponents();

        $response = response(SupportPageComponents::renderContentsIntoLayout($html, $layoutConfig));

        if (is_callable($layoutConfig->response)) {
            call_user_func($layoutConfig->response, $response);
        }

        return $response;
    }
}
