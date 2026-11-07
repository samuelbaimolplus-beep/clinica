<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgendaAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['released_at' => 'immutable_datetime'];
    }
}
