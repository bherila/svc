<?php

namespace Tests\Feature\Files;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentWriteAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AttachmentMutationOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_current_manager_authority_preserves_dual_identity_while_default_agent_authority_refuses_it(): void
    {
        $actor = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Origin', 'slug' => 'synthetic-origin']);
        $membership = $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Origin Client', 'slug' => 'synthetic-origin-client']);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'viewer']);
        $access = app(AttachmentWriteAccess::class);
        $access->assertCurrentManager($actor, $workspace, AttachmentMutationOrigin::Native);
        try {
            $access->assertCurrentManager($actor, $workspace);
            $this->fail('Agent-origin authority must reject a dual identity.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $membership->forceFill(['role' => 'viewer'])->save();
        $this->expectException(HttpException::class);
        $access->assertCurrentManager($actor, $workspace, AttachmentMutationOrigin::Native);
    }
}
