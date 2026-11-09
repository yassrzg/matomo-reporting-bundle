<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Yassrzg\MatomoReportingBundle\Controller\DashboardController;
use Yassrzg\MatomoReportingBundle\Controller\LiveController;

/*
 * Tableau de bord, à importer sous le préfixe choisi par l'application (option dashboard.enabled) :
 *   matomo_reporting:
 *       resource: '@MatomoReportingBundle/config/routes.php'
 *       prefix: /admin/mesure-audience
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('matomo_reporting_dashboard', '/')
        ->controller(DashboardController::class)
        ->methods(['GET']);
    // Fréquentation en temps réel (option live.enabled), rechargée par la page du tableau de bord.
    $routes->add('matomo_reporting_live', '/en-ce-moment')
        ->controller(LiveController::class)
        ->methods(['GET']);
};
