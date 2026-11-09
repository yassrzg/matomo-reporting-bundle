<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Fréquentation des dernières minutes (temps réel, Live.getCounters) : totaux uniquement, aucune visite détaillée.
 */
final readonly class LiveCounters
{
    public function __construct(
        public int $minutes,
        public int $visits,
        public int $actions,
        public int $visitors,
        public \DateTimeImmutable $readAt,
    ) {
    }
}
