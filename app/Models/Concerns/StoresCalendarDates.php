<?php

namespace App\Models\Concerns;

/**
 * Write DATE columns as a bare `Y-m-d`, whatever the driver.
 *
 * Eloquent's `date` and `immutable_date` casts format a value with the
 * model's full date-time format before it is stored. MariaDB truncates that to
 * the date, so production never sees a time; SQLite stores the text as given,
 * and `2026-01-31 00:00:00` then sorts after `2026-01-31`. Every `<=` or
 * `whereBetween` bound ending on a period's last day silently dropped that
 * day's rows in the local suite only (#354), so a month-end billing bug could
 * pass it. A model lists its calendar-date columns in `calendarDates()`.
 */
trait StoresCalendarDates
{
    /** @return list<string> */
    abstract protected function calendarDates(): array;

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function setAttribute($key, $value): mixed
    {
        $result = parent::setAttribute($key, $value);

        if (in_array($key, $this->calendarDates(), true)
            && is_string($this->attributes[$key] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $this->attributes[$key]) === 1) {
            $this->attributes[$key] = substr($this->attributes[$key], 0, 10);
        }

        return $result;
    }
}
