<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Yassrzg\MatomoReportingBundle\MatomoReportingBundle;

/**
 * Application minimale pour les tests : FrameworkBundle, TwigBundle, SecurityBundle (deux utilisateurs en mémoire,
 * authentification HTTP Basic) et le bundle, avec un client HTTP simulé (service test.matomo_http_client) et le
 * tableau de bord sous /statistiques.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new MatomoReportingBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2).'/var/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['utf8' => true],
            'cache' => ['app' => 'cache.adapter.filesystem'],
        ]);
        $container->extension('twig', ['default_path' => __DIR__.'/templates']);
        $container->extension('security', [
            'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
            'providers' => ['memory' => ['memory' => ['users' => [
                'admin' => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                'lecteur' => ['password' => 'lecteur', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['http_basic' => null, 'provider' => 'memory']],
        ]);
        $container->extension('matomo_reporting', [
            'url' => 'https://matomo.test/',
            'token' => 'jeton-secret-de-test',
            'sites' => ['principal' => 7],
            'cache' => ['ttl' => 300],
            'http' => ['client' => 'test.matomo_http_client'],
        ]);
        $container->services()->set('test.matomo_http_client', MockHttpClient::class)->public();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@MatomoReportingBundle/config/routes.php')->prefix('/statistiques');
    }
}
