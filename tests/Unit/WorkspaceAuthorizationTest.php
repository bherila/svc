<?php

namespace Tests\Unit;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientCompany;
use App\Models\ClientStripeEvent;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_ownership_assertion_rejects_a_resource_from_another_workspace_as_not_found(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic First', 'slug' => 'synthetic-first']);
        $otherWorkspace = Workspace::query()->create(['name' => 'Synthetic Second', 'slug' => 'synthetic-second']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Synthetic Other Client',
            'slug' => 'synthetic-other-client',
        ]);
        $authorization = app(WorkspaceAuthorization::class);

        $this->assertInstanceOf(WorkspaceOwned::class, $company);
        $this->assertFalse($authorization->isOwnedBy($workspace, $company));

        $this->expectException(ModelNotFoundException::class);
        $authorization->assertOwnedBy($workspace, $company);
    }

    public function test_nullable_workspace_resource_is_never_treated_as_owned(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $event = ClientStripeEvent::query()->create([
            'workspace_id' => null,
            'stripe_event_id' => 'evt_synthetic_unscoped',
            'event_type' => 'payment_intent.succeeded',
            'payload_hash' => hash('sha256', 'synthetic-unscoped-event'),
            'status' => 'received',
        ]);

        $this->assertNull($event->workspaceId());
        $this->assertFalse(app(WorkspaceAuthorization::class)->isOwnedBy($workspace, $event));
    }
}
