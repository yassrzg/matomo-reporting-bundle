<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Indicateurs généraux d'une période (VisitsSummary.get et Actions.get). Un indicateur que Matomo ne fournit pas pour
 * la période vaut null : il n'est jamais estimé.
 */
final readonly class Summary
{
    public function __construct(
        public int $visits,
        public ?int $uniqueVisitors,
        public ?int $pageviews,
        public ?int $uniquePageviews,
        public int $actions,
        public float $actionsPerVisit,
        public int $averageVisitDuration,
        public ?float $bounceRate,
    ) {
    }

    /**
     * Pages vues par visite (pages vues / visites), null sans visite ou sans pages vues.
     */
    public function pageviewsPerVisit(): ?float
    {
        return null === $this->pageviews || 0 === $this->visits ? null : round($this->pageviews / $this->visits, 1);
    }

    /**
     * Valeur d'un indicateur par son nom (visits, uniqueVisitors, pageviews, pageviewsPerVisit, averageVisitDuration,
     * bounceRate…) : comparaison de deux périodes.
     */
    public function metric(string $name): int|float|null
    {
        return match ($name) {
            'visits' => $this->visits,
            'uniqueVisitors' => $this->uniqueVisitors,
            'pageviews' => $this->pageviews,
            'uniquePageviews' => $this->uniquePageviews,
            'actions' => $this->actions,
            'actionsPerVisit' => $this->actionsPerVisit,
            'pageviewsPerVisit' => $this->pageviewsPerVisit(),
            'averageVisitDuration' => $this->averageVisitDuration,
            'bounceRate' => $this->bounceRate,
            default => throw new \InvalidArgumentException(\sprintf('Indicateur inconnu : « %s ».', $name)),
        };
    }
}
