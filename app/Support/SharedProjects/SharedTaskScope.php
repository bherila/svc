<?php

namespace App\Support\SharedProjects;

/**
 * What of a host task a participant is shown: a fixed list of columns, chosen
 * here rather than removed from the full row. Anything not named - milestone
 * price, the invoice line a milestone was billed on, lists, labels,
 * dependencies, contacts - is never selected for a participant, so no
 * presenter has a field to forget to strip.
 *
 * A task is shared with participants only when it is already shown to the
 * client (`is_visible_to_client`): a task the host hid from its own client is
 * internal to the host. The repository applies that as a where clause.
 */
final class SharedTaskScope
{
    /** @var list<string> */
    public const array COLUMNS = ['id', 'public_id', 'client_project_id', 'title', 'description', 'status', 'is_visible_to_client', 'starts_on', 'due_on', 'completed_at'];
}
