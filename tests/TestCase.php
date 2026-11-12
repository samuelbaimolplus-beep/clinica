<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** Fixture de una cuenta que ya completó la activación; no afecta al alta real. */
    protected function verifiedAccount(array $attributes): User
    {
        $user = new User($attributes + ['birth_date' => '1990-01-01', 'terms_accepted_at' => now(), 'terms_version' => config('clinic.terms_version')]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
