<?php

namespace App\Exceptions;

use LogicException;

/**
 * A tenant-owned row was about to be saved into a different workspace.
 *
 * Refused rather than resolved either way, because both resolutions are worse
 * than a throw. Predicating the update on the stored workspace would match the
 * row and move it, carrying its children into a tenant that never asked for
 * them; predicating it on the new one would match nothing and leave the model
 * disagreeing with the database while `save()` reported success. Ownership is
 * fixed when the row is created, so a save that changes it is a bug in the
 * caller either way.
 */
final class WorkspaceOwnershipImmutable extends LogicException {}
