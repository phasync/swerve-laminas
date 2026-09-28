<?php

/*
 * Swerve\Http\WebSocket from Laminas controller actions (tests/Fixtures/module): both ways,
 * server push through Swerve::subscribe(), clients leaving, the user, the worker's turn while
 * sockets are open, memory, drain and refusal.
 */

/** Every log line that reports a problem. */
function problems(string $log): array
{
    return \preg_grep('/error|warning|critical|exception|failed/i', \explode("\n", $log));
}

test('text and binary messages both ways, several in a row', function () {
    $log = with_app(function (string $addr) {
        $ws = ws_connect($addr, '/test/websocket');
        for ($i = 0; $i < 5; ++$i) {
            ws_send($ws, 1, "hello $i ✓");
        }
        for ($i = 0; $i < 5; ++$i) {
            expect(ws_read($ws))->toBe([1, "echo: hello $i ✓"]);
        }
        $binary = \random_bytes(70_000);
        ws_send($ws, 2, $binary);
        ws_send($ws, 2, "\x00\xFF");
        expect(ws_read($ws))->toBe([2, $binary])
            ->and(ws_read($ws))->toBe([2, "\x00\xFF"]);
        ws_send($ws, 8, \pack('n', 1000));
        expect(ws_read($ws))->toBe([8, \pack('n', 1000)])->and(ws_read($ws))->toBeNull();
    });
    expect(problems($log))->toBe([]);
});

test('server push: every client on both workers gets every message, in order', function () {
    $log = with_app(function (string $addr) {
        $clients = [];
        $pids    = [];
        // At least 8 clients, and until both workers have some
        while (\count($clients) < 8 || \count($pids) < 2) {
            $ws        = ws_connect($addr, '/test/news');
            [, $ready] = ws_read($ws);
            expect($ready)->toStartWith('ready ');
            $pids[\substr($ready, 6)] = true;
            $clients[]                = $ws;
            expect(\count($clients))->toBeLessThan(64);
        }
        // On one keep-alive connection, so from one worker: messages published one after
        // another from different workers may arrive in the other order (phasync/swerve#5)
        $curl = \curl_init();
        for ($i = 1; $i <= 20; ++$i) {
            \curl_setopt_array($curl, [\CURLOPT_URL => "http://$addr/test/publish?m=news-$i", \CURLOPT_RETURNTRANSFER => true]);
            \curl_exec($curl);
            expect(\curl_getinfo($curl, \CURLINFO_RESPONSE_CODE))->toBe(200);
        }
        foreach ($clients as $ws) {
            for ($i = 1; $i <= 20; ++$i) {
                expect(ws_read($ws))->toBe([1, "news-$i"]);
            }
        }
    });
    expect(problems($log))->toBe([]);
});

test('clients leaving, with a close frame or without a word: every callback ends', function () {
    $log = with_app(function (string $addr) {
        $clients = [];
        for ($i = 0; $i < 10; ++$i) {
            $clients[] = $ws = ws_connect($addr, $i % 2 ? '/test/news' : '/test/websocket');
            if ($i % 2) {
                ws_read($ws); // ready
            }
        }
        $clients[] = ws_connect($addr, '/test/news-bound');
        ws_read(\end($clients));
        expect(live_callbacks($addr, 2))->toBe(6);
        foreach ($clients as $i => $ws) {
            if ($i % 2) {
                ws_send($ws, 8, \pack('n', 1000));
                expect(ws_read($ws))->toBe([8, \pack('n', 1000)]);
            }
            \fclose($ws);
        }
        $deadline = \microtime(true) + 3;
        while (($live = live_callbacks($addr, 2)) > 0 && \microtime(true) < $deadline) {
            \usleep(50_000);
        }
        expect($live)->toBe(0);
    });
    expect(problems($log))->toBe([]);
});

