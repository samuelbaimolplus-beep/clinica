<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['qr_image'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime'];
    }
}
