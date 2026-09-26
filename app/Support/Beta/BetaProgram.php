<?php

declare(strict_types=1);

namespace App\Support\Beta;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One home for the closed-beta program's cross-cutting rules: the global cutover
 * date, whether beta is still open, the comp window stamped on a free managed
 * box.
 *
 * Beta is a single global program (not per-org windows): one `cutover_at` date
 * ends it for everyone. Before the cutover, beta orgs pay $0, trial/pause is
 * suppressed, the beta caps envelope applies, and the bundle flags are on.
 */
final class BetaProgram
{
    /**
     * The global beta cutover. Null = no end date set yet (beta open-ended).
     */
    public static function cutoverAt(): ?CarbonImmutable
    {
        $raw = config('subscription.standard.beta.cutover_at');

        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True while the beta program is still running (no cutover, or it's future).
     * After cutover, beta orgs fall back to the normal trial/plan lifecycle.
     */
    public static function isOpen(): bool
    {
        $cutover = self::cutoverAt();

        return $cutover === null || $cutover->isFuture();
    }

    /**
     * The `comped_until` value to stamp on a beta org's free managed box. The
     * box stays free until the global cutover; with no cutover set yet we leave
     * it null (a null stamp on a beta-managed box reads as comped-until-cutover,
     * see Server::isComped()). A backfill stamps the real date once it's known.
     */
    public static function compedUntil(): ?Carbon
    {
        $cutover = self::cutoverAt();

        return $cutover === null ? null : Carbon::instance($cutover->toDateTime());
    }
}
