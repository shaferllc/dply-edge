<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteUptimeMonitor;

class SiteUptimeCheckUrlResolver
{
    /**
     * Full URL for an HTTP GET check (scheme + host + monitor path).
     */
    public function resolveFullUrl(Site $site, SiteUptimeMonitor $monitor): ?string
    {
        $base = $this->resolveBaseUrl($site, $monitor);
        if ($base === null) {
            return null;
        }

        $path = $monitor->normalizedPath();
        if ($path === '') {
            return $base;
        }

        return rtrim($base, '/').$path;
    }

    /**
     * Best-effort public URL for the site (no path). Primary domain →
     * serverless function host → preview/testing → runtime publication.
     *
     * When a monitor is passed, the scheme follows its check type (http vs
     * https/ssl) instead of always preferring https.
     */
    public function resolveBaseUrl(Site $site, ?SiteUptimeMonitor $monitor = null): ?string
    {
        $site->loadMissing('domains', 'previewDomains');

        $primary = $site->primaryDomain();
        if ($primary && ($primary->hostname) && trim($primary->hostname) !== '') {
            $host = strtolower(trim($primary->hostname));

            return $this->applyScheme('https://'.$host, $monitor);
        }

        $testing = $site->testingHostname();
        if ($testing !== '') {
            return $this->applyScheme('https://'.strtolower(trim($testing)), $monitor);
        }

        $target = $site->runtimeTarget();
        $publication = is_array($target['publication'] ?? null) ? $target['publication'] : [];
        $url = $publication['url'] ?? null;
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->applyScheme(rtrim($url, '/'), $monitor);
        }

        $hostname = isset($publication['hostname']) && is_string($publication['hostname'])
            ? trim($publication['hostname'])
            : '';
        if ($hostname !== '') {
            return $this->applyScheme('http://'.strtolower($hostname), $monitor);
        }

        return null;
    }

    /**
     * Rewrite the URL's scheme to match the monitor's check type.
     * HTTP stays http; HTTPS and SSL stay https. No monitor → leave as built.
     */
    private function applyScheme(string $url, ?SiteUptimeMonitor $monitor): string
    {
        $scheme = $this->schemeFor($monitor);
        if ($scheme === null) {
            return rtrim($url, '/');
        }

        $url = rtrim($url, '/');
        if (preg_match('#^https?://#i', $url) === 1) {
            return (string) preg_replace('#^https?://#i', $scheme.'://', $url, 1);
        }

        return $scheme.'://'.$url;
    }

    private function schemeFor(?SiteUptimeMonitor $monitor): ?string
    {
        if ($monitor === null) {
            return null;
        }

        return ($monitor->check_type ?? SiteUptimeMonitor::CHECK_HTTP) === SiteUptimeMonitor::CHECK_HTTP
            ? 'http'
            : 'https';
    }
}
