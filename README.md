# swerve for Laminas MVC

[![CI](https://github.com/phasync/swerve-laminas/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-laminas/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-laminas)](https://packagist.org/packages/phasync/swerve-laminas)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-laminas/php)](https://packagist.org/packages/phasync/swerve-laminas)
![License](https://img.shields.io/github/license/phasync/swerve-laminas)

**Your Laminas MVC application, kept loaded.** [swerve](https://github.com/phasync/swerve) is a PHP
application server: long-running workers that serve HTTP/1.1 themselves, stream request and
response bodies, and hold WebSockets and Server-Sent Events. This package lets it run a Laminas
MVC application (the successor of Zend Framework MVC, `laminas/laminas-mvc-skeleton`) unchanged.

```bash
composer config minimum-stability alpha   # while swerve is in alpha
composer config prefer-stable true         # everything else stays stable
composer require phasync/swerve-laminas
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laminas\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

That's the whole setup. `public/index.php` stays as it is, so the same application still runs
under PHP-FPM.

## WebSockets

A controller action returns `Swerve\Http\WebSocket::from()`, with the PSR-7 request that the
handler puts in the Laminas request's metadata:

```php
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;

public function chatAction()
{
    return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) {
        foreach ($ws as $message) {            // ends when the client leaves
            $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
        }
    });
}
```

The callback runs after the action has returned, in a coroutine of its own. An ordinary GET to
the route is answered `426 Upgrade Required`.

**Server push.** A callback that only forwards a topic, and an ordinary action that publishes
to it, in any worker:

```php
use Swerve\Swerve;

public function newsAction()
{
    return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) {
        foreach (Swerve::subscribe('news') as $message) {
            $ws->send($message);
        }
    });
}

public function publishAction()
{
    Swerve::publish('news', json_encode(['headline' => $this->params()->fromPost('headline')]));

    return new JsonModel(['published' => true]);
}
```

The callback ends when its client leaves, with a close frame or without a word, and when the
worker drains: clients then get a close frame with 1001.

**The user.** Take what the callback needs from the request before `WebSocket::from()`:

```php
public function notificationsAction()
{
    $user = $this->auth->getIdentity();        // laminas-authentication, from the session

    return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) use ($user) {
        foreach (Swerve::subscribe("user:$user") as $message) {
            $ws->send($message);
        }
    });
}
```

The handler closes the session when the action returns, as PHP does at the end of a request.
`getIdentity()`, a `Laminas\Session\Container` or anything else that reads the session inside the
callback reads the session of whatever request the worker runs at that moment: none between
requests, another visitor's during one. The tests show both.

**Make the callback `static`.** A closure written in a controller method is bound to the
controller, which holds the application: the socket then keeps the whole application, about
300 KiB, until it closes. A `static` closure keeps what it `use`s. Measured with 200 sockets on
one worker: 84 KiB a socket (swerve's WebSocket, its coroutines and subscription) with a static
closure, 380 KiB with a bound one; both give everything back when the sockets close.

**The worker keeps serving.** Laminas requests run one at a time per worker, but only until the
action returns: open sockets and their subscriptions don't hold that turn. With 200 sockets
open on one worker, each receiving messages, its ordinary requests were answered within 15 ms.

Every client sees a topic's messages in the same order. Two messages published one right after
the other through different workers may arrive in the other order
([phasync/swerve#5](https://github.com/phasync/swerve/issues/5)).

## What changes

| Laminas MVC skeleton, 4 workers | PHP-FPM | swerve | | swerve + phasync-ext |
|---|---:|---:|---:|---:|
| JSON route | 2,862 req/s | 3,535 req/s | 1.24× | 3,509 req/s |
| Home page (layout, view helpers) | 2,119 req/s | 2,843 req/s | 1.34× | 2,821 req/s |
| Page with a session | 2,525 req/s | 3,009 req/s | 1.19× | 2,864 req/s |

[Method and raw results](benchmarks/). Laminas MVC is built for one request per process, so
every request still gets a new application (see below), and building it is most of a request's
time. What swerve adds is what PHP-FPM can't do: streamed responses, WebSockets and Server-Sent
Events from a controller action.

## How it runs

- **Once per worker:** the application configuration is read as `config/container.php` reads
  it (`config/application.config.php`, merged with `config/development.config.php` in
  development mode), and one application is booted, which loads the classes and writes the
  configuration cache.
- **Per request:** a new `Laminas\Mvc\Application` from `Application::init()`, with its own
  ServiceManager, modules, Request, Response and MvcEvent, as under PHP-FPM. Laminas keeps request
  state in shared services (the layout view model, the head title and other placeholder helpers,
  the route match) and has no mechanism to reset them: an application reused with a new request
  and response serves the skeleton's page with its title once more for every earlier request. A
  new application costs 1.2 ms, 0.7 ms of it the module manager configuring the ServiceManager
  and its plugin managers; the skeleton's home page takes 0.45 ms more.
- **Concurrency:** one request at a time per worker (`phasync\Util\Synchronized`). Laminas reads
  the request from the superglobals, which the handler fills from the PSR-7 request as PHP-FPM
  would, and so do `RemoteAddress`, the `ServerUrl` helper and laminas-session; the session
  itself lives in PHP's session module. All of that is per process. A worker's other connections
  (static files, keep-alive, WebSockets) go on while a request runs.
- **Sessions:** laminas-session with PHP's native sessions, as configured (`session_config`,
  `session_manager`, save handlers). After each request the handler writes and closes the
  session, sends the cookie and the `session.cache_limiter` headers that PHP-FPM sends, and makes
  PHP forget the session id. `session_id('')` alone would make PHP ignore the next visitor's
  cookie.
- **Responses:** the Laminas response becomes a PSR-7 response. A `Laminas\Http\Response\Stream`
  is sent as it is read, and its file deleted afterwards when `setCleanup()` asks for it. A
  controller action may return a PSR-7 response, which is sent as it is (see
  [WebSockets](#websockets)); the PSR-7 request is the Laminas request's metadata
  `Psr\Http\Message\ServerRequestInterface`.

## Mezzio

[Mezzio](https://docs.mezzio.dev/), Laminas' PSR-15 framework, needs no adapter: its
`Application` is a request handler, so `swerve.php` returns it.

```php
<?php // swerve.php, as public/index.php

