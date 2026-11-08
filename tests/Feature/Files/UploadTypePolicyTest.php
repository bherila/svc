<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Services\Files\AttachmentStorageService;
use App\Services\Files\AttachmentTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsNativeFileApi;
use Tests\TestCase;

/** The server enforces the upload type policy on the real upload routes: nothing is stored for a refused file. */
#[CoversClass(AttachmentTypePolicy::class)]
#[CoversClass(AttachmentStorageService::class)]
final class UploadTypePolicyTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;
    use CallsNativeFileApi;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** Real bytes, so the type the server sniffs is the type of the content and not of the name. */
    private function upload(string $name, string $bytes, string $claimedType = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svc-upload-');
        $this->paths[] = $path;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $claimedType, null, true);
    }

    /** @return array<string, array{string, string, string}> */
    public static function refusedReceipts(): array
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return [
            'html' => ['receipt.html', '<!doctype html><script>alert(1)</script>', 'text/html'],
            'html claiming to be a pdf' => ['receipt.pdf', '<!doctype html><script>alert(1)</script>', 'application/pdf'],
            'svg claiming to be a png' => ['receipt.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/png'],
            'script claiming to be a jpeg' => ['receipt.jpg', '<?php system($_GET["c"]);', 'image/jpeg'],
            'plain text' => ['receipt.txt', 'Synthetic receipt text', 'text/plain'],
            'a png whose extension lies' => ['receipt.pdf', $png, 'application/pdf'],
        ];
    }

    #[DataProvider('refusedReceipts')]
    public function test_a_refused_receipt_answers_422_with_a_message_and_stores_nothing(string $name, string $bytes, string $claimed): void
    {
        $workspace = $this->syntheticWorkspace('receipt policy');
        $manager = $this->syntheticMember($workspace, 'manager');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'receipt policy'));

        $response = $this->actingAs($manager, 'web')->uploadNativeFile($workspace, 'expense', $expense->public_id, ['file' => $this->upload($name, $bytes, $claimed)]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, ClientAttachment::query()->count());
        Storage::disk('svc_files')->assertDirectoryEmpty('/');
    }

    public function test_a_receipt_that_is_what_it_says_is_stored_with_its_sniffed_type(): void
    {
        $workspace = $this->syntheticWorkspace('receipt policy ok');
        $manager = $this->syntheticMember($workspace, 'manager');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'receipt policy ok'));
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->actingAs($manager, 'web')->uploadNativeFile($workspace, 'expense', $expense->public_id, ['file' => $this->upload('synthetic.pdf', $pdf, 'text/plain')])->assertCreated();

        $this->assertSame('application/pdf', ClientAttachment::query()->sole()->media_type);
    }

    public function test_the_service_refuses_before_staging_a_byte_whatever_the_caller(): void
    {
        $workspace = $this->syntheticWorkspace('receipt policy service');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'receipt policy service');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $storage = app(AttachmentStorageService::class);

        foreach ([fn () => $storage->store($workspace, $expense, $this->upload('r.html', '<html></html>'), $manager),
            fn () => $storage->prepareForPublication($workspace, $expense, $this->upload('r.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'), $manager),
            fn () => $storage->store($workspace, $company, $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), $manager)] as $attempt) {
            try {
                $attempt();
                $this->fail('The upload should have been refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $this->assertSame(0, ClientAttachment::query()->count());
        Storage::disk('svc_files')->assertDirectoryEmpty('/');
    }

    public function test_other_attachments_keep_accepting_documents_and_images(): void
    {
        $workspace = $this->syntheticWorkspace('general policy');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'general policy');

        foreach ([['notes.txt', 'Synthetic notes'], ['sheet.csv', "a,b\n1,2\n"], ['photo.gif', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')]] as [$name, $bytes]) {
            $this->actingAs($manager, 'web')->uploadNativeFile($workspace, 'company', $company->public_id, ['file' => $this->upload($name, $bytes, 'application/octet-stream')])->assertCreated();
        }
        $this->assertSame(3, ClientAttachment::query()->count());

        $this->actingAs($manager, 'web')->uploadNativeFile($workspace, 'company', $company->public_id, ['file' => $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(3, ClientAttachment::query()->count());
    }
}
