<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

use Yassrzg\MatomoReportingBundle\Period\Period;

/**
 * Indicateurs de la période et de la période précédente de même durée.
 */
final readonly class Comparison
{
    public function __construct(
        public Period $period,
        public Summary $current,
        public Period $previousPeriod,
        public Summary $previous,
    ) {
    }

    /**
     * Écart absolu d'un indicateur avec la période précédente (taux : en points, 15 % contre 17 % → -2.0), null si une
     * des deux valeurs manque.
     */
    public function difference(string $metric): ?float
    {
        $current = $this->current->metric($metric);
        $previous = $this->previous->metric($metric);

        return null === $current || null === $previous ? null : round($current - $previous, 1);
    }

    /**
     * Évolution d'un indicateur en pourcentage (+12.5, -3.0), null si une des deux valeurs manque ou si la période
     * précédente vaut zéro (évolution non calculable).
     */
    public function change(string $metric): ?float
    {
        $current = $this->current->metric($metric);
        $previous = $this->previous->metric($metric);
        if (null === $current || null === $previous || 0 == $previous) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
