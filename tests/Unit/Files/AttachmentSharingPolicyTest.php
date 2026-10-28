<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentDeletion;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingGate;
use App\Services\Files\AttachmentVisibilityChange;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

#[CoversClass(AttachmentSharingGate::class)]
#[CoversClass(AttachmentDeletion::class)]
#[CoversClass(AttachmentVisibilityChange::class)]
#[CoversClass(AttachmentParentReference::class)]
final class AttachmentSharingPolicyTest extends TestCase
{
    public function test_reader_capabilities_hide_unverifiable_controls_while_explicit_writes_fail_safely(): void
    {
        $directory = sys_get_temp_dir().'/svc-sharing-policy-'.bin2hex(random_bytes(8));
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($directory));
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $this->assertFalse(AttachmentSharingGate::available());
        $this->assertRefused(404, fn () => AttachmentSharingGate::assertEnabled());
        config(['svc.attachments.sharing_writes_enabled' => true]);
        $this->assertFalse(AttachmentSharingGate::available());
        $this->assertRefused(503, fn () => AttachmentSharingGate::assertEnabled());
        File::makeDirectory($directory, 0700);
        try {
            $this->assertFalse(AttachmentSharingGate::available());
            $this->assertRefused(503, fn () => AttachmentSharingGate::assertEnabled());
            File::put($directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
            $this->assertTrue(AttachmentSharingGate::available());
            AttachmentSharingGate::assertEnabled();
            chmod($directory, 0400);
            clearstatcache(true, $directory);
            $this->assertTrue(is_readable($directory));
            $this->assertFalse(is_executable($directory));
            $this->assertFalse(AttachmentSharingGate::available());
            $this->assertRefused(503, fn () => AttachmentSharingGate::assertEnabled());
            chmod($directory, 0700);
            foreach (['true', 1, null] as $notBoolean) {
                config(['svc.attachments.sharing_writes_enabled' => $notBoolean]);
                $this->assertFalse(AttachmentSharingGate::available());
                $this->assertRefused(404, fn () => AttachmentSharingGate::assertEnabled());
            }
        } finally {
            chmod($directory, 0700);
            File::deleteDirectory($directory);
        }
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalidChanges(): iterable
    {
        $valid = ['is_visible_to_client' => false, 'expected_version' => str_repeat('a', 64), 'expected_parent_version' => str_repeat('b', 64)];
        foreach ([null, '', ' ', 'false', [], 2] as $index => $value) {
            yield 'invalid explicit checkbox '.$index => [['is_visible_to_client' => $value] + $valid];
        }
        foreach (array_keys($valid) as $key) {
            yield 'missing '.$key => [array_diff_key($valid, [$key => true])];
        }
        yield 'body identity' => [$valid + ['attachment_id' => (string) Str::uuid()]];
        yield 'forged origin' => [$valid + ['origin' => 'Native']];
        yield 'short parent version' => [['expected_parent_version' => 'short'] + $valid];
    }

    /** @param array<string,mixed> $input */
    #[DataProvider('invalidChanges')]
    public function test_sharing_change_requires_explicit_closed_validated_intent(array $input): void
    {
        $this->expectException(ValidationException::class);
        AttachmentVisibilityChange::fromInput($input);
    }

    public function test_false_and_true_form_values_preserve_the_requested_choice(): void
    {
        foreach ([false, 0, '0', true, 1, '1'] as $value) {
            $change = AttachmentVisibilityChange::fromInput(['is_visible_to_client' => $value,
                'expected_version' => str_repeat('a', 64), 'expected_parent_version' => str_repeat('b', 64)]);
            $this->assertSame(in_array($value, [true, 1, '1'], true), $change->visibleToClient);
            $this->assertSame(str_repeat('a', 64), $change->expectedVersion);
            $this->assertSame(str_repeat('b', 64), $change->expectedParentVersion);
        }
        $this->assertFalse((new AttachmentParentReference('company', (string) Str::uuid()))->collaboration());
        $this->assertTrue((new AttachmentParentReference('comment', (string) Str::uuid(), (string) Str::uuid()))->collaboration());
        $this->expectException(\InvalidArgumentException::class);
        new AttachmentParentReference('topic', (string) Str::uuid());
    }

    /** @return iterable<string, array{string,string,?string}> */
    public static function invalidReferences(): iterable
    {
        $id = (string) Str::uuid();
        yield 'unsupported type' => ['history', $id, null];
        yield 'invalid record UUID' => ['company', 'not-a-uuid', null];
        yield 'invalid company UUID' => ['topic', $id, 'not-a-uuid'];
        yield 'missing company scope' => ['comment', $id, null];
        yield 'injected legacy scope' => ['company', $id, (string) Str::uuid()];
    }

    #[DataProvider('invalidReferences')]
    public function test_parent_reference_cannot_accept_foreign_shape_or_unknown_identity(string $type, string $id, ?string $company): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AttachmentParentReference($type, $id, $company);
    }

    public function test_deletion_requires_literal_confirmation_and_both_versions(): void
    {
        foreach ([true, false, 1, '1', null, '', 'true'] as $value) {
            try {
                $deletion = AttachmentDeletion::fromInput(['confirm' => $value, 'expected_version' => str_repeat('a', 64), 'expected_parent_version' => str_repeat('b', 64)]);
                $this->assertTrue($value);
                $this->assertSame(str_repeat('a', 64), $deletion->expectedVersion);
                $this->assertSame(str_repeat('b', 64), $deletion->expectedParentVersion);
            } catch (ValidationException) {
                $this->assertNotSame(true, $value);
            }
        }
        $this->expectException(ValidationException::class);
        AttachmentDeletion::fromInput(['confirm' => true, 'expected_version' => str_repeat('a', 64)]);
    }

    public function test_closed_payload_errors_name_the_field_and_deletion_requires_the_file_version(): void
    {
        $valid = ['expected_version' => str_repeat('a', 64), 'expected_parent_version' => str_repeat('b', 64)];
        foreach ([fn () => AttachmentDeletion::fromInput($valid + ['confirm' => true, 'origin' => 'Native']),
            fn () => AttachmentVisibilityChange::fromInput($valid + ['is_visible_to_client' => false, 'origin' => 'Native'])] as $attempt) {
            try {
                $attempt();
                $this->fail('A closed payload accepted an unsupported origin.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('attachment', $exception->errors());
                $this->assertNotEmpty($exception->errors()['attachment']);
            }
        }
        try {
            AttachmentDeletion::fromInput(['confirm' => true, 'expected_parent_version' => str_repeat('b', 64)]);
            $this->fail('Deletion accepted a missing captured file version.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }
    }

    private function assertRefused(int $status, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('The sharing writer unexpectedly succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
