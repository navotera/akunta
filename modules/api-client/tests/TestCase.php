<?php

declare(strict_types=1);

namespace Akunta\ApiClient\Tests;

use Akunta\ApiClient\ApiClientServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ApiClientServiceProvider::class];
    }
}
