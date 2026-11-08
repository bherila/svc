<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentFamily;
use App\Services\Files\AttachmentTypePolicy;
use App\Services\Files\AttachmentUploadKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(AttachmentTypePolicy::class)]
#[CoversClass(AttachmentFamily::class)]
#[CoversClass(AttachmentUploadKind::class)]
final class AttachmentTypePolicyTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** Real bytes on disk, so the type is sniffed exactly as an upload's is. */
    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svc-policy-');
        $this->paths[] = $path;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private static function bytes(string $kind): string
    {
        return match ($kind) {
            'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
            'png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
            'jpeg' => (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
            'webp' => (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA=='),
            'gif' => (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
            'heic' => "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic\x00\x00\x00\x00",
            'html' => '<!doctype html><html><body><script>alert(1)</script></body></html>',
            'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            'xml' => '<?xml version="1.0"?><root><child/></root>',
            'php' => '<?php echo "synthetic";',
            'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 48)."\x80\x00\x00\x00This program cannot be run in DOS mode.",
            'text' => 'Synthetic plain text receipt',
            default => throw new \LogicException($kind),
        };
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedReceipts(): array
    {
        return [
            'pdf' => ['receipt.pdf', 'pdf'],
            'upper-case extension' => ['RECEIPT.PDF', 'pdf'],
            'jpg' => ['photo.jpg', 'jpeg'],
            'jpeg' => ['photo.jpeg', 'jpeg'],
            'png' => ['scan.png', 'png'],
            'webp' => ['scan.webp', 'webp'],
            'dots in the name' => ['amazon.com.invoice.pdf', 'pdf'],
        ];
    }

    #[DataProvider('acceptedReceipts')]
    public function test_a_receipt_whose_bytes_and_extension_agree_is_accepted(string $name, string $bytes): void
    {
        AttachmentTypePolicy::assertAcceptable(AttachmentUploadKind::Receipt, $this->upload($name, self::bytes($bytes)));
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string, string}> */
    public static function refusedReceipts(): array
    {
        $accepts = 'A receipt must be a PDF or a JPEG, PNG or WebP image.';

        return [
            'gif is not supported' => ['receipt.gif', 'gif', $accepts.' This image format is not supported.'],
            'heic is not supported' => ['receipt.heic', 'heic', $accepts.' This image format is not supported.'],
            'plain text' => ['receipt.txt', 'text', $accepts.' This file is a text file.'],
            'text named pdf' => ['receipt.pdf', 'text', $accepts.' This file is a text file.'],
            'svg' => ['receipt.svg', 'svg', 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.'],
            'svg named png' => ['receipt.png', 'svg', 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.'],
            'html named pdf' => ['receipt.pdf', 'html', 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.'],
            'php named jpg' => ['receipt.jpg', 'php', 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.'],
            'program named pdf' => ['receipt.pdf', 'exe', 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.'],
            'pdf named png' => ['receipt.png', 'pdf', 'The file name ends in .png but the file is a PDF. Rename it to end in .pdf, or upload the original file.'],
            'png named jpg' => ['receipt.jpg', 'png', 'The file name ends in .jpg but the file is a PNG image. Rename it to end in .png, or upload the original file.'],
            'jpeg named png' => ['receipt.png', 'jpeg', 'The file name ends in .png but the file is a JPEG image. Rename it to end in .jpg, or upload the original file.'],
            'webp named png' => ['receipt.png', 'webp', 'The file name ends in .png but the file is a WebP image. Rename it to end in .webp, or upload the original file.'],
            'png with no extension' => ['receipt', 'png', 'The file name ends in nothing but the file is a PNG image. Rename it to end in .png, or upload the original file.'],
        ];
    }

    #[DataProvider('refusedReceipts')]
    public function test_a_receipt_is_refused_with_a_sentence_a_person_can_act_on(string $name, string $bytes, string $message): void
    {
        try {
            AttachmentTypePolicy::assertAcceptable(AttachmentUploadKind::Receipt, $this->upload($name, self::bytes($bytes)));
            $this->fail('The receipt should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['file' => [$message]], $exception->errors());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedGeneral(): array
    {
        return [
            'text' => ['notes.txt', 'text'],
            'pdf' => ['contract.pdf', 'pdf'],
            'png' => ['logo.png', 'png'],
            'gif' => ['logo.gif', 'gif'],
            'heic' => ['photo.heic', 'heic'],
            'webp' => ['photo.webp', 'webp'],
            'no extension' => ['README', 'text'],
            'a pdf named like a program' => ['amazon.com.pdf', 'pdf'],
            'png named pdf (general uploads do not match extensions)' => ['scan.pdf', 'png'],
        ];
    }

    #[DataProvider('acceptedGeneral')]
    public function test_a_general_attachment_keeps_its_broad_behaviour(string $name, string $bytes): void
    {
        AttachmentTypePolicy::assertAcceptable(AttachmentUploadKind::General, $this->upload($name, self::bytes($bytes)));
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string}> */
    public static function refusedGeneral(): array
    {
        return [
            'html' => ['page.html', 'html'],
            'html named txt' => ['page.txt', 'html'],
            'svg' => ['logo.svg', 'svg'],
            'svg named png' => ['logo.png', 'svg'],
            'xml' => ['data.xml', 'xml'],
            'php' => ['shell.php', 'php'],
            'php named pdf' => ['shell.pdf', 'php'],
            'program' => ['setup.exe', 'exe'],
            'program named txt' => ['setup.txt', 'exe'],
            'script by extension only' => ['app.js', 'text'],
            'shell by extension only' => ['run.sh', 'text'],
            'upper-case extension' => ['PAGE.HTML', 'text'],
        ];
    }

    #[DataProvider('refusedGeneral')]
    public function test_a_general_attachment_still_refuses_active_content(string $name, string $bytes): void
    {
        try {
            AttachmentTypePolicy::assertAcceptable(AttachmentUploadKind::General, $this->upload($name, self::bytes($bytes)));
            $this->fail('Active content should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['file' => ['Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.']], $exception->errors());
        }
    }

    public function test_the_decision_is_a_pure_function_of_the_sniffed_type_and_the_extension(): void
    {
        $this->assertNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::Receipt, 'application/pdf', '.PDF'));
        $this->assertNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::Receipt, 'image/jpeg; charset=binary', 'jpg'));
        $this->assertNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::General, null, ''));
        $this->assertNotNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::Receipt, null, 'pdf'));
        $this->assertNotNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::Receipt, '', 'pdf'));
        $this->assertNotNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::General, 'TEXT/HTML; charset=utf-8', 'txt'));
        $this->assertNotNull(AttachmentTypePolicy::refusal(AttachmentUploadKind::General, 'application/octet-stream', '.exe'));
    }

    /** @return array<string, array{string, string, AttachmentFamily}> */
    public static function families(): array
    {
        return [
            'pdf' => ['application/pdf', 'pdf', AttachmentFamily::Pdf],
            'image' => ['image/avif', 'avif', AttachmentFamily::Image],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', AttachmentFamily::Office],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', AttachmentFamily::Office],
            'legacy openxml prefix' => ['application/vnd.openxml.example', 'x', AttachmentFamily::Office],
            'doc' => ['application/msword', 'doc', AttachmentFamily::Office],
            'odt' => ['application/vnd.oasis.opendocument.text', 'odt', AttachmentFamily::Office],
            'csv' => ['text/csv', 'csv', AttachmentFamily::Text],
            'text' => ['text/plain', 'txt', AttachmentFamily::Text],
            'zip' => ['application/zip', 'zip', AttachmentFamily::Archive],
            'audio' => ['audio/mpeg', 'mp3', AttachmentFamily::Media],
            'video' => ['video/mp4', 'mp4', AttachmentFamily::Media],
            'octet stream' => ['application/octet-stream', 'dat', AttachmentFamily::Other],
            'json' => ['application/json', 'json', AttachmentFamily::Other],
            'unknown' => [null, '', AttachmentFamily::Other],
            'html' => ['text/html', 'txt', AttachmentFamily::Active],
            'xhtml' => ['application/xhtml+xml', '', AttachmentFamily::Active],
            'svg' => ['image/svg+xml', 'png', AttachmentFamily::Active],
            'xml' => ['text/xml', '', AttachmentFamily::Active],
            'javascript' => ['application/javascript', '', AttachmentFamily::Active],
            'php' => ['text/x-php', '', AttachmentFamily::Active],
            'shell' => ['text/x-shellscript', '', AttachmentFamily::Active],
            'windows program' => ['application/x-dosexec', '', AttachmentFamily::Active],
            'java archive' => ['application/java-archive', '', AttachmentFamily::Active],
            'script by name' => ['text/plain', 'JS', AttachmentFamily::Active],
            'program by name' => ['application/octet-stream', '.exe', AttachmentFamily::Active],
            'html by name' => ['application/pdf', 'htm', AttachmentFamily::Active],
        ];
    }

    #[DataProvider('families')]
    public function test_a_file_is_classified_by_its_bytes_and_only_made_active_by_its_name(?string $type, string $extension, AttachmentFamily $family): void
    {
        $this->assertSame($family, AttachmentFamily::classify($type, $extension));
    }

    public function test_every_family_has_a_label_and_receipts_take_only_pdf_and_images(): void
    {
        foreach (AttachmentFamily::cases() as $family) {
            $this->assertNotSame('', $family->label());
        }
        $this->assertSame([AttachmentFamily::Pdf, AttachmentFamily::Image], AttachmentUploadKind::Receipt->families());
        $this->assertNotContains(AttachmentFamily::Active, AttachmentUploadKind::General->families());
        $this->assertCount(count(AttachmentFamily::cases()) - 1, AttachmentUploadKind::General->families());
        $this->assertSame(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], array_keys(AttachmentUploadKind::Receipt->exactTypes()));
        $this->assertSame([], AttachmentUploadKind::General->exactTypes());
    }
}
