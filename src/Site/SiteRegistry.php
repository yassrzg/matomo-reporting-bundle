<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Site;

use Yassrzg\MatomoReportingBundle\Exception\UnknownSiteException;

/**
 * Sites Matomo déclarés dans la configuration (nom => identifiant Matomo) et site par défaut.
 */
final readonly class SiteRegistry
{
    /**
     * @param array<string, int> $sites
     */
    public function __construct(private array $sites, private string $default)
    {
        if (!isset($sites[$default])) {
            throw new UnknownSiteException(\sprintf('Site par défaut « %s » absent de matomo_reporting.sites.', $default));
        }
    }

    public function id(?string $name = null): int
    {
        $name ??= $this->default;

        return $this->sites[$name] ?? throw new UnknownSiteException(\sprintf('Site Matomo inconnu : « %s ».', $name));
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->sites;
    }

    public function defaultName(): string
    {
        return $this->default;
    }
}
