<?php

namespace App\Support\AgentApi\BearerRejection;

/** Why the agent API refused a presented bearer, as its log line names it. */
enum BearerRejectionReason: string
{
    /** Not a JWT at all: a refresh token, a Sanctum token, a truncated paste. */
    case InvalidStructure = 'invalid_structure';
    /** A JWT not signed with this server's key. */
    case BadSignature = 'bad_signature';
    case Expired = 'expired';
    /** A JWT whose claims or time window fail validation for another reason. */
    case InvalidToken = 'invalid_token';
    /** Signed by this server, but no stored credential has its id. */
    case UnknownToken = 'unknown_token';
    case Revoked = 'revoked';
    /** The credential's client was revoked or deleted. */
    case ClientRevoked = 'client_revoked';
    /** Issued for another protected resource (a REST token at MCP, or the reverse). */
    case WrongAudience = 'wrong_audience';
    /** The credential's owner, or the person's provider identity, may no longer act. */
    case IdentityRefused = 'identity_refused';
    case InsufficientScope = 'insufficient_scope';
    /** A valid, current credential refused by any other policy. */
    case Refused = 'refused';
    /** Refused without a more specific reason (e.g. a token no check here can read). */
    case Unauthenticated = 'unauthenticated';
}
