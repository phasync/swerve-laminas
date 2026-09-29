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

/** Whether the handler runs requests in Virtual::run(): with phasync-ext 0.5.0-alpha11 or later. */
function virtual(string $addr): bool
{
    return json((new Browser($addr))->request('GET', '/test/memory'))['virtual'];
}

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

test('unlocked, with laminas-session: the superglobals, the session and statics leak; in Virtual::run(), not the superglobals', function () {
    with_app(function (string $addr) {
        [$leaks] = interleave($addr);
        // Laminas' Request, route match and view state belong to the application, new per request
        expect($leaks)->not->toContain('before.get', 'request', 'head-title', 'layout');
        // PHP's session and $_SESSION, laminas-session's default manager, the doctype and the
        // locale are the worker's; so are the superglobals, and what reads them later (the
        // ServerUrl helper), but in Virtual::run()
        expect($leaks)->toContain('session', 'native-session', 'late-container', 'default-manager', 'doctype', 'locale');
        if (virtual($addr)) {
            expect(\array_intersect($leaks, [...SUPERGLOBALS, 'server-url']))->toBe([]);
        } else {
            expect($leaks)->toContain(...SUPERGLOBALS, ...['server-url']);
        }
    }, workers: 1, script: '../swerve-unlocked.php');
});

test('unlocked, in Virtual::run(): a request that never starts a session empties another\'s', function () {
    with_app(function (string $addr) {
        if (!virtual($addr)) {
            $this->markTestSkipped('needs phasync-ext 0.5.0-alpha11 or later');
        }
        $browser          = new Browser($addr);
        [$session, $none] = $browser->concurrently([
            ['GET', '/test/interleave/mine?ms=300'],
            // Builds a SessionManager (Module::onBootstrap), starts no session, ends while the other runs
            ['GET', '/test/usleep?ms=100'],
        ]);
        expect($none['status'])->toBe(200)->and($session['status'])->toBe(200)
            // At its end, $_SESSION was written: the other request's session, which comes back empty
            ->and(json($session)['session'])->toBeNull();
        expect(json($browser->request('GET', '/test/peek'))['session'])->toBeNull();
    }, workers: 1, script: '../swerve-unlocked.php');
});

test('unlocked, without laminas-session: PHP\'s $_SESSION leaks, also in Virtual::run(); the superglobals but in it', function () {
    with_app(function (string $addr) {
        expect(native_session_leaks(native_sessions($addr)))->toBeGreaterThan(0);
        [$leaks] = interleave($addr);
        expect($leaks)->toContain('doctype', 'locale');
        if (virtual($addr)) {
            expect($leaks)->toBe(['doctype', 'locale']);
        } else {
            expect($leaks)->toContain(...SUPERGLOBALS, ...['server-url']);
        }
    }, workers: 1, env: ['SWERVE_TEST_NO_SESSIONS' => '1'], script: '../swerve-unlocked.php');
});

test('with laminas-session: one request at a time, nothing leaks', function () {
    with_app(function (string $addr) {
        [$leaks, $elapsed] = interleave($addr);
        expect($leaks)->toBe([])->and($elapsed)->toBeGreaterThan(4 * WAIT);
        expect(native_session_leaks(native_sessions($addr)))->toBe(0);
    }, workers: 1);
});

test('without laminas-session: requests overlap in Virtual::run(), sharing only the doctype and locale, statics', function () {
    with_app(function (string $addr) {
        [$leaks, $elapsed] = interleave($addr);
        if (virtual($addr)) {
            expect($leaks)->toBe(['doctype', 'locale'])->and($elapsed)->toBeLessThan(2 * WAIT);
        } else {
            expect($leaks)->toBe([])->and($elapsed)->toBeGreaterThan(4 * WAIT);
        }
    }, workers: 1, env: ['SWERVE_TEST_NO_SESSIONS' => '1']);
});

test('without laminas-session: requests with PHP\'s own session take turns, the others go on in Virtual::run()', function () {
    with_app(function (string $addr) {
        $responses = native_sessions($addr, [['GET', '/test/json']]);
        expect(native_session_leaks($responses))->toBe(0)
            // One after the other
            ->and(\max(\array_column(\array_slice($responses, 0, 4), 'time')))->toBeGreaterThan(4 * WAIT)
            ->and($responses[4]['status'])->toBe(200);
        if (virtual($addr)) {
            expect($responses[4]['time'])->toBeLessThan(WAIT);
        }
    }, workers: 1, env: ['SWERVE_TEST_NO_SESSIONS' => '1']);
});
