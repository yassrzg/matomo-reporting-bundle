<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Tableau de bord complet, lu en un seul échange avec Matomo (AudienceReporter::overview()). En mode « pages seules »
 * (recherche, pagination), comparison vaut null, evolution et dimensions sont vides.
 */
final readonly class Overview
{
    /**
     * @param list<EvolutionPoint>                   $evolution
     * @param array<string, list<DimensionRow>|null> $dimensions répartition => lignes (null : refusée par Matomo)
     */
    public function __construct(
        public ?Comparison $comparison,
        public array $evolution,
        public PageList $pages,
        public array $dimensions,
    ) {
    }
}
