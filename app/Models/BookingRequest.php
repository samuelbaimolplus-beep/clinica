<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime'];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function appointment()
    {
        return $this->hasOne(Appointment::class);
    }
}
