<?php
declare(strict_types=1);

namespace App;

/** Domain booking error; the message is a translation key suffix (taken, deadline …). */
final class BookingException extends \RuntimeException
{
}
