<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Period;

use Yassrzg\MatomoReportingBundle\Exception\InvalidPeriodException;

/**
 * Période à partir des paramètres d'une requête (préréglage, ou dates AAAA-MM-JJ pour une période personnalisée),
 * dans le fuseau horaire des statistiques. Toute valeur inattendue lève InvalidPeriodException.
 */
final readonly class PeriodFactory
{
    private \DateTimeZone $timezone;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now horloge (tests)
     */
    public function __construct(
        string $timezone = 'UTC',
        private PeriodPreset $default = PeriodPreset::LAST_30_DAYS,
        private ?\Closure $now = null,
    ) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function create(?string $preset = null, ?string $start = null, ?string $end = null): Period
    {
        $value = PeriodPreset::tryFrom((string) $preset);
        if (null === $value && null !== $preset && '' !== $preset) {
            throw new InvalidPeriodException('Période inconnue.');
        }
        $value ??= $this->default;
        if (PeriodPreset::CUSTOM !== $value) {
            return Period::fromPreset($value, $this->today());
        }

        return Period::custom($this->date($start, 'début'), $this->date($end, 'fin'), $this->today());
    }

    public function today(): \DateTimeImmutable
    {
        $now = null !== $this->now ? ($this->now)() : new \DateTimeImmutable();

        return $now->setTimezone($this->timezone)->setTime(0, 0);
    }

    private function date(?string $value, string $name): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value, $this->timezone)
            : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidPeriodException(\sprintf('Date de %s invalide (format attendu : AAAA-MM-JJ).', $name));
        }

        return $date;
    }
}
