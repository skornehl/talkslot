# Smoke test extensions

`tests/smoke.sh` sources every `*.sh` file in this directory in name order, just before its final
clean-up. Put the checks of one topic in one file (e.g. `security.sh`), so that changes to different
topics do not touch the same lines of `smoke.sh`.

A file runs with all helpers and variables of `smoke.sh` (`B`, `J`, `T`, `RID`, `DBQ`, `DC`, `check`,
`book`, …). Start it with `echo "<Section>"`, create the data it needs, and remove it again at the end:
the clean-up after it expects the state of the main script.
