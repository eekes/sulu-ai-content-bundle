<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The admin API of the bundle. A project imports this file once, under the /admin/api prefix.
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__.'/../src/Infrastructure/Sulu/Controller/', 'attribute');
};
