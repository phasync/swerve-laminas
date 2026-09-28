<?php

namespace SwerveTest\Controller;

use Laminas\Authentication\AuthenticationService;
use Psr\Container\ContainerInterface;
use SwerveTest\RequestScoped;

class TestControllerFactory
{
    public function __invoke(ContainerInterface $container): TestController
    {
        return new TestController($container->get(RequestScoped::class), $container->get(AuthenticationService::class));
    }
}
