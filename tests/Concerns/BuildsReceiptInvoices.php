<?php

namespace Tests\Concerns;

use App\Models\ClientAttachment;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic expenses and receipt files for the Appendix B tests.
 *
 * Needs {@see BuildsGoldenInvoices}. Files are written to a faked private disk
 * and images are generated in the test; nothing is read from or written to a
 * real store.
 */
trait BuildsReceiptInvoices
{
    protected function fakeReceiptDisk(): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
    }

    /** A real PNG of the given size, filled with one colour. */
    protected function pngBytes(int $width = 60, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('GD could not create a test image.');
        }
        imagefill($image, 0, 0, 0x3366CC);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A real JPEG of the given size. */
    protected function jpegBytes(int $width = 60, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('GD could not create a test image.');
        }
        imagefill($image, 0, 0, 0xCC6633);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * An expense line on the invoice and the expense it bills, claimed by it.
     *
     * @param  array<string, mixed>  $expense
     * @return array{0: ClientExpense, 1: ClientInvoiceLine}
     */
    protected function goldenExpense(ClientInvoice $invoice, string $description, int $amount, string $spentOn, array $expense = [], int $sort = 10): array
    {
        $line = $this->goldenLine($invoice, [
            'type' => 'expense', 'description' => $description, 'unit_amount' => $amount, 'total_amount' => $amount,
            'line_date' => $spentOn, 'sort_order' => $sort,
        ]);
        $record = ClientExpense::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'created_by_user_id' => $this->member->id, 'spent_on' => $spentOn, 'amount' => $amount, 'currency' => 'USD',
            'description' => $description, 'status' => 'invoiced', 'client_invoice_line_id' => $line->id,
            ...$expense,
        ]);

        return [$record, $line];
    }

    /**
     * A receipt filed on an expense, with its bytes on the faked disk.
     *
     * @param  array<string, mixed>  $attributes  Overrides for the attachment row.
     */
    protected function goldenReceipt(ClientExpense $expense, string $filename, string $mediaType, string $contents, array $attributes = [], bool $visibleToClient = true, bool $store = true): ClientAttachment
    {
        $key = 'synthetic/receipts/'.Str::uuid()->toString();
        if ($store) {
            Storage::disk('svc_files')->put($key, $contents);
        }
        $attachment = ClientAttachment::query()->create([
            'workspace_id' => $expense->workspace_id, 'record_type' => 'expense', 'record_public_id' => $expense->public_id,
            'object_key' => $key, 'original_filename' => $filename, 'media_type' => $mediaType,
            'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents), 'uploader_id' => $this->member->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
            ...$attributes,
        ]);
        // The flag is immutable through the model and an expense receipt is
        // created private, so a shared one is set at the table, as the sharing
        // action does.
        if ($visibleToClient) {
            DB::table('client_attachments')->where('id', $attachment->id)->update(['is_visible_to_client' => true]);
        }

        return $attachment->fresh() ?? $attachment;
    }
}
