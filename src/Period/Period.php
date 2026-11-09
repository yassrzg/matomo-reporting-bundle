<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Period;

use Yassrzg\MatomoReportingBundle\Exception\InvalidPeriodException;

/**
 * Période d'analyse (dates incluses, jours entiers) et sa traduction en paramètres de l'API Matomo.
 *
 * Jour, mois entier ou année : périodes natives de Matomo (visiteurs uniques disponibles). Autre plage : période
 * « range » (Matomo n'y calcule pas les visiteurs uniques, sauf configuration particulière du serveur).
 */
final readonly class Period
{
    /** Plage personnalisée la plus longue acceptée (limite la charge sur Matomo). */
    public const int MAX_DAYS = 1100;

    private function __construct(
        public PeriodPreset $preset,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function fromPreset(PeriodPreset $preset, \DateTimeImmutable $today): self
    {
        $today = $today->setTime(0, 0);

        return match ($preset) {
            PeriodPreset::TODAY => new self($preset, $today, $today),
            PeriodPreset::YESTERDAY => new self($preset, $today->modify('-1 day'), $today->modify('-1 day')),
            PeriodPreset::LAST_7_DAYS => new self($preset, $today->modify('-6 days'), $today),
            PeriodPreset::LAST_30_DAYS => new self($preset, $today->modify('-29 days'), $today),
            PeriodPreset::CURRENT_MONTH => new self($preset, $today->modify('first day of this month'), $today),
            PeriodPreset::PREVIOUS_MONTH => new self($preset, $today->modify('first day of last month'), $today->modify('last day of last month')),
            PeriodPreset::CURRENT_YEAR => new self($preset, $today->setDate((int) $today->format('Y'), 1, 1), $today),
            PeriodPreset::CUSTOM => throw new InvalidPeriodException('Une période personnalisée exige une date de début et une date de fin.'),
        };
    }

    public static function custom(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $today): self
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($start > $end) {
            throw new InvalidPeriodException('La date de début doit précéder la date de fin.');
        }
        if ($end > $today->setTime(0, 0)) {
            throw new InvalidPeriodException('La date de fin ne peut pas être dans le futur.');
        }
        if ($start->diff($end)->days >= self::MAX_DAYS) {
            throw new InvalidPeriodException(\sprintf('La période ne peut pas dépasser %d jours.', self::MAX_DAYS));
        }

        return new self(PeriodPreset::CUSTOM, $start, $end);
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    /**
     * Paramètres « period » et « date » de l'API pour les totaux de la période.
     *
     * @return array{period: string, date: string}
     */
    public function apiParameters(): array
    {
        if ($this->start == $this->end) {
            return ['period' => 'day', 'date' => $this->start->format('Y-m-d')];
        }
        if ($this->isWholeMonth()) {
            return ['period' => 'month', 'date' => $this->start->format('Y-m-d')];
        }

        return ['period' => 'range', 'date' => $this->start->format('Y-m-d').','.$this->end->format('Y-m-d')];
    }

    /**
     * Visiteurs uniques fournis par Matomo pour cette période (jour ou mois entier, pas une plage quelconque).
     */
    public function hasUniqueVisitors(): bool
    {
        return 'range' !== $this->apiParameters()['period'];
    }

    /** Pas de l'évolution : par jour jusqu'à deux mois, par semaine jusqu'à un an, par mois au-delà. */
    public function granularity(): Granularity
    {
        return match (true) {
            $this->days() <= 62 => Granularity::DAY,
            $this->days() <= 366 => Granularity::WEEK,
            default => Granularity::MONTH,
        };
    }

    /**
     * Paramètres de l'API pour l'évolution de la période, au pas demandé.
     *
     * @return array{period: string, date: string}
     */
    public function evolutionParameters(?Granularity $granularity = null): array
    {
        return ['period' => ($granularity ?? $this->granularity())->value, 'date' => $this->start->format('Y-m-d').','.$this->end->format('Y-m-d')];
    }

    /**
     * Période de même durée qui précède immédiatement (comparaison) ; mois précédent pour un mois entier.
     */
    public function previous(): self
    {
        if ($this->isWholeMonth()) {
            $start = $this->start->modify('first day of last month');

            return new self(PeriodPreset::CUSTOM, $start, $start->modify('last day of this month'));
        }
        $end = $this->start->modify('-1 day');

        return new self(PeriodPreset::CUSTOM, $end->modify(\sprintf('-%d days', $this->days() - 1)), $end);
    }

    public function label(): string
    {
        if ($this->start == $this->end) {
            return 'le '.$this->start->format('d/m/Y');
        }

        return 'du '.$this->start->format('d/m/Y').' au '.$this->end->format('d/m/Y');
    }

    private function isWholeMonth(): bool
    {
        return '01' === $this->start->format('d')
            && $this->end->format('Y-m-d') === $this->start->modify('last day of this month')->format('Y-m-d');
    }
}
