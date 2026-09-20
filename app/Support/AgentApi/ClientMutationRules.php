<?php

namespace App\Support\AgentApi;

use App\Http\Requests\Engagement\StoreAgreementRequest;
use App\Http\Requests\Engagement\UpdateAgreementRequest;
use App\Http\Requests\StoreClientCompanyRequest;
use App\Http\Requests\UpdateClientCompanyRequest;
use Illuminate\Support\Arr;

/**
 * The validation MCP applies to client and agreement writes, taken from the web
 * forms' own requests rather than restated.
 *
 * A rule copied here would be a second opinion about what a valid client or
 * agreement is, and the two would drift the first time one form tightened. The
 * field lists the tools read from a call are `array_keys()` of these, for the
 * same reason.
 */
final class ClientMutationRules
{
    /** @return array<string, list<mixed>> */
    public static function clientCreate(): array
    {
        return (new StoreClientCompanyRequest)->rules();
    }

    /**
     * The Manage form sends every field, so its rules demand `name`, `billing_email`
     * and `is_active`. An MCP edit sends only what it changes, so the presence
     * demands relax to `sometimes` while every constraint on the value stays.
     *
     * @return array<string, list<mixed>>
     */
    public static function clientUpdate(): array
    {
        return array_map(
            static fn (array $rules): array => array_map(
                static fn (mixed $rule): mixed => $rule === 'required' || $rule === 'present' ? 'sometimes' : $rule,
                $rules,
            ),
            (new UpdateClientCompanyRequest)->rules(),
        );
    }

    /**
     * Without `source_proposal`: MCP does not materialise agreements from proposals.
     *
     * @return array<string, list<mixed>>
     */
    public static function agreementCreate(): array
    {
        return Arr::except((new StoreAgreementRequest)->rules(), ['source_proposal']);
    }

    /** @return array<string, list<mixed>> */
    public static function agreementUpdate(): array
    {
        return (new UpdateAgreementRequest)->rules();
    }
}
