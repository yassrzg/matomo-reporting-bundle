<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Rapports de répartition des visites.
 */
enum DimensionReport: string
{
    case CHANNELS = 'channels';
    case SEARCH_ENGINES = 'search-engines';
    case WEBSITES = 'websites';
    case DEVICES = 'devices';
    case BROWSERS = 'browsers';

    public function method(): string
    {
        return match ($this) {
            self::CHANNELS => 'Referrers.getReferrerType',
            self::SEARCH_ENGINES => 'Referrers.getSearchEngines',
            self::WEBSITES => 'Referrers.getWebsites',
            self::DEVICES => 'DevicesDetection.getType',
            self::BROWSERS => 'DevicesDetection.getBrowsers',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CHANNELS => 'Sources de trafic',
            self::SEARCH_ENGINES => 'Moteurs de recherche',
            self::WEBSITES => 'Sites référents',
            self::DEVICES => 'Types d\'appareils',
            self::BROWSERS => 'Navigateurs',
        };
    }
}
