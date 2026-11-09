<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Yassrzg\MatomoReportingBundle\Client\HttpMatomoClient;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Period\Granularity;
use Yassrzg\MatomoReportingBundle\Period\PeriodFactory;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Report\DimensionReport;
use Yassrzg\MatomoReportingBundle\Report\PageReport;
use Yassrzg\MatomoReportingBundle\Site\SiteRegistry;

/**
 * Appels réels à une instance Matomo (jamais la production : une instance de test). Ignoré sans les variables
 * MATOMO_REPORTING_TEST_URL, MATOMO_REPORTING_TEST_TOKEN et MATOMO_REPORTING_TEST_SITE.
 */
final class LiveMatomoTest extends TestCase
{
    public function testEveryReportIsReadFromALiveInstance(): void
    {
        $url = getenv('MATOMO_REPORTING_TEST_URL');
        $token = getenv('MATOMO_REPORTING_TEST_TOKEN');
        $site = (int) getenv('MATOMO_REPORTING_TEST_SITE');
        if (!\is_string($url) || '' === $url || !\is_string($token) || '' === $token || $site < 1) {
            self::markTestSkipped('Instance Matomo de test non configurée.');
        }

        $reporter = new AudienceReporter(new HttpMatomoClient(HttpClient::create(), $url, $token), new SiteRegistry(['test' => $site], 'test'));
        $period = (new PeriodFactory())->create('last30');

        $comparison = $reporter->compare($period);
        self::assertGreaterThanOrEqual(0, $comparison->current->visits);
        self::assertNotEmpty($reporter->evolution($period, Granularity::WEEK));
        foreach (PageReport::cases() as $report) {
            self::assertGreaterThanOrEqual(0, \count($reporter->pages($period, $report, 5)->rows), $report->value);
        }
        foreach (DimensionReport::cases() as $dimension) {
            self::assertGreaterThanOrEqual(0, \count($reporter->dimension($period, $dimension)), $dimension->value);
        }

        // Demande groupée (API.getBulkRequest) : mêmes chiffres que les appels un par un.
        $overview = $reporter->overview($period, Granularity::WEEK, PageReport::URLS, 5);
        self::assertNotNull($overview->comparison);
        self::assertSame($comparison->current->visits, $overview->comparison->current->visits);
        self::assertSame($comparison->previous->pageviews, $overview->comparison->previous->pageviews);
        self::assertEquals($reporter->pages($period, PageReport::URLS, 5), $overview->pages);
        self::assertEquals($reporter->dimension($period, DimensionReport::BROWSERS), $overview->dimensions['browsers']);

        $this->expectException(MatomoApiException::class);
        (new AudienceReporter(new HttpMatomoClient(HttpClient::create(), $url, 'jeton-invalide'), new SiteRegistry(['test' => $site], 'test')))->summary($period);
    }
}
