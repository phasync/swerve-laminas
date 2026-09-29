<?php

/*
 * The test application as swerve.php runs it, but with the handler's lock taken out: four
 * handlers, each request going to the next. Each handler serializes only its own requests, so
 * four overlapping requests run at the same time. For the tests that show what the lock prevents.
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Laminas\Handler;

require __DIR__ . '/app/vendor/autoload.php';

return new class([new Handler(__DIR__ . '/app'), new Handler(__DIR__ . '/app'), new Handler(__DIR__ . '/app'), new Handler(__DIR__ . '/app')]) implements RequestHandlerInterface {
    private int $next = 0;

    /** @param list<Handler> $handlers */
    public function __construct(private readonly array $handlers)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handlers[$this->next++ % \count($this->handlers)]->handle($request);
    }
};
