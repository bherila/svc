<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Identity models name themselves by public id and never serialize internal keys. */
class WorkspaceIdentityKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_models_expose_public_ids_without_serializing_internal_keys(): void
    {
        $user = User::factory()->create();
        $clientUser = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Identity', 'slug' => 'synthetic-identity']);
        $membership = $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'admin']);
        $workspace->users()->attach($clientUser, ['role' => 'member']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Identity Client',
            'slug' => 'synthetic-identity-client',
        ]);
        $company->portalUsers()->attach($clientUser, ['role' => 'client']);

        $this->assertTrue(Str::isUuid($user->public_id));
        $this->assertTrue(Str::isUuid($membership->public_id));
        $this->assertTrue(Str::isUuid((string) $workspace->users()->whereKey($clientUser->id)->firstOrFail()->pivot->public_id));
        $this->assertTrue(Str::isUuid((string) $company->portalUsers()->firstOrFail()->pivot->public_id));
        $this->assertArrayNotHasKey('id', $user->toArray());
        $this->assertArrayNotHasKey('id', $membership->toArray());
        $this->assertArrayNotHasKey('workspace_id', $membership->toArray());
        $this->assertArrayNotHasKey('user_id', $membership->toArray());
    }
}
