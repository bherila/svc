<?php

namespace App\Support\Billing;

/**
 * A refusal because state moved under the request, not because the request
 * is wrong: nothing was written, and making the same request again checks it
 * against current state. Rendered to API callers as 409, which the agent
 * contract reads as "re-read and retry", rather than the 422 other billing
 * refusals get.
 */
interface RetryableConflict {}
