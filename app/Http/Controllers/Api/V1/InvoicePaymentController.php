<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InvoicePaymentResource;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InvoicePaymentController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:pending,succeeded,failed,refunded,disputed'],
            'invoice' => ['sometimes', 'uuid'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', $validated['status'] ?? 'succeeded')
            ->when(isset($validated['invoice']), fn ($query) => $query->whereHas(
                'invoice',
                fn ($query) => $query->where('public_id', $validated['invoice']),
            ))
            ->when(isset($validated['received_from']), fn ($query) => $query->whereDate('received_on', '>=', $validated['received_from']))
            ->when(isset($validated['received_to']), fn ($query) => $query->whereDate('received_on', '<=', $validated['received_to']))
            ->with([
                'invoice.clientCompany',
                'reconciliations' => fn ($query) => $query->orderByDesc('is_active')->latest('id'),
            ])
            ->withSum([
                'reconciliations as active_reconciled_amount' => fn ($query) => $query->where('is_active', true),
            ], 'allocated_amount')
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        return InvoicePaymentResource::collection($payments);
    }
}
