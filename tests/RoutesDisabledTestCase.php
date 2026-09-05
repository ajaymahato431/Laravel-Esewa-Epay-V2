<?php

namespace AjayMahato\Esewa\Tests;

/**
 * The application boots with `esewa.routes.enabled` off, so route registration
 * can be asserted rather than assumed.
 */
abstract class RoutesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('esewa.routes.enabled', false);
    }
}
