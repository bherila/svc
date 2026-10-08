<?php

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAttachmentService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! (str_starts_with($input['connection']['database'], 'svc_probe_') || (($input['connection']['driver'] ?? '') === 'sqlite' && str_starts_with(basename($input['connection']['database']), 'svc-probe-')))
    || ! str_starts_with(basename($input['storage']), 'svc-generic-upload-probe-')) {
    exit(2);
}
config(['database.default' => 'generic_upload_probe', 'database.connections.generic_upload_probe' => $input['connection'],
    'svc.filesystem_disk' => 'generic_upload_probe', 'filesystems.disks.generic_upload_probe' => ['driver' => 'local', 'root' => $input['storage']],
    'agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true]);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$user = User::query()->findOrFail($input['user']);
$project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($input['project'])->firstOrFail();
DB::connection()->beforeExecuting(function (string $sql): void {
    if (str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'client_attachments')) {
        echo "promoted\n";
        flush();
        fgets(STDIN);
        throw new RuntimeException('Synthetic crash probe must be killed.');
    }
});
$file = UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic generic crash upload');
app(AgentAttachmentService::class)->upload($user, $workspace, 'synthetic-generic-upload-client', 'synthetic-crash-upload', 'project', $project->public_id, $file,
    ['expected_version' => AgentApiVersion::for($project), 'file' => $file]);
