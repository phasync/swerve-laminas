<?php

/*
 * The Laminas MVC skeleton with the test module of tests/Fixtures/module on a real swerve. The
 * application runs in production mode, with its configuration cache.
 */

beforeAll(function () {
    [$proc, $addr, $log] = app_start(2);
    $GLOBALS['app']      = ['proc' => $proc, 'addr' => $addr, 'log' => $log];
});

afterAll(function () {
    app_stop($GLOBALS['app']['proc']);
    \unlink($GLOBALS['app']['log']);
});

function browser(): Browser
{
    return new Browser($GLOBALS['app']['addr']);
}

test('phasync-ext is loaded when SWERVE_PHP_ARGS loads it', function () {
    expect(json(browser()->request('GET', '/test/memory'))['phasync-ext'])->toBe(\str_contains((string) \getenv('SWERVE_PHP_ARGS'), 'phasync'));
});

test('the skeleton home page', function () {
    $response = browser()->request('GET', '/');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toBe(['text/html; charset=UTF-8'])
        ->and($response['body'])->toContain('<title>Laminas MVC Skeleton</title>');
    // The layout's head title is set per request: a reused application would repeat it
    expect(browser()->request('GET', '/')['body'])->toContain('<title>Laminas MVC Skeleton</title>');
});

test('a JSON route', function () {
    $response = browser()->request('GET', '/test/json');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toBe(['application/json; charset=utf-8'])
        ->and(json($response))->toBe(['framework' => 'laminas', 'ok' => true]);
});

test('a 404 is the skeleton\'s not found page', function () {
    $response = browser()->request('GET', '/no/such/page');
    expect($response['status'])->toBe(404)->and($response['body'])->toContain('A 404 error occurred');
});

test('an exception is the skeleton\'s error page', function () {
    $response = browser()->request('GET', '/test/throw');
    expect($response['status'])->toBe(500)->and($response['body'])->toContain('Thrown on purpose');
});

test('a form POST with a CSRF token', function () {
    $browser = browser();
    $form    = $browser->request('GET', '/test/form');
    expect(\preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $m))->toBe(1);

    $response = $browser->request('POST', '/test/form', [], \http_build_query(['name' => 'Ada', 'csrf' => $m[1]]));
    expect($response['status'])->toBe(200)->and(json($response))->toBe(['name' => 'Ada']);

    $forged = $browser->request('POST', '/test/form', [], \http_build_query(['name' => 'Ada', 'csrf' => 'forged']));
    expect($forged['status'])->toBe(400);
    $elsewhere = browser()->request('POST', '/test/form', [], \http_build_query(['name' => 'Ada', 'csrf' => $m[1]]));
    expect($elsewhere['status'])->toBe(400);
});

test('a JSON POST', function () {
    $response = browser()->request('POST', '/test/json-post', ['Content-Type: application/json'], '{"list":[1,2,3],"name":"Ada"}');
    expect(json($response))->toBe(['received' => ['list' => [1, 2, 3], 'name' => 'Ada']]);
});

test('an upload through a Laminas form with RenameUpload', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'swerve-laminas-test');
    \file_put_contents($file, \random_bytes(200_000));
    $response = browser()->request('POST', '/test/upload', [], ['title' => 'A file', 'file' => new CURLFile($file, 'application/octet-stream', 'data.bin')]);
    expect($response['status'])->toBe(200)
        ->and(json($response))->toBe(['title' => 'A file', 'name' => 'data.bin', 'size' => 200_000, 'sha1' => \sha1_file($file)]);
    \unlink($file);
});

test('overlapping requests each see only their own request, route, session, identity and services', function () {
    $requests = [];
    for ($i = 0; $i < 10; ++$i) {
        $requests[] = ['GET', "/test/isolation/v$i?v=v$i", ["X-Value: v$i"]];
    }
    $started   = \microtime(true);
    $responses = browser()->concurrently($requests);
    $ids       = [];
    foreach ($responses as $i => $response) {
        expect($response['status'])->toBe(200)->and(json($response))->toBe([
            'route'    => "v$i",
            'url'      => "/test/isolation/v$i",
            'query'    => "v$i",
            'header'   => "v$i",
            'server'   => "v$i",
            'session'  => "v$i",
            'identity' => "user-v$i",
            'service'  => "v$i",
        ]);
        $ids[] = $response['headers']['set-cookie'][0];
    }
    // A new session each, as each came without a cookie
    expect(\array_unique($ids))->toHaveCount(10)
        // One at a time per worker, 0.3 s each, over 2 workers
        ->and(\microtime(true) - $started)->toBeGreaterThan(1.4);
});

