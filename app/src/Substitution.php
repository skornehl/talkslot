<?php
declare(strict_types=1);

namespace App;

/**
 * "Vertretung übernehmen" (expert review P2-10, DS-4): admins manage all rounds and see how they are booked, but
 * the names and e-mail addresses of parents in another teacher's round only after this logged action, which
 * asks for the password and tells the teacher by e-mail (AdminController::roundSubstitute()). It lasts as long as
 * the sign-in: a new session has to take over again. This limits what a taken-over admin session or a curious
 * volunteer admin can see, without getting in the way of a real substitution.
 */
final class Substitution
{
    public static function active(int $roundId): bool
    {
        return !empty($_SESSION['substitutions'][$roundId]);
    }

    public static function start(int $roundId): void
    {
        $_SESSION['substitutions'][$roundId] = true;
    }
}