test('the callback takes the user from the request before WebSocket::from()', function () {
    $log = with_app(function (string $addr) {
        $ada = new Browser($addr);
        $bob = new Browser($addr);
        expect($ada->request('POST', '/test/login', [], \http_build_query(['user' => 'ada', 'password' => 'secret']))['status'])->toBe(200)
            ->and($bob->request('POST', '/test/login', [], \http_build_query(['user' => 'bob', 'password' => 'secret']))['status'])->toBe(200);
        $cookie = fn (Browser $browser) => ['Cookie: laminas_session=' . $browser->cookies['laminas_session']];
        $adaWs  = ws_connect($addr, '/test/identity', $cookie($ada));
        $bobWs  = ws_connect($addr, '/test/identity', $cookie($bob));
        $anonWs = ws_connect($addr, '/test/identity');
        foreach ([[$adaWs, 'ada'], [$bobWs, 'bob'], [$anonWs, null], [$adaWs, 'ada']] as [$ws, $user]) {
            ws_send($ws, 1, 'who');
            expect(\json_decode(ws_read($ws)[1], true))->toBe(['user' => $user]);
        }

        // Read in the callback, the identity is the session of whatever request the worker runs
        // now: none between requests, and another visitor's during one
        ws_send($adaWs, 1, 'inside');
        expect(\json_decode(ws_read($adaWs)[1], true))->toBe(['user' => null]);
        $slow = \stream_socket_client("tcp://$addr");
        \fwrite($slow, "GET /test/isolation/eve HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        \usleep(150_000);
        ws_send($adaWs, 1, 'inside');
        expect(\json_decode(ws_read($adaWs)[1], true))->toBe(['user' => 'user-eve']);
        expect(\stream_get_contents($slow))->toContain('"identity":"user-eve"');
    }, workers: 1);
    expect(problems($log))->toBe([]);
});

/** Without laminas-session's SessionManager per request, which stays in memory (see LaminasTest). */
const SESSION_LEAK_OFF = ['SWERVE_TEST_DEFAULT_SESSION_MANAGER' => '1'];

/**
 * Open $n sockets to $path on one worker, then measure what they cost and how the worker serves
 * meanwhile: the bytes a socket held, the worker's memory after they closed, the slowest request,
 * the messages delivered.
 *
 * @return array{bytes: float, after: int, slowest: float, delivered: int}
 */
function hold_sockets(string $addr, string $path, int $n): array
{
    $browser = new Browser($addr);
    $memory  = fn () => json($browser->request('GET', '/test/memory'))['memory'];
    // A first socket, so that what one allocates once (classes, the worker's pub/sub) is not counted
    $first = ws_connect($addr, $path);
    ws_read($first);
    $before  = $memory();
    $sockets = [];
    for ($i = 0; $i < $n; ++$i) {
        $sockets[] = $ws = ws_connect($addr, $path);
        expect(ws_read($ws)[1])->toStartWith('ready ');
    }
    $bytes = ($memory() - $before) / $n;

    // Ordinary requests, while the sockets are open and each gets a message
    $slowest = 0.0;
    for ($i = 0; $i < 20; ++$i) {
        $started = \microtime(true);
        expect($browser->request('GET', $i % 2 ? '/' : "/test/publish?m=m$i")['status'])->toBe(200);
        $slowest = \max($slowest, \microtime(true) - $started);
    }
    $delivered = 0;
    foreach ($sockets as $ws) {
        for ($i = 0; $i < 20; $i += 2) {
            $delivered += (int) (ws_read($ws) === [1, "m$i"]);
        }
        \fclose($ws);
    }
    for ($i = 0; $i < 20; $i += 2) {
        ws_read($first);
    }
    \fclose($first);
    $deadline = \microtime(true) + 3;
    while (live_callbacks($addr, 1) > 0 && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return ['bytes' => $bytes, 'after' => $memory(), 'slowest' => $slowest, 'delivered' => $delivered];
}

test('200 open sockets on one worker hold neither its turn nor their applications', function () {
    $log = with_app(function (string $addr) {
        $held = hold_sockets($addr, '/test/news', 200);
        // What stays after the first round (pools, grown arrays) stays once: nothing more after the second
        $left = (hold_sockets($addr, '/test/news', 200)['after'] - $held['after']) / 200;
        expect($held['delivered'])->toBe(200 * 10)
            // Laminas runs one request at a time per worker: a socket holding that turn would
            // stop every request here
            ->and($held['slowest'])->toBeLessThan(0.5)
            // swerve's own WebSocket, its coroutines and subscription take 84 KiB; an
            // application would add about 300 KiB
            ->and($held['bytes'])->toBeLessThan(100_000.0)
            ->and($left)->toBeLessThan(500.0);
        \fwrite(\STDERR, \sprintf("\n  200 sockets: %.1f KiB each, %.2f KiB left after, slowest request %.0f ms\n", $held['bytes'] / 1024, $left / 1024, $held['slowest'] * 1000));
    }, workers: 1, env: SESSION_LEAK_OFF);
    expect(problems($log))->toBe([]);
});

test('a callback that is not static keeps its controller, and so its application, while the socket is open', function () {
    $log = with_app(function (string $addr) {
        $held = hold_sockets($addr, '/test/news-bound', 50);
        $left = (hold_sockets($addr, '/test/news-bound', 50)['after'] - $held['after']) / 50;
        expect($held['delivered'])->toBe(50 * 10)->and($held['bytes'])->toBeGreaterThan(250_000.0)
            // and lets it go when it closes
            ->and($left)->toBeLessThan(500.0);
        \fwrite(\STDERR, \sprintf("\n  bound closure: %.1f KiB a socket, %.2f KiB left after\n", $held['bytes'] / 1024, $left / 1024));
    }, workers: 1, env: SESSION_LEAK_OFF);
    expect(problems($log))->toBe([]);
});

test('SIGTERM with sockets open: 1001 to every client, callbacks end, exit code 0, a clean log', function () {
    [$proc, $addr, $log] = app_start(2);
    $clients             = [];
    for ($i = 0; $i < 10; ++$i) {
        $clients[] = $ws = ws_connect($addr, $i % 2 ? '/test/news' : '/test/websocket');
        if ($i % 2) {
            ws_read($ws);
        }
    }
    $started = \microtime(true);
    \proc_terminate($proc, \SIGTERM);
    foreach ($clients as $ws) {
        expect(ws_read($ws))->toBe([8, \pack('n', 1001)]);
        ws_send($ws, 8, \pack('n', 1001));
        \fclose($ws);
    }
    expect(app_wait($proc))->toBe(0)
        // Well before --grace=5, which would stop callbacks that don't end
        ->and(\microtime(true) - $started)->toBeLessThan(3.0);
    $contents = \file_get_contents($log);
    \unlink($log);
    expect(problems($contents))->toBe([]);
});

test('an ordinary GET to a WebSocket route is answered 426', function () {
    $log = with_app(function (string $addr) {
        $response = (new Browser($addr))->request('GET', '/test/news');
        expect($response['status'])->toBe(426)
            ->and($response['headers']['upgrade'])->toBe(['websocket']);
    });
    expect(problems($log))->toBe([]);
});
