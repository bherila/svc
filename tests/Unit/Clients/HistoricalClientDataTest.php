<?php

namespace Tests\Unit\Clients;

use App\Models\ClientCompany;
use App\Services\Clients\CompanyProfile;
use App\Services\Clients\ContactDetails;
use PHPUnit\Framework\TestCase;

class HistoricalClientDataTest extends TestCase
{
    public function test_company_profile_contains_the_whole_profile_and_no_access_or_billing_controls(): void
    {
        $company = new ClientCompany;
        $company->setRawAttributes([
            'billing_address' => 'A synthetic address', 'phone' => '555-0100',
            'website_url' => 'https://example.test', 'tax_id' => 'SYN-TAX',
            'currency' => 'USD', 'notes' => 'Private synthetic note',
            'workspace_id' => 31, 'automatic_invoice_email_enabled' => true,
        ]);
        $this->assertSame([
            'billing_address' => 'A synthetic address', 'phone' => '555-0100',
            'website_url' => 'https://example.test', 'tax_id' => 'SYN-TAX',
            'currency' => 'USD', 'notes' => 'Private synthetic note',
        ], (new CompanyProfile)->read($company));
    }

    public function test_contact_details_keep_archived_people_without_creating_authentication_attributes(): void
    {
        $details = ContactDetails::fromValidated([
            'first_name' => 'Casey', 'last_name' => 'Example', 'title' => 'Synthetic role',
            'email' => 'casey@example.test', 'phone' => '555-0101', 'notes' => 'Historical participant',
            'is_archived' => true,
        ]);
        $this->assertSame([
            'first_name' => 'Casey', 'last_name' => 'Example', 'title' => 'Synthetic role',
            'email' => 'casey@example.test', 'phone' => '555-0101', 'notes' => 'Historical participant',
            'is_archived' => true,
        ], $details->attributes());
    }

    public function test_an_unnamed_optional_field_remains_unspecified_and_archival_is_explicit(): void
    {
        $details = ContactDetails::fromValidated(['last_name' => 'Example']);
        $this->assertSame([
            'first_name' => null, 'last_name' => 'Example', 'title' => null,
            'email' => null, 'phone' => null, 'notes' => null, 'is_archived' => false,
        ], $details->attributes());
    }
}
