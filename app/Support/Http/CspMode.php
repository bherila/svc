<?php

namespace App\Support\Http;

/**
 * What the Content-Security-Policy header does: nothing, report violations without blocking,
 * or block them. An unrecognised setting is {@see self::ReportOnly}: a typo must neither turn
 * the policy off nor start blocking pages.
 */
enum CspMode: string
{
    case Off = 'off';
    case ReportOnly = 'report-only';
    case Enforce = 'enforce';

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::ReportOnly) : self::ReportOnly;
    }

    /** The response header that carries the policy in this mode, or null when none is sent. */
    public function header(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::ReportOnly => 'Content-Security-Policy-Report-Only',
            self::Enforce => 'Content-Security-Policy',
        };
    }
}
