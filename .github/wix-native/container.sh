#!/usr/bin/env bash
set -euo pipefail
mkdir -p storage/framework/{cache,sessions,views} storage/logs
php .github/wix-native/preflight.php
# External wall-clock bound, including laboratory cleanup. TERM then KILL.
exec timeout --signal=TERM --kill-after=10s 300s php vendor/bin/pest \
    --ci --testsuite=Wix --compact --colors=never --do-not-cache-result \
    --cache-directory=/tmp/phpunit-cache --log-junit=/results/junit.xml \
    --fail-on-empty-test-suite --fail-on-warning --fail-on-notice \
    --fail-on-deprecation --fail-on-phpunit-deprecation --fail-on-skipped \
    --fail-on-incomplete --fail-on-risky
