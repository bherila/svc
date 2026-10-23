<?php

namespace App\Services\Square;

/** Fixed local diagnostics; never derived from a provider payload. */
enum SquareReadFailureReason: string
{
    case MalformedJson = 'MALFORMED_JSON';
    case MalformedRecords = 'MALFORMED_RECORDS';
    case MappingRejected = 'MAPPING_REJECTED';
    case InvalidCursor = 'INVALID_CURSOR';
    case RepeatedCursor = 'REPEATED_CURSOR';
    case Transport = 'TRANSPORT';
}
