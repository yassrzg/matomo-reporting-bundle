<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Yassrzg\MatomoReportingBundle\Client\CachedMatomoClient;
use Yassrzg\MatomoReportingBundle\Client\HttpMatomoClient;
use Yassrzg\MatomoReportingBundle\Client\MatomoClientInterface;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\UnknownSiteException;
use Yassrzg\MatomoReportingBundle\Label\LabelSanitizerInterface;
use Yassrzg\MatomoReportingBundle\Period\Granularity;
use Yassrzg\MatomoReportingBundle\Period\Period;
use Yassrzg\MatomoReportingBundle\Period\PeriodPreset;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Report\DimensionReport;
use Yassrzg\MatomoReportingBundle\Report\PageReport;
use Yassrzg\MatomoReportingBundle\Site\SiteRegistry;
use Yassrzg\MatomoReportingBundle\Tests\MatomoFixtures;

/**
 * Lecture des réponses réelles de Matomo 5 (tests/Fixtures) en objets typés.
 */
final class AudienceReporterTest extends TestCase
{
    private MatomoFixtures $fixtures;
    private AudienceReporter $reporter;
    private Period $last30;

    protected function setUp(): void
    {
        $this->fixtures = new MatomoFixtures();
        $sanitizer = new class implements LabelSanitizerInterface {
            public function sanitize(string $label): string
            {
                return str_replace('/recherche/resultats', '/recherche/***', $label);
            }
        };
        $this->reporter = new AudienceReporter(
            new HttpMatomoClient(new MockHttpClient($this->fixtures), 'https://matomo.test/', 'jeton'),
            new SiteRegistry(['principal' => 7, 'autre' => 9], 'principal'),
            $sanitizer,
        );
        $this->last30 = Period::fromPreset(PeriodPreset::LAST_30_DAYS, new \DateTimeImmutable('2026-11-08'));
    }

