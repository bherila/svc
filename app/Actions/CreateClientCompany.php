<?php

namespace App\Actions;

use App\Models\ClientCompany;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreateClientCompany
{
    public function handle(Workspace $workspace, string $name, ?string $billingEmail): ClientCompany
    {
        return $workspace->clientCompanies()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($workspace, $name),
            'billing_email' => $billingEmail,
        ]);
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
