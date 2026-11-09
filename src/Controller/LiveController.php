<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Controller;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Yassrzg\MatomoReportingBundle\Exception\MatomoExceptionInterface;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;

/**
 * Fréquentation en temps réel (GET, fragment) : visites, pages vues et visiteurs des dernières minutes, rendus dans le
 * cadre « matomo-reporting-live » (gabarit live.template), que la page du tableau de bord recharge régulièrement.
 * Même rôle exigé que le tableau de bord ; Matomo indisponible ou temps réel désactivé côté Matomo : counters = null.
 */
final readonly class LiveController
{
    public const string FRAME = 'matomo-reporting-live';

    public function __construct(
        private AudienceReporter $reporter,
        private Environment $twig,
        private string $template,
        private int $minutes,
        private ?string $role,
        private ?AuthorizationCheckerInterface $authorization = null,
        private LoggerInterface $logger = new NullLogger(),
        private bool $enabled = true,
        private string $timezone = 'Europe/Paris',
    ) {
    }

    public function __invoke(): Response
    {
        if (null !== $this->role && (null === $this->authorization || !$this->authorization->isGranted($this->role))) {
            throw new AccessDeniedException();
        }
        if (!$this->enabled) {
            throw new NotFoundHttpException('Fréquentation en temps réel désactivée.');
        }

        $counters = null;
        try {
            $counters = $this->reporter->live($this->minutes);
        } catch (MatomoExceptionInterface $e) {
            $this->logger->warning('Temps réel Matomo indisponible : {message}', ['message' => $e->getMessage(), 'exception_class' => $e::class]);
        }

        $response = new Response($this->twig->render($this->template, ['counters' => $counters, 'minutes' => $this->minutes, 'live_frame' => self::FRAME, 'timezone' => $this->timezone]));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
