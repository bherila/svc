<?php

namespace Tests\Feature\Files;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AttachmentDownloadHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        require base_path('routes/files.php');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_a_download_is_an_attachment_that_cannot_be_sniffed_or_framed_into_a_page(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $company, UploadedFile::fake()->createWithContent('notes.txt', 'Synthetic notes'), $owner);

        $response = $this->actingAs($owner, 'web')->get(route('svc.files.download', ['workspace' => $workspace, 'clientAttachment' => $attachment->public_id]));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('Content-Disposition', 'attachment; filename=notes.txt')
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_the_type_is_the_sniffed_one_not_the_one_the_extension_or_client_claims(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $storage = app(AttachmentStorageService::class);

        $disguised = $storage->store($workspace, $company, $this->realUpload('photo.png', 'plain text, not a picture'), $owner);

        $this->assertSame('text/plain', $disguised->media_type);
    }

    /** @return array<string, array{string, string}> */
    public static function activeContent(): array
    {
        return [
            'html' => ['text/html', 'application/octet-stream'],
            'svg' => ['image/svg+xml', 'application/octet-stream'],
            'xml' => ['application/xml', 'application/octet-stream'],
            'plain text' => ['text/plain', 'text/plain; charset=utf-8'],
        ];
    }

    /**
     * Uploads of active content are refused now (AttachmentTypePolicy), but a file stored before that
     * policy can still exist: its download is served as opaque bytes.
     */
    #[DataProvider('activeContent')]
    public function test_a_previously_stored_type_a_browser_would_run_is_served_as_opaque_bytes(string $storedType, string $served): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $company, $this->realUpload('legacy.txt', 'Synthetic legacy bytes'), $owner);
        DB::table('client_attachments')->where('id', $attachment->id)->update(['media_type' => $storedType]);

        $this->actingAs($owner, 'web')
            ->get(route('svc.files.download', ['workspace' => $workspace, 'clientAttachment' => $attachment->public_id]))
            ->assertOk()
            ->assertHeader('Content-Type', $served)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_type_with_parameters_or_odd_case_is_still_recognised_as_active(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $company, UploadedFile::fake()->createWithContent('a.txt', 'x'), $owner);

        foreach (['TEXT/HTML; charset=utf-8', ' text/html', 'application/xhtml+xml', 'text/javascript', ''] as $type) {
            DB::table('client_attachments')->where('id', $attachment->id)->update(['media_type' => $type]);
            $this->actingAs($owner, 'web')
                ->get(route('svc.files.download', ['workspace' => $workspace, 'clientAttachment' => $attachment->public_id]))
                ->assertHeader('Content-Type', 'application/octet-stream');
        }

        DB::table('client_attachments')->where('id', $attachment->id)->update(['media_type' => 'application/pdf']);
        $this->actingAs($owner, 'web')
            ->get(route('svc.files.download', ['workspace' => $workspace, 'clientAttachment' => $attachment->public_id]))
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_served_filename_is_one_clean_segment(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $storage = app(AttachmentStorageService::class);
        $attachment = $storage->store($workspace, $company, UploadedFile::fake()->createWithContent('a.txt', 'x'), $owner);

        $cases = [
            '../../etc/passwd' => 'passwd',
            'C:\\temp\\report.pdf' => 'report.pdf',
            "evil\u{202E}fdp.exe" => 'evilfdp.exe',
            "line\nbreak.txt" => 'line break.txt',
            "tab\there.txt" => 'tabhere.txt',
            '..' => 'download',
            '.' => 'download',
            '   ' => 'download',
            str_repeat('a', 400).'.txt' => str_repeat('a', 255),
        ];
        foreach ($cases as $stored => $expected) {
            DB::table('client_attachments')->where('id', $attachment->id)->update(['original_filename' => Crypt::encryptString($stored)]);
            $disposition = (string) $this->actingAs($owner, 'web')
                ->get(route('svc.files.download', ['workspace' => $workspace, 'clientAttachment' => $attachment->public_id]))
                ->assertOk()->headers->get('Content-Disposition');

            $this->assertStringStartsWith('attachment; filename=', $disposition);
            $this->assertStringContainsString('filename='.$expected, str_replace('"', '', $disposition), json_encode($stored));
        }
    }

    public function test_an_upload_over_the_size_limit_is_refused_before_it_is_stored(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $file = $this->realUpload('big.dat', '');
        $handle = fopen($file->getPathname(), 'r+b');
        ftruncate($handle, 52428801);
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        try {
            app(AttachmentStorageService::class)->store($workspace, $company, $file, $owner);
        } finally {
            Storage::disk('svc_files')->assertDirectoryEmpty('_staged');
        }
    }

    /** A real file on disk, so the type is sniffed from the bytes as in production (the fake helper guesses from the extension). */
    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svc-upload-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function companyWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-'.str()->random(6)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'client-'.str()->random(6)]);

        return [$owner, $workspace, $company];
    }
}
