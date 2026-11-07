<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalSlot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public static function identify(int $doctor, string $date, string $start, string $end): int
    {
        return static::firstOrCreate(['doctor_id' => $doctor, 'date' => $date,
            'start_time' => strlen($start) === 5 ? $start.':00' : $start,
            'end_time' => strlen($end) === 5 ? $end.':00' : $end])->id;
    }
}
