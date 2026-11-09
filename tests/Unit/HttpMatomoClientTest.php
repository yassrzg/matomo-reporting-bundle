<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Yassrzg\MatomoReportingBundle\Client\HttpMatomoClient;
use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoUnavailableException;
use Yassrzg\MatomoReportingBundle\Tests\MatomoFixtures;

final class HttpMatomoClientTest extends TestCase
{
    private const string TOKEN = 'jeton-secret-0123456789';

    public function testTokenIsSentInThePostBodyOnly(): void
    {
        $fixtures = new MatomoFixtures();
        $client = new HttpMatomoClient(new MockHttpClient($fixtures), 'https://matomo.test/sous-dossier/', self::TOKEN, 5.0);

        $data = $client->request('VisitsSummary.get', ['idSite' => 7, 'period' => 'range', 'date' => '2026-10-10,2026-11-08', 'segment' => null]);

        self::assertSame(220, $data['nb_visits']);
        $request = $fixtures->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://matomo.test/sous-dossier/index.php', $request['url'], 'Jeton absent de l\'adresse.');
        self::assertSame(['module' => 'API', 'format' => 'JSON', 'token_auth' => self::TOKEN, 'method' => 'VisitsSummary.get', 'language' => 'fr', 'idSite' => '7', 'period' => 'range', 'date' => '2026-10-10,2026-11-08'], $request['body'], 'Paramètre null omis.');
        self::assertSame(0, $request['options']['max_redirects'], 'Redirection jamais suivie (le jeton ne part pas ailleurs).');
        self::assertSame(5.0, $request['options']['timeout']);
    }

    public function testManyMethodsInASingleRequest(): void
    {
        $fixtures = new MatomoFixtures();
        $client = new HttpMatomoClient(new MockHttpClient($fixtures), 'https://matomo.test/', self::TOKEN);

        $results = $client->requestMany([
            'visits' => ['VisitsSummary.get', ['idSite' => 7, 'period' => 'range', 'date' => '2026-10-10,2026-11-08']],
            'unknown' => ['Inconnu.get', ['idSite' => 7]],
            'devices' => ['DevicesDetection.getType', ['idSite' => 7, 'period' => 'range', 'date' => '2026-10-10,2026-11-08', 'segment' => null]],
        ]);

        self::assertCount(1, $fixtures->requests, 'Un seul échange.');
        $body = $fixtures->requests[0]['body'];
        self::assertSame(['API', 'API.getBulkRequest', self::TOKEN], [$body['module'], $body['method'], $body['token_auth']]);
        self::assertSame(['VisitsSummary.get', 'Inconnu.get', 'DevicesDetection.getType'], array_column($fixtures->calls, 'method'));
        self::assertArrayNotHasKey('segment', $fixtures->calls[2], 'Paramètre null omis.');
        self::assertArrayNotHasKey('token_auth', $fixtures->calls[0], 'Jeton une seule fois, hors des adresses groupées.');
        self::assertSame(['visits', 'unknown', 'devices'], array_keys($results));
        self::assertIsArray($results['visits']);
        self::assertSame(220, $results['visits']['nb_visits']);
        self::assertInstanceOf(MatomoApiException::class, $results['unknown'], 'Erreur d\'un seul appel : les autres sont gardés.');
        self::assertIsArray($results['devices']);
        self::assertSame([], $client->requestMany([]), 'Rien à demander : aucun échange.');
        self::assertCount(1, $fixtures->requests);

        $truncated = new HttpMatomoClient(new MockHttpClient(new MockResponse('[{"nb_visits":1}]')), 'https://matomo.test/', self::TOKEN);
        $this->expectException(MatomoUnavailableException::class);
        $truncated->requestMany(['a' => ['VisitsSummary.get'], 'b' => ['Actions.get']]);
    }

    public function testMatomoErrorIsAnApiException(): void
    {
        // Matomo 5 : 200 avec une erreur JSON, ou 401 (jeton refusé) avec la même erreur.
        foreach ([200, 401] as $status) {
            $client = new HttpMatomoClient(new MockHttpClient(new MockResponse(MatomoFixtures::read('error_token'), ['http_code' => $status])), 'https://matomo.test', self::TOKEN);
            try {
                $client->request('VisitsSummary.get');
                self::fail($status.' : exception attendue.');
            } catch (MatomoApiException $e) {
                self::assertStringContainsString('Unable to authenticate', $e->getMessage());
            }
        }
    }

    public function testUnavailabilityNeverLeaksTheToken(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $message.' '.json_encode($context);
            }
        };
        $cases = [
            'transport' => static fn (): MockResponse => throw new TransportException('Could not resolve host: matomo.test (token_auth='.self::TOKEN.')'),
            'statut 500' => static fn (): MockResponse => new MockResponse('panne', ['http_code' => 500]),
            'redirection' => static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://ailleurs.test/']]),
            'pas du JSON' => static fn (): MockResponse => new MockResponse('<html>maintenance</html>'),
        ];
        foreach ($cases as $name => $response) {
            $client = new HttpMatomoClient(new MockHttpClient($response), 'https://matomo.test', self::TOKEN, logger: $logger);
            try {
                $client->request('VisitsSummary.get');
                self::fail($name.' : exception attendue.');
            } catch (MatomoUnavailableException $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $name);
            }
        }
        self::assertCount(4, $logger->lines);
        self::assertStringNotContainsString(self::TOKEN, implode("\n", $logger->lines), 'Jeton absent des journaux.');
    }

    public function testConfigurationAndMethodAreChecked(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('Aucune requête attendue.'));

        try {
            (new HttpMatomoClient($http, 'https://matomo.test', ''))->request('VisitsSummary.get');
            self::fail('Jeton vide accepté.');
        } catch (MatomoUnavailableException $e) {
            self::assertSame('Jeton de l\'API Matomo non configuré.', $e->getMessage());
        }
        try {
            (new HttpMatomoClient($http, 'https://matomo.test', self::TOKEN))->request('VisitsSummary.get&token_auth=x');
            self::fail('Méthode invalide acceptée.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new HttpMatomoClient($http, 'file:///etc/passwd', self::TOKEN);
    }
}
