<?php
declare(strict_types=1);

namespace App;

/**
 * What a change by the teacher meant for the parents (result of ScheduleService): how many upcoming
 * bookings it concerned, how many parents get a mail, and who has to be told in person or later.
 */
final class ScheduleChange
{
    /**
     * @param int $bookings upcoming bookings concerned (deleted, or with a new date, time, location or teacher)
     * @param int $mailed of these, the ones whose parents get a mail (queued in the outbox, or one waiting there
     *        already, which is created with the new details when sent)
     * @param list<array{name:string,email:string,day_date:string,start_time:string}> $inPerson of these, the
     *        ones whose parents get none: no address, an entry by the teacher that never got a mail, or $notify off
     * @param bool $notify the teacher left "Eltern per E-Mail informieren" ticked
     * @param int $conflicts booked slots that no longer fit the schedule (their parents are told when the
     *        teacher moves or deletes them)
     * @param list<array{name:string,email:string,day_date:string,start_time:string}> $held of the bookings,
     *        the ones whose change notice the limit per account and hour held back (ScheduleService::BULK_LIMIT)
     * @param int $movedConflicts conflicts that got a new date, location or teacher: their parents do not know
     *        it yet (a conflict is not mailed about)
     */
    public function __construct(
        public readonly int $bookings,
        public readonly int $mailed,
        public readonly array $inPerson,
        public readonly bool $notify,
        public readonly int $conflicts = 0,
        public readonly array $held = [],
        public readonly int $movedConflicts = 0,
    ) {
    }
}
