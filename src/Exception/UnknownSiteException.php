<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Exception;

/**
 * Site absent de la configuration (clé matomo_reporting.sites).
 */
final class UnknownSiteException extends \InvalidArgumentException implements MatomoExceptionInterface
{
}
