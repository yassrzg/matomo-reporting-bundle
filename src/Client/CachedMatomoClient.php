<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Client;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache des réponses de l'API (décorateur de MatomoClientInterface) : une même méthode avec les mêmes paramètres
 * n'est appelée qu'une fois par durée de cache ; pour plusieurs méthodes, seules celles absentes du cache sont
 * demandées, en un seul échange. Les échecs ne sont jamais mis en cache (nouvel essai au prochain affichage). La clé
 * est une empreinte de la méthode et des paramètres : le jeton n'y figure pas.
 */
final readonly class CachedMatomoClient implements MatomoClientInterface
{
    public function __construct(
        private MatomoClientInterface $client,
        private CacheItemPoolInterface $cache,
        private int $ttl,
        private string $namespace = 'matomo_reporting',
    ) {
    }

    public function request(string $method, array $parameters = []): array
    {
        if ($this->ttl <= 0) {
            return $this->client->request($method, $parameters);
        }
        $item = $this->cache->getItem($this->key($method, $parameters));
        $cached = $item->isHit() ? $item->get() : null;
        if (\is_array($cached)) {
            return $cached;
        }

        $data = $this->client->request($method, $parameters);
        $this->cache->save($item->set($data)->expiresAfter($this->ttl));

        return $data;
    }

    public function requestMany(array $calls): array
    {
        if ($this->ttl <= 0) {
            return $this->client->requestMany($calls);
        }

        $keys = [];
        foreach ($calls as $name => $call) {
            $keys[$name] = $this->key($call[0], $call[1] ?? []);
        }
        $items = [];
        foreach ($this->cache->getItems(array_values($keys)) as $item) {
            if ($item instanceof CacheItemInterface) {
                $items[$item->getKey()] = $item;
            }
        }

        $results = [];
        $missing = [];
        foreach ($calls as $name => $call) {
            $item = $items[$keys[$name]] ??= $this->cache->getItem($keys[$name]);
            $cached = $item->isHit() ? $item->get() : null;
            if (\is_array($cached)) {
                $results[$name] = $cached;
            } else {
                $missing[$name] = $call;
            }
        }

        foreach ([] === $missing ? [] : $this->client->requestMany($missing) as $name => $result) {
            if (\is_array($result)) {
                $this->cache->saveDeferred($items[$keys[$name]]->set($result)->expiresAfter($this->ttl));
            }
            $results[$name] = $result;
        }
        $this->cache->commit();

        // Ordre des appels conservé.
        $ordered = [];
        foreach (array_keys($calls) as $name) {
            $ordered[$name] = $results[$name];
        }

        return $ordered;
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $parameters
     */
    private function key(string $method, array $parameters): string
    {
        ksort($parameters);

        return $this->namespace.'_'.hash('xxh128', $method.'|'.json_encode($parameters, \JSON_THROW_ON_ERROR));
    }
}
