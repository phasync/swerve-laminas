<?php

/*
 * Requests overlapping in one worker. Each request to /test/interleave/v<i> puts its own value in
 * every place a request's state lives (superglobals, laminas-session, the head title and layout,
 * the doctype, the locale), waits 200 ms (usleep() with phasync-ext, phasync::sleep() without) and
 * reads each back: what it reads that isn't its own leaked from another request.
 *
 * "Unlocked" runs tests/Fixtures/swerve-unlocked.php, four handlers in the worker, one per
 * request: the handler's lock taken out. It shows what the lock keeps apart. See docs/concurrency.md.
 */

const WAIT = 0.2;

const SUPERGLOBALS = ['after.cookie', 'after.get', 'after.post', 'after.server'];

/**
 * Four overlapping requests to /test/interleave, each with its own query, form body, cookie,
 * header, host, doctype and locale.
 *
 * @return array{0: list<string>, 1: float} what leaked (into any of them), and how long they took
 */
function interleave(string $addr): array
{
    $locales  = ['en_US', 'nb_NO', 'de_DE', 'fr_FR'];
    $doctypes = ['HTML5', 'XHTML1_STRICT', 'HTML4_LOOSE', 'XHTML11'];
    $requests = [];
    foreach ($locales as $i => $locale) {
        $requests[] = ['POST', "/test/interleave/v$i?v=v$i&locale=$locale&doctype={$doctypes[$i]}&ms=" . WAIT * 1000,
            ["X-Value: v$i", "Cookie: c=v$i", "Host: h$i.test", 'Content-Type: application/x-www-form-urlencoded'], "v=v$i"];
    }
    $started   = \microtime(true);
    $responses = (new Browser($addr))->concurrently($requests);
    $elapsed   = \microtime(true) - $started;
    $leaks     = [];
    foreach ($responses as $i => $response) {
        expect($response['status'])->toBe(200, $response['body']);
        $seen = json($response);
        $own  = [
            'server-url' => "http://h$i.test",
            'locale'     => $locales[$i],
            'doctype'    => $doctypes[$i],
        ];
        foreach (['before', 'after'] as $when) {
            foreach ($seen[$when] as $name => $value) {
                if ($value !== "v$i") {
                    $leaks[] = "$when.$name";
                }
            }
        }
        foreach ($seen as $name => $value) {
            $mine = match (true) {
                \is_array($value)              => true, // before, after: above
                \is_bool($value)               => $value, // same-session-id, default-manager
                \array_key_exists($name, $own) => $own[$name] === $value,
                default                        => \str_contains((string) $value, "v$i"),
            };
            if (!$mine) {
                $leaks[] = $name;
            }
        }
    }
    $leaks = \array_values(\array_unique($leaks));
    \sort($leaks);

    return [$leaks, $elapsed];
}

/**
 * Four overlapping requests with PHP's own session, each putting its value in $_SESSION, and
 * the $also requests with them.
 *
 * @return list<array{status: int, headers: array<string, list<string>>, body: string, time: float}>
 */
function native_sessions(string $addr, array $also = []): array
{
    $requests = [];
    for ($i = 0; $i < 4; ++$i) {
        $requests[] = ['GET', "/test/native-session/v$i?ms=" . WAIT * 1000];
    }

    return (new Browser($addr))->concurrently([...$requests, ...$also]);
}

/** How many of native_sessions()' requests read another's $_SESSION. */
function native_session_leaks(array $responses): int
{
    $leaks = 0;
    for ($i = 0; $i < 4; ++$i) {
        $leaks += json($responses[$i])['session'] !== "v$i";
    }

    return $leaks;
}

test('unlocked, with laminas-session: the superglobals, the session and statics leak', function () {
    with_app(function (string $addr) {
        [$leaks] = interleave($addr);
        // Laminas' Request, route match and view state belong to the application, new per request
        expect($leaks)->not->toContain('before.get', 'request', 'head-title', 'layout');
        // The superglobals and what reads them later (the ServerUrl helper), PHP's session and
        // $_SESSION, laminas-session's default manager, the doctype and the locale are the worker's
        expect($leaks)->toContain(...SUPERGLOBALS, ...['server-url', 'session', 'native-session', 'late-container', 'default-manager', 'doctype', 'locale']);
    }, workers: 1, script: '../swerve-unlocked.php');
});

test('unlocked, without laminas-session: the superglobals and PHP\'s $_SESSION leak', function () {
    with_app(function (string $addr) {
        expect(native_session_leaks(native_sessions($addr)))->toBeGreaterThan(0);
        [$leaks] = interleave($addr);
        expect($leaks)->toContain(...SUPERGLOBALS, ...['server-url', 'doctype', 'locale']);
    }, workers: 1, env: ['SWERVE_TEST_NO_SESSIONS' => '1'], script: '../swerve-unlocked.php');
});

test('one request at a time: nothing leaks', function (array $env) {
    with_app(function (string $addr) {
        [$leaks, $elapsed] = interleave($addr);
        expect($leaks)->toBe([])->and($elapsed)->toBeGreaterThan(4 * WAIT);
        $responses = native_sessions($addr);
        expect(native_session_leaks($responses))->toBe(0);
    }, workers: 1, env: $env);
})->with([
    'with laminas-session'    => [[]],
    'without laminas-session' => [['SWERVE_TEST_NO_SESSIONS' => '1']],
]);
