<?php

namespace App\Services\Credentials;

use RuntimeException;

/** A revocation that cannot be made safely in the deployment's current state; nothing was revoked. */
final class RevocationRefused extends RuntimeException {}
