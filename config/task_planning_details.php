<?php

return [
    // Independent of list/subtask management; never gates existing privacy readers.
    'enabled' => (bool) env('TASK_PLANNING_DETAILS_ENABLED', false),
];
