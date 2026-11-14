#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'HELP'
Usage: bash .github/wix-native/run.sh 8.4|8.5 [--build]
Runs ALL Wix tests, offline, in the repository-defined isolated Linux container.
Requires local Linux Docker (cgroup v2), Bash, Git, tar, sha256sum and vendor/.
--build explicitly permits downloading the pinned official PHP base and building
the sockets extension inside Docker. Without it, a missing image is an error.
Logs and JUnit: test-results/wix-native-8.4/ or wix-native-8.5/.
No host packages, cgroup changes, .env, Docker socket mounts or external WIX calls.
HELP
}

if [[ ${1:-} == --help || ${1:-} == -h ]]; then usage; exit 0; fi
version=${1:-}
case "$version" in 8.4|8.5) ;; *) usage >&2; exit 64 ;; esac
if [[ $# -gt 2 || ( $# -eq 2 && $2 != --build ) ]]; then usage >&2; exit 64; fi

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
for command in docker git tar sha256sum; do
    command -v "$command" >/dev/null || { echo "Missing prerequisite: $command" >&2; exit 69; }
done
docker info >/dev/null 2>&1 || { echo 'Docker daemon unavailable. Start local Docker; no host changes are made.' >&2; exit 69; }
[[ -f vendor/autoload.php ]] || { echo 'Missing vendor/. Run composer install from composer.lock first.' >&2; exit 69; }
source .github/wix-native/images.env
case "$version" in 8.4) base=$PHP84_BASE ;; 8.5) base=$PHP85_BASE ;; esac
recipe=$(cat .github/wix-native/Dockerfile .github/wix-native/images.env | sha256sum)
image="filtroven-wix-native:${version}-${recipe:0:16}"
reports="$root/test-results/wix-native-$version"
mkdir -p "$reports"
if [[ ${2:-} == --build ]]; then
    docker build --platform linux/amd64 --build-arg "PHP_BASE=$base" -t "$image" - \
        < .github/wix-native/Dockerfile 2>&1 | tee "$reports/build.log"
fi
docker image inspect "$image" >/dev/null 2>&1 || {
    echo "Missing image $image. Re-run with --build to prepare it explicitly." >&2; exit 69;
}
rm -f -- "$reports/junit.xml"

scratch=$(mktemp -d "${TMPDIR:-/tmp}/filtroven-wix-native.XXXXXXXX")
name="filtroven-wix-native-${version//./}-$$"
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    rm -rf -- "$scratch"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$scratch/source" "$scratch/vendor" "$scratch/results"
# Only this private, empty artifact directory is writable by container UID 1000.
chmod 777 "$scratch/results"
tar --exclude='bootstrap/cache/*' --exclude='tests/.pest' \
    --exclude='resources/js/actions' --exclude='resources/js/routes' --exclude='resources/js/wayfinder' \
    -cf - app bootstrap config database resources routes tests artisan \
    composer.json composer.lock phpunit.xml PRD-15.md .github/wix-native \
    | tar -xf - -C "$scratch/source"
mkdir -p "$scratch/source/storage" "$scratch/source/bootstrap/cache" "$scratch/source/vendor"
# Snapshot dependencies too: Docker must never create missing nested cache
# mountpoints in the developer's vendor directory (fresh installs differ).
tar -C "$root/vendor" --exclude='./pestphp/pest/.temp' \
    --exclude='./pestphp/pest-plugin-browser/.temp' -cf - . \
    | tar -xf - -C "$scratch/vendor"
mkdir -p "$scratch/vendor/pestphp/pest/.temp" "$scratch/vendor/pestphp/pest-plugin-browser/.temp"
(
    cd "$scratch/source"
    find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$reports/source-sha256.txt"
docker image inspect --format '{{.Id}}' "$image" > "$reports/image-id.txt"
printf 'PHP base: %s\nRecipe: %s\n' "$base" "${recipe%% *}" > "$reports/recipe.txt"

set +e
docker run --name "$name" --pull=never --platform linux/amd64 \
    --network=none --read-only --user 1000:1000 --cap-drop=ALL \
    --security-opt=no-new-privileges --cpus=1 --memory=512m --memory-swap=512m --pids-limit=64 \
    --mount "type=bind,src=$scratch/source,dst=/work,readonly" \
    --mount "type=bind,src=$scratch/vendor,dst=/work/vendor,readonly" \
    --mount "type=bind,src=$scratch/results,dst=/results" \
    --tmpfs /tmp:rw,nosuid,nodev,size=128m,mode=1777 \
    --tmpfs /work/storage:rw,nosuid,nodev,size=32m,uid=1000,gid=1000 \
    --tmpfs /work/bootstrap/cache:rw,nosuid,nodev,size=8m,uid=1000,gid=1000 \
    --tmpfs /work/vendor/pestphp/pest/.temp:rw,nosuid,nodev,size=8m,uid=1000,gid=1000 \
    --tmpfs /work/vendor/pestphp/pest-plugin-browser/.temp:rw,nosuid,nodev,size=8m,uid=1000,gid=1000 \
    --env APP_ENV=testing --env DB_CONNECTION=sqlite --env DB_DATABASE=:memory: --env DB_URL= \
    --env CACHE_STORE=array --env SESSION_DRIVER=array --env QUEUE_CONNECTION=sync \
    --env MAIL_MAILER=array --env LOG_CHANNEL=stderr \
    --env APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    --env "WIX_TEST_PHP=$version" \
    "$image" bash .github/wix-native/container.sh 2>&1 | tee "$reports/run.log"
status=${PIPESTATUS[0]}
set -e
cp -R "$scratch/results/." "$reports/"
if ! sha256sum -c "$reports/source-sha256.txt" > "$reports/source-integrity.log" 2>&1; then
    echo 'Source changed during the native run; see source-integrity.log. Re-run against a stable tree.' >&2
    status=1
fi
exit "$status"
