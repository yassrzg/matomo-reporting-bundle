<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Exception;

/**
 * Matomo injoignable ou réponse inexploitable : DNS, certificat, délai dépassé, statut HTTP en erreur, JSON invalide.
 * Le message ne contient jamais le jeton d'API.
 */
final class MatomoUnavailableException extends \RuntimeException implements MatomoExceptionInterface
{
}
