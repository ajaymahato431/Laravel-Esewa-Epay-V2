<?php

use AjayMahato\Esewa\Support\RedirectGuard;

beforeEach(function () {
    config()->set('app.url', 'https://shop.test');
    config()->set('esewa.redirect.allowed_hosts', ['partner.example', '*.cdn.example']);
});

it('allows targets that stay on this application', function (string $target) {
    expect(RedirectGuard::fromConfig()->safe($target))->toBe($target);
})->with([
    'root' => '/',
    'path' => '/orders/42',
    'path with query' => '/orders/42?status=paid',
    'own host' => 'https://shop.test/orders/42',
    'allow-listed host' => 'https://partner.example/thanks',
    'allow-listed wildcard' => 'https://assets.cdn.example/thanks',
]);

it('refuses to send customers off this application', function (string $target) {
    expect(RedirectGuard::fromConfig()->safe($target))->toBeNull();
})->with([
    // The open redirect this guard exists to close: the callback route is
    // public, so an unchecked redirect parameter would bounce a shopper to an
    // attacker's page at the exact moment they most trust the merchant domain.
    'other host' => 'https://evil.example/phish',
    // Browsers treat a protocol-relative URL as absolute.
    'protocol relative' => '//evil.example/phish',
    'javascript' => 'javascript:alert(1)',
    'data uri' => 'data:text/html,<script>alert(1)</script>',
    'backslash trick' => 'https://shop.test\\@evil.example',
    'subdomain of allowed host is not the host' => 'https://shop.test.evil.example',
    'wildcard parent is not covered' => 'https://cdn.example/thanks',
]);

it('treats an empty target as no target', function () {
    $guard = RedirectGuard::fromConfig();

    expect($guard->safe(null))->toBeNull()
        ->and($guard->safe(''))->toBeNull()
        ->and($guard->safe('   '))->toBeNull();
});

it('allows the application host regardless of scheme case', function () {
    expect(RedirectGuard::fromConfig()->safe('https://SHOP.TEST/orders/1'))
        ->toBe('https://SHOP.TEST/orders/1');
});
