<?php

use Illuminate\Foundation\Console\AboutCommand;

AboutCommand::add('POSO', fn () => [
    'Tier' => 'Second tier operations',
    'Main tier' => 'Ecopa',
    'Accounting tier' => 'Akunta',
]);

