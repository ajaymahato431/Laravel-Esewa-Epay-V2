<?php

namespace AjayMahato\Esewa\Support;

use Illuminate\Support\Facades\Log;

/**
 * Decides whether a redirect target is safe to send a customer to.
 *
 * The callback and relay routes are reachable by anyone, and both read a
 * `redirect` parameter. Without this check the package is an open redirect:
 * `/esewa/callback?redirect=https://evil.example` would bounce a shopper
 * straight off the merchant's domain immediately after paying - exactly the
 * moment they are most likely to trust what they see and re-enter credentials.
 */
final class RedirectGuard
{
    /**
     * @param array<int, string> $allowedHosts extra hosts to permit, beyond the application's own
     */
    public function __construct(
        private readonly array $allowedHosts = [],
        private readonly ?string $appUrl = null,
    ) {}

    public static function fromConfig(): self
    {
        $allowed = config('esewa.redirect.allowed_hosts', []);

        return new self(
            is_array($allowed) ? array_values(array_filter(array_map('strval', $allowed))) : [],
            is_string($appUrl = config('app.url')) ? $appUrl : null,
        );
    }

    /**
     * Return the target if it is safe, or null if it is not.
     *
     * Relative paths are always safe. Absolute URLs must point at the
     * application's own host or one the developer explicitly allow-listed.
     */
    public function safe(?string $target): ?string
    {
        $target = is_string($target) ? trim($target) : null;

        if ($target === null || $target === '') {
            return null;
        }

        if ($this->isRelative($target)) {
            return $target;
        }

        $host = parse_url($target, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $this->reject($target, 'it is neither a relative path nor an absolute http(s) URL');
        }

        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->reject($target, "the scheme \"{$scheme}\" is not http or https");
        }

        if ($this->isAllowedHost($host)) {
            return $target;
        }

        return $this->reject($target, "the host \"{$host}\" is not this application and is not listed in esewa.redirect.allowed_hosts");
    }

    /**
     * A path relative to the application root.
     *
     * `//evil.example` is deliberately excluded: browsers treat a
     * protocol-relative URL as absolute, so it would leave the site.
     */
    private function isRelative(string $target): bool
    {
        if (str_starts_with($target, '//')) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            return true;
        }

        // Anything with a scheme (or a scheme-like prefix such as javascript:)
        // is not a relative path.
        return ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)
            && ! str_contains($target, '\\');
    }

    private function isAllowedHost(string $host): bool
    {
        $host = strtolower($host);

        $hosts = $this->allowedHosts;

        if (is_string($this->appUrl) && $this->appUrl !== '') {
            $appHost = parse_url($this->appUrl, PHP_URL_HOST);

            if (is_string($appHost) && $appHost !== '') {
                $hosts[] = $appHost;
            }
        }

        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            // Bare hosts are compared directly; a full URL is reduced to its host.
            if (str_contains($allowed, '://')) {
                $allowed = (string) parse_url($allowed, PHP_URL_HOST);
            }

            if ($allowed === $host) {
                return true;
            }

            // "*.example.com" permits any subdomain, but not example.com itself.
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $target, string $reason): null
    {
        Log::warning("[esewa] Refused to redirect to \"{$target}\" because {$reason}.");

        return null;
    }
}
