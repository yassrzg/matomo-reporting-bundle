<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Réponses réelles de Matomo 5 (instance de test, données synthétiques) servies selon la méthode et la période
 * demandées : callback de MockHttpClient. Les requêtes reçues sont gardées pour les vérifications.
 */
final class MatomoFixtures
{
    /** @var list<array{method: string, url: string, body: array<string, string>, options: array<mixed>}> requêtes HTTP */
    public array $requests = [];

    /** @var list<array<string, string>> appels de l'API (une requête groupée en contient plusieurs) */
    public array $calls = [];

    /**
     * @param array<mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $parsed);
        $body = self::strings($parsed);
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'options' => $options];

        // Demande groupée : une réponse par adresse, dans l'ordre (comme API.getBulkRequest).
        if ('API.getBulkRequest' === ($body['method'] ?? null)) {
            $answers = [];
            foreach (\is_array($parsed['urls'] ?? null) ? $parsed['urls'] : [] as $query) {
                parse_str(\is_string($query) ? $query : '', $call);
                $this->calls[] = self::strings($call);
                $answers[] = json_decode(self::read(self::file(self::strings($call))), true);
            }

            return new MockResponse((string) json_encode($answers));
        }

        $this->calls[] = $body;

        return new MockResponse(self::read(self::file($body)));
    }

    /**
     * @param array<string, string> $body
     */
    public static function file(array $body): string
    {
        $evolution = 'range' !== ($body['period'] ?? '') && str_contains($body['date'] ?? '', ',');

        return match ($body['method'] ?? '') {
            'VisitsSummary.get' => match (true) {
                $evolution => 'week' === $body['period'] ? 'visits_evolution_week' : 'visits_evolution_day',
                'month' === ($body['period'] ?? '') => 'visits_summary_month',
                default => 'visits_summary_range',
            },
            'Actions.get' => $evolution ? 'actions_evolution_day' : 'actions_range',
            'Actions.getPageUrls', 'Actions.getPageTitles', 'Actions.getExitPageUrls' => 'page_urls',
            'Actions.getEntryPageUrls' => 'entry_pages',
            'Referrers.getReferrerType', 'Referrers.getSearchEngines', 'Referrers.getWebsites' => 'referrer_types',
            'DevicesDetection.getType' => 'device_types',
            'DevicesDetection.getBrowsers' => 'browsers',
            'Live.getCounters' => 'live_counters',
            default => 'error_token',
        };
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            $strings[(string) $key] = \is_string($value) ? $value : '';
        }

        return $strings;
    }

    public static function read(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
