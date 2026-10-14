<?php

namespace Swerve\Laminas;

use Swerve\Psr15\Bridge;
use Swerve\RequestHandler;

/**
 * The entry point of the `laminas` swerve adapter, which swerve's workers call once each, with the
 * application directory (see the `extra.swerve` section of this package's composer.json): the
 * application served by a {@see Handler} through swerve-psr15's Bridge.
 *
 * @param string $appDir the application directory: where swerve was started
 */
function entry(string $appDir): RequestHandler
{
    return Bridge::wrap(new Handler($appDir));
}
