<?php

use App\Models\ClientTimeEntry;

$app = require __DIR__.'/bootstrap.php';
$fixture = json_decode(file_get_contents(getenv('SVC_LAYOUT_RUNTIME').'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$entry = ClientTimeEntry::query()->where('workspace_id', $fixture['workspace_id'])
    ->where('description', 'Synthetic explicit rate acceptance')->sole();
if ($entry->client_project_id !== $fixture['log_project_id'] || $entry->billing_rate_amount !== 12345
    || $entry->currency !== 'USD' || $entry->status !== 'approved') {
    throw new RuntimeException('Time logging lost the selected project or explicit rate.');
}
echo "Time logging browser acceptance: selected project and explicit rate preserved through approval.\n";
