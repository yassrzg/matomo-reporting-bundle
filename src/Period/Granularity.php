<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Period;

/**
 * Pas de l'évolution (graphique) : valeur du paramètre « period » de Matomo.
 */
enum Granularity: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Par jour',
            self::WEEK => 'Par semaine',
            self::MONTH => 'Par mois',
        };
    }
}
