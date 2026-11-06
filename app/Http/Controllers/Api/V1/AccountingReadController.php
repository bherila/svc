<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounting\AccountingReadService;
use App\Support\Accounting\AccountingQuery;
use App\Support\Finance\FinanceCaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingReadController extends Controller
{
    public function lines(Request $request, Workspace $workspace, AccountingReadService $reads): JsonResponse
    {
        return response()->json($reads->lines($this->caller($request), $workspace, AccountingQuery::from($request->query())));
    }

    public function income(Request $request, Workspace $workspace, AccountingReadService $reads): JsonResponse
    {
        return response()->json($reads->income($this->caller($request), $workspace, AccountingQuery::from($request->query())));
    }

    public function yearly(Request $request, Workspace $workspace, AccountingReadService $reads): JsonResponse|StreamedResponse
    {
        $data = Validator::make($request->query(), ['year' => ['required', 'integer', 'between:1900,9999'], 'format' => ['sometimes', 'in:json,csv']])->validate();
        $result = $reads->yearly($this->caller($request), $workspace, (int) $data['year']);
        if (($data['format'] ?? 'json') === 'json') {
            return response()->json($result->toArray());
        }

        return response()->streamDownload(static function () use ($result): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('The CSV output stream could not be opened.');
            }
            fputcsv($stream, array_keys($result->months[0]), escape: '');
            foreach ($result->months as $month) {
                fputcsv($stream, array_values($month), escape: '');
            }
            fclose($stream);
        }, 'income-'.$result->year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function caller(Request $request): FinanceCaller
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal || $user instanceof User, 401);
        $token = $user instanceof AgentPrincipal ? $user->token() : null;
        $clientId = $token instanceof AccessToken ? $token->oauth_client_id : 'accounting-api';

        return FinanceCaller::oauth(User::query()->findOrFail($user->id), (string) $clientId);
    }
}
