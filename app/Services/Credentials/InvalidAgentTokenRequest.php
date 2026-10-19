<?php

namespace App\Services\Credentials;

use InvalidArgumentException;

/** A token request that names something no agent token may carry; nothing was issued. */
final class InvalidAgentTokenRequest extends InvalidArgumentException {}
