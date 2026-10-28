<?php

namespace Tests\Concerns;

use App\Models\ClientAttachment;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Real browser-session API calls, including signed multipart preparation. */
trait CallsNativeFileApi
{
    /** @param array<string,mixed> $payload */
    private function uploadNativeFile(Workspace $workspace, string $type, string $id, array $payload): TestResponse
    {
        $this->nativeFileSession();
        try {
            $route = $type === 'expense' ? 'expense-receipts.upload-url' : 'attachments.upload-url';
            $parameters = $type === 'expense' ? [$workspace, $id] : [$workspace, $type, $id];
            $url = route('agent-api.v1.'.$route, $parameters);
            $prepared = $type === 'expense' ? $this->getJson($url) : $this->postJson($url);
            if ($prepared->getStatusCode() !== 200) {
                return $prepared;
            }
            $data = $prepared->json('data');

            return $this->withHeader('Idempotency-Key', 'synthetic-file-'.Str::uuid())->post(
                $data[$type === 'expense' ? 'upload_url' : 'url'],
                ['expected_version' => $data['expected_version'], ...$payload],
                ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data'],
            );
        } finally {
            Auth::shouldUse('web');
        }
    }

    /** @param array<string,mixed> $payload */
    private function uploadNativeCollaborationFile(Workspace $workspace, string $company, string $type, string $id, array $payload): TestResponse
    {
        $this->nativeFileSession();
        try {
            $prepared = $this->postJson(route('agent-api.v1.collaboration.attachments.upload-url', [$workspace, $company, $type, $id]));
            if ($prepared->getStatusCode() !== 200) {
                return $prepared;
            }
            $data = $prepared->json('data');

            return $this->withHeader('Idempotency-Key', 'synthetic-collaboration-file-'.Str::uuid())->post($data['url'],
                ['expected_version' => $data['expected_version'], ...$payload],
                ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data']);
        } finally {
            Auth::shouldUse('web');
        }
    }

    /** @param array<string,mixed> $payload */
    private function deleteNativeFile(Workspace $workspace, string $id, array $payload = []): TestResponse
    {
        $file = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->first();
        $body = ['expected_version' => $file === null ? str_repeat('0', 64) : AgentApiVersion::for($file), 'confirm' => true];
        if ($file?->record_type === 'expense') {
            $expense = ClientExpense::query()->where('workspace_id', $workspace->id)->where('public_id', $file->record_public_id)->first();
            $body['expected_parent_version'] = $expense === null ? str_repeat('0', 64) : AgentApiVersion::for($expense);
            $url = route('agent-api.v1.expense-receipts.destroy', [$workspace, $file->record_public_id, $id]);
        } else {
            $url = route('agent-api.v1.attachments.destroy', [$workspace, $id]);
        }

        return $this->nativeFileJson('DELETE', $url, [...$body, ...$payload]);
    }

    /** @param array<string,mixed> $body */
    private function nativeFileJson(string $method, string $url, array $body): TestResponse
    {
        $this->nativeFileSession();
        try {
            return $this->withHeader('Idempotency-Key', 'synthetic-native-file-'.Str::uuid())->json($method, $url, $body);
        } finally {
            Auth::shouldUse('web');
        }
    }

    private function nativeFileSession(): void
    {
        $actor = Auth::guard('web')->user();
        $this->assertInstanceOf(User::class, $actor);
        $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }

    private function forgetNativeFileSession(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
