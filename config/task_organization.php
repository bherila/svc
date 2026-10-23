<?php

return [
    // Enable only after the combined graph/file R1 reader floor is deployed
    // and independently verified. This never gates existing disclosure readers.
    'enabled' => (bool) env('TASK_ORGANIZATION_WRITES_ENABLED', false),
];