test('a visitor without a session cookie never gets the previous visitor\'s session', function () {
    // One worker, so that both visitors are served by the same process
    $log = with_app(function (string $addr) {
        $with = new Browser($addr);
        expect(json($with->request('GET', '/test/isolation/mine'))['session'])->toBe('mine');
        [$again, $without] = (new Browser($addr))->concurrently([
            ['GET', '/test/peek', ['Cookie: laminas_session=' . $with->cookies['laminas_session']]],
            ['GET', '/test/peek'],
        ]);
        expect(json($again))->toBe(['session' => 'mine', 'identity' => 'user-mine'])
            ->and(json($without))->toBe(['session' => null, 'identity' => null]);
        // And after it, on the same connection-less worker
        $after = new Browser($addr);
        expect(json($after->request('GET', '/test/peek')))->toBe(['session' => null, 'identity' => null])
            ->and($after->cookies['laminas_session'])->not->toBe($with->cookies['laminas_session']);
    }, workers: 1);
    expect($log)->not->toContain('error');
});

test('a session counter counts across requests on different workers', function () {
    $browser = browser();
    $pids    = [];
    for ($i = 1; $i <= 16; ++$i) {
        $response = $browser->request('GET', '/test/counter');
        expect(json($response)['count'])->toBe($i);
        $pids[json($response)['pid']] = true;
    }
    expect($pids)->toHaveCount(2)
        // PHP-FPM's session headers: the cookie once, and no caching of a page with a session
        ->and($response['headers'])->not->toHaveKey('set-cookie')
        ->and($response['headers']['cache-control'])->toBe(['no-store, no-cache, must-revalidate']);
});

test('a flash message is shown once', function () {
    $browser  = browser();
    $redirect = $browser->request('GET', '/test/flash');
    expect($redirect['status'])->toBe(302)->and($redirect['headers']['location'])->toBe(['/test/flashes']);
    expect(json($browser->request('GET', '/test/flashes')))->toBe(['messages' => ['Saved']])
        ->and(json($browser->request('GET', '/test/flashes')))->toBe(['messages' => []]);
});

test('login and logout with laminas-authentication', function () {
    $browser = browser();
    expect(json($browser->request('GET', '/test/whoami')))->toBe(['user' => null]);
    expect($browser->request('POST', '/test/login', [], \http_build_query(['user' => 'ada', 'password' => 'wrong']))['status'])->toBe(401);
    $before = $browser->cookies['laminas_session'];
    $login  = $browser->request('POST', '/test/login', [], \http_build_query(['user' => 'ada', 'password' => 'secret']));
    // The session id changed on login, and the browser was given the new one
    expect(json($login))->toBe(['user' => 'ada'])
        ->and($browser->cookies['laminas_session'])->not->toBe($before)
        ->and(json($browser->request('GET', '/test/whoami')))->toBe(['user' => 'ada'])
        ->and(json((new Browser($GLOBALS['app']['addr']))->request('GET', '/test/whoami')))->toBe(['user' => null]);
    $browser->request('POST', '/test/logout');
    expect(json($browser->request('GET', '/test/whoami')))->toBe(['user' => null]);
});

