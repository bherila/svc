<?php

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Billing\InvoiceLineDetail;

// Read committed acceptance results from a fresh process and database connection.
$app = require __DIR__.'/bootstrap.php';
try {
    $fixture = json_decode(file_get_contents(getenv('SVC_LAYOUT_RUNTIME').'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $invoice = ClientInvoice::findOrFail($fixture['draft_id']);
    $entry = ClientTimeEntry::where('workspace_id', $invoice->workspace_id)->findOrFail($fixture['correction_id']);
    $companion = ClientTimeEntry::query()->where('workspace_id', $invoice->workspace_id)->findOrFail($fixture['companion_id']);
    $companionLine = $invoice->lines()->where('workspace_id', $invoice->workspace_id)
        ->whereHas('timeEntries', fn ($entries) => $entries
            ->where('client_time_entries.workspace_id', $invoice->workspace_id)
            ->where('client_invoice_line_time_entries.workspace_id', $invoice->workspace_id)
            ->whereKey($companion->id))->sole();
    if ($invoice->total_amount !== 24500 || $invoice->paid_amount !== 24500 || $invoice->balance_amount !== 0 || $entry->minutes !== 90 || $entry->invoiceLines()->count() !== 1 || $invoice->lines()->count() !== 3 || $companionLine->total_amount !== 6000 || $companionLine->description !== InvoiceLineDetail::clientWording($companion)) {
        throw new RuntimeException('Draft time acceptance totals or allocation mismatch.');
    }
    foreach ($fixture['preserved_lines'] as $attributes) {
        if ($invoice->lines()->where('workspace_id', $invoice->workspace_id)->whereKey($attributes['id'])->sole()->getAttributes() !== $attributes) {
            throw new RuntimeException('Existing invoice line changed during time addition.');
        }
    }
    echo "Draft time browser acceptance: correction, same-invoice addition, issuance and payment passed; existing lines preserved.\n";

} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
