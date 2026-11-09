<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Label;

/**
 * Libellés affichés tels que Matomo les renvoie (par défaut).
 */
final class NullLabelSanitizer implements LabelSanitizerInterface
{
    public function sanitize(string $label): string
    {
        return $label;
    }
}
