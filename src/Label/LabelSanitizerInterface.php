<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Label;

/**
 * Nettoyage des libellés et adresses renvoyés par Matomo avant affichage (ex. masquer une donnée personnelle présente
 * dans d'anciennes adresses de pages). Implémentation propre à l'application : option label_sanitizer.
 */
interface LabelSanitizerInterface
{
    public function sanitize(string $label): string;
}
