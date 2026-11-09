<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoUnavailableException;

/**
 * Client de l'API de reporting Matomo (HttpClient de Symfony).
 *
 * Le jeton est envoyé dans le corps d'une requête POST (jamais dans l'adresse : ni journal du serveur web, ni
 * historique), les redirections ne sont pas suivies (le jeton ne part pas vers un autre hôte) et le délai est borné.
 * Les journaux ne contiennent que la méthode, la durée et la cause d'un échec : jamais le jeton ni les paramètres.
 * Plusieurs méthodes peuvent être demandées en un seul échange (API.getBulkRequest) : une seule requête HTTP.
 */
final readonly class HttpMatomoClient implements MatomoClientInterface
{
    private string $endpoint;

    public function __construct(
        private HttpClientInterface $httpClient,
        string $url,
        #[\SensitiveParameter] private string $token,
        private float $timeout = 10.0,
        private bool $verifyPeer = true,
        private LoggerInterface $logger = new NullLogger(),
        private string $language = 'fr',
    ) {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true) || null === parse_url($url, \PHP_URL_HOST)) {
            throw new \InvalidArgumentException('Adresse de Matomo invalide : http(s)://hôte/ attendu.');
        }
        $this->endpoint = rtrim(preg_replace('~/index\.php$~', '', $url) ?? $url, '/').'/index.php';
    }

    public function request(string $method, array $parameters = []): array
    {
        self::assertMethod($method);

        return self::result($this->send(['method' => $method, 'language' => $this->language] + self::filled($parameters), $method), $method, $this->logger);
    }

    public function requestMany(array $calls): array
    {
        if ([] === $calls) {
            return [];
        }
        $urls = [];
        foreach ($calls as [$method]) {
            self::assertMethod($method);
        }
        foreach ($calls as $call) {
            $urls[] = http_build_query(['method' => $call[0], 'format' => 'JSON', 'language' => $this->language] + self::filled($call[1] ?? []), '', '&', \PHP_QUERY_RFC3986);
        }
        $label = 'API.getBulkRequest ('.implode(', ', array_map(static fn (array $call): string => $call[0], $calls)).')';
        $data = self::result($this->send(['method' => 'API.getBulkRequest', 'urls' => $urls], $label), $label, $this->logger);
        if (\count($data) !== \count($calls) || !array_is_list($data)) {
            throw new MatomoUnavailableException('Réponse groupée de Matomo inattendue.');
        }

        $results = [];
        $index = 0;
        foreach ($calls as $key => [$method]) {
            $item = $data[$index++];
            try {
                $results[$key] = self::result(\is_array($item) ? $item : null, $method, $this->logger);
            } catch (MatomoApiException $e) {
                $results[$key] = $e;
            }
        }

        return $results;
    }

    /**
     * Envoi d'une demande (jeton dans le corps) : réponse JSON décodée, ou erreur de Matomo (tableau « result: error »).
     *
     * @param array<string, mixed> $body
     *
     * @throws MatomoUnavailableException
     */
    private function send(array $body, string $label): mixed
    {
        if ('' === $this->token) {
            throw new MatomoUnavailableException('Jeton de l\'API Matomo non configuré.');
        }

        $start = microtime(true);
        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'body' => ['module' => 'API', 'format' => 'JSON', 'token_auth' => $this->token] + $body,
                'timeout' => $this->timeout,
                'max_redirects' => 0,
                'verify_peer' => $this->verifyPeer,
                'verify_host' => $this->verifyPeer,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            // Matomo 5 répond 401 (jeton refusé) ou 400 avec une erreur JSON : erreur de l'API, pas une panne.
            $data = $status >= 400 && $status < 500 ? json_decode($response->getContent(false), true) : null;
            if (!\is_array($data) || 'error' !== ($data['result'] ?? null)) {
                if ($status >= 300) {
                    throw new MatomoUnavailableException(\sprintf('Réponse HTTP %d de Matomo.', $status));
                }
                $data = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            }
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('API Matomo {method} : échec ({cause})', ['method' => $label, 'cause' => $e::class, 'duration_ms' => self::elapsed($start)]);

            throw new MatomoUnavailableException('Matomo est injoignable.', 0, $e);
        } catch (\JsonException $e) {
            $this->logger->error('API Matomo {method} : réponse non JSON', ['method' => $label, 'duration_ms' => self::elapsed($start)]);

            throw new MatomoUnavailableException('Réponse de Matomo illisible.', 0, $e);
        } catch (MatomoUnavailableException $e) {
            $this->logger->error('API Matomo {method} : {message}', ['method' => $label, 'message' => $e->getMessage(), 'duration_ms' => self::elapsed($start)]);

            throw $e;
        }
        $this->logger->info('API Matomo {method} : {duration_ms} ms', ['method' => $label, 'duration_ms' => self::elapsed($start)]);

        return $data;
    }

    /**
     * @return array<mixed>
     *
     * @throws MatomoApiException         erreur renvoyée par Matomo
     * @throws MatomoUnavailableException réponse qui n'est pas un objet ou une liste JSON
     */
    private static function result(mixed $data, string $method, LoggerInterface $logger): array
    {
        if (!\is_array($data)) {
            throw new MatomoUnavailableException('Réponse de Matomo inattendue.');
        }
        if ('error' === ($data['result'] ?? null)) {
            $message = \is_string($data['message'] ?? null) ? $data['message'] : 'erreur inconnue';
            $logger->warning('API Matomo {method} : erreur « {message} »', ['method' => $method, 'message' => $message]);

            throw new MatomoApiException($message);
        }

        return $data;
    }

    private static function assertMethod(string $method): void
    {
        if (1 !== preg_match('/^[A-Za-z]+\.[A-Za-z]+$/', $method)) {
            throw new \InvalidArgumentException(\sprintf('Méthode de l\'API Matomo invalide : « %s ».', $method));
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private static function filled(array $parameters): array
    {
        return array_filter($parameters, static fn (mixed $value): bool => null !== $value);
    }

    private static function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
