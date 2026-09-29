# Concurrency

The handler runs one request at a time per worker (`phasync\Util\Synchronized`,
`src/Handler.php:98`). A request that waits (a database query with phasync-ext) holds its worker
until it ends, as a PHP-FPM child does: size `--workers` as you would size `pm.max_children`.
WebSocket callbacks, streamed bodies, static files and other connections are not held.

Every request already gets a new `Laminas\Mvc\Application`, with its own ServiceManager, Request,
route match, layout and view helpers (see the README). What overlapping requests would share is
below the application. `tests/ConcurrencyTest.php` shows each item: four requests in one worker
each store a value, wait 200 ms and read it back, through `tests/Fixtures/swerve-unlocked.php`
(four handlers, so no lock between them). Laminas' own Request, route parameters, head title and
layout never leak there. Framework paths below are under `vendor/laminas/`.

## Why requests take turns

**The superglobals.** Laminas reads the request from `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and
`$_SERVER`, which the handler fills (`src/Handler.php:154`) and empties after the request
(`:139`). The Request object copies them when the application is built
(`laminas-http/src/PhpEnvironment/Request.php:86-95`), but the `ServerUrl` helper
(`laminas-view/src/Helper/ServerUrl.php:90`), `RemoteAddress`
(`laminas-http/src/PhpEnvironment/RemoteAddress.php:106`), laminas-session's validators and
application code read them later. Without the lock a request reads the last request's values,
or nothing once another ended: test "unlocked, with laminas-session", without and with
phasync-ext.

**PHP's session and `$_SESSION`.** laminas-session keeps the session in PHP's session module, one
per process: session id, status and `$_SESSION`. `SessionArrayStorage` reads and writes
`$_SESSION` on every access (`laminas-session/src/Storage/SessionArrayStorage.php:20-33`).
Without the lock a request reads another's session data and loses its session id when another
request ends (the handler closes and forgets the session, `src/Handler.php:276`). The same holds
for PHP's session without laminas-session: test "unlocked, without laminas-session".

**laminas-session's default manager.** `Container::$defaultManager` is static
(`laminas-session/src/AbstractContainer.php:57`), set by every request's `SessionManagerFactory`
(`Service/SessionManagerFactory.php:149`): a `Container` made in one request uses the manager of
the request that started last (`default-manager` in the test).

**Statics that applications set per request.** The doctype helper keeps the doctype in a static
(`laminas-view/src/Helper/Doctype.php:54`), which every application sets again from
`view_manager.doctype`; `Locale::setDefault()` is the process's. A request that changes either
sees the other requests' value (`doctype`, `locale` in the test). These are not a reason for the
lock on their own: an application running requests concurrently should not set them per request.

## What was tried

- **A pool of applications**, as swerve-symfony's pool of kernels: nothing to gain. Requests
  already have applications of their own; every item above is outside the application.
- **Resetting state per request**: the handler already builds everything Laminas owns per
  request. Superglobals and `$_SESSION` are read by Laminas code directly; no reset or
  `phasync::getContext()` storage can give two running requests different ones.
- **`Swerve\Http\Virtual::run()`** (phasync-ext 0.5.0-alpha11 or later): see below. It removes
  the superglobals, not the session.
- **A narrower lock**, on the branch `concurrent`: requests run in `Virtual::run()` and take turns
  only while they hold `$_SESSION`. With laminas-session registered, that is the whole of every
  request: laminas-session writes `$_SESSION` outside of any session. Every `SessionManager`
  registers `writeClose()` as a shutdown function (`laminas-session/src/SessionManager.php:96`),
  which marks the storage immutable in `$_SESSION` after the request's session closed
  (`:256-261`); `SessionManager::start()` merges whatever `$_SESSION` holds into the session it
  starts (`:147-162`); and the storage writes it when it is built
  (`Storage/AbstractSessionArrayStorage.php:69`). A request that builds a
  `SessionManager` and never starts a session, ending while another request's session is open,
  empties that session (test "a request that never starts a session empties another's", on the
  branch). Without laminas-session, only requests that open a PHP session take turns, from
  `open()` to their end; the others overlap. On the `/test/usleep?ms=10` route, 1 worker,
  phasync-ext, `wrk -t2 -c32 -d5s`: 68 req/s on main, 68 on the branch with laminas-session, 510
  on the branch without it.

## What `virtualize()` removes

With phasync-ext's `phasync\ext\virtualize()` (`Virtual::run()`), each request has its own
superglobals, output buffers, headers and status, `php://input`, shutdown functions, and PHP
session id, status and save handler. `$_SESSION` stays one global variable for the worker: a
request reads the `$_SESSION` of the last request that started a session. Application globals,
static properties (the doctype, `Container::$defaultManager`) and `Locale` are not isolated.

Shutdown functions per request also end laminas-session's growth of 3 KiB a request
([#1](https://github.com/phasync/swerve-laminas/issues/1)): each `SessionManager` is let go when
its request ends (the memory test on the branch).
