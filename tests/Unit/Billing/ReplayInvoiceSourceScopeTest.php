<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\ReplayInvoiceSourceScope;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReplayInvoiceSourceScopeTest extends TestCase
{
    public function test_it_requires_the_exact_tenant_company_project_scope_and_inclusive_period(): void
    {
        $scope = new ReplayInvoiceSourceScope(
            workspaceId: 3,
            companyId: 5,
            agreementProjectId: 7,
            servicePeriodStart: CarbonImmutable::parse('2026-01-01'),
            servicePeriodEnd: CarbonImmutable::parse('2026-01-31'),
        );

        foreach (['2026-01-01', '2026-01-31'] as $boundary) {
            $this->assertTrue($scope->contains(3, 5, 7, 3, 5, CarbonImmutable::parse($boundary)));
        }

        $this->assertFalse($scope->contains(4, 5, 7, 3, 5, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 6, 7, 3, 5, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 5, 8, 3, 5, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 5, 7, 4, 5, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 5, 7, 3, 6, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 5, 7, 3, 5, CarbonImmutable::parse('2025-12-31')));
        $this->assertFalse($scope->contains(3, 5, 7, 3, 5, CarbonImmutable::parse('2026-02-01')));

        $reversed = new ReplayInvoiceSourceScope(
            workspaceId: 3,
            companyId: 5,
            agreementProjectId: 7,
            servicePeriodStart: CarbonImmutable::parse('2026-01-31'),
            servicePeriodEnd: CarbonImmutable::parse('2026-01-01'),
        );
        $this->assertFalse($reversed->contains(3, 5, 7, 3, 5, CarbonImmutable::parse('2026-01-15')));
    }

    public function test_a_company_wide_agreement_still_requires_a_project_owned_by_that_company(): void
    {
        $scope = new ReplayInvoiceSourceScope(
            workspaceId: 3,
            companyId: 5,
            agreementProjectId: null,
            servicePeriodStart: CarbonImmutable::parse('2026-01-01'),
            servicePeriodEnd: CarbonImmutable::parse('2026-01-31'),
        );

        $this->assertTrue($scope->contains(3, 5, 99, 3, 5, CarbonImmutable::parse('2026-01-15')));
        $this->assertFalse($scope->contains(3, 5, 99, 3, 6, CarbonImmutable::parse('2026-01-15')));
    }
}
