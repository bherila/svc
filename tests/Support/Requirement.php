<?php

namespace Tests\Support;

use Attribute;

/**
 * Names the docs/service-packages.md requirements a test proves.
 *
 * RequirementTraceabilityTest reads these, and fails when a requirement has no
 * test or a test names one that does not exist. Put it on the test method, or
 * on the class when every test in it proves the same requirement.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Requirement
{
    /** @var list<string> */
    public array $ids;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
    }
}
