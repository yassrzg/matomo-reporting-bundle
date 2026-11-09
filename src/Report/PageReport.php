<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Report;

/**
 * Rapports de pages de Matomo (module Actions, version « à plat » : une ligne par page).
 */
enum PageReport: string
{
    case URLS = 'urls';
    case TITLES = 'titles';
    case ENTRY = 'entry';
    case EXIT = 'exit';

    public function method(): string
    {
        return match ($this) {
            self::URLS => 'Actions.getPageUrls',
            self::TITLES => 'Actions.getPageTitles',
            self::ENTRY => 'Actions.getEntryPageUrls',
            self::EXIT => 'Actions.getExitPageUrls',
        };
    }

    public function sortColumn(): string
    {
        return match ($this) {
            self::ENTRY => 'entry_nb_visits',
            self::EXIT => 'exit_nb_visits',
            default => 'nb_hits',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::URLS => 'Pages (adresses)',
            self::TITLES => 'Pages (titres)',
            self::ENTRY => 'Pages d\'entrée',
            self::EXIT => 'Pages de sortie',
        };
    }
}
