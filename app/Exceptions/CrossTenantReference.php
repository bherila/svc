<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A tenant-scoped boundary was handed a row belonging to another workspace.
 *
 * Raised rather than returned as a null so the mistake cannot be read as "not
 * found" and retried with a different lookup. A caller reaching this has a bug:
 * the ids it is holding came from somewhere that did not check them, which is
 * the defect class the composite tenant keys and these boundaries both exist to
 * end.
 */
final class CrossTenantReference extends InvalidArgumentException {}
