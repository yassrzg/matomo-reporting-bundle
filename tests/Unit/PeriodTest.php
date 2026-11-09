<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yassrzg\MatomoReportingBundle\Exception\InvalidPeriodException;
use Yassrzg\MatomoReportingBundle\Period\Granularity;
use Yassrzg\MatomoReportingBundle\Period\Period;
use Yassrzg\MatomoReportingBundle\Period\PeriodFactory;
use Yassrzg\MatomoReportingBundle\Period\PeriodPreset;

final class PeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{PeriodPreset, string, string, array{period: string, date: string}}>
     */
    public static function presets(): iterable
    {
        yield 'aujourd\'hui' => [PeriodPreset::TODAY, '2026-11-08', '2026-11-08', ['period' => 'day', 'date' => '2026-11-08']];
        yield 'hier' => [PeriodPreset::YESTERDAY, '2026-11-07', '2026-11-07', ['period' => 'day', 'date' => '2026-11-07']];
        yield '7 jours' => [PeriodPreset::LAST_7_DAYS, '2026-11-02', '2026-11-08', ['period' => 'range', 'date' => '2026-11-02,2026-11-08']];
        yield '30 jours' => [PeriodPreset::LAST_30_DAYS, '2026-10-10', '2026-11-08', ['period' => 'range', 'date' => '2026-10-10,2026-11-08']];
        yield 'mois en cours' => [PeriodPreset::CURRENT_MONTH, '2026-11-01', '2026-11-08', ['period' => 'range', 'date' => '2026-11-01,2026-11-08']];
        yield 'mois précédent' => [PeriodPreset::PREVIOUS_MONTH, '2026-10-01', '2026-10-31', ['period' => 'month', 'date' => '2026-10-01']];
        yield 'année en cours' => [PeriodPreset::CURRENT_YEAR, '2026-01-01', '2026-11-08', ['period' => 'range', 'date' => '2026-01-01,2026-11-08']];
    }

    /**
     * @param array{period: string, date: string} $api
     */
    #[DataProvider('presets')]
    public function testPresets(PeriodPreset $preset, string $start, string $end, array $api): void
    {
        $period = Period::fromPreset($preset, new \DateTimeImmutable('2026-11-08 15:42'));

        self::assertSame([$start, $end], [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')]);
        self::assertSame($api, $period->apiParameters());
        self::assertSame('range' !== $api['period'], $period->hasUniqueVisitors(), 'Visiteurs uniques : jour ou mois entier seulement.');
    }

    public function testPreviousPeriodHasTheSameLength(): void
    {
        $previous = Period::fromPreset(PeriodPreset::LAST_7_DAYS, new \DateTimeImmutable('2026-11-08'))->previous();
        self::assertSame(['2026-10-26', '2026-11-01'], [$previous->start->format('Y-m-d'), $previous->end->format('Y-m-d')]);

        $month = Period::fromPreset(PeriodPreset::PREVIOUS_MONTH, new \DateTimeImmutable('2026-03-15'))->previous();
        self::assertSame(['2026-01-01', '2026-01-31'], [$month->start->format('Y-m-d'), $month->end->format('Y-m-d')], 'Mois entier : mois précédent entier.');
    }

    public function testGranularityAndEvolution(): void
    {
        $today = new \DateTimeImmutable('2026-11-08');
        self::assertSame(Granularity::DAY, Period::fromPreset(PeriodPreset::LAST_30_DAYS, $today)->granularity());
        self::assertSame(Granularity::WEEK, Period::fromPreset(PeriodPreset::CURRENT_YEAR, $today)->granularity());
        self::assertSame(Granularity::MONTH, Period::custom(new \DateTimeImmutable('2024-01-01'), $today, $today)->granularity());
        self::assertSame(['period' => 'month', 'date' => '2026-01-01,2026-11-08'], Period::fromPreset(PeriodPreset::CURRENT_YEAR, $today)->evolutionParameters(Granularity::MONTH));
    }

    public function testCustomPeriodIsValidated(): void
    {
        $today = new \DateTimeImmutable('2026-11-08');
        self::assertSame('du 01/10/2026 au 15/10/2026', Period::custom(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'), $today)->label());

        foreach ([['2026-10-15', '2026-10-01'], ['2026-11-01', '2026-11-09'], ['2023-01-01', '2026-11-01']] as [$start, $end]) {
            try {
                Period::custom(new \DateTimeImmutable($start), new \DateTimeImmutable($end), $today);
                self::fail(\sprintf('Période %s – %s acceptée.', $start, $end));
            } catch (InvalidPeriodException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidPeriodException::class);
        Period::fromPreset(PeriodPreset::CUSTOM, $today);
    }

    public function testFactoryReadsAndValidatesRequestValues(): void
    {
        $factory = new PeriodFactory('Europe/Paris', PeriodPreset::LAST_7_DAYS, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-08 23:30', new \DateTimeZone('UTC')));

        self::assertSame('2026-11-09', $factory->today()->format('Y-m-d'), 'Date du jour dans le fuseau des statistiques.');
        self::assertSame(PeriodPreset::LAST_7_DAYS, $factory->create()->preset, 'Sans paramètre : période par défaut.');
        self::assertSame('2026-10-01', $factory->create('custom', '2026-10-01', '2026-10-31')->start->format('Y-m-d'));

        foreach ([['inconnue', null, null], ['custom', '2026-02-30', '2026-03-01'], ['custom', '01/10/2026', '2026-10-31'], ['custom', null, null], ['custom', '2026-10-01', "2026-10-31' OR 1=1"]] as [$preset, $start, $end]) {
            try {
                $factory->create($preset, $start, $end);
                self::fail('Valeur acceptée : '.json_encode([$preset, $start, $end]));
            } catch (InvalidPeriodException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
