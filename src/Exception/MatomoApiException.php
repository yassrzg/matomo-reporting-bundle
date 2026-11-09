<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Exception;

/**
 * Réponse d'erreur de l'API Matomo ({"result": "error", "message": …}) : jeton refusé, site inconnu, méthode
 * désactivée, paramètre invalide.
 */
final class MatomoApiException extends \RuntimeException implements MatomoExceptionInterface
{
}
