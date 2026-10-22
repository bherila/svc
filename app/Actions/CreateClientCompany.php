<?php

namespace App\Actions;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Clients\CompanyProfile;
use Illuminate\Support\Str;

class CreateClientCompany
{
    /** @param array<string, mixed> $profile */
    public function handle(Workspace $workspace, string $name, ?string $billingEmail, array $profile = []): ClientCompany
    {
        return $workspace->clientCompanies()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($workspace, $name),
            'billing_email' => $billingEmail,
        ] + array_intersect_key($profile, array_flip(CompanyProfile::FIELDS)));
    }

    private function uniqueSlug(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = $base;
        $suffix = 2;

        while ($workspace->clientCompanies()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
