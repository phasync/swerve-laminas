<?php

namespace Swerve\Laminas;

use Laminas\Http\Response as LaminasResponse;
use Laminas\Http\Response\Stream as StreamResponse;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Session\Config\ConfigInterface as SessionConfig;
use Laminas\Session\Storage\StorageInterface as SessionStorage;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\Parameters;
use phasync\Psr\ComposableStream;
use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;

/**
 * A Laminas MVC application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laminas\Handler(__DIR__);
 *
 * Once per worker: the application configuration is read as config/container.php reads it
 * (config/application.config.php, merged with config/development.config.php in development
 * mode), and one application is booted, so that classes are loaded and the configuration cache
 * is written before the first request.
 *
 * Per request: a new Laminas\Mvc\Application from Application::init(), with its own
 * ServiceManager, Request, Response and MvcEvent, as under PHP-FPM. Laminas keeps request state in
 * shared services (the layout view model, the head title and other placeholder helpers, the
 * route match) and has no mechanism to reset them, so an application is never reused.
 *
 * Concurrency: one request at a time per worker (phasync\Util\Synchronized). Laminas reads the
 * request from the superglobals, which this handler fills, and laminas-session keeps the session
 * in PHP's session module and $_SESSION; both are process-wide. See docs/concurrency.md.
 *
 * A controller action may return a PSR-7 response, such as Swerve\Http\WebSocket::from(); the
 * PSR-7 request is the Laminas request's metadata Psr\Http\Message\ServerRequestInterface. A
 * WebSocket's callback runs after the action returned, without the worker's turn and after the
 * session was closed: the action takes the user and session data the callback needs.
 */
final class Handler implements RequestHandlerInterface
{
    /** @var array<string, mixed> the application configuration, as Application::init() takes it */
    private array $config;

    /** @var array<string, mixed> the superglobal SERVER between requests */
    private array $server;

    private ?\SessionHandlerInterface $nullSessionHandler = null;