test('a stream response arrives as it is written', function () {
    $socket = \stream_socket_client('tcp://' . $GLOBALS['app']['addr']);
    \fwrite($socket, "GET /test/stream HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $started  = \microtime(true);
    $received = '';
    $first    = null;
    while (!\feof($socket)) {
        $received .= \fread($socket, 8192);
        if (null === $first && \str_contains($received, "first\n")) {
            $first = \microtime(true) - $started;
            expect($received)->not->toContain("last\n");
        }
    }
    $last = \microtime(true) - $started;
    expect($received)->toContain('Transfer-Encoding: chunked')->toContain("last\n")
        ->and($first)->toBeLessThan(0.4)
        ->and($last - $first)->toBeGreaterThan(0.4);
});

test('a file download, the file deleted after it was sent', function () {
    $response = browser()->request('GET', '/test/download');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-length'])->toBe(['1000000'])
        ->and($response['body'])->toBe(\str_repeat('0123456789', 100_000));
    \usleep(100_000);
    expect(\file_exists($response['headers']['x-path'][0]))->toBeFalse();
});

test('a WebSocket from a controller action', function () {
    $socket = \stream_socket_client('tcp://' . $GLOBALS['app']['addr']);
    $key    = \base64_encode(\random_bytes(16));
    \fwrite($socket, "GET /test/websocket HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!\str_contains($head, "\r\n\r\n")) {
        $head .= \fread($socket, 1);
    }
    expect($head)->toStartWith('HTTP/1.1 101')
        ->toContain('Sec-WebSocket-Accept: ' . \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    $mask = \random_bytes(4);
    \fwrite($socket, "\x81" . \chr(0x80 | 5) . $mask . ('hello' ^ \str_repeat($mask, 2)));
    $frame = \fread($socket, 2);
    expect(\ord($frame[0]))->toBe(0x81)->and(\fread($socket, \ord($frame[1])))->toBe('echo: hello');
    \fwrite($socket, "\x88" . \chr(0x80) . \random_bytes(4));
    \fclose($socket);
});

test('SIGTERM during a slow request: the request completes, the log has no errors', function () {
    [$proc, $addr, $log] = app_start(1);
    $socket              = \stream_socket_client("tcp://$addr");
    \fwrite($socket, "GET /test/slow HTTP/1.1\r\nHost: localhost\r\n\r\n");
    \usleep(300_000);
    \proc_terminate($proc, \SIGTERM);
    $response = \stream_get_contents($socket);
    expect($response)->toStartWith('HTTP/1.1 200')->toContain('{"slow":"done"}');
    $deadline = \microtime(true) + 10;
    while (\proc_get_status($proc)['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    expect(\proc_close($proc))->toBe(0);
    $contents = \file_get_contents($log);
    \unlink($log);
    expect($contents)->toContain('GET /test/slow 200')->not->toContain('error')->not->toContain('Warning');
});

/**
 * Memory growth per request over 10,000 requests, in bytes, alternating the home page and a page
 * with a session.
 */
function growth(array $env): float
{
    $growth = 0;
    $log    = with_app(function (string $addr) use (&$growth) {
        $browser = new Browser($addr);
        $memory  = fn () => json($browser->request('GET', '/test/memory'))['memory'];
        $run     = function (int $n) use ($addr, $browser) {
            $curl = \curl_init();
            for ($i = 0; $i < $n; ++$i) {
                \curl_setopt_array($curl, [\CURLOPT_URL => "http://$addr" . ($i % 2 ? '/' : '/test/counter'), \CURLOPT_RETURNTRANSFER => true, \CURLOPT_COOKIE => 'laminas_session=' . $browser->cookies['laminas_session']]);
                \curl_exec($curl);
                expect(\curl_getinfo($curl, \CURLINFO_RESPONSE_CODE))->toBe(200);
            }
        };
        $browser->request('GET', '/test/counter');
        $run(1_000);
        $before = $memory();
        $run(10_000);
        $growth = ($memory() - $before) / 10_000;
    }, workers: 1, env: $env);
    expect($log)->not->toContain('error');

    return $growth;
}

test('memory stays flat over 10,000 requests', function () {
    expect(growth(['SWERVE_TEST_DEFAULT_SESSION_MANAGER' => '1']))->toBeLessThan(10.0);
});

test('a laminas-session SessionManager per request stays in memory: 3 to 4 KiB a request', function () {
    // SessionManager::__construct() registers its writeClose() as a shutdown function, which keeps
    // the manager until the worker exits. Pinned here, and in the README.
    expect(growth([]))->toBeGreaterThan(2_500.0)->toBeLessThan(4_500.0);
});
