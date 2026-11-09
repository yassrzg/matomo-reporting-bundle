<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Yassrzg\MatomoReportingBundle\Client\HttpMatomoClient;
use Yassrzg\MatomoReportingBundle\Controller\LiveController;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Site\SiteRegistry;
use Yassrzg\MatomoReportingBundle\Tests\MatomoFixtures;

final class LiveControllerTest extends TestCase
{
    public function testDisabledLiveCountersAreNotFound(): void
    {
        $fixtures = new MatomoFixtures();
        $reporter = new AudienceReporter(new HttpMatomoClient(new MockHttpClient($fixtures), 'https://matomo.test/', 'jeton'), new SiteRegistry(['principal' => 1], 'principal'));
        $controller = new LiveController($reporter, new Environment(new ArrayLoader(['live' => ''])), 'live', 30, null, enabled: false);

        try {
            $controller();
            self::fail('404 attendue.');
        } catch (NotFoundHttpException) {
            self::assertSame([], $fixtures->requests, 'Aucun appel à Matomo.');
        }
    }
}
