<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Yassrzg\MatomoReportingBundle\Client\CachedMatomoClient;
use Yassrzg\MatomoReportingBundle\Client\HttpMatomoClient;
use Yassrzg\MatomoReportingBundle\Client\MatomoClientInterface;
use Yassrzg\MatomoReportingBundle\Controller\DashboardController;
use Yassrzg\MatomoReportingBundle\Controller\LiveController;
use Yassrzg\MatomoReportingBundle\Label\LabelSanitizerInterface;
use Yassrzg\MatomoReportingBundle\Label\NullLabelSanitizer;
use Yassrzg\MatomoReportingBundle\Period\PeriodFactory;
use Yassrzg\MatomoReportingBundle\Period\PeriodPreset;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Site\SiteRegistry;
use Yassrzg\MatomoReportingBundle\Twig\MatomoReportingExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Statistiques Matomo côté serveur : client de l'API de reporting, cache, périodes, rapports typés (AudienceReporter)
 * et tableau de bord facultatif. Configuration : clé matomo_reporting (voir README).
 */
final class MatomoReportingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'matomo_reporting';

    public function configure(DefinitionConfigurator $definition): void
    {
        $presets = array_map(static fn (PeriodPreset $p): string => $p->value, array_filter(PeriodPreset::cases(), static fn (PeriodPreset $p): bool => PeriodPreset::CUSTOM !== $p));

        /** @var NodeBuilder $root */
        $root = $definition->rootNode()->children();
        $root
            ->scalarNode('url')
                ->info('Adresse de l\'instance Matomo (ex. https://matomo.exemple.fr/), en général %env(MATOMO_API_URL)%.')
                ->isRequired()->cannotBeEmpty()
            ->end()
            ->scalarNode('token')
                ->info('Jeton d\'API (token_auth) d\'un utilisateur Matomo ayant un accès en lecture : %env(MATOMO_API_TOKEN)%. Vide : statistiques indisponibles.')
                ->defaultValue('')
            ->end()
            ->arrayNode('sites')
                ->info('Sites Matomo : nom => identifiant (idSite).')
                ->useAttributeAsKey('name')
                ->requiresAtLeastOneElement()
                ->integerPrototype()->end()
            ->end()
            ->scalarNode('default_site')
                ->info('Site utilisé sans précision (par défaut : le premier de « sites »).')
                ->defaultNull()
            ->end()
            ->scalarNode('language')
                ->info('Langue des libellés renvoyés par Matomo (sources de trafic, appareils…).')
                ->defaultValue('fr')->cannotBeEmpty()
            ->end()
            ->scalarNode('timezone')
                ->info('Fuseau horaire des périodes (celui du site dans Matomo).')
                ->defaultValue('Europe/Paris')
            ->end()
            ->enumNode('default_period')
                ->values($presets)->defaultValue(PeriodPreset::LAST_30_DAYS->value)
            ->end()
            ->arrayNode('cache')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('pool')->info('Pool de cache PSR-6 (Psr\Cache\CacheItemPoolInterface, ex. cache.app).')->defaultValue('cache.app')->end()
                    ->integerNode('ttl')->info('Durée de cache des réponses de l\'API, en secondes (0 : pas de cache).')->defaultValue(600)->min(0)->end()
                ->end()
            ->end()
            ->arrayNode('http')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('client')->info('Service HttpClient à utiliser (ex. client restreint) ; par défaut http_client.')->defaultValue('http_client')->end()
                    ->floatNode('timeout')->info('Délai maximal d\'une requête, en secondes.')->defaultValue(10.0)->min(1.0)->end()
                    ->booleanNode('verify_peer')->info('Vérification du certificat TLS de Matomo.')->defaultTrue()->end()
                ->end()
            ->end()
            ->scalarNode('label_sanitizer')
                ->info('Service LabelSanitizerInterface appliqué aux libellés et adresses affichés (ex. masquage d\'une donnée personnelle).')
                ->defaultNull()
            ->end()
            ->arrayNode('live')
                ->info('Fréquentation en temps réel (Live.getCounters : totaux des dernières minutes, aucune visite détaillée).')
                ->addDefaultsIfNotSet()
                ->canBeDisabled()
                ->children()
                    ->integerNode('minutes')->info('Fenêtre observée, en minutes.')->defaultValue(30)->min(1)->max(AudienceReporter::LIVE_MAX_MINUTES)->end()
                    ->integerNode('ttl')->info('Durée de cache du temps réel, en secondes (0 : pas de cache).')->defaultValue(60)->min(0)->end()
                    ->scalarNode('template')->info('Gabarit du cadre « en ce moment ».')->defaultValue('@MatomoReporting/live.html.twig')->end()
                ->end()
            ->end()
            ->arrayNode('dashboard')
                ->addDefaultsIfNotSet()
                ->canBeDisabled()
                ->children()
                    ->scalarNode('role')->info('Rôle exigé pour le tableau de bord (null : aucun contrôle, à réserver à une zone déjà protégée).')->defaultValue('ROLE_ADMIN')->end()
                    ->scalarNode('template')->info('Gabarit Twig du tableau de bord (remplaçable par celui de l\'application).')->defaultValue('@MatomoReporting/dashboard.html.twig')->end()
                    ->integerNode('page_size')->info('Lignes du tableau des pages.')->defaultValue(25)->min(5)->max(AudienceReporter::MAX_LIMIT)->end()
                    ->integerNode('dimension_limit')->info('Lignes de chaque répartition (sources, appareils…).')->defaultValue(10)->min(1)->max(AudienceReporter::MAX_LIMIT)->end()
                ->end()
            ->end()
        ->end();
    }

    /**
     * @param array{url: string, token: string, sites: array<string, int>, default_site: ?string, language: string, timezone: string, default_period: string, cache: array{pool: string, ttl: int}, http: array{client: string, timeout: float, verify_peer: bool}, label_sanitizer: ?string, live: array{enabled: bool, minutes: int, ttl: int, template: string}, dashboard: array{enabled: bool, role: ?string, template: string, page_size: int, dimension_limit: int}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('matomo_reporting.http_client', HttpMatomoClient::class)
            ->args([
                service($config['http']['client']),
                $config['url'],
                $config['token'],
                $config['http']['timeout'],
                $config['http']['verify_peer'],
                service('logger')->nullOnInvalid(),
                $config['language'],
            ])
            ->tag('monolog.logger', ['channel' => 'matomo_reporting']);

        $services->set('matomo_reporting.client', CachedMatomoClient::class)
            ->args([service('matomo_reporting.http_client'), service($config['cache']['pool']), $config['cache']['ttl']]);
        $services->alias(MatomoClientInterface::class, 'matomo_reporting.client');

        $services->set('matomo_reporting.sites', SiteRegistry::class)
            ->args([$config['sites'], $config['default_site'] ?? (string) array_key_first($config['sites'])]);
        $services->alias(SiteRegistry::class, 'matomo_reporting.sites');

        $services->set('matomo_reporting.label_sanitizer', NullLabelSanitizer::class);
        $services->alias(LabelSanitizerInterface::class, $config['label_sanitizer'] ?? 'matomo_reporting.label_sanitizer');

        $services->set('matomo_reporting.periods', PeriodFactory::class)
            ->args([$config['timezone'], PeriodPreset::from($config['default_period'])]);
        $services->alias(PeriodFactory::class, 'matomo_reporting.periods');

        // Temps réel : même client HTTP, cache court.
        $services->set('matomo_reporting.live_client', CachedMatomoClient::class)
            ->args([service('matomo_reporting.http_client'), service($config['cache']['pool']), $config['live']['ttl'], 'matomo_reporting_live']);

        $services->set('matomo_reporting.reporter', AudienceReporter::class)
            ->args([service('matomo_reporting.client'), service('matomo_reporting.sites'), service(LabelSanitizerInterface::class), service('matomo_reporting.live_client')]);
        $services->alias(AudienceReporter::class, 'matomo_reporting.reporter');

        if (class_exists(\Twig\Environment::class)) {
            $services->set('matomo_reporting.twig_extension', MatomoReportingExtension::class)->tag('twig.extension');
        }

        if ($config['dashboard']['enabled']) {
            $services->set(DashboardController::class)
                ->args([
                    service('matomo_reporting.reporter'),
                    service('matomo_reporting.periods'),
                    service('twig'),
                    $config['dashboard']['template'],
                    $config['dashboard']['page_size'],
                    $config['dashboard']['dimension_limit'],
                    $config['dashboard']['role'],
                    service('security.authorization_checker')->nullOnInvalid(),
                    service('logger')->nullOnInvalid(),
                    $config['live']['enabled'] ? $config['live']['minutes'] : null,
                ])
                ->public()
                ->tag('controller.service_arguments')
                ->tag('monolog.logger', ['channel' => 'matomo_reporting']);

            // Toujours enregistré (la route existe) : 404 quand le temps réel est désactivé.
            $services->set(LiveController::class)
                ->args([
                    service('matomo_reporting.reporter'),
                    service('twig'),
                    $config['live']['template'],
                    $config['live']['minutes'],
                    $config['dashboard']['role'],
                    service('security.authorization_checker')->nullOnInvalid(),
                    service('logger')->nullOnInvalid(),
                    $config['live']['enabled'],
                    $config['timezone'],
                ])
                ->public()
                ->tag('controller.service_arguments')
                ->tag('monolog.logger', ['channel' => 'matomo_reporting']);
        }
    }
}
