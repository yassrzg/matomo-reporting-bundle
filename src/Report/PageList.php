<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Page de résultats d'un rapport de pages : lignes, position et existence d'une page suivante.
 */
final readonly class PageList
{
    /**
     * @param list<PageRow> $rows
     */
    public function __construct(
        public PageReport $report,
        public array $rows,
        public int $offset,
        public int $limit,
        public bool $hasMore,
    ) {
    }
}
