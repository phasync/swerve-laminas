<?php

/*
 * The tests run the Laminas MVC application in tests/Fixtures/app (made by tests/create-app.sh)
 * on a real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it. SWERVE_TEST_PORTS is the range of ports
 * the tests may listen on (default 19010-19049).
 */

const APP = __DIR__ . '/Fixtures/app';

/**
 * Start swerve on a free port with the fixture application and wait until it answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    [$from, $to] = \array_map('intval', \explode('-', \getenv('SWERVE_TEST_PORTS') ?: '19010-19049'));
    // A port in use and swerve not answering yet warn
    \set_error_handler(fn () => true);
    for ($port = $from; $port <= $to; ++$port) {
        if ($socket = \stream_socket_server("tcp://127.0.0.1:$port")) {
            \fclose($socket);
            break;
        }
    }
    $addr     = "127.0.0.1:$port";
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-laminas-log');
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg(APP . '/vendor/bin/swerve') . " --workers=$workers --grace=5 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg(APP . '/swerve.php');
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, APP, $env + \getenv());
    $deadline = \microtime(true) + 20;
    while (false === \file_get_contents("http://$addr/test/json", false, \stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]))) {
        if (\microtime(true) > $deadline) {
            \restore_error_handler();
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }
    \restore_error_handler();

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);

    return app_wait($proc);
}

/** Wait for swerve to exit, and return its exit code. */
function app_wait($proc): int
{
    $deadline = \microtime(true) + 10;
    while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    \proc_close($proc);

    // Before PHP 8.3, only the first proc_get_status() after the exit has the exit code
    return $status['exitcode'];
}

/** Run $test against a swerve serving the fixture application, then stop it; returns its log. */
function with_app(Closure $test, int $workers = 2, array $env = []): string
{
    [$proc, $addr, $log] = app_start($workers, $env);
    try {
        $test($addr);
    } finally {
        app_stop($proc);
    }
    $contents = \file_get_contents($log);
    \unlink($log);

    return $contents;
}

/** A browser: a cookie jar, and requests each on a connection of its own. */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(public readonly string $addr)
    {
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    public function request(string $method, string $path, array $headers = [], string|array|null $body = null): array
    {
        $curl = $this->handle($method, $path, $headers, $body);

        return $this->response($curl, (string) \curl_exec($curl));
    }

    /**
     * Requests at the same time.
     *
     * @param list<array{0: string, 1: string, 2?: array, 3?: string|array|null}> $requests method, path, headers, body
     *
     * @return list<array{status: int, headers: array<string, list<string>>, body: string}>
     */
    public function concurrently(array $requests): array
    {
        $multi   = \curl_multi_init();
        $handles = [];
        foreach ($requests as $request) {
            \curl_multi_add_handle($multi, $handles[] = $this->handle($request[0], $request[1], $request[2] ?? [], $request[3] ?? null));
        }
        do {
            \curl_multi_exec($multi, $running);
            \curl_multi_select($multi, 0.05);
        } while ($running > 0);

        return \array_map(fn ($curl) => $this->response($curl, (string) \curl_multi_getcontent($curl)), $handles);
    }

    private function handle(string $method, string $path, array $headers, string|array|null $body): CurlHandle
    {
        $curl = \curl_init("http://{$this->addr}$path");
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . \implode('; ', \array_map(fn ($name, $value) => "$name=$value", \array_keys($this->cookies), $this->cookies));
        }
        \curl_setopt_array($curl, [
            \CURLOPT_CUSTOMREQUEST  => $method,
            \CURLOPT_HTTPHEADER     => [...$headers, 'Connection: close'],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER         => true,
            \CURLOPT_TIMEOUT        => 30,
            \CURLOPT_FORBID_REUSE   => true,
        ]);
        if (null !== $body) {
            \curl_setopt($curl, \CURLOPT_POSTFIELDS, $body);
        }

        return $curl;
    }

    private function response(CurlHandle $curl, string $raw): array
    {
        $size    = \curl_getinfo($curl, \CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (\array_slice(\explode("\r\n", \trim(\substr($raw, 0, $size))), 1) as $line) {
            [$name, $value]                = \explode(':', $line, 2);
            $headers[\strtolower($name)][] = \trim($value);
        }
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            [$name, $value]       = \explode('=', \explode(';', $cookie)[0], 2);
            $this->cookies[$name] = $value;
        }

        return ['status' => \curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => \substr($raw, $size)];
    }
}

/** A response's body as JSON. */
function json(array $response): array
{
    return \json_decode($response['body'], true, flags: \JSON_THROW_ON_ERROR);
}

/**
 * Open a WebSocket to $path, as a browser does, with the given extra header lines.
 *
 * @return resource
 */
function ws_connect(string $addr, string $path, array $headers = [])
{
    $socket = \stream_socket_client("tcp://$addr", $errno, $error, 5);
    \stream_set_timeout($socket, 10);
    $key = \base64_encode(\random_bytes(16));
    \fwrite($socket, "GET $path HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n" . \implode('', \array_map(fn ($line) => "$line\r\n", $headers)) . "\r\n");
    $head = '';
    while (!\str_contains($head, "\r\n\r\n") && '' !== ($byte = (string) \fread($socket, 1))) {
        $head .= $byte;
    }
    if (!\str_starts_with($head, 'HTTP/1.1 101') || !\str_contains($head, 'Sec-WebSocket-Accept: ' . \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)))) {
        throw new RuntimeException("Not a WebSocket handshake:\n$head");
    }

    return $socket;
}

/** Send a frame, masked as a client must. */
function ws_send($socket, int $opcode, string $payload, bool $fin = true): void
{
    $length = \strlen($payload);
    $mask   = \random_bytes(4);
    $head   = \chr(($fin ? 0x80 : 0) | $opcode) . match (true) {
        $length < 126   => \chr(0x80 | $length),
        $length < 65536 => \chr(0x80 | 126) . \pack('n', $length),
        default         => \chr(0x80 | 127) . \pack('J', $length),
    };
    \fwrite($socket, $head . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($length, 4) + 1), 0, $length)));
}

/** Read exactly $length bytes, or fewer when the connection ends. */
function ws_bytes($socket, int $length): string
{
    $bytes = '';
    while (\strlen($bytes) < $length && '' !== ($chunk = (string) \fread($socket, $length - \strlen($bytes)))) {
        $bytes .= $chunk;
    }

    return $bytes;
}

/**
 * The next frame from the server, or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null opcode and payload
 */
function ws_read($socket): ?array
{
    $head = ws_bytes($socket, 2);
    if (\strlen($head) < 2) {
        return null;
    }
    $length = \ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = \unpack('n', ws_bytes($socket, 2))[1];
    } elseif (127 === $length) {
        $length = \unpack('J', ws_bytes($socket, 8))[1];
    }

    return [\ord($head[0]) & 0x0F, $length > 0 ? ws_bytes($socket, $length) : ''];
}

/** The WebSocket callbacks running, over the workers: $workers answers from different ones. */
function live_callbacks(string $addr, int $workers): int
{
    $seen = [];
    for ($i = 0; \count($seen) < $workers; ++$i) {
        if ($i > 200) {
            throw new RuntimeException('Not every worker answered');
        }
        $live               = json((new Browser($addr))->request('GET', '/test/live'));
        $seen[$live['pid']] = $live['callbacks'];
    }

    return \array_sum($seen);
}
