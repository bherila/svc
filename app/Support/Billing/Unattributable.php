<?php

namespace App\Support\Billing;

/**
 * What a cycle lookup should do with an invoice it cannot place.
 *
 * `cycle_start` and `cycle_end` are nullable. A comparison with SQL `NULL`
 * produces UNKNOWN, which a `WHERE` clause excludes, so a row missing either
 * silently leaves every set that compares them (#141). Whether that is safe
 * depends on what the set is for, and the three readings in #141 do not reduce
 * to one default - which is why this exists as a stated answer rather than a
 * flag someone can forget.
 *
 * The name is the point. `true`/`false` at a call site says nothing about which
 * direction is safe, and a boolean parameter would be read as "include nulls" -
 * a description of the SQL rather than of the decision. What is actually being
 * decided is how to treat a row whose cycle cannot be determined, and the right
 * answer runs opposite ways for a guard and for a delete.
 */
enum Unattributable
{
    /**
     * Treat a row with no cycle as a possible member of this cycle.
     *
     * For a duplicate guard, assuming it belongs costs a refusal an operator
     * can look at; assuming it does not costs a second invoice for a cycle
     * already billed. For a sum of what has already been charged, this is the
     * #135 answer: a dropped row is hours billed twice.
     */
    case Include;

    /**
     * Leave a row with no cycle out.
     *
     * For anything that rewrites what it selects. The draft sweep strips a
     * claim's system-generated lines and zeroes its charge, so including a row
     * that cannot be shown to belong to this cycle wipes work that was not this
     * cycle's to wipe - where excluding it merely leaves a draft for someone to
     * look at.
     */
    case Exclude;
}
