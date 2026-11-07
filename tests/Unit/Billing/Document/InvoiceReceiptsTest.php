<?php

namespace Tests\Unit\Billing\Document;

use App\Services\Files\AttachmentReader;
use App\Support\Billing\Document\InvoiceReceiptEntry;
use App\Support\Billing\Document\InvoiceReceiptFile;
use App\Support\Billing\Document\InvoiceReceipts;
use App\Support\Billing\Document\ReceiptDetail;
use App\Support\Billing\Document\ReceiptPlacement;
use App\Support\Billing\InvoiceLineDetail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The pure decisions behind Appendix B: who is told, what is drawn, and at what size. */
#[CoversClass(InvoiceReceipts::class)]
#[CoversClass(ReceiptPlacement::class)]
#[CoversClass(ReceiptDetail::class)]
#[CoversClass(InvoiceReceiptFile::class)]
#[CoversClass(InvoiceReceiptEntry::class)]
final class InvoiceReceiptsTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function visibility(): iterable
    {
        yield 'operator, shared' => [InvoiceLineDetail::OPERATOR, true, true, true];
        yield 'operator, private' => [InvoiceLineDetail::OPERATOR, false, false, true];
        yield 'operator, portal closed' => [InvoiceLineDetail::OPERATOR, true, false, true];
        yield 'client, shared and portal open' => [InvoiceLineDetail::CLIENT, true, true, true];
        yield 'client, private' => [InvoiceLineDetail::CLIENT, false, true, false];
        yield 'client, shared but the portal never serves expenses' => [InvoiceLineDetail::CLIENT, true, false, false];
        yield 'client, private and closed' => [InvoiceLineDetail::CLIENT, false, false, false];
    }

    #[DataProvider('visibility')]
    public function test_a_client_is_told_of_a_receipt_only_where_the_file_is_shared_and_the_portal_serves_expense_receipts(string $audience, bool $flagged, bool $portalReads, bool $expected): void
    {
        $this->assertSame($expected, InvoiceReceipts::visibleTo($audience, $flagged, $portalReads));
    }

    public function test_the_attachment_policy_does_not_serve_expense_receipts_to_the_portal_today(): void
    {
        // The seam Appendix B follows. If this starts to hold, a client document
        // begins to carry shared receipts and the golden tests say what changes.
        $this->assertFalse(AttachmentReader::portalReadsParentType('expense'));
        $this->assertFalse(AttachmentReader::portalReadsParentType('company'));
        foreach (['project', 'task', 'proposal', 'agreement', 'invoice'] as $type) {
            $this->assertTrue(AttachmentReader::portalReadsParentType($type), $type);
        }
    }

    public function test_only_an_operator_is_given_an_address_to_open_a_receipt_at(): void
    {
        $this->assertSame('/workspaces/w/attachments/a', InvoiceReceipts::hrefFor(InvoiceLineDetail::OPERATOR, '/workspaces/w/attachments/a'));
        $this->assertNull(InvoiceReceipts::hrefFor(InvoiceLineDetail::CLIENT, '/workspaces/w/attachments/a'));
    }

    public function test_only_jpeg_and_png_are_drawable_whatever_the_case_of_the_stored_type(): void
    {
        foreach (['image/jpeg', 'image/png', 'IMAGE/PNG'] as $type) {
            $this->assertTrue(InvoiceReceipts::isDrawableType($type), $type);
        }
        foreach (['image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'text/plain', ''] as $type) {
            $this->assertFalse(InvoiceReceipts::isDrawableType($type), $type);
        }
    }

    public function test_stored_metadata_places_a_file_before_any_is_read(): void
    {
        $limit = InvoiceReceipts::MAX_IMAGE_BYTES;

        $this->assertSame(ReceiptPlacement::ListedOnly, InvoiceReceipts::placementFromMetadata('application/pdf', 10));
        $this->assertSame(ReceiptPlacement::ListedOnly, InvoiceReceipts::placementFromMetadata('image/gif', 10));
        $this->assertSame(ReceiptPlacement::Embedded, InvoiceReceipts::placementFromMetadata('image/png', $limit));
        $this->assertSame(ReceiptPlacement::TooLarge, InvoiceReceipts::placementFromMetadata('image/png', $limit + 1));
        $this->assertSame(ReceiptPlacement::Embedded, InvoiceReceipts::placementFromMetadata('image/jpeg', 0));
    }

    public function test_the_document_budget_stops_at_its_image_count_and_byte_total(): void
    {
        $count = InvoiceReceipts::MAX_IMAGES;
        $total = InvoiceReceipts::MAX_TOTAL_IMAGE_BYTES;

        $this->assertTrue(InvoiceReceipts::fitsBudget(0, 0, 100));
        $this->assertTrue(InvoiceReceipts::fitsBudget($count - 1, 0, 100));
        $this->assertFalse(InvoiceReceipts::fitsBudget($count, 0, 100));
        $this->assertTrue(InvoiceReceipts::fitsBudget(1, $total - 100, 100));
        $this->assertFalse(InvoiceReceipts::fitsBudget(1, $total - 100, 101));
    }

    public function test_what_the_bytes_say_beats_what_the_uploader_claimed(): void
    {
        $png = $this->image('png', 30, 20);
        $jpeg = $this->image('jpeg', 31, 21);
        $gif = $this->image('gif', 10, 10);

        $this->assertSame(['mime' => 'image/png', 'width' => 30, 'height' => 20], InvoiceReceipts::inspect($png));
        $this->assertSame(['mime' => 'image/jpeg', 'width' => 31, 'height' => 21], InvoiceReceipts::inspect($jpeg));
        $this->assertNull(InvoiceReceipts::inspect($gif), 'A GIF is an image Dompdf could draw but this document does not.');
        $this->assertNull(InvoiceReceipts::inspect('%PDF-1.4 not an image'));
        $this->assertNull(InvoiceReceipts::inspect(''));
    }

    public function test_an_image_header_claiming_no_width_or_no_height_is_not_an_image(): void
    {
        $this->assertNull(InvoiceReceipts::inspect($this->pngHeader(0, 5)));
        $this->assertNull(InvoiceReceipts::inspect($this->pngHeader(5, 0)));
        $this->assertSame(['mime' => 'image/png', 'width' => 1, 'height' => 5], InvoiceReceipts::inspect($this->pngHeader(1, 5)));
        $this->assertSame(['mime' => 'image/png', 'width' => 5, 'height' => 1], InvoiceReceipts::inspect($this->pngHeader(5, 1)));
    }

    public function test_an_image_is_refused_above_six_megapixels(): void
    {
        $this->assertTrue(InvoiceReceipts::withinPixelBudget(3000, 2000));
        $this->assertFalse(InvoiceReceipts::withinPixelBudget(3000, 2001));
    }

    public function test_an_image_is_drawn_to_fit_the_page_and_never_enlarged(): void
    {
        $this->assertSame([60, 40], InvoiceReceipts::drawnSize(60, 40));
        $this->assertSame([600, 300], InvoiceReceipts::drawnSize(1200, 600));
        $this->assertSame([260, 520], InvoiceReceipts::drawnSize(520, 1040), 'Tall images are held to the height.');
        $this->assertSame([600, 520], InvoiceReceipts::drawnSize(600, 520));
        $this->assertSame([1, 1], InvoiceReceipts::drawnSize(1, 1));
        $this->assertSame([600, 1], InvoiceReceipts::drawnSize(6000, 1), 'A sliver stays a pixel tall.');
        $this->assertSame([1, 520], InvoiceReceipts::drawnSize(1, 5000), 'And a thin strip a pixel wide.');
        // Rounded to the nearest pixel, not up or down.
        $this->assertSame([402, 520], InvoiceReceipts::drawnSize(1006, 1300));
        $this->assertSame([403, 520], InvoiceReceipts::drawnSize(1007, 1300));
        $this->assertSame([600, 200], InvoiceReceipts::drawnSize(1000, 334));
        $this->assertSame([600, 200], InvoiceReceipts::drawnSize(1000, 333));
    }

    public function test_references_count_from_one_with_a_b_prefix(): void
    {
        $this->assertSame('B1', InvoiceReceipts::ref(1));
        $this->assertSame('B12', InvoiceReceipts::ref(12));
    }

    public function test_each_placement_says_why_it_is_not_drawn_or_says_nothing(): void
    {
        $this->assertNull(ReceiptPlacement::Embedded->note());
        $this->assertSame('Not drawn in the PDF; open the file from the invoice online.', ReceiptPlacement::ListedOnly->note());
        $this->assertSame('Too large to draw in the PDF; open the file from the invoice online.', ReceiptPlacement::TooLarge->note());
        $this->assertSame('Could not be drawn in the PDF; open the file from the invoice online.', ReceiptPlacement::Unreadable->note());
    }

    public function test_a_caller_asks_for_nothing_a_list_or_the_files(): void
    {
        $this->assertFalse(ReceiptDetail::None->drawsAppendix());
        $this->assertTrue(ReceiptDetail::Listed->drawsAppendix());
        $this->assertTrue(ReceiptDetail::Embedded->drawsAppendix());
        $this->assertFalse(ReceiptDetail::None->readsFiles());
        $this->assertFalse(ReceiptDetail::Listed->readsFiles());
        $this->assertTrue(ReceiptDetail::Embedded->readsFiles());
    }

    public function test_a_file_that_is_not_drawn_carries_no_image_and_no_size(): void
    {
        $file = new InvoiceReceiptFile('f', 'a.pdf', 'application/pdf', 1, null, ReceiptPlacement::ListedOnly);

        $this->assertNull($file->imageDataUri);
        $this->assertSame(0, $file->drawnWidth);
        $this->assertSame(0, $file->drawnHeight);
    }

    public function test_the_screen_json_carries_the_address_and_the_note_but_never_the_image(): void
    {
        $drawn = new InvoiceReceiptFile('f1', 'receipt.png', 'image/png', 99, '/workspaces/w/attachments/f1', ReceiptPlacement::Embedded, 'data:image/png;base64,AAAA', 60, 40);
        $listed = new InvoiceReceiptFile('f2', 'receipt.pdf', 'application/pdf', 5, null, ReceiptPlacement::ListedOnly);
        $entry = new InvoiceReceiptEntry('B1', 'line-1', '2026-08-12', 'Synthetic hosting', 4120, [$drawn, $listed]);

        $this->assertSame([
            'ref' => 'B1', 'line_id' => 'line-1', 'spent_on' => '2026-08-12', 'description' => 'Synthetic hosting', 'amount' => 4120,
            'files' => [
                ['id' => 'f1', 'filename' => 'receipt.png', 'media_type' => 'image/png', 'bytes' => 99, 'href' => '/workspaces/w/attachments/f1', 'note' => null],
                ['id' => 'f2', 'filename' => 'receipt.pdf', 'media_type' => 'application/pdf', 'bytes' => 5, 'href' => null, 'note' => 'Not drawn in the PDF; open the file from the invoice online.'],
            ],
        ], $entry->toArray());
        $this->assertStringNotContainsString('base64', json_encode($entry->toArray(), JSON_THROW_ON_ERROR));
    }

    private function pngHeader(int $width, int $height): string
    {
        $header = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));
    }

    private function image(string $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }
}
