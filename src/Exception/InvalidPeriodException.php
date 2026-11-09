<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Exception;

/**
 * Période demandée invalide (préréglage inconnu, date mal formée, début après la fin, plage trop longue).
 */
final class InvalidPeriodException extends \InvalidArgumentException implements MatomoExceptionInterface
{
}
