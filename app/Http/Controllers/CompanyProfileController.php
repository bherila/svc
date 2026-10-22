<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Clients\CompanyProfile;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CompanyProfileController extends Controller
{
    public function update(UpdateCompanyProfileRequest $request, Workspace $workspace, ClientCompany $clientCompany, WorkspaceAuthorization $authorization, CompanyProfile $profiles): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $profiles->update($workspace, $clientCompany, $request->validated());

        return back()->with('status', 'Company profile saved.');
    }
}
