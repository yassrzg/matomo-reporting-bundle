<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Point de l'évolution : un jour, une semaine ou un mois.
 */
final readonly class EvolutionPoint
{
    public function __construct(
        public \DateTimeImmutable $start,
        public string $label,
        public int $visits,
        public ?int $uniqueVisitors,
        public ?int $pageviews,
    ) {
    }
}
