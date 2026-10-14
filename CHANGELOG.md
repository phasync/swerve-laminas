# Changelog

## Unreleased

- Requires phasync and swerve `dev-main`, and swerve-psr15 (from its GitHub repository, until it is on
  Packagist): swerve serves an exchange with a `Swerve\RequestHandler` over a `Swerve\ClientRequest`,
  no longer a PSR-15 handler. `Handler` stays a PSR-15 handler, served through swerve-psr15's `Bridge`.
- The `laminas` adapter: `vendor/bin/swerve --adapter=laminas` serves the application with no
  `swerve.php`.
- WebSockets: `Swerve\Psr15\WebSocketResponse::from($psrRequest, ...)`; the callback gets swerve's
  `Swerve\WebSocket` (`Swerve\Http\WebSocket` is gone from swerve).
- The handler loads the application's Composer autoloader: swerve's worker loads only swerve's own
  dependencies.
- `SERVER_NAME` and `SERVER_PORT` are the host and port of the request's `Host` header (or the
  scheme's port), as nginx in front of PHP-FPM gives them, not swerve's own listening port: the
  `ServerUrl` helper built URLs with swerve's port behind a proxy.
- A stopping worker ends its requests at once (swerve's new stop): a request in flight is answered
  `503` by swerve, not Laminas' error page.
- phasync keeps `$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` per request; `docs/concurrency.md` and
  the unlocked tests say so. The handler still serves one request at a time.

## 0.1.0-alpha1

- First release: Laminas MVC (laminas-mvc 3.8) on swerve.
