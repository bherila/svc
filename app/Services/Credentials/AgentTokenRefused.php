<?php

namespace App\Services\Credentials;

use RuntimeException;

/**
 * A valid token request this deployment or this person may not be issued now.
 *
 * The message is written for an operator's terminal: it names the reason and
 * never the person's email, the credential, or the token id.
 */
final class AgentTokenRefused extends RuntimeException {}
