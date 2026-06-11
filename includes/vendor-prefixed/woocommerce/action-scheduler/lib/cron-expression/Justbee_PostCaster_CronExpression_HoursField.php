<?php

/**
 * Hours field.  Allows: * , / -
 *
 * @author Michael Dowling <mtdowling@gmail.com>
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_CronExpression_HoursField extends Justbee_PostCaster_CronExpression_AbstractField
{
    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(DateTime $date, $value)
    {
        return $this->isSatisfied($date->format('H'), $value);
    }

    /**
     * {@inheritdoc}
     */
    public function increment(DateTime $date, $invert = false)
    {
        // Change timezone to UTC temporarily. This will
        // allow us to go back or forwards and hour even
        // if DST will be changed between the hours.
        $timezone = $date->getTimezone();
        $date->setTimezone(new DateTimeZone('UTC'));
        if ($invert) {
            $date->modify('-1 hour');
            $date->setTime($date->format('H'), 59);
        } else {
            $date->modify('+1 hour');
            $date->setTime($date->format('H'), 0);
        }
        $date->setTimezone($timezone);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value)
    {
        return (bool) preg_match('/[\*,\/\-0-9]+/', $value);
    }
}
