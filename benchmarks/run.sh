#!/bin/bash
# PHP-FPM (behind nginx) against swerve, with and without phasync-ext, on the Laminas MVC skeleton
# of tests/Fixtures/app (tests/create-app.sh), 4 processes each. The application runs as in
# production: development mode off, so the configuration cache is on; opcache on everywhere.
# The test module's per-request SessionManager (Module::onBootstrap) is taken out, so both run
# the skeleton's own session setup: see the README on laminas-session's per-request managers.
#
#   benchmarks/run.sh > benchmarks/results.txt
#
# laminas-mvc 3.8 declares PHP up to 8.4; this machine's PHP-FPM is 8.5, so both sides run the
# application on PHP 8.5, which it runs on without a deprecation.
#
# Needs wrk, and PHP-FPM + nginx in user space (FPM_BENCH, default /home/frode/dev/fpm-bench).
# Only one benchmark runs at a time on the machine: every wrk run takes $FPM_BENCH/bench.lock.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
fpm=${FPM_BENCH:-/home/frode/dev/fpm-bench}
ext=${PHASYNC_EXT:-/home/frode/dev/phasync-ext/modules/phasync.so}
php=${PHP:-php8.5}
port=${PORT:-19001}
app=$(mktemp -d /tmp/swerve-laminas-bench.XXXXXX)
trap 'kill $(jobs -p) 2>/dev/null; rm -rf "$app"; true' EXIT
cp -a "$here/tests/Fixtures/app/." "$app"
cd "$app"
sed -i '/function onBootstrap/,/^    }/d' module/SwerveTest/src/Module.php
rm -f "$app"/data/cache/*.php
# The adapter from this checkout, wherever the copy is; an optimized autoloader, as in production
$php -r '$c = json_decode(file_get_contents("composer.json"), true); $c["autoload"]["psr-4"]["Swerve\\Laminas\\"] = $argv[1]; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' "$here/src/"
$php /usr/bin/composer dump-autoload -q --optimize

echo "# $(date -u +%F) $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) CPUs"
echo "# $($php -r 'echo "PHP ", PHP_VERSION;'), laminas-mvc $($php /usr/bin/composer show laminas/laminas-mvc | sed -n 's/^versions : \* //p'), $($php -d extension="$ext" vendor/bin/swerve --version | tr '\n' ' ')"
echo "# wrk -t4 -c64 -d10s after a 3 s warm-up; 4 PHP-FPM children or 4 swerve workers"

wait_for() { until curl -s -o /dev/null "http://127.0.0.1:$port/test/json"; do sleep 0.2; done; }
run() { # name
    # A session to come back to, for the counter
    cookie=$(curl -s -c - -o /dev/null "http://127.0.0.1:$port/test/counter" | awk '$6 == "laminas_session" {print $6 "=" $7}')
    for path in /test/json / /test/counter; do
        header=(); [ "$path" = /test/counter ] && header=(-H "Cookie: $cookie")
        flock "$fpm/bench.lock" wrk -t4 -c64 -d3s "${header[@]}" "http://127.0.0.1:$port$path" > /dev/null
        echo "## $1 $path"
        flock "$fpm/bench.lock" wrk -t4 -c64 -d10s "${header[@]}" "http://127.0.0.1:$port$path"
    done
}

"$fpm/serve.sh" "$app/public" "$port" 4 & pid=$!
wait_for; run php-fpm; kill $pid; wait $pid 2>/dev/null || true

for cmd in "$php -d opcache.enable_cli=1" "$php -d opcache.enable_cli=1 -d extension=$ext"; do
    $cmd vendor/bin/swerve --workers=4 --http=127.0.0.1:$port --public=public -q swerve.php & pid=$!
    wait_for; run "swerve$([[ $cmd == *extension* ]] && echo ' + phasync-ext')"; kill $pid; wait $pid 2>/dev/null || true
done
