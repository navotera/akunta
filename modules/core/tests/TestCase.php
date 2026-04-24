<?php

declare(strict_types=1);

namespace Akunta\Core\Tests;

use Akunta\Core\CoreServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CoreServiceProvider::class];
    }
}
