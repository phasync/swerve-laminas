<?php

namespace SwerveTest;

use Laminas\Authentication\AuthenticationService;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'router' => [
        'routes' => [
            'test' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/test/:action[/:value]',
                    'defaults' => ['controller' => Controller\TestController::class],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\TestController::class => Controller\TestControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            RequestScoped::class         => InvokableFactory::class,
            AuthenticationService::class => InvokableFactory::class,
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'swerve-test/test/form' => __DIR__ . '/../view/swerve-test/form.phtml',
        ],
        'strategies' => ['ViewJsonStrategy'],
    ],
];