chdir(__DIR__);
require 'vendor/autoload.php';

$container = require 'config/container.php';
$app       = $container->get(Mezzio\Application::class);
$factory   = $container->get(Mezzio\MiddlewareFactory::class);
(require 'config/pipeline.php')($app, $factory, $container);
(require 'config/routes.php')($app, $factory, $container);

return $app;
```

A WebSocket is a handler's response, and the user or session data come from the request's
attributes before `WebSocket::from()`:

```php
use Mezzio\Authentication\UserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

final class NewsHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(UserInterface::class)?->getIdentity();

        return WebSocket::from($request, static function (WebSocket $ws) use ($user) {
            foreach (Swerve::subscribe('news') as $message) {
                $ws->send($message);
            }
        });
    }
}
```

Requests run concurrently in a worker, so request state belongs in the request's attributes.
Mezzio's `UrlHelper` is a shared service holding the last route result: called without a route
name, it returns the URL of another request in flight.

## Before you deploy

- `exit` and `die()` end the worker, and the requests it is serving with it.
- A request holds its worker until its action returns, as a PHP-FPM child does: size
  `--workers` as you size `pm.max_children`. A WebSocket's callback and a streamed response's
  body don't hold it.
- A WebSocket callback reads no session and no identity of its own: take them in the action,
  and make the callback `static` (see [WebSockets](#websockets)).
- laminas-session's `SessionManager` registers a shutdown function in its constructor, which
  keeps every manager until the worker exits. An application that gets the `SessionManager`
  service on every request, as laminas-session's documentation does in `onBootstrap()`, grows by
  about 3 KiB a request. Set `--max-requests` (50,000 is about 150 MiB), or a `memory_limit` so
  that `--max-memory` recycles workers: the CLI's default `memory_limit` of -1 turns that off.
- `$request->getFiles()` holds PSR-7 `UploadedFileInterface` objects: PHP's
  `is_uploaded_file()` and `move_uploaded_file()` don't know swerve's uploads, so Laminas' upload
  validator would reject the arrays PHP-FPM gives. laminas-form's file inputs and validators
  take the objects; `RenameUpload` needs its `stream_factory` and `upload_file_factory` options
  (laminas-diactoros' `StreamFactory` and `UploadedFileFactory`). Code that moves
  `$_FILES[...]['tmp_name']` with `move_uploaded_file()` should call `moveTo()` on the upload.
- `header()`, `setcookie()`, `http_response_code()` and `echo` don't reach the client: set
  headers and cookies on the Laminas response. Output goes to swerve's terminal. laminas-session's
  `destroy()` expires the session cookie with `setcookie()`, so the browser keeps it; the next
  request gets an empty session under the old id, or a new id with `session.use_strict_mode=1`.
- A PSR-15 middleware route (laminas-mvc-middleware) converts its PSR-7 response through
  laminas-psr7bridge, which buffers it: return streamed responses and WebSockets from a
  controller action.
- A session save handler set with `session_set_save_handler()` outside the application, in
  `swerve.php`, is gone after the first request with a session: set it through laminas-session's
  `SaveHandlerInterface` service, or in `onBootstrap()`.

## Compatibility

| laminas-mvc | PHP | phasync-ext |
|---|---|---|
| 3.8 | 8.2 – 8.4, as laminas-mvc 3.8 declares | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
