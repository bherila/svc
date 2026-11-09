<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Support\Time\DurationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/** The casts and defaults on `users` that the rest of the app reads as typed values. */
#[CoversClass(User::class)]
class UserCastsTest extends TestCase
{
    public function test_email_verified_at_is_a_date(): void
    {
        $user = new User;
        $user->setRawAttributes(['email_verified_at' => '2026-01-02 03:04:05']);

        $this->assertInstanceOf(\DateTimeInterface::class, $user->email_verified_at);
        $this->assertSame('2026-01-02', $user->email_verified_at->toDateString());
    }

    public function test_password_is_hashed_when_assigned(): void
    {
        $user = new User;
        $user->password = 'synthetic-password';

        $this->assertNotSame('synthetic-password', $user->getAttributes()['password']);
    }

    public function test_duration_style_defaults_to_decimal_and_casts_to_the_enum(): void
    {
        $this->assertSame(DurationStyle::Decimal, (new User)->duration_style);

        $user = new User;
        $user->setRawAttributes(['duration_style' => 'clock']);

        $this->assertSame(DurationStyle::Clock, $user->duration_style);
    }

    public function test_duration_style_is_mass_assignable(): void
    {
        $this->assertSame(DurationStyle::Clock, (new User(['duration_style' => 'clock']))->duration_style);
    }
}