    public function testSummaryOfARange(): void
    {
        $summary = $this->reporter->summary($this->last30);

        self::assertSame(220, $summary->visits);
        self::assertNull($summary->uniqueVisitors, 'Plage : visiteurs uniques non fournis par Matomo, jamais estimés.');
        self::assertSame(677, $summary->pageviews);
        self::assertSame(3.1, $summary->pageviewsPerVisit());
        self::assertSame(373, $summary->averageVisitDuration);
        self::assertSame(18.0, $summary->bounceRate);

        $french = new AudienceReporter(new HttpMatomoClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('[{"nb_visits":10,"bounce_rate":"18,5\u00a0%"},{}]')), 'https://matomo.test/', 'jeton'), new SiteRegistry(['principal' => 7], 'principal'));
        self::assertSame(18.5, $french->summary($this->last30)->bounceRate, 'Format français (espace insécable, virgule).');
        self::assertSame('7', $this->fixtures->calls[0]['idSite']);
    }

    public function testSummaryOfAWholeMonthHasUniqueVisitors(): void
    {
        $summary = $this->reporter->summary(Period::fromPreset(PeriodPreset::PREVIOUS_MONTH, new \DateTimeImmutable('2026-11-08')), 'autre');

        self::assertNotNull($summary->uniqueVisitors);
        self::assertSame(['9', 'month', '2026-10-01'], [$this->fixtures->calls[0]['idSite'], $this->fixtures->calls[0]['period'], $this->fixtures->calls[0]['date']]);
    }

    public function testComparisonWithThePreviousPeriod(): void
    {
        $comparison = $this->reporter->compare($this->last30);

        self::assertSame('2026-09-10,2026-10-09', $this->fixtures->calls[2]['date'], 'Période précédente de même durée.');
        self::assertSame(0.0, $comparison->change('visits'), 'Mêmes réponses simulées : aucune évolution.');
        self::assertNull($comparison->change('uniqueVisitors'));
    }

    public function testEvolutionIsSortedWithPageviews(): void
    {
        $points = $this->reporter->evolution(Period::fromPreset(PeriodPreset::LAST_7_DAYS, new \DateTimeImmutable('2026-11-08')));

        self::assertCount(7, $points);
        self::assertSame('2026-11-02', $points[0]->start->format('Y-m-d'));
        self::assertSame('2026-11-08', $points[6]->label);
        self::assertGreaterThan(0, $points[6]->visits);
        self::assertNotNull($points[6]->pageviews);
        self::assertSame('day', $this->fixtures->calls[0]['period']);

        $weeks = $this->reporter->evolution(Period::fromPreset(PeriodPreset::CURRENT_YEAR, new \DateTimeImmutable('2026-11-08')), Granularity::WEEK);
        self::assertSame('week', $this->fixtures->calls[2]['period']);
        self::assertLessThan($weeks[1]->start, $weeks[0]->start, 'Semaines par ordre chronologique.');
    }

    public function testPagesArePaginatedSearchedAndSanitized(): void
    {
        $list = $this->reporter->pages($this->last30, PageReport::URLS, 3, 0, ' fiche (2) ');

        self::assertCount(3, $list->rows);
        self::assertTrue($list->hasMore, 'Une ligne de plus demandée : page suivante détectée.');
        $request = $this->fixtures->calls[0];
        self::assertSame(['Actions.getPageUrls', '1', 'nb_hits', 'desc', '0', '4', 'fiche \(2\)'], [$request['method'], $request['flat'], $request['filter_sort_column'], $request['filter_sort_order'], $request['filter_offset'], $request['filter_limit'], $request['filter_pattern']], 'Recherche en texte simple (expression régulière neutralisée).');

        $first = $list->rows[0];
        self::assertSame('/', $first->label);
        self::assertSame('/recherche/***', $list->rows[2]->label, 'Libellé nettoyé par le LabelSanitizer de l\'application.');
        self::assertSame('https://www.exemple.fr/recherche/***', $list->rows[2]->url, 'Adresse nettoyée aussi.');
        self::assertSame(103, $first->pageviews);
        self::assertSame(88, $first->visits);
        self::assertSame(92, $first->averageTimeOnPage);
        self::assertSame(31.0, $first->bounceRate);
        self::assertSame(36.0, $first->exitRate);
        self::assertNull($first->uniqueVisitors);

        $entry = $this->reporter->pages($this->last30, PageReport::ENTRY, 500, -3);
        self::assertSame([100, 0], [$entry->limit, $entry->offset], 'Limites bornées.');
        self::assertSame('entry_nb_visits', $this->fixtures->calls[1]['filter_sort_column']);
        self::assertGreaterThan(0, $entry->rows[0]->visits, 'Pages d\'entrée : visites entrées par la page.');
    }

    public function testDimensionsSkipEmptyRowsAndComputeShares(): void
    {
        $devices = $this->reporter->dimension($this->last30, DimensionReport::DEVICES);
        self::assertSame(['Bureau', 'Téléphone intelligent (smartphone)'], array_map(static fn ($row): string => $row->label, $devices), 'Types sans visite écartés.');
        self::assertEqualsWithDelta(100.0, array_sum(array_map(static fn ($row): float => $row->share, $devices)), 0.2);

        $channels = $this->reporter->dimension($this->last30, DimensionReport::CHANNELS, 3);
        self::assertSame('Entrées directes', $channels[0]->label);
        self::assertSame('3', $this->fixtures->calls[1]['filter_limit']);
    }

    public function testOverviewIsReadInASingleExchangeThenFromCache(): void
    {
        $reporter = new AudienceReporter(
            new CachedMatomoClient(new HttpMatomoClient(new MockHttpClient($this->fixtures), 'https://matomo.test/', 'jeton'), new ArrayAdapter(), 600),
            new SiteRegistry(['principal' => 7], 'principal'),
        );

        $overview = $reporter->overview($this->last30, null, PageReport::URLS, 3, 0, 'fiche');

        self::assertCount(1, $this->fixtures->requests, 'Une seule requête HTTP pour tout le tableau de bord.');
        self::assertSame('API.getBulkRequest', $this->fixtures->requests[0]['body']['method']);
        self::assertCount(12, $this->fixtures->calls, 'Indicateurs (2 × 2), évolution (2), pages (1), répartitions (5).');
        self::assertNotNull($overview->comparison);
        self::assertSame(220, $overview->comparison->current->visits);
        self::assertSame('2026-09-10,2026-10-09', $overview->comparison->previousPeriod->apiParameters()['date']);
        self::assertNotEmpty($overview->evolution);
        self::assertCount(3, $overview->pages->rows);
        self::assertSame(array_map(static fn (DimensionReport $d): string => $d->value, DimensionReport::cases()), array_keys($overview->dimensions));

        $reporter->overview($this->last30, null, PageReport::URLS, 3, 0, 'fiche');
        self::assertCount(1, $this->fixtures->requests, 'Second affichage : tout vient du cache.');

        // Recherche dans les pages : seul le rapport des pages est demandé (le reste n'est pas relu).
        $pagesOnly = $reporter->overview($this->last30, null, PageReport::URLS, 3, 0, 'article', pagesOnly: true);
        self::assertCount(2, $this->fixtures->requests);
        self::assertSame('Actions.getPageUrls', $this->fixtures->calls[12]['method']);
        self::assertCount(13, $this->fixtures->calls);
        self::assertNull($pagesOnly->comparison);
        self::assertSame([], $pagesOnly->dimensions);
    }

    public function testRefusedDimensionDoesNotHideTheRest(): void
    {
        $client = new class implements MatomoClientInterface {
            public function request(string $method, array $parameters = []): array
            {
                return [];
            }

            public function requestMany(array $calls): array
            {
                $results = [];
                foreach (array_keys($calls) as $key) {
                    $results[$key] = 'dimension.browsers' === $key ? new MatomoApiException('Module DevicesDetection désactivé.') : [];
                }

                return $results;
            }
        };
        $overview = (new AudienceReporter($client, new SiteRegistry(['principal' => 1], 'principal')))->overview($this->last30);

        self::assertNull($overview->dimensions['browsers'], 'Répartition refusée : non disponible.');
        self::assertSame([], $overview->dimensions['devices']);
        self::assertNotNull($overview->comparison);

        $pagesRefused = new class implements MatomoClientInterface {
            public function request(string $method, array $parameters = []): array
            {
                return [];
            }

            public function requestMany(array $calls): array
            {
                return array_map(static fn (): MatomoApiException => new MatomoApiException('Site inconnu.'), $calls);
            }
        };
        $this->expectException(MatomoApiException::class);
        (new AudienceReporter($pagesRefused, new SiteRegistry(['principal' => 1], 'principal')))->overview($this->last30);
    }

    public function testLiveCountersOfTheLastMinutes(): void
    {
        $live = $this->reporter->live();

        self::assertSame([30, 1, 6, 1], [$live->minutes, $live->visits, $live->actions, $live->visitors]);
        self::assertSame(['Live.getCounters', '7', '30'], [$this->fixtures->calls[0]['method'], $this->fixtures->calls[0]['idSite'], $this->fixtures->calls[0]['lastMinutes']]);
        self::assertSame(AudienceReporter::LIVE_MAX_MINUTES, $this->reporter->live(100000)->minutes, 'Fenêtre bornée.');
        self::assertSame(1, $this->reporter->live(-5)->minutes);
    }

    public function testUnknownSiteIsRejected(): void
    {
        $this->expectException(UnknownSiteException::class);
        $this->reporter->summary($this->last30, 'inconnu');
    }
}
