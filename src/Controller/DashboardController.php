<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Controller;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Yassrzg\MatomoReportingBundle\Exception\InvalidPeriodException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoExceptionInterface;
use Yassrzg\MatomoReportingBundle\Period\Granularity;
use Yassrzg\MatomoReportingBundle\Period\PeriodFactory;
use Yassrzg\MatomoReportingBundle\Period\PeriodPreset;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Report\DimensionReport;
use Yassrzg\MatomoReportingBundle\Report\PageReport;

/**
 * Tableau de bord (GET seulement, aucune écriture) : période, indicateurs et comparaison, évolution, pages, répartitions.
 * Gabarit configurable (dashboard.template) : l'application le remplace pour l'intégrer à son interface.
 *
 * Paramètres de l'adresse, tous validés : period (préréglage), start et end (AAAA-MM-JJ, période personnalisée),
 * step (day, week, month), report (urls, titles, entry, exit), q (recherche dans les pages), offset.
 *
 * Tout est lu en un seul échange avec Matomo (AudienceReporter::overview()). Demande venant du cadre Turbo des pages
 * (en-tête « Turbo-Frame: matomo-reporting-pages » : recherche, onglets, pagination sans recharger la page) : seul le
 * rapport des pages est lu, et le gabarit reçoit pages_only = true pour ne rendre que ce cadre.
 */
final readonly class DashboardController
{
    public const string PAGES_FRAME = 'matomo-reporting-pages';

    public function __construct(
        private AudienceReporter $reporter,
        private PeriodFactory $periods,
        private Environment $twig,
        private string $template,
        private int $pageSize,
        private int $dimensionLimit,
        private ?string $role,
        private ?AuthorizationCheckerInterface $authorization = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?int $liveMinutes = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (null !== $this->role && (null === $this->authorization || !$this->authorization->isGranted($this->role))) {
            throw new AccessDeniedException();
        }

        // Paramètres lus bruts (all()) : un tableau à la place d'un texte est ignoré au lieu de lever une erreur 400.
        $query = $request->query->all();
        $periodError = null;
        try {
            $period = $this->periods->create(self::param($query['period'] ?? null), self::param($query['start'] ?? null), self::param($query['end'] ?? null));
        } catch (InvalidPeriodException $e) {
            $periodError = $e->getMessage();
            $period = $this->periods->create();
        }
        $granularity = Granularity::tryFrom((string) self::param($query['step'] ?? null));
        $report = PageReport::tryFrom((string) self::param($query['report'] ?? null)) ?? PageReport::URLS;
        $search = self::param($query['q'] ?? null);
        $offset = max(0, (int) self::param($query['offset'] ?? null));
        $offset -= $offset % $this->pageSize;

        $pagesOnly = self::PAGES_FRAME === $request->headers->get('Turbo-Frame');

        $context = [
            'period' => $period,
            'period_error' => $periodError,
            'presets' => PeriodPreset::cases(),
            'granularity' => $granularity ?? $period->granularity(),
            'granularities' => Granularity::cases(),
            'page_reports' => PageReport::cases(),
            'report' => $report,
            'search' => $search,
            'today' => $this->periods->today(),
            'pages_only' => $pagesOnly,
            'pages_frame' => self::PAGES_FRAME,
            // Cadre « en ce moment » (temps réel) : null si désactivé (option live.enabled).
            'live_minutes' => $this->liveMinutes,
            'live_frame' => LiveController::FRAME,
            'comparison' => null,
            'evolution' => [],
            'pages' => null,
            'dimensions' => [],
            'error' => null,
        ];
        try {
            $overview = $this->reporter->overview($period, $granularity, $report, $this->pageSize, $offset, $search, $this->dimensionLimit, pagesOnly: $pagesOnly);
            $context['comparison'] = $overview->comparison;
            $context['evolution'] = $overview->evolution;
            $context['pages'] = $overview->pages;
            foreach ($overview->dimensions as $name => $rows) {
                if (null === $rows) {
                    // Rapport refusé par ce serveur (extension Matomo désactivée) : les autres restent affichés.
                    $this->logger->warning('Rapport Matomo {report} indisponible', ['report' => $name]);
                }
                $context['dimensions'][$name] = ['report' => DimensionReport::from($name), 'rows' => $rows];
            }
        } catch (MatomoExceptionInterface $e) {
            $this->logger->error('Mesure d\'audience indisponible : {message}', ['message' => $e->getMessage(), 'exception_class' => $e::class]);
            $context['error'] = $e;
        }

        // Matomo indisponible : page affichée (200) avec un message ; un 503 serait remplacé par certains reverse proxies.
        $response = new Response($this->twig->render($this->template, $context));
        $response->setPrivate();
        $response->headers->set('X-Robots-Tag', 'noindex');
        // Réponse différente selon le cadre demandé : jamais servie à la place de la page complète.
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /** Valeur scalaire d'un paramètre de l'adresse (un tableau ou une valeur vide est ignoré). */
    private static function param(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
