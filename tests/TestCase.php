<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests write freely (posts, settings, revisions), so they must never
     * reach the site's real content: everything goes to the throwaway test
     * database, and a test refuses to start if anything still points at
     * content/ files or a real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('booklet.default_driver', 'database');
        $app['config']->set('booklet.database.connection', null);
        foreach (array_keys((array) $app['config']->get('booklet.drivers', [])) as $repository) {
            $app['config']->set("booklet.drivers.{$repository}", 'database');
        }
        $app['config']->set('booklet.paths.content', storage_path('framework/testing/content'));
        $app['config']->set('booklet.paths.storage', storage_path('framework/testing/booklet'));
        $app['config']->set('booklet.paths.private', storage_path('framework/testing/private'));

        // Anything already opened while the app started is opened again with the test settings.
        if ($app->resolved(\App\Booklet\BookletManager::class)) {
            $app->make(\App\Booklet\BookletManager::class)->flush();
        }
        $app->forgetInstance(\App\Booklet\Site::class);

        $connection = $app['config']->get('database.default');
        if ($app['config']->get("database.connections.{$connection}.database") !== ':memory:') {
            throw new \RuntimeException('Tests must run on the in-memory test database (phpunit.xml sets it). They were about to use '.$connection.', which may be the real site.');
        }

        return $app;
    }
}
