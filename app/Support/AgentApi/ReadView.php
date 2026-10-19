<?php

namespace App\Support\AgentApi;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `?view=read` on a write (#459): answer with the written records exactly as
 * their read operation presents them - `time_entries.list` for a time log,
 * `invoices.get` for an issue - when the caller also holds that read's scope,
 * and with the plain write shape otherwise. It is a choice of representation
 * only: the view never joins the request digest, so a receipt replays the
 * same way with or without it.
 */
final class ReadView
{
    public const string PARAMETER = 'view';

    public const string READ = 'read';

    /**
     * Whether the caller asked for the read shape. Any other view, and a
     * `view` sent in the body - where it would otherwise pass unnoticed - are
     * refused before anything is written.
     */
    public static function requested(Request $request): bool
    {
        if ($request->json()->has(self::PARAMETER) || $request->request->has(self::PARAMETER)) {
            throw ValidationException::withMessages([self::PARAMETER => 'The view is a query parameter, not a field.']);
        }
        if (! $request->query->has(self::PARAMETER)) {
            return false;
        }
        if ($request->query->get(self::PARAMETER) !== self::READ) {
            throw ValidationException::withMessages([self::PARAMETER => 'The view must be read.']);
        }

        return true;
    }

    /**
     * The request without the view, for the rules and the digest. Call it
     * after {@see requested()}, which refuses a view anywhere but the query.
     *
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        return array_diff_key($request->all(), [self::PARAMETER => true]);
    }
}
