#!/bin/sh
# Optional one-shot wrapper for a bounded RC2 baseline file check.
# Usage in a temporary DirectAdmin cron: /bin/sh /private/kit/run-once.sh /absolute/project-root
# This does not install the RC2 archive or inspect configuration/database data.
# The operator must ensure the kit is outside the project and EVERY webroot.
set -u
umask 077
LC_ALL=C
export LC_ALL

fail_before_run() {
    # Never echo the user's absolute paths into cron mail.
    printf '%s\n' "$1" >&2
    exit "$2"
}

[ "$#" -eq 1 ] || fail_before_run 'Expected one absolute project-root path.' 64
root_arg=$1
case "$root_arg" in /*) ;; *) fail_before_run 'Project root must be an absolute path.' 64 ;; esac
while [ "$root_arg" != / ] && [ "${root_arg%/}" != "$root_arg" ]; do
    root_arg=${root_arg%/}
done
if [ "$root_arg" = / ] || [ -L "$root_arg" ]; then
    fail_before_run 'Project root is invalid or is a symlink.' 66
fi
root=$(CDPATH='' cd -P "$root_arg" 2>/dev/null && pwd -P) || fail_before_run 'Project root is unavailable.' 66
[ "$root" != / ] || fail_before_run 'Project root cannot be the filesystem root.' 66

kit_arg=$(dirname "$0")
[ ! -L "$kit_arg" ] || fail_before_run 'The kit directory cannot be a symlink.' 66
kit=$(CDPATH='' cd -P "$kit_arg" 2>/dev/null && pwd -P) || fail_before_run 'Private kit directory is unavailable.' 66
case "$kit/" in "$root/"*) fail_before_run 'Place the kit outside the project root and all webroots.' 66 ;; esac

# Require an operator-owned 0700 kit directory before writing the report.
if ! command -v stat >/dev/null 2>&1 || ! command -v id >/dev/null 2>&1; then
    fail_before_run 'Required privacy checks are unavailable.' 69
fi
mode=$(stat -c '%a' "$kit" 2>/dev/null) || fail_before_run 'Cannot check kit permissions.' 69
owner=$(stat -c '%u' "$kit" 2>/dev/null) || fail_before_run 'Cannot check kit owner.' 69
uid=$(id -u 2>/dev/null) || fail_before_run 'Cannot check process owner.' 69
if [ "$mode" != 700 ] || [ "$owner" != "$uid" ]; then
    fail_before_run 'Private kit must be owned by this user and have mode 0700.' 73
fi

# mkdir is atomic: even an overlapping or repeating cron scans only once.
# Never remove this marker while the temporary cron is still installed.
run="$kit/run-once"
mkdir "$run" 2>/dev/null || exit 0

finish_failure() {
    printf '{"decision":"STOP","errors":["%s"]}\n' "$1" > "$run/report.txt"
    printf '%s\n' "$2" > "$run/exit-code.txt"
    exit 0
}

for component in app database public_html; do
    if [ ! -d "$root/$component" ] || [ -L "$root/$component" ]; then
        finish_failure 'project_layout_unavailable_or_symlinked' 66
    fi
done
command -v sha256sum >/dev/null 2>&1 || finish_failure 'sha256sum_unavailable' 69
command -v timeout >/dev/null 2>&1 || finish_failure 'timeout_unavailable' 69
command -v php >/dev/null 2>&1 || finish_failure 'php_cli_unavailable' 69

script="$kit/rc2_read_only_preflight.php"
if [ ! -f "$script" ] || [ -L "$script" ]; then
    finish_failure 'preflight_script_unavailable' 66
fi
size=$(stat -c '%s' "$script" 2>/dev/null) || finish_failure 'preflight_script_unavailable' 66
[ "$size" -le 65536 ] || finish_failure 'preflight_script_unavailable' 66
line=$(sha256sum "$script" 2>/dev/null) || finish_failure 'preflight_script_unavailable' 66
actual=${line%% *}
expected=568b2ca17af161da43cc6d9e28505a1f0717f7f67af8d2332ae859478afb0ecd
[ "$actual" = "$expected" ] || finish_failure 'preflight_script_digest_mismatch' 66

# -n ignores PHP ini files and their hooks. JSON/hash must be built into CLI.
# The PHP tool verifies the manifest, reads only its listed paths, uses bounded
# reads and performs no database/network calls. Web PHP is not inspected.
timeout 90 php -n -d auto_prepend_file= -d auto_append_file= -d display_errors=0 -d log_errors=0 \
    "$script" --baseline-only "$root" "$kit/BASELINE-SHA256.txt" \
    > "$run/report.tmp" 2> "$run/error.tmp"
result=$?
# A wrong PHP SAPI can exit zero without producing a report. Fail closed.
if [ "$result" -eq 0 ] && [ ! -s "$run/report.tmp" ]; then
    printf '{"decision":"STOP","errors":["empty_preflight_output"]}\n' > "$run/report.tmp"
    result=69
fi
mv "$run/report.tmp" "$run/report.txt" || exit 1
mv "$run/error.tmp" "$run/error.txt" || exit 1
# Create this last; it means the other result files are complete.
printf '%s\n' "$result" > "$run/exit-code.txt"
exit 0
