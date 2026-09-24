<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Build logs are shown to customers. The deployer prints the upstream
 * vendor's name, registry host, and telemetry banner; none of that is
 * part of Dply Edge.
 */
final class EdgeLogCopy
{
    public static function forCustomer(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $endsWithNewline = str_ends_with($text, "\n");
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/anonymous telemetry|workers-sdk/i', $line) === 1) {
                continue;
            }

            if (preg_match('/password will be stored unencrypted|credential helper to remove this warning|credential-stores/i', $line) === 1) {
                continue;
            }

            $line = str_ireplace(
                ['registry.cloudflare.com', 'api.cloudflare.com', 'dash.cloudflare.com'],
                'dply-edge',
                $line,
            );
            $line = preg_replace('/(?<![A-Za-z0-9_@])cloudflare(?![A-Za-z0-9_])/i', 'Dply Edge', $line) ?? $line;
            $kept[] = $line;
        }

        $out = implode("\n", $kept);

        return $endsWithNewline && $out !== '' && ! str_ends_with($out, "\n") ? $out."\n" : $out;
    }
}
