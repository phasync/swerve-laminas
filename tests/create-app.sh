#!/bin/sh
# Create the Laminas MVC test application in tests/Fixtures/app, for laminas-mvc version $1: the
# laminas-mvc-skeleton, with this package from the checkout, the components the tests use, and
# the test module of tests/Fixtures/module. Idempotent.
set -eu
cd "$(dirname "$0")/Fixtures"
rm -rf app
composer create-project -n --no-install --no-scripts --ignore-platform-req=php laminas/laminas-mvc-skeleton app
cd app
# The skeleton declares PHP up to 8.3, laminas-mvc 3.8 supports 8.4. Its installer and
# development tools are for creating and checking a project, not for running one.
php -r '
$c = json_decode(file_get_contents("composer.json"), true);
$c["require"]["php"] = "~8.2.0 || ~8.3.0 || ~8.4.0";
$c["require"]["laminas/laminas-mvc"] = "^" . $argv[1];
unset($c["require"]["laminas/laminas-skeleton-installer"], $c["require-dev"], $c["autoload-dev"]);
$c["autoload"]["psr-4"]["SwerveTest\\"] = "module/SwerveTest/src/";
// This package from the checkout, by its autoload rule: a path repository would link the
// checkout, which holds this application, into the application
$c["autoload"]["psr-4"]["Swerve\\Laminas\\"] = "../../../src/";
$c["minimum-stability"] = "alpha";
$c["prefer-stable"] = true;
file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$1"
composer require -n --no-progress laminas/laminas-session laminas/laminas-mvc-plugin-flashmessenger laminas/laminas-mvc-form laminas/laminas-authentication laminas/laminas-diactoros phasync/swerve:^0.1.0-alpha12
cp -r ../module/SwerveTest module/
cp ../session.global.php config/autoload/
sed -i "s/'Application',/'Application',\n    'SwerveTest',/" config/modules.config.php
cat > swerve.php <<'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laminas\Handler(__DIR__);
PHP
composer dump-autoload -n
