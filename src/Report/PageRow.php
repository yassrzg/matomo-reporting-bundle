<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Ligne d'un rapport de pages (adresses, titres, pages d'entrée, de sortie). Indicateur non fourni par Matomo : null.
 */
final readonly class PageRow
{
    public function __construct(
        public string $label,
        public ?string $url,
        public int $pageviews,
        public int $visits,
        public ?int $uniqueVisitors,
        public ?int $averageTimeOnPage,
        public ?float $bounceRate,
        public ?float $exitRate,
    ) {
    }
}
