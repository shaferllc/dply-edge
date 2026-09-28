<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Head\Enums\ImageType;
use Laravel\Head\Enums\OgType;
use Laravel\Head\Enums\TwitterCard;
use Laravel\Head\ErrorPages;
use Laravel\Head\Facades\Head;
use Laravel\Head\HeadBuilder;

/**
 * Site-wide <head> defaults and error-page metadata (laravel/head). Every
 * layout renders them with @head; pages add route metadata (routes/*.php
 * ->withHead) or runtime Head::title() calls on top.
 *
 * The brand is the literal "dply", not config('app.name') — that is
 * "dply-edge" locally.
 */
class HeadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Head::defaults(fn (HeadBuilder $head) => $head
            ->title('dply', suffix: ' · dply')
            ->description('dply deploys static sites, server-rendered apps and PHP, Rails or Node servers from a Git push, with managed Postgres, MySQL, Valkey and queue workers alongside. One bill, no servers to run.')
            ->canonical()
            ->searchableByRobots()
            ->og(siteName: 'dply', type: OgType::Website)
            ->ogImage(asset('images/og/dply-og.png'), alt: 'dply — edge hosting for static, SSG, and SSR sites', width: 1200, height: 630, type: ImageType::Png)
            ->twitter(card: TwitterCard::SummaryWithLargeImage)
            // Every page is statically dark (partials/theme-head paints
            // #0b0d0a before CSS loads), so one theme colour, not a light/dark pair.
            ->themeColor('#0b0d0a')
            ->favicon(asset('favicon.ico'), sizes: '32x32')
            ->favicon(asset('favicon.svg'), type: ImageType::Svg)
            ->favicon(asset('favicon-32x32.png'), type: ImageType::Png, sizes: '32x32')
            ->favicon(asset('favicon-16x16.png'), type: ImageType::Png, sizes: '16x16')
            ->appleTouchIcon(asset('apple-touch-icon.png'))
            ->manifest(asset('site.webmanifest')));

        Head::errors(function (ErrorPages $errors): void {
            $errors->defaults(robots: 'noindex, nofollow');

            foreach ([
                400 => 'Bad request',
                401 => 'Unauthorized',
                403 => 'Access forbidden',
                404 => 'Page not found',
                419 => 'Page expired',
                429 => 'Too many requests',
                500 => 'Server error',
                502 => 'Bad gateway',
                503 => 'Service unavailable',
                504 => 'Gateway timeout',
            ] as $status => $title) {
                $errors->status($status, title: __($title));
            }
        });
    }
}
