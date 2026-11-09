<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Yassrzg\MatomoReportingBundle\Report\Comparison;

/**
 * Présentation des statistiques : indicateurs prêts à afficher (libellé, valeur formatée, évolution), durées et
 * nombres au format français.
 */
final class MatomoReportingExtension extends AbstractExtension
{
    /**
     * Indicateurs du tableau de bord : nom (Summary::metric) => [libellé, format, une baisse est-elle favorable].
     * Taux : évolution en points (15 % contre 17 % : -2 pts) ; autres : évolution en %.
     */
    public const array INDICATORS = [
        'visits' => ['Visites', 'number', false],
        'uniqueVisitors' => ['Visiteurs uniques', 'number', false],
        'pageviews' => ['Pages vues', 'number', false],
        'pageviewsPerVisit' => ['Pages vues par visite', 'decimal', false],
        'averageVisitDuration' => ['Durée moyenne des visites', 'duration', false],
        'bounceRate' => ['Taux de rebond', 'percent', true],
    ];

    public function getFunctions(): array
    {
        return [new TwigFunction('matomo_indicators', $this->indicators(...))];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('matomo_duration', self::duration(...)),
            new TwigFilter('matomo_number', self::number(...)),
        ];
    }

    /**
     * change : évolution (en % ou, pour un taux, en points : unit) ; trend : up, down, stable ou null (non calculable) ;
     * favorable : l'évolution est-elle une bonne nouvelle (null si stable ou non calculable).
     *
     * @return list<array{key: string, label: string, value: ?string, raw: int|float|null, change: ?float, unit: string, trend: ?string, favorable: ?bool, previous: ?string}>
     */
    public function indicators(Comparison $comparison): array
    {
        $items = [];
        foreach (self::INDICATORS as $key => [$label, $format, $lowerIsBetter]) {
            $value = $comparison->current->metric($key);
            $previous = $comparison->previous->metric($key);
            $change = 'percent' === $format ? $comparison->difference($key) : $comparison->change($key);
            $trend = null === $change ? null : ($change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'));
            $items[] = [
                'key' => $key,
                'label' => $label,
                'value' => self::format($value, $format),
                'raw' => $value,
                'change' => $change,
                'unit' => 'percent' === $format ? 'pts' : '%',
                'trend' => $trend,
                'favorable' => \in_array($trend, ['up', 'down'], true) ? ('down' === $trend) === $lowerIsBetter : null,
                'previous' => self::format($previous, $format),
            ];
        }

        return $items;
    }

    /** Durée en secondes : « 45 s », « 6 min 13 s », « 1 h 02 min ». */
    public static function duration(?int $seconds): string
    {
        if (null === $seconds) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            return \sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
        }

        return \sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** Nombre au format français (espace insécable comme séparateur de milliers, virgule décimale). */
    public static function number(int|float|null $value, int $decimals = 0): string
    {
        return null === $value ? '—' : number_format((float) $value, $decimals, ',', "\u{202F}");
    }

    private static function format(int|float|null $value, string $format): ?string
    {
        if (null === $value) {
            return null;
        }

        return match ($format) {
            'decimal' => self::number($value, 1),
            'duration' => self::duration((int) $value),
            'percent' => self::number($value, (float) $value === floor((float) $value) ? 0 : 1).' %',
            default => self::number($value),
        };
    }
}
