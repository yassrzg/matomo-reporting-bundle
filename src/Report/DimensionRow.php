<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Ligne d'un rapport de répartition (source de trafic, moteur, site référent, appareil, navigateur) : visites et part
 * des visites de la liste renvoyée.
 */
final readonly class DimensionRow
{
    public function __construct(
        public string $label,
        public int $visits,
        public int $actions,
        public float $share,
    ) {
    }
}
