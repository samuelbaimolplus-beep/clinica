<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Doctor extends Model
{
    protected $guarded = ['id'];

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class);
    }
}
