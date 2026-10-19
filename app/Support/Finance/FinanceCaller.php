<?php

namespace App\Support\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Who is calling a finance reconciliation operation (#457), and through
 * which door.
 *
 * - An **OAuth** caller (a REST or MCP connection, or a personal API token)
 *   holds `finance:read` / `finance:reconcile` and must be a workspace
 *   owner/admin for every finance operation.
 * - A **finance token** is the Sanctum token `svc:auth:issue-token` issues
 *   with the `finance.read` / `finance.reconcile` abilities, the door the
 *   finance integration has always used. It keeps the contract it has
 *   always had: any workspace member may list; only an owner/admin writes.
 * - The **operator** is the console, acting as the owner/admin it names.
 *
 * `credential` names the caller in the mutation audit: the OAuth client id,
 * the Sanctum token's row, or the console.
 */
final readonly class FinanceCaller
{
    private function __construct(
        public User $user,
        public FinanceDoor $door,
        public string $credential,
    ) {}

    public static function oauth(User $user, string $clientId): self
    {
        return new self($user, FinanceDoor::OAuth, $clientId);
    }

    /** Named by the token's row; a session's transient token has none. */
    public static function financeToken(User $user, HasAbilities $token): self
    {
        $id = $token instanceof Model ? $token->getKey() : null;

        return new self($user, FinanceDoor::FinanceToken, 'finance-token:'.(is_int($id) || is_string($id) ? $id : 'transient'));
    }

    public static function operator(User $user): self
    {
        return new self($user, FinanceDoor::Console, 'console');
    }
}
