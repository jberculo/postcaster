<?php

/**
 * CRON field interface
 *
 * @author Michael Dowling <mtdowling@gmail.com>
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
interface Justbee_PostCaster_CronExpression_FieldInterface
{
    /**
     * Check if the respective value of a DateTime field satisfies a CRON exp
     *
     * @param DateTime $date  DateTime object to check
     * @param string   $value CRON expression to test against
     *
     * @return bool Returns TRUE if satisfied, FALSE otherwise
     */
    public function isSatisfiedBy(DateTime $date, $value);

    /**
     * When a CRON expression is not satisfied, this method is used to increment
     * or decrement a DateTime object by the unit of the cron field
     *
     * @param DateTime $date   DateTime object to change
     * @param bool     $invert (optional) Set to TRUE to decrement
     *
     * @return Justbee_PostCaster_CronExpression_FieldInterface
     */
    public function increment(DateTime $date, $invert = false);

    /**
     * Validates a CRON expression for a given field
     *
     * @param string $value CRON expression value to validate
     *
     * @return bool Returns TRUE if valid, FALSE otherwise
     */
    public function validate($value);
}
