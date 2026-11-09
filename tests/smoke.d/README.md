# Smoke test extensions

`tests/smoke.sh` sources every `*.sh` file in this directory in name order, just before its final
clean-up. Put the checks of one topic in one file (e.g. `security.sh`), so that changes to different
topics do not touch the same lines of `smoke.sh`.

A file runs with all helpers and variables of `smoke.sh` (`B`, `J`, `T`, `RID`, `DBQ`, `DC`, `check`,
`book`, …). Start it with `echo "<Section>"`, create the data it needs, and remove it again at the end:
the clean-up after it expects the state of the main script.

Prefer stable evidence over wording: the HTTP status, the database (`DBQ`), or a marker attribute of the template
(`data-<name>`, tested with `marker <name>`). Wording changes; a check that something is *not* shown would then
pass without testing anything. Add the attribute to the template when a check needs one.

At the end `tests/smoke.sh` fails if the app logged a PHP warning, notice or deprecation during the run
(`tests/php-errors.sh`).
