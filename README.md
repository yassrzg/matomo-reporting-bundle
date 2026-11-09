# Matomo Reporting Bundle

Statistiques de fréquentation [Matomo](https://matomo.org/) dans une application Symfony : client de l'API de
reporting appelé **côté serveur** (le jeton n'est jamais envoyé au navigateur), cache des réponses, périodes prêtes à
l'emploi, rapports typés et tableau de bord personnalisable.

```php
$comparison = $reporter->compare($periods->create('last30'));
$comparison->current->visits;          // 220
$comparison->change('visits');         // +12.5 (en %, par rapport aux 30 jours précédents)
```

## Sommaire

1. [Objectifs](#objectifs)
2. [Fonctionnalités](#fonctionnalités)
3. [Prérequis](#prérequis)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Jeton d'API Matomo](#jeton-dapi-matomo)
7. [Tableau de bord](#tableau-de-bord)
8. [Personnaliser l'affichage](#personnaliser-laffichage)
9. [Utilisation en PHP](#utilisation-en-php)
10. [Périodes](#périodes)
11. [Gestion des erreurs](#gestion-des-erreurs)
12. [Sécurité et données personnelles](#sécurité-et-données-personnelles)
13. [Tests et qualité](#tests-et-qualité)
14. [Limites connues](#limites-connues)
15. [Compatibilité](#compatibilité)

## Objectifs

Matomo mesure l'audience ; son interface est réservée aux personnes qui y ont un compte. Ce bundle présente les
chiffres utiles **dans l'application elle-même**, à ses administrateurs, sans leur ouvrir Matomo et sans exposer le
jeton d'API :

- appels à l'API de reporting depuis le serveur, en `POST`, jeton dans le corps de la requête ;
- aucune donnée inventée : un chiffre que Matomo ne fournit pas pour la période est affiché « non disponible » ;
- un Matomo indisponible n'empêche jamais la page de s'afficher (message, pas d'erreur 500) ;
- composants indépendants (client, cache, périodes, rapports, contrôleur, Twig), remplaçables un par un.

## Fonctionnalités

- **Client HTTP** (`HttpMatomoClient`) : Symfony HttpClient, délai maximal, vérification TLS, aucune redirection
  suivie, méthodes d'API validées, erreurs typées ; journal sur le canal `matomo_reporting`, sans le jeton.
- **Demandes groupées** (`requestMany()`, `API.getBulkRequest`) : tout le tableau de bord (12 rapports) en **une seule
  requête HTTP** ; une erreur sur un rapport n'empêche pas les autres.
- **Cache** (`CachedMatomoClient`) : pool PSR-6 au choix, durée configurable ; seuls les rapports absents du cache sont
  redemandés ; les échecs ne sont pas mis en cache.
- **Périodes** : aujourd'hui, hier, 7 et 30 derniers jours, mois en cours, mois précédent, année en cours, période
  personnalisée ; comparaison automatique avec la période précédente de même durée.
- **Rapports** (`AudienceReporter`) :
  - indicateurs : visites, visiteurs uniques, pages vues, pages vues uniques, actions, actions et pages par visite,
    durée moyenne, taux de rebond ;
  - évolution par jour, semaine ou mois ;
  - pages (adresses, titres, pages d'entrée, pages de sortie), avec recherche et pagination ;
  - répartitions : canaux d'acquisition, moteurs de recherche, sites référents, types d'appareils, navigateurs.
- **Tableau de bord** : route, contrôleur et gabarit neutre (HTML sans style) à remplacer par celui de l'application.
- **Twig** : `matomo_indicators()`, filtres `matomo_number` et `matomo_duration` (format français).
- **Nettoyage des libellés** (`LabelSanitizerInterface`) : masquage d'une donnée personnelle présente dans une adresse
  de page avant affichage.

## Prérequis

- PHP 8.3 ou supérieur.
- Symfony 7.4 ou 8 : `framework-bundle`, `http-client`, `cache` ; `twig-bundle` et `security-bundle` pour le tableau de
  bord.
- Matomo 5 (4 non testé) joignable depuis le serveur de l'application, et un jeton d'API d'un compte ayant le droit de lecture
  (« view ») sur le ou les sites.

## Installation

Depuis un dépôt Composer interne ou un dossier local (dépôt de type `path`) :

```json
"repositories": {
    "matomo-reporting-bundle": {
        "type": "path",
        "url": "packages/matomo-reporting-bundle",
        "options": { "symlink": true, "relative": true }
    }
}
```

```bash
composer require yassrzg/matomo-reporting-bundle:@dev
```

Enregistrer le bundle s'il ne l'a pas été automatiquement (`config/bundles.php`) :

```php
Yassrzg\MatomoReportingBundle\MatomoReportingBundle::class => ['all' => true],
```

Importer la route du tableau de bord, sous une adresse déjà réservée aux administrateurs
(`config/routes/matomo_reporting.yaml`) :

```yaml
matomo_reporting:
    resource: '@MatomoReportingBundle/config/routes.php'
    prefix: /admin/mesure-audience
```

## Configuration

`config/packages/matomo_reporting.yaml` :

```yaml
matomo_reporting:
    url: '%env(MATOMO_API_URL)%'          # https://matomo.exemple.fr/ (obligatoire)
    token: '%env(MATOMO_API_TOKEN)%'      # vide : statistiques indisponibles, aucun appel
    sites:                                # nom => idSite (au moins un)
        principal: '%env(int:MATOMO_SITE_ID)%'
    default_site: ~                       # par défaut : le premier de « sites »
    language: fr                          # langue des libellés renvoyés par Matomo
    timezone: Europe/Paris                # fuseau des périodes (celui du site dans Matomo)
    default_period: last30                # today, yesterday, last7, last30, month, previous-month, year
    cache:
        pool: cache.app                   # pool de cache PSR-6
        ttl: '%env(int:MATOMO_API_CACHE_TTL)%'  # secondes ; 0 : pas de cache
    http:
        client: http_client               # ou un client restreint (scoped_clients)
        timeout: 10                       # secondes
        verify_peer: true                 # vérification du certificat TLS
    label_sanitizer: ~                    # service implémentant LabelSanitizerInterface
    dashboard:
        enabled: true                     # false : ni contrôleur ni route utilisables
        role: ROLE_ADMIN                  # rôle exigé (null : aucun contrôle, zone déjà protégée)
        template: '@MatomoReporting/dashboard.html.twig'
        page_size: 25                     # lignes du tableau des pages (5 à 100)
        dimension_limit: 10               # lignes de chaque répartition (1 à 100)
```

`.env` (valeurs par défaut, sans secret) :

```dotenv
MATOMO_API_URL=https://matomo.exemple.fr/
MATOMO_API_TOKEN=
MATOMO_SITE_ID=1
MATOMO_API_CACHE_TTL=600
```

Le jeton est défini dans l'environnement du serveur (fichier généré au déploiement, `.env.local`, secret Symfony),
jamais dans un fichier versionné.

## Jeton d'API Matomo

1. Dans Matomo, créer un utilisateur dédié (ex. `reporting-application`) et lui donner l'accès **en lecture seule** au
   site concerné.
2. Se connecter avec ce compte : *Administration > Personnel > Sécurité > Jetons d'authentification*, créer un jeton
   (cocher « uniquement par requêtes POST » quand l'option existe).
3. Placer le jeton dans `MATOMO_API_TOKEN` sur le serveur, vider le cache de l'application.

Un jeton d'un compte « super utilisateur » fonctionne mais donne bien plus de droits que nécessaire : à éviter.

## Tableau de bord

Adresse : celle de la route `matomo_reporting_dashboard` (ex. `/admin/mesure-audience/`), en `GET` uniquement.

Paramètres d'adresse (tous facultatifs, validés ; une valeur invalide affiche la période par défaut avec un message) :

| Paramètre | Valeurs |
|---|---|
| `period` | `today`, `yesterday`, `last7`, `last30`, `month`, `previous-month`, `year`, `custom` |
| `start`, `end` | dates `AAAA-MM-JJ` de la période personnalisée (fin au plus tard aujourd'hui, 1 100 jours au plus) |
| `step` | granularité de l'évolution : `day`, `week`, `month` |
| `report` | tableau des pages : `urls`, `titles`, `entry`, `exit` |
| `q` | recherche dans les pages |
| `offset` | pagination du tableau des pages |

Tout est lu en un seul échange avec Matomo (`AudienceReporter::overview()`).

**Recherche en direct (facultatif, Turbo)** : une demande portant l'en-tête `Turbo-Frame: matomo-reporting-pages`
(`DashboardController::PAGES_FRAME`) ne lit que le rapport des pages, et le gabarit reçoit `pages_only = true` pour ne
rendre que ce cadre (le gabarit neutre le fait déjà). Placer le formulaire de recherche hors du cadre, avec
`data-turbo-frame="matomo-reporting-pages"`, et l'envoyer pendant la saisie : seule la liste des pages est rechargée.
La réponse porte `Vary: Turbo-Frame`. Sans JavaScript, la page entière est rendue.

Le contrôleur vérifie le rôle configuré (`AuthorizationCheckerInterface`) : sans composant de sécurité, l'accès est
refusé. La réponse est privée (`Cache-Control: private`) et non indexable (`X-Robots-Tag: noindex`).

Variables du gabarit : `period`, `period_error`, `presets`, `granularity`, `granularities`, `page_reports`, `report`,
`search`, `today`, `comparison`, `evolution`, `pages`, `dimensions`, `error`, `pages_only`, `pages_frame`.

## Personnaliser l'affichage

Le gabarit fourni est volontairement neutre. Deux possibilités :

- **remplacer** le gabarit (`dashboard.template`) par celui de l'application, ou le surcharger à l'emplacement
  `templates/bundles/MatomoReportingBundle/dashboard.html.twig` ;
- **étendre** le gabarit et redéfinir un ou plusieurs blocs : `filters`, `error`, `summary`, `evolution`, `pages`,
  `dimensions` (le bloc `matomo_reporting` contient l'ensemble).

```twig
{% extends 'base.html.twig' %}
{% import '@MatomoReporting/_macros.html.twig' as matomo %}

{% block body %}
    {% for item in matomo_indicators(comparison) %}
        <p>{{ item.label }} : {{ item.value ?? 'non disponible' }}</p>
    {% endfor %}
    {{ matomo.pages_table(pages, period) }}
{% endblock %}
```

Fonctions et filtres Twig :

| Nom | Rôle |
|---|---|
| `matomo_indicators(comparison)` | liste prête à afficher : `key`, `label`, `value` (formatée, null si indisponible), `change` (évolution en %, ou en points pour un taux : `unit` vaut `%` ou `pts`), `trend` (`up`, `down`, `stable`, null), `favorable` (une baisse du taux de rebond est favorable), `previous` |
| `nombre\|matomo_number(décimales = 0)` | `12 345` (espace fine insécable), `2,5` |
| `secondes\|matomo_duration` | `1 min 35 s`, `2 h 05 min` |

## Utilisation en PHP

Les services sont injectables par leur interface ou leur classe :

```php
use Yassrzg\MatomoReportingBundle\Period\PeriodFactory;
use Yassrzg\MatomoReportingBundle\Report\AudienceReporter;
use Yassrzg\MatomoReportingBundle\Report\DimensionReport;
use Yassrzg\MatomoReportingBundle\Report\PageReport;

final readonly class RapportMensuel
{
    public function __construct(private AudienceReporter $reporter, private PeriodFactory $periods)
    {
    }

    public function __invoke(): array
    {
        $period = $this->periods->create('previous-month');

        return [
            'summary' => $this->reporter->summary($period),
            'pages' => $this->reporter->pages($period, PageReport::ENTRY, limit: 10),
            'devices' => $this->reporter->dimension($period, DimensionReport::DEVICES),
        ];
    }
}
```

Tableau de bord complet en un seul échange :

```php
$overview = $this->reporter->overview($period, report: PageReport::URLS, pageLimit: 25, search: 'article');
$overview->comparison;   // indicateurs comparés à la période précédente
$overview->evolution;    // list<EvolutionPoint>
$overview->pages;        // PageList
$overview->dimensions;   // ['channels' => list<DimensionRow>|null, …] (null : refusé par Matomo)
```

Pour une méthode d'API non couverte, `MatomoClientInterface::request('Module.method', [...])` renvoie la réponse JSON
décodée, et `requestMany(['cle' => ['Module.method', [...]], …])` plusieurs réponses en un échange (le cache s'applique).

## Périodes

| Préréglage | Période envoyée à Matomo |
|---|---|
| Aujourd'hui, hier | `day` |
| Mois en cours, mois précédent (entiers) | `month` |
| 7 et 30 derniers jours, année en cours, personnalisée | `range` |

Les visiteurs uniques ne sont pas calculés par Matomo pour une période `range` (sauf réglage serveur) : l'indicateur
est alors « non disponible », jamais estimé. La période de comparaison est la période précédente de même durée.

## Gestion des erreurs

| Exception | Cas |
|---|---|
| `MatomoUnavailableException` | jeton vide, adresse invalide, Matomo injoignable, délai dépassé, réponse non JSON, erreur HTTP 5xx |
| `MatomoApiException` | Matomo répond une erreur (jeton refusé, site inconnu, méthode inexistante) |
| `InvalidPeriodException` | période ou dates invalides |
| `UnknownSiteException` | nom de site absent de la configuration |

Toutes implémentent `MatomoExceptionInterface`. Le tableau de bord les intercepte : message « statistiques
momentanément indisponibles » (code 200) et entrée dans le journal `matomo_reporting`. Une répartition refusée par le
serveur (extension Matomo désactivée) est signalée « non disponible » sans masquer le reste de la page.

## Sécurité et données personnelles

- Jeton transmis uniquement dans le corps des requêtes `POST` vers l'adresse configurée ; jamais dans une URL, un
  journal, un message d'erreur ou une page.
- Adresse de Matomo fixée par la configuration (pas de SSRF), schéma `http`/`https` vérifié, redirections refusées.
- Le bundle ne lit que des agrégats (aucun visiteur individuel, aucune adresse IP, aucun identifiant).
- Les libellés issus de Matomo (adresses et titres de pages) sont échappés par Twig ; un `LabelSanitizerInterface`
  permet en plus de masquer une donnée qu'une adresse de page pourrait contenir (paramètre de recherche, etc.) :

```php
final class MasquageEmail implements LabelSanitizerInterface
{
    public function sanitize(string $label): string
    {
        return (string) preg_replace('/[^\s@\/?&=]+@[^\s\/?&]+/', '***', $label);
    }
}
```

```yaml
matomo_reporting:
    label_sanitizer: App\Statistiques\MasquageEmail
```

## Tests et qualité

```bash
composer install
vendor/bin/phpunit                      # tests unitaires et fonctionnels (réponses Matomo enregistrées)
vendor/bin/phpstan analyse              # niveau max
vendor/bin/php-cs-fixer fix --dry-run
```

Test d'intégration contre une instance Matomo réelle (ignoré sans ces variables) :

```bash
MATOMO_REPORTING_TEST_URL=http://127.0.0.1:8080/ \
MATOMO_REPORTING_TEST_TOKEN=… \
MATOMO_REPORTING_TEST_SITE=1 \
vendor/bin/phpunit --testsuite integration
```

## Fraîcheur des chiffres

Les rapports ne sont pas en temps réel : Matomo les calcule par **archivage** (tâche planifiée `core:archive`, ou à la
demande avec une durée de validité de 15 minutes par défaut pour la journée en cours), puis le bundle les garde
`cache.ttl` secondes (600 par défaut). Les chiffres du jour peuvent donc avoir quelques dizaines de minutes de retard ;
les journées passées ne changent plus.

## Limites connues

- Un seul tableau de bord par application (une route) ; plusieurs sites sont lisibles par l'API PHP (`$site`).
- Pas de rapport en temps réel (`Live`) ni d'objectifs/e-commerce : volontairement hors du périmètre (données
  individuelles ou sans objet pour une application d'administration).
- Les libellés des répartitions sont ceux de Matomo, dans la langue `language`.

## Compatibilité

| Bundle | PHP | Symfony | Matomo |
|---|---|---|---|
| 1.0 | 8.3, 8.4 | 7.4 (testé), 8.0 (déclaré) | 5.x (testé avec 5.14) ; 4.x non testé |

Licence GPL-2.0-or-later.

## Auteur

Conçu et développé par yassine REZGUI ([github.com/yassrzg](https://github.com/yassrzg)).
