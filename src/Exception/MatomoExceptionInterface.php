<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Exception;

/**
 * Toute erreur du bundle : à intercepter pour afficher un message compréhensible (le détail technique va au journal).
 */
interface MatomoExceptionInterface extends \Throwable
{
}
