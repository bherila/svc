<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentRecordResolver;
use PHPUnit\Framework\TestCase;

class AttachmentRecordResolverTest extends TestCase
{
    public function test_record_types_are_a_closed_allowlist(): void
    {
        $this->assertSame([
            'company',
            'project',
            'task',
            'proposal',
            'agreement',
            'invoice',
        ], AttachmentRecordResolver::allowedTypes());
    }
}
