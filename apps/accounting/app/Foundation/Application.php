<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Foundation\Application as LaravelApplication;

class Application extends LaravelApplication
{
    public function __construct(?string $basePath = null)
    {
        parent::__construct($basePath);

        // Akunta owns its complete application configuration. Prevent Laravel
        // 11's vendor config merge from evaluating the deprecated
        // PDO::MYSQL_ATTR_SSL_CA constant on PHP 8.5.
        $this->dontMergeFrameworkConfiguration();
    }
}
