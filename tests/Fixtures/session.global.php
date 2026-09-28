<?php

// Sessions as laminas-session's documentation sets them up for an MVC application
use Laminas\Session\Storage\SessionArrayStorage;
use Laminas\Session\Validator\HttpUserAgent;
use Laminas\Session\Validator\RemoteAddr;

return [
    'session_config' => [
        'name'            => 'laminas_session',
        'cookie_httponly' => true,
        'gc_maxlifetime'  => 3600,
    ],
    'session_manager' => [
        'validators' => [RemoteAddr::class, HttpUserAgent::class],
    ],
    'session_storage' => [
        'type' => SessionArrayStorage::class,
    ],
];
