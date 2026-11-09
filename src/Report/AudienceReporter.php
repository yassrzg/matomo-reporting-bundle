<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

use Yassrzg\MatomoReportingBundle\Client\MatomoClientInterface;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoExceptionInterface;
use Yassrzg\MatomoReportingBundle\Label\LabelSanitizerInterface;
use Yassrzg\MatomoReportingBundle\Label\NullLabelSanitizer;
use Yassrzg\MatomoReportingBundle\Period\Granularity;
use Yassrzg\MatomoReportingBundle\Period\Period;
use Yassrzg\MatomoReportingBundle\Site\SiteRegistry;

/**
 * Statistiques de fréquentation d'un site Matomo, en objets typés : indicateurs généraux, comparaison, évolution,
 * pages et répartitions. Seuls des rapports agrégés sont lus (jamais le détail des visites ni de donnée personnelle).
 *
 * @throws MatomoExceptionInterface sur chaque méthode publique (Matomo indisponible, erreur de l'API, site inconnu)
 */
final readonly class AudienceReporter
{
    public const int MAX_LIMIT = 100;
    /** Durée maximale de la fenêtre « temps réel » acceptée par Live.getCounters (minutes). */
    public const int LIVE_MAX_MINUTES = 2880;
    private const int SEARCH_MAX_LENGTH = 100;

    /**
     * @param MatomoClientInterface|null $liveClient client du temps réel (cache court) ; par défaut, le client des rapports
     */
    public function __construct(
        private MatomoClientInterface $client,
        private SiteRegistry $sites,
        private LabelSanitizerInterface $sanitizer = new NullLabelSanitizer(),
        private ?MatomoClientInterface $liveClient = null,
    ) {
    }

    /**
     * Visites, actions et visiteurs des dernières minutes (Live.getCounters) : seul chiffre en temps réel, les rapports
     * suivant l'archivage de Matomo. Totaux uniquement.
     */
    public function live(int $minutes = 30, ?string $site = null): LiveCounters
    {
        $minutes = max(1, min(self::LIVE_MAX_MINUTES, $minutes));
        $data = ($this->liveClient ?? $this->client)->request('Live.getCounters', ['idSite' => $this->sites->id($site), 'lastMinutes' => $minutes]);
        $row = \is_array($data[0] ?? null) ? $data[0] : $data;

        return new LiveCounters($minutes, self::int($row, 'visits'), self::int($row, 'actions'), self::int($row, 'visitors'), new \DateTimeImmutable());
    }

    public function summary(Period $period, ?string $site = null): Summary
    {
        $results = $this->fetch($this->summaryCalls('', $period, $site));

        return $this->summaryFrom($results, '', $period);
    }

    public function compare(Period $period, ?string $site = null): Comparison
    {
        $previous = $period->previous();
        $results = $this->fetch($this->summaryCalls('current.', $period, $site) + $this->summaryCalls('previous.', $previous, $site));

        return new Comparison($period, $this->summaryFrom($results, 'current.', $period), $previous, $this->summaryFrom($results, 'previous.', $previous));
    }

    /**
     * @return list<EvolutionPoint>
     */
    public function evolution(Period $period, ?Granularity $granularity = null, ?string $site = null): array
    {
        return $this->evolutionFrom($this->fetch($this->evolutionCalls($period, $granularity, $site)));
    }

    /**
     * Pages les plus consultées (ou pages d'entrée, de sortie), par ordre décroissant, avec recherche facultative sur
     * le libellé (texte simple, sans expression régulière).
     */
    public function pages(Period $period, PageReport $report = PageReport::URLS, int $limit = 25, int $offset = 0, ?string $search = null, ?string $site = null): PageList
    {
        [$call, $limit, $offset] = $this->pagesCall($period, $report, $limit, $offset, $search, $site);

        return $this->pagesFrom($this->fetch(['pages' => $call])['pages'], $period, $report, $limit, $offset);
    }

    /**
     * @return list<DimensionRow>
     */
    public function dimension(Period $period, DimensionReport $report, int $limit = 10, ?string $site = null): array
    {
        return $this->dimensionFrom($this->fetch(['dimension' => $this->dimensionCall($period, $report, $limit, $site)])['dimension']);
    }

    /**
     * Tout le tableau de bord en un seul échange avec Matomo (réponses en cache non redemandées) : indicateurs comparés
     * à la période précédente, évolution, pages et répartitions. Une répartition que Matomo refuse (extension
     * désactivée) vaut null sans empêcher le reste. pagesOnly : seulement les pages (recherche, pagination).
     *
     * @param list<DimensionReport>|null $dimensions répartitions voulues (par défaut : toutes)
     */
    public function overview(
        Period $period,
        ?Granularity $granularity = null,
        PageReport $report = PageReport::URLS,
        int $pageLimit = 25,
        int $offset = 0,
        ?string $search = null,
        int $dimensionLimit = 10,
        ?array $dimensions = null,
        bool $pagesOnly = false,
        ?string $site = null,
    ): Overview {
        [$pagesCall, $pageLimit, $offset] = $this->pagesCall($period, $report, $pageLimit, $offset, $search, $site);
        $calls = ['pages' => $pagesCall];
        $dimensions ??= DimensionReport::cases();
        $previous = $period->previous();
        if (!$pagesOnly) {
            $calls += $this->summaryCalls('current.', $period, $site) + $this->summaryCalls('previous.', $previous, $site) + $this->evolutionCalls($period, $granularity, $site);
            foreach ($dimensions as $dimension) {
                $calls['dimension.'.$dimension->value] = $this->dimensionCall($period, $dimension, $dimensionLimit, $site);
            }
        }
        $results = $this->client->requestMany($calls);
        $required = array_filter($results, static fn (string $key): bool => !str_starts_with($key, 'dimension.'), \ARRAY_FILTER_USE_KEY);
        foreach ($required as $result) {
            if ($result instanceof MatomoApiException) {
                throw $result;
            }
        }
        /** @var array<string, array<mixed>> $required */
        $pages = $this->pagesFrom($required['pages'], $period, $report, $pageLimit, $offset);
        if ($pagesOnly) {
            return new Overview(null, [], $pages, []);
        }

        $rows = [];
        foreach ($dimensions as $dimension) {
            $result = $results['dimension.'.$dimension->value];
            $rows[$dimension->value] = \is_array($result) ? $this->dimensionFrom($result) : null;
        }

        return new Overview(
            new Comparison($period, $this->summaryFrom($required, 'current.', $period), $previous, $this->summaryFrom($required, 'previous.', $previous)),
            $this->evolutionFrom($required),
            $pages,
            $rows,
        );
    }

    /**
     * @param array<string, array{0: string, 1: array<string, scalar|list<scalar>|null>}> $calls
     *
     * @return array<string, array<mixed>>
     */
    private function fetch(array $calls): array
    {
        $results = [];
        foreach ($this->client->requestMany($calls) as $key => $result) {
            if ($result instanceof MatomoApiException) {
                throw $result;
            }
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, scalar|list<scalar>|null>}>
     */
    private function summaryCalls(string $prefix, Period $period, ?string $site): array
    {
        $parameters = ['idSite' => $this->sites->id($site)] + $period->apiParameters();

        return [$prefix.'visits' => ['VisitsSummary.get', $parameters], $prefix.'actions' => ['Actions.get', $parameters]];
    }

    /**
     * @param array<string, array<mixed>> $results
     */
    private function summaryFrom(array $results, string $prefix, Period $period): Summary
    {
        return $this->toSummary($results[$prefix.'visits'], $results[$prefix.'actions'], $period->hasUniqueVisitors());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, scalar|list<scalar>|null>}>
     */
    private function evolutionCalls(Period $period, ?Granularity $granularity, ?string $site): array
    {
        $parameters = ['idSite' => $this->sites->id($site)] + $period->evolutionParameters($granularity);

        return ['evolution.visits' => ['VisitsSummary.get', $parameters], 'evolution.actions' => ['Actions.get', $parameters]];
    }

    /**
     * @param array<string, array<mixed>> $results
     *
     * @return list<EvolutionPoint>
     */
    private function evolutionFrom(array $results): array
    {
        $actions = $results['evolution.actions'];
        $points = [];
        foreach ($results['evolution.visits'] as $key => $row) {
            $row = \is_array($row) ? $row : [];
            $pageviews = \is_array($actions[$key] ?? null) ? $actions[$key] : [];
            $points[] = new EvolutionPoint(
                self::periodStart((string) $key),
                (string) $key,
                self::int($row, 'nb_visits'),
                self::nullableInt($row, 'nb_uniq_visitors'),
                [] === $pageviews && [] === $row ? 0 : self::nullableInt($pageviews, 'nb_pageviews') ?? 0,
            );
        }
        usort($points, static fn (EvolutionPoint $a, EvolutionPoint $b): int => $a->start <=> $b->start);

        return $points;
    }

    /**
     * @return array{0: array{0: string, 1: array<string, scalar|list<scalar>|null>}, 1: int, 2: int} appel, nombre de lignes, décalage
     */
    private function pagesCall(Period $period, PageReport $report, int $limit, int $offset, ?string $search, ?string $site): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $search = null === $search ? '' : trim(mb_substr($search, 0, self::SEARCH_MAX_LENGTH));

        return [[$report->method(), ['idSite' => $this->sites->id($site)] + $period->apiParameters() + [
            'flat' => 1,
            'filter_sort_column' => $report->sortColumn(),
            'filter_sort_order' => 'desc',
            'filter_offset' => $offset,
            // Une ligne de plus que demandé : indique s'il existe une page suivante.
            'filter_limit' => $limit + 1,
            'filter_pattern' => '' === $search ? null : preg_quote($search),
        ]], $limit, $offset];
    }

    /**
     * @param array<mixed> $rows
     */
    private function pagesFrom(array $rows, Period $period, PageReport $report, int $limit, int $offset): PageList
    {
        $list = [];
        foreach (\array_slice(array_values($rows), 0, $limit) as $row) {
            if (\is_array($row)) {
                $list[] = $this->toPageRow($row, $report, $period->hasUniqueVisitors());
            }
        }

        return new PageList($report, $list, $offset, $limit, \count($rows) > $limit);
    }

    /**
     * @return array{0: string, 1: array<string, scalar|list<scalar>|null>}
     */
    private function dimensionCall(Period $period, DimensionReport $report, int $limit, ?string $site): array
    {
        return [$report->method(), ['idSite' => $this->sites->id($site)] + $period->apiParameters() + [
            'filter_sort_column' => 'nb_visits',
            'filter_sort_order' => 'desc',
            'filter_limit' => max(1, min(self::MAX_LIMIT, $limit)),
        ]];
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<DimensionRow>
     */
    private function dimensionFrom(array $rows): array
    {
        // Lignes à zéro visite (ex. types d'appareils jamais rencontrés) : sans intérêt.
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => \is_array($row) && self::int($row, 'nb_visits') > 0));
        $total = array_sum(array_map(static fn (array $row): int => self::int($row, 'nb_visits'), $rows));

        return array_map(fn (array $row): DimensionRow => new DimensionRow(
            $this->sanitizer->sanitize(self::string($row, 'label')),
            self::int($row, 'nb_visits'),
            self::int($row, 'nb_actions'),
            0 === $total ? 0.0 : round(self::int($row, 'nb_visits') / $total * 100, 1),
        ), $rows);
    }

    /**
     * @param array<mixed> $visits
     * @param array<mixed> $actions
     */
    private function toSummary(array $visits, array $actions, bool $hasUniqueVisitors): Summary
    {
        $count = self::int($visits, 'nb_visits');

        return new Summary(
            $count,
            $hasUniqueVisitors ? self::nullableInt($visits, 'nb_uniq_visitors') ?? ([] === $visits ? 0 : null) : null,
            self::nullableInt($actions, 'nb_pageviews') ?? ([] === $actions ? 0 : null),
            self::nullableInt($actions, 'nb_uniq_pageviews') ?? ([] === $actions ? 0 : null),
            self::int($visits, 'nb_actions'),
            is_numeric($visits['nb_actions_per_visit'] ?? null) ? (float) $visits['nb_actions_per_visit'] : 0.0,
            self::int($visits, 'avg_time_on_site'),
            0 === $count ? null : self::percent($visits['bounce_rate'] ?? null),
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function toPageRow(array $row, PageReport $report, bool $hasUniqueVisitors): PageRow
    {
        $visits = match ($report) {
            PageReport::ENTRY => self::int($row, 'entry_nb_visits'),
            PageReport::EXIT => self::int($row, 'exit_nb_visits'),
            default => self::int($row, 'nb_visits'),
        };
        $url = isset($row['url']) && \is_string($row['url']) ? $this->sanitizer->sanitize($row['url']) : null;

        return new PageRow(
            $this->sanitizer->sanitize(self::string($row, 'label')),
            $url,
            self::int($row, 'nb_hits'),
            $visits,
            $hasUniqueVisitors ? self::nullableInt($row, 'nb_uniq_visitors') : null,
            self::nullableInt($row, 'avg_time_on_page'),
            self::percent($row['bounce_rate'] ?? null),
            self::percent($row['exit_rate'] ?? null),
        );
    }

    /** Début d'un point d'évolution : « 2026-11-06 », « 2026-10-27,2026-11-02 » (semaine) ou « 2026-10 » (mois). */
    private static function periodStart(string $key): \DateTimeImmutable
    {
        $first = explode(',', $key)[0];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $first) ?: \DateTimeImmutable::createFromFormat('!Y-m', $first);

        return false === $date ? new \DateTimeImmutable('@0') : $date;
    }

    /** « 18% », « 18,5 % » (espace insécable selon la langue) ou 0.18 (selon la version de Matomo) en pourcentage. */
    private static function percent(mixed $value): ?float
    {
        if (\is_string($value) && 1 === preg_match('/^\s*(\d+(?:[.,]\d+)?)[\s\x{00A0}\x{202F}]*%\s*$/u', $value, $match)) {
            return (float) str_replace(',', '.', $match[1]);
        }
        if (\is_int($value) || \is_float($value)) {
            return $value <= 1 ? round($value * 100, 1) : (float) $value;
        }

        return null;
    }

    /** @param array<mixed> $row */
    private static function int(array $row, string $key): int
    {
        return self::nullableInt($row, $key) ?? 0;
    }

    /** @param array<mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