    /**
     * @param string $root the application's root directory, where composer.json is
     */
    public function __construct(string $root)
    {
        $root = \rtrim($root, '/');
        // As public/index.php does: the configuration's paths are relative to the root
        \chdir($root);
        $this->config = require "$root/config/application.config.php";
        if (\is_file("$root/config/development.config.php")) {
            $this->config = ArrayUtils::merge($this->config, require "$root/config/development.config.php");
        }

        $server = $_SERVER;
        unset($server['argv'], $server['argc'], $server['REQUEST_TIME'], $server['REQUEST_TIME_FLOAT']);
        $this->server = [
            'SCRIPT_NAME'     => '/index.php',
            'PHP_SELF'        => '/index.php',
            'SCRIPT_FILENAME' => "$root/public/index.php",
            'DOCUMENT_ROOT'   => "$root/public",
            'SERVER_SOFTWARE' => 'swerve',
        ] + $server;

        // PHP's session module refuses to start a session, or to change the session id, once
        // output was sent, and in the CLI any output counts. Output goes to the terminal, as
        // swerve's documentation says, without ever reaching the SAPI.
        \ob_start(static function (string $output): string {
            \fwrite(\STDOUT, $output);

            return '';
        }, 1);

        $services = Application::init($this->config)->getServiceManager();
        // laminas-session applies its configuration (the cookie's name among it) to PHP when
        // the configuration service is made; the cookie's name must be known before a request
        if (isset($services->get('config')['session_config']) && $services->has(SessionConfig::class)) {
            $services->get(SessionConfig::class);
        }
        $this->endSession();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, fn () => $this->run($request));
    }

    private function run(ServerRequestInterface $request): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $this->fillSuperglobals($request, $files);
        try {
            $app    = Application::init($this->config);
            $events = $app->getEventManager();
            // The response goes back to swerve, not out through the SAPI
            $app->getServiceManager()->get('SendResponseListener')->detach($events);
            // A PSR-7 response from a controller action is the response, as it is
            $events->attach(MvcEvent::EVENT_DISPATCH, static fn (MvcEvent $e) => $e->getResult() instanceof ResponseInterface ? $e->getResponse() : null, -1);

            $laminasRequest = $app->getRequest();
            $laminasRequest->setMetadata(ServerRequestInterface::class, $request);
            // Laminas' file inputs, validators and RenameUpload take PSR-7 uploads; PHP's
            // is_uploaded_file() and move_uploaded_file() know nothing of swerve's
            $laminasRequest->setFiles(new Parameters($files));
            // php://input, which Laminas reads, is empty in the CLI. An upgrade request's body
            // is the connection after the response, not a body to read now.
            if (!$request->hasHeader('upgrade')) {
                $laminasRequest->setContent((string) $request->getBody());
            }

            $app->run();

            $session = $this->sessionHeaders($request);
            $result  = $app->getMvcEvent()->getResult();
            if ($result instanceof ResponseInterface) {
                foreach ($session as $name => $value) {
                    $result = $result->withAddedHeader($name, $value);
                }

                return $result;
            }

            return $this->response($app->getResponse(), $session);
        } finally {
            $this->endSession();
            $_GET    = $_POST = $_COOKIE = $_FILES = $_REQUEST = [];
            $_SERVER = $this->server;
            // An application is a graph of cycles, 200 KiB and more, which phasync's cycle
            // collector would let pile up for half a second. Collected now, it costs less.
            unset($app, $events, $laminasRequest);
            \gc_collect_cycles();
        }
    }

    /**
     * The superglobals as PHP-FPM behind nginx fills them: Laminas' Request, RemoteAddress, the
     * ServerUrl helper and laminas-session read them.
     *
     * @param array<string, mixed> $files
     */
    private function fillSuperglobals(ServerRequestInterface $request, array $files): void
    {
        $uri    = $request->getUri();
        $server = $request->getServerParams() + [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI'    => $uri->getPath() . ('' !== $uri->getQuery() ? '?' . $uri->getQuery() : ''),
            'QUERY_STRING'   => $uri->getQuery(),
            'SERVER_NAME'    => $uri->getHost(),
            'SERVER_PORT'    => $uri->getPort() ?? ('https' === $uri->getScheme() ? 443 : 80),
        ];
        foreach ($request->getHeaders() as $name => $values) {
            $name = \strtoupper(\strtr($name, '-', '_'));
            $name = 'CONTENT_TYPE' === $name || 'CONTENT_LENGTH' === $name ? $name : "HTTP_$name";
            $server[$name] ??= \implode('HTTP_COOKIE' === $name ? '; ' : ', ', $values);
        }
        $_SERVER  = $server + $this->server;
        $_GET     = $request->getQueryParams();
        $post     = $request->getParsedBody();
        $_POST    = \is_array($post) ? $post : [];
        $_REQUEST = $_POST + $_GET;
        $_COOKIE  = $request->getCookieParams();
        $_FILES   = [];
        foreach ($files as $name => $file) {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $_FILES[$name][$key] = self::phpFiles($file, $key);
            }
        }
    }

    /** One key of a $_FILES entry, for a PSR-7 upload or a tree of them, nested as PHP nests it. */
    private static function phpFiles(UploadedFileInterface|array $file, string $key): mixed
    {
        if (\is_array($file)) {
            return \array_map(static fn ($file) => self::phpFiles($file, $key), $file);
        }

        return match ($key) {
            'name'     => $file->getClientFilename(),
            'type'     => $file->getClientMediaType(),
            'tmp_name' => \UPLOAD_ERR_OK === $file->getError() ? $file->getStream()->getMetadata('uri') : '',
            'error'    => $file->getError(),
            'size'     => $file->getSize(),
        };
    }

    /** @param array<string, string> $session headers the response doesn't set itself */
    private function response(LaminasResponse $response, array $session): ResponseInterface
    {
        $headers = [];
        foreach ($response->getHeaders() as $header) {
            $headers[$header->getFieldName()][] = $header->getFieldValue();
        }
        foreach ($session as $name => $value) {
            if ('Set-Cookie' === $name || !$response->getHeaders()->has($name)) {
                $headers[$name][] = $value;
            }
        }
        // PHP's default, which PHP-FPM sends
        if (!$response->getHeaders()->has('Content-Type') && '' !== ($type = (string) \ini_get('default_mimetype'))) {
            $charset                 = (string) \ini_get('default_charset');
            $headers['Content-Type'] = [\str_starts_with($type, 'text/') && '' !== $charset ? "$type; charset=$charset" : $type];
        }
        if ($response instanceof StreamResponse && \is_resource($stream = $response->getStream())) {
            // Sent as it is read. The stream response lives as long as its body: its destructor
            // deletes the file when asked to (setCleanup()), as at the end of a PHP-FPM request.
            $body = new ComposableStream(
                readFunction: static fn (int $length) => (string) \fread(\phasync::readable($stream), $length),
                getSizeFunction: static fn () => $response->getContentLength(),
                closeFunction: static fn () => \fclose($stream),
                eofFunction: static fn () => \feof($stream),
            );
        } else {
            $body = $response->getContent();
        }

        return new Response($body, $headers, $response->getStatusCode(), $response->getReasonPhrase());
    }

    /**
     * The session cookie and cache headers that PHP-FPM sends for a session started in the
     * request, and the CLI's session module doesn't.
     *
     * @return array<string, string>
     */
    private function sessionHeaders(ServerRequestInterface $request): array
    {
        // Set only by a session started in this request: endSession() forgets it after each
        $id = \session_id();
        if ('' === $id) {
            return [];
        }
        $expire  = (int) \session_cache_expire() * 60;
        $headers = match (\session_cache_limiter()) {
            'nocache'           => ['Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT', 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache'],
            'private'           => ['Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT', 'Cache-Control' => "private, max-age=$expire"],
            'private_no_expire' => ['Cache-Control' => "private, max-age=$expire"],
            'public'            => ['Expires' => \gmdate('D, d M Y H:i:s \G\M\T', \time() + $expire), 'Cache-Control' => "public, max-age=$expire"],
            default             => [],
        };
        if ($id !== ($request->getCookieParams()[\session_name()] ?? null) && \ini_get('session.use_cookies')) {
            $p      = \session_get_cookie_params();
            $cookie = \rawurlencode(\session_name()) . '=' . \rawurlencode($id);
            if ($p['lifetime'] > 0) {
                $cookie .= '; expires=' . \gmdate('D, d M Y H:i:s \G\M\T', \time() + $p['lifetime']) . '; Max-Age=' . $p['lifetime'];
            }
            $headers['Set-Cookie'] = $cookie . ('' !== $p['path'] ? '; path=' . $p['path'] : '') . ('' !== $p['domain'] ? '; domain=' . $p['domain'] : '')
                . ($p['secure'] ? '; secure' : '') . ($p['httponly'] ? '; HttpOnly' : '') . ('' !== $p['samesite'] ? '; SameSite=' . $p['samesite'] : '');
        }

        return $headers;
    }

    /**
     * Write and close the request's session, and forget it, as PHP does at the end of a request.
     *
     * PHP keeps the session id in the process, for the next visitor. session_id('') is not
     * enough: PHP then ignores the next visitor's cookie, and laminas-session, which takes a
     * session id and a defined SID constant for a started session, never starts it. Only
     * session_destroy() forgets the id, so a session with a handler that stores nothing is
     * started and destroyed. laminas-session registers its save handler again at every start;
     * PHP's own (files, redis, ...) is set back here.
     */
    private function endSession(): void
    {
        if (\PHP_SESSION_ACTIVE === \session_status()) {
            // As SessionManager::writeClose() does: laminas-session's SessionStorage replaces
            // $_SESSION with an object, which the session module can't write
            if ($_SESSION instanceof SessionStorage) {
                $_SESSION = $_SESSION->toArray(true);
            }
            \session_write_close();
        }
        if ('' !== \session_id()) {
            $handler = \ini_get('session.save_handler');
            \session_set_save_handler($this->nullSessionHandler ??= new class implements \SessionHandlerInterface {
                public function open(string $path, string $name): bool
                {
                    return true;
                }

                public function close(): bool
                {
                    return true;
                }

                public function read(string $id): string
                {
                    return '';
                }

                public function write(string $id, string $data): bool
                {
                    return true;
                }

                public function destroy(string $id): bool
                {
                    return true;
                }

                public function gc(int $max_lifetime): int
                {
                    return 0;
                }
            }, false);
            \session_start();
            \session_destroy();
            if ('user' !== $handler) {
                \ini_set('session.save_handler', $handler);
            }
        }
        $_SESSION = [];
    }
}
