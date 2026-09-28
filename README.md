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
  controller action may return a PSR-7 response, which is sent as it is; the PSR-7 request is the
  Laminas request's metadata:

```php
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;

public function chatAction()
{
    return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), function (WebSocket $ws) {
        foreach ($ws as $message) {
            $ws->send("echo: $message");
        }
    });
}
```

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

Requests run concurrently in a worker, so request state belongs in the request's attributes.
Mezzio's `UrlHelper` is a shared service holding the last route result: called without a route
name, it returns the URL of another request in flight.

## Before you deploy

- `exit` and `die()` end the worker, and the requests it is serving with it.
- A request holds its worker until it ends, as a PHP-FPM child does: size `--workers` as you
  size `pm.max_children`.
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
