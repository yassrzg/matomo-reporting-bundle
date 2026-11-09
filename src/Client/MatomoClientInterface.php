<?php

declare(strict_types=1);

namespace Yassrzg\MatomoReportingBundle\Client;

use Yassrzg\MatomoReportingBundle\Exception\MatomoApiException;
use Yassrzg\MatomoReportingBundle\Exception\MatomoUnavailableException;

/**
 * Appel d'une méthode de l'API de reporting Matomo (ex. VisitsSummary.get), toujours côté serveur.
 */
interface MatomoClientInterface
{
    /**
     * @param string                                  $method     méthode de l'API, « Module.action »
     * @param array<string, scalar|list<scalar>|null> $parameters paramètres de la méthode (idSite, period, date…)
     *
     * @return array<mixed> réponse JSON décodée
     *
     * @throws MatomoUnavailableException Matomo injoignable ou réponse inexploitable
     * @throws MatomoApiException         erreur renvoyée par Matomo
     */
    public function request(string $method, array $parameters = []): array;

    /**
     * Plusieurs méthodes en un seul échange avec Matomo (API.getBulkRequest).
     *
     * @template K of array-key
     *
     * @param array<K, array{0: string, 1?: array<string, scalar|list<scalar>|null>}> $calls clé => [méthode, paramètres]
     *
     * @return array<K, array<mixed>|MatomoApiException> réponse de chaque appel, dans le même ordre, ou l'erreur que
     *                                                   Matomo a renvoyée pour cet appel seulement
     *
     * @throws MatomoUnavailableException Matomo injoignable ou réponse inexploitable (aucun appel n'a abouti)
     * @throws MatomoApiException         demande entière refusée (ex. jeton invalide)
     */
    public function requestMany(array $calls): array;
}
