<?php

namespace App\Services\Mcp;

/** Only API refusals written for the caller may cross the MCP boundary. */
final class AgentMcpApiFailure
{
    /** @param array<string, mixed>|null $body */
    public static function message(int $status, ?array $body): string
    {
        if ($status === 403) {
            return 'This connection lacks the required permission.';
        }

        if (! in_array($status, [409, 422], true)) {
            return 'The SVC API request could not be completed.';
        }

        $messages = [];
        $message = $body['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            $messages[] = trim($message);
        }

        $validation = self::firstValidationError($body['errors'] ?? null);
        if ($validation !== null && ! in_array($validation, $messages, true)) {
            $messages[] = $validation;
        }

        return $messages === []
            ? 'The SVC API request could not be completed.'
            : implode(' ', $messages);
    }

    private static function firstValidationError(mixed $errors): ?string
    {
        if (! is_array($errors)) {
            return null;
        }

        foreach ($errors as $fieldErrors) {
            if (! is_array($fieldErrors)) {
                continue;
            }
            foreach ($fieldErrors as $error) {
                if (is_string($error) && trim($error) !== '') {
                    return trim($error);
                }
            }
        }

        return null;
    }
}
