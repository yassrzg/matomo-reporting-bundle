<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Yassrzg\MatomoReportingBundle\Client\CachedMatomoClient;
use Yassrzg\MatomoReportingBundle\Client\MatomoClientInterface;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoUnavailableException;

final class CachedMatomoClientTest extends TestCase
{
    public function testSameRequestIsServedFromCacheAndFailuresAreNotCached(): void
    {
        $inner = new class implements MatomoClientInterface {
            public int $calls = 0;
            public bool $down = false;

            public function request(string $method, array $parameters = []): array
            {
                ++$this->calls;
                if ($this->down) {
                    throw new MatomoUnavailableException('Matomo est injoignable.');
                }

                return ['method' => $method, 'call' => $this->calls];
            }

            public function requestMany(array $calls): array
            {
                $results = [];
                foreach ($calls as $key => $call) {
                    $results[$key] = $this->request($call[0], $call[1] ?? []);
                }

                return $results;
            }
        };
        $cache = new ArrayAdapter();
        $client = new CachedMatomoClient($inner, $cache, 600);

        self::assertSame(1, $client->request('VisitsSummary.get', ['idSite' => 1, 'date' => 'x'])['call']);
        self::assertSame(1, $client->request('VisitsSummary.get', ['date' => 'x', 'idSite' => 1])['call'], 'Ordre des paramètres sans effet.');
        self::assertSame(2, $client->request('VisitsSummary.get', ['idSite' => 2, 'date' => 'x'])['call'], 'Autres paramètres : autre entrée.');
        foreach (array_keys($cache->getValues()) as $key) {
            self::assertMatchesRegularExpression('/^matomo_reporting_[0-9a-f]{32}$/', $key, 'Clé : empreinte, sans jeton ni paramètre en clair.');
        }

        $inner->down = true;
        $calls = $inner->calls;
        for ($i = 0; $i < 2; ++$i) {
            try {
                $client->request('Actions.get', ['idSite' => 1]);
            } catch (MatomoUnavailableException) {
            }
        }
        self::assertSame($calls + 2, $inner->calls, 'Échec jamais mis en cache : nouvel essai à chaque appel.');

        $inner->down = false;
        $uncached = new CachedMatomoClient($inner, $cache, 0);
        $calls = $inner->calls;
        $uncached->request('VisitsSummary.get', ['idSite' => 1, 'date' => 'x']);
        self::assertSame($calls + 1, $inner->calls, 'Durée 0 : pas de cache.');
    }

    public function testManyRequestsAskOnlyForWhatIsNotCachedAndKeepTheOrder(): void
    {
        $inner = new class implements MatomoClientInterface {
            /** @var list<list<string>> */
            public array $batches = [];

            public function request(string $method, array $parameters = []): array
            {
                $result = $this->requestMany(['one' => [$method, $parameters]])['one'];
                if ($result instanceof MatomoApiException) {
                    throw $result;
                }

                return $result;
            }

            public function requestMany(array $calls): array
            {
                $this->batches[] = array_map('strval', array_keys($calls));
                $results = [];
                foreach ($calls as $key => $call) {
                    $results[$key] = 'Refuse.get' === $call[0] ? new MatomoApiException('Refusé.') : ['method' => $call[0]];
                }

                return $results;
            }
        };
        $client = new CachedMatomoClient($inner, new ArrayAdapter(), 600);
        $client->request('Actions.get', ['idSite' => 1]);

        $results = $client->requestMany(['a' => ['VisitsSummary.get', ['idSite' => 1]], 'b' => ['Actions.get', ['idSite' => 1]], 'c' => ['Refuse.get']]);

        self::assertSame(['a', 'b', 'c'], array_keys($results), 'Ordre des appels conservé.');
        self::assertSame([['one'], ['a', 'c']], $inner->batches, 'Réponse déjà en cache (b) non redemandée.');
        self::assertInstanceOf(MatomoApiException::class, $results['c']);
        $client->requestMany(['a' => ['VisitsSummary.get', ['idSite' => 1]], 'c' => ['Refuse.get']]);
        self::assertSame(['c'], end($inner->batches), 'Erreur jamais mise en cache.');
    }
}
