<?php

namespace SwerveTest;

use Laminas\Mvc\MvcEvent;
use Laminas\Session\SessionManager;

class Module
{
    public function getConfig(): array
    {
        return require __DIR__ . '/../config/module.config.php';
    }

    /**
     * As laminas-session's documentation does: the configured manager becomes the containers'
     * default. SWERVE_TEST_DEFAULT_SESSION_MANAGER leaves laminas-session's own default instead;
     * SWERVE_TEST_NO_SESSIONS runs the application without laminas-session.
     */
    public function onBootstrap(MvcEvent $e): void
    {
        if (!\getenv('SWERVE_TEST_DEFAULT_SESSION_MANAGER') && !\getenv('SWERVE_TEST_NO_SESSIONS')) {
            $e->getApplication()->getServiceManager()->get(SessionManager::class);
        }
    }
}
