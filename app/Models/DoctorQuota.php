<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorQuota extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['presential_percent' => 'decimal:2', 'online_percent' => 'decimal:2'];
    }
}
