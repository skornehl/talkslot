# Unit test extensions

`tests/unit.php` includes every `*.php` file in this directory in name order before its summary. Put the
tests of one topic in one file (e.g. `security.php`). The files run in the scope of `unit.php`, so
`check()`, `$run` and the loaded configuration are available. Start a file with `<?php` and
`echo "<Section>\n";`.

A PHP warning, notice or deprecation fails the run (tests/unit.php turns them into an `ErrorException`), so a
template rendered in a test needs all of its variables. `App\Time::setNow()` fixes the clock; reset it with
`App\Time::setNow(null)` at the end of the file.
