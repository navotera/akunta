<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcopaConfigIntegration extends Model
{
    protected $table = 'ecopa_config_integration';

    public $timestamps = false;

    protected $fillable = ['name', 'value'];
}
