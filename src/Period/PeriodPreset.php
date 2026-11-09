<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Period;

/**
 * Périodes proposées par le tableau de bord (paramètre « period » de l'adresse).
 */
enum PeriodPreset: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case LAST_7_DAYS = 'last7';
    case LAST_30_DAYS = 'last30';
    case CURRENT_MONTH = 'month';
    case PREVIOUS_MONTH = 'previous-month';
    case CURRENT_YEAR = 'year';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::TODAY => 'Aujourd\'hui',
            self::YESTERDAY => 'Hier',
            self::LAST_7_DAYS => '7 derniers jours',
            self::LAST_30_DAYS => '30 derniers jours',
            self::CURRENT_MONTH => 'Mois en cours',
            self::PREVIOUS_MONTH => 'Mois précédent',
            self::CURRENT_YEAR => 'Année en cours',
            self::CUSTOM => 'Période personnalisée',
        };
    }
}
