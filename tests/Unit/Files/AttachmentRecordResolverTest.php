<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentRecordResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttachmentRecordResolver::class)]
class AttachmentRecordResolverTest extends TestCase
{
    public function test_record_types_are_a_closed_allowlist(): void
    {
        $this->assertSame([
            'company',
            'expense',
            'project',
            'task',
            'proposal',
            'agreement',
            'invoice',
        ], AttachmentRecordResolver::allowedTypes());
        $this->assertSame(AttachmentRecordResolver::allowedTypes(), AttachmentRecordResolver::uploadableTypes());
        $this->assertSame([...AttachmentRecordResolver::uploadableTypes(), 'topic', 'comment'], AttachmentRecordResolver::readableTypes());
    }
}
