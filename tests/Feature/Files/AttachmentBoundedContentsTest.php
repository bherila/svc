<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Concerns\BuildsReceiptInvoices;
use Tests\TestCase;

/** Reading a stored file into memory for a document is bounded, verified, and never throws. */
class AttachmentBoundedContentsTest extends TestCase
{
    use BuildsGoldenInvoices;
    use BuildsReceiptInvoices;
    use RefreshDatabase;

    private ClientAttachment $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
        $this->fakeReceiptDisk();
        [$expense] = $this->goldenExpense($this->goldenInvoice(), 'Synthetic expense', 100, '2026-08-01');
        $this->file = $this->goldenReceipt($expense, 'receipt.txt', 'text/plain', 'synthetic receipt bytes');
    }

    private function service(): AttachmentStorageService
    {
        return app(AttachmentStorageService::class);
    }

    public function test_it_returns_exactly_the_stored_bytes_at_and_under_the_limit(): void
    {
        $this->assertSame('synthetic receipt bytes', $this->service()->boundedContents($this->file, 23));
        $this->assertSame('synthetic receipt bytes', $this->service()->boundedContents($this->file, 1000));
    }

    public function test_it_refuses_a_file_recorded_as_larger_than_the_limit_without_opening_it(): void
    {
        Storage::disk('svc_files')->delete($this->file->object_key);

        $this->assertNull($this->service()->boundedContents($this->file, 22));
    }

    public function test_it_refuses_a_file_that_is_longer_on_disk_than_its_record_says(): void
    {
        Storage::disk('svc_files')->put($this->file->object_key, str_repeat('x', 5000));
        DB::table('client_attachments')->where('id', $this->file->id)->update(['bytes' => 10]);

        $this->assertNull($this->service()->boundedContents($this->file->fresh() ?? $this->file, 10));
    }

    public function test_it_refuses_bytes_that_do_not_match_the_recorded_digest(): void
    {
        Storage::disk('svc_files')->put($this->file->object_key, 'tampered receipt byte');

        $this->assertNull($this->service()->boundedContents($this->file, 1000));
    }

    public function test_it_refuses_a_missing_object_and_a_file_that_is_not_available(): void
    {
        $missing = $this->file->replicate();
        $missing->forceFill(['object_key' => 'synthetic/receipts/does-not-exist']);
        $this->assertNull($this->service()->boundedContents($missing, 1000));

        $this->file->forceFill(['lifecycle_state' => ClientAttachment::STATE_DELETING]);
        $this->assertNull($this->service()->boundedContents($this->file, 1000));
    }
}
