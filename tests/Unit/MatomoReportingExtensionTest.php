<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yassrzg\MatomoReportingBundle\Period\Period;
use Yassrzg\MatomoReportingBundle\Period\PeriodPreset;
use Yassrzg\MatomoReportingBundle\Report\Comparison;
use Yassrzg\MatomoReportingBundle\Report\Summary;
use Yassrzg\MatomoReportingBundle\Twig\MatomoReportingExtension;

final class MatomoReportingExtensionTest extends TestCase
{
    public function testFormats(): void
    {
        self::assertSame(['45 s', '6 min 13 s', '1 h 02 min', '—'], array_map(MatomoReportingExtension::duration(...), [45, 373, 3725, null]));
        self::assertSame("12\u{202F}345", MatomoReportingExtension::number(12345));
        self::assertSame('3,5', MatomoReportingExtension::number(3.46, 1));
    }

    public function testIndicatorsCompareWithThePreviousPeriod(): void
    {
        $period = Period::fromPreset(PeriodPreset::LAST_7_DAYS, new \DateTimeImmutable('2026-11-08'));
        $comparison = new Comparison($period, new Summary(120, null, 300, 250, 330, 2.8, 95, 20.0), $period->previous(), new Summary(100, null, 300, 240, 310, 3.1, 100, 25.0));

        $items = array_column((new MatomoReportingExtension())->indicators($comparison), null, 'key');

        self::assertSame(['120', 20.0, '100'], [$items['visits']['value'], $items['visits']['change'], $items['visits']['previous']]);
        self::assertNull($items['uniqueVisitors']['value'], 'Indisponible : jamais estimé.');
        self::assertSame('2,5', $items['pageviewsPerVisit']['value']);
        self::assertSame('1 min 35 s', $items['averageVisitDuration']['value']);
        self::assertSame(['up', true, '%'], [$items['visits']['trend'], $items['visits']['favorable'], $items['visits']['unit']]);
        self::assertSame(['down', false], [$items['pageviewsPerVisit']['trend'], $items['pageviewsPerVisit']['favorable']]);
        self::assertSame(['stable', null], [$items['pageviews']['trend'], $items['pageviews']['favorable']]);
        self::assertSame([null, null], [$items['uniqueVisitors']['trend'], $items['uniqueVisitors']['favorable']]);
        // Taux : écart en points, une baisse du rebond est favorable.
        self::assertSame(['20 %', -5.0, 'pts', 'down', true], [$items['bounceRate']['value'], $items['bounceRate']['change'], $items['bounceRate']['unit'], $items['bounceRate']['trend'], $items['bounceRate']['favorable']]);
    }
}
