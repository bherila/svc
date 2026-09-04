<?php

namespace App\Exceptions;

use LogicException;

/**
 * A tenant-owned row was about to be written by a model that cannot say which
 * workspace it belongs to.
 *
 * The only way to reach this is to hydrate a model without its `workspace_id` -
 * a partial `select()`, or a hand-built instance marked as existing - and then
 * save or delete it. The alternative to refusing is a statement predicated on
 * `workspace_id is null`, which matches no row on a column the schema requires,
 * so the write silently does not happen and `save()` still returns true. A
 * throw at the point of the mistake is the cheaper failure by a long way.
 */
final class UnscopableWorkspaceWrite extends LogicException {}
