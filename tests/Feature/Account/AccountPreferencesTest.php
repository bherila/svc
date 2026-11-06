<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Support\Time\DurationStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_style_defaults_to_decimal(): void
    {
        $user = User::factory()->create();

        $this->assertSame(DurationStyle::Decimal, $user->fresh()->duration_style);
    }

    public function test_a_person_can_choose_clock_and_it_is_shared_with_the_frontend(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/account/preferences', ['duration_style' => 'clock'])
            ->assertOk()
            ->assertJsonPath('data.duration_style', 'clock');

        $this->assertSame(DurationStyle::Clock, $user->fresh()->duration_style);

        $this->actingAs($user->fresh())
            ->get('/app')
            ->assertInertia(fn ($page) => $page->where('auth.user.duration_style', 'clock'));
    }

    public function test_an_unknown_style_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/account/preferences', ['duration_style' => 'sexagesimal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_style');

        $this->assertSame(DurationStyle::Decimal, $user->fresh()->duration_style);
    }

    public function test_it_writes_only_the_callers_own_row(): void
    {
        $caller = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($caller)
            ->patchJson('/account/preferences', ['duration_style' => 'clock', 'id' => $other->id, 'user' => $other->public_id])
            ->assertOk();

        $this->assertSame(DurationStyle::Decimal, $other->fresh()->duration_style);
    }

    public function test_guests_cannot_write_it(): void
    {
        $this->patchJson('/account/preferences', ['duration_style' => 'clock'])->assertUnauthorized();
    }
}
