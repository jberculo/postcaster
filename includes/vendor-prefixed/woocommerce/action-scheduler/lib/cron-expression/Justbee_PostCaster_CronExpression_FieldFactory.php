<?php

/**
 * CRON field factory implementing a flyweight factory
 *
 * @author Michael Dowling <mtdowling@gmail.com>
 * @link http://en.wikipedia.org/wiki/Cron
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_CronExpression_FieldFactory
{
    /**
     * @var array Cache of instantiated fields
     */
    private $fields = array();

    /**
     * Get an instance of a field object for a cron expression position
     *
     * @param int $position CRON expression position value to retrieve
     *
     * @return Justbee_PostCaster_CronExpression_FieldInterface
     * @throws InvalidArgumentException if a position is not valid
     */
    public function getField($position)
    {
        if (!isset($this->fields[$position])) {
            switch ($position) {
                case 0:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_MinutesField();
                    break;
                case 1:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_HoursField();
                    break;
                case 2:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_DayOfMonthField();
                    break;
                case 3:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_MonthField();
                    break;
                case 4:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_DayOfWeekField();
                    break;
                case 5:
                    $this->fields[$position] = new Justbee_PostCaster_CronExpression_YearField();
                    break;
                default:
                    throw new InvalidArgumentException(
                        $position . ' is not a valid position'
                    );
            }
        }

        return $this->fields[$position];
    }
}
