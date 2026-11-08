<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Patient extends Model
{
    protected $guarded = ['id'];

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'patient_id');
    }
}
