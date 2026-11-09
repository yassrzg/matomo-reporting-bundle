<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Tests\MatomoFixtures;

/**
 * Bundle installé dans une application minimale (tests/App) : services, configuration, tableau de bord et droits.
 */
final class DashboardTest extends WebTestCase
{
    private KernelBrowser $client;
    private MatomoFixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Même conteneur pour toutes les requêtes du test (réponses simulées de Matomo conservées).
        $this->client->disableReboot();
        $this->fixtures = new MatomoFixtures();
        $http = static::getContainer()->get('test.matomo_http_client');
        self::assertInstanceOf(MockHttpClient::class, $http);
        $http->setResponseFactory($this->fixtures);
        // Cache fichier (comme une application réelle), vidé avant chaque test.
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    public function testDashboardIsReservedToTheConfiguredRole(): void
    {
        $this->client->request('GET', '/statistiques/');
        self::assertResponseStatusCodeSame(401, 'Anonyme : authentification demandée.');

        $this->client->request('GET', '/statistiques/', server: ['PHP_AUTH_USER' => 'lecteur', 'PHP_AUTH_PW' => 'lecteur']);
        self::assertResponseStatusCodeSame(403, 'Sans le rôle : refusé.');
        self::assertSame([], $this->fixtures->requests, 'Aucun appel à Matomo sans droit.');

        $this->client->request('POST', '/statistiques/', server: self::admin());
        self::assertResponseStatusCodeSame(405, 'Lecture seule.');
    }

    public function testDashboardShowsMatomoData(): void
    {
        $crawler = $this->client->request('GET', '/statistiques/?period=last7', server: self::admin());

        self::assertResponseIsSuccessful();
        self::assertSame('private', $this->client->getResponse()->headers->get('Cache-Control'));
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Visites', $text);
        self::assertStringContainsString('Non disponible pour cette période', $text, 'Visiteurs uniques d\'une plage : non estimés.');
        self::assertStringContainsString('/blog', $text);
        self::assertStringContainsString('Entrées directes', $text);
        self::assertCount(7, $crawler->filter('caption:contains("Visites et pages vues") ~ tbody tr'));
        self::assertStringNotContainsString('jeton-secret-de-test', (string) $this->client->getResponse()->getContent(), 'Jeton jamais dans la page.');
        self::assertCount(0, $crawler->filter('a:contains("Suivant")'), 'Moins de lignes que la taille de page : pas de page suivante.');

        self::assertCount(1, $this->fixtures->requests, 'Tout le tableau de bord en une seule requête HTTP.');
        self::assertCount(12, $this->fixtures->calls);
        $this->client->request('GET', '/statistiques/?period=last7', server: self::admin());
        self::assertCount(1, $this->fixtures->requests, 'Second affichage : réponses en cache, aucun nouvel appel.');
    }

    public function testPagesFrameOnlyReadsThePages(): void
    {
        $crawler = $this->client->request('GET', '/statistiques/?period=last7&q=articles', server: self::admin() + ['HTTP_TURBO_FRAME' => 'matomo-reporting-pages']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Actions.getPageUrls'], array_column($this->fixtures->calls, 'method'), 'Recherche : seul le rapport des pages est demandé.');
        self::assertSame('articles', $this->fixtures->calls[0]['filter_pattern']);
        self::assertCount(1, $crawler->filter('turbo-frame#matomo-reporting-pages'), 'Cadre des pages rendu.');
        self::assertCount(0, $crawler->filter('h2:contains("Indicateurs")'), 'Rien d\'autre que le cadre.');
        self::assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
    }

    public function testLiveCountersFrame(): void
    {
        $crawler = $this->client->request('GET', '/statistiques/', server: self::admin());
        self::assertSame('/statistiques/en-ce-moment', $crawler->filter('turbo-frame#matomo-reporting-live')->attr('src'), 'Cadre « en ce moment » chargé à part.');

        $this->client->request('GET', '/statistiques/en-ce-moment', server: ['PHP_AUTH_USER' => 'lecteur', 'PHP_AUTH_PW' => 'lecteur']);
        self::assertResponseStatusCodeSame(403, 'Même rôle que le tableau de bord.');

        $this->fixtures->calls = [];
        $crawler = $this->client->request('GET', '/statistiques/en-ce-moment', server: self::admin());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 visite, 6 actions', $crawler->filter('turbo-frame#matomo-reporting-live')->text());
        $readAt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i');
        self::assertStringContainsString('Lu à '.$readAt, $crawler->filter('turbo-frame#matomo-reporting-live')->text(), 'Heure dans le fuseau configuré.');
        self::assertSame(['Live.getCounters'], array_column($this->fixtures->calls, 'method'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'), 'Jamais gardé par le navigateur.');

        $http = static::getContainer()->get('test.matomo_http_client');
        self::assertInstanceOf(MockHttpClient::class, $http);
        $http->setResponseFactory(static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $crawler = $this->client->request('GET', '/statistiques/en-ce-moment', server: self::admin());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('indisponible', $crawler->filter('turbo-frame')->text());
    }

    public function testInvalidParametersFallBackSafely(): void
    {
        $crawler = $this->client->request('GET', '/statistiques/?period=custom&start=2026-13-45&end=x&report[]=1&offset=-9&step=heure', server: self::admin());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Date de début invalide', $crawler->filter('[role="alert"]')->text());
        $pages = array_values(array_filter($this->fixtures->calls, static fn (array $call): bool => 'Actions.getPageUrls' === $call['method']));
        self::assertSame(['0', '26'], [$pages[0]['filter_offset'], $pages[0]['filter_limit']], 'Décalage négatif ramené à 0, rapport invalide : adresses.');
    }

    public function testMatomoOutageShowsAMessage(): void
    {
        $http = static::getContainer()->get('test.matomo_http_client');
        self::assertInstanceOf(MockHttpClient::class, $http);
        $http->setResponseFactory(static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));

        $crawler = $this->client->request('GET', '/statistiques/?period=yesterday', server: self::admin());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('momentanément indisponibles', $crawler->filter('[role="alert"]')->text());
    }

    public function testServicesAreAvailableToTheApplication(): void
    {
        self::assertInstanceOf(AudienceReporter::class, static::getContainer()->get(AudienceReporter::class));
    }

    /** @return array<string, string> */
    private static function admin(): array
    {
        return ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'admin'];
    }
}
