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

    /** Whether the caller asked for the read shape; any other view is refused before anything is written. */
    public static function requested(Request $request): bool
    {
        if (! $request->query->has(self::PARAMETER)) {
            return false;
        }
        if ($request->query->get(self::PARAMETER) !== self::READ) {
            throw ValidationException::withMessages([self::PARAMETER => 'The view must be read.']);
        }

        return true;
    }

    /**
     * The request without the view, for the rules and the digest. A `view`
     * sent in the body is not the view and stays, to be refused by name.
     *
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $payload = $request->all();
        if ($request->query->has(self::PARAMETER) && ! $request->json()->has(self::PARAMETER) && ! $request->request->has(self::PARAMETER)) {
            unset($payload[self::PARAMETER]);
        }

        return $payload;
    }
}
