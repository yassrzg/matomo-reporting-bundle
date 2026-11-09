# Changements

## 1.0.0 — 2026-11-08

- Client de l'API de reporting Matomo (`HttpMatomoClient`) : `POST`, jeton dans le corps de la requête, délai,
  vérification TLS, aucune redirection, méthodes validées ; erreurs typées (`MatomoUnavailableException`,
  `MatomoApiException`) ; journal `matomo_reporting` sans le jeton.
- Demandes groupées (`MatomoClientInterface::requestMany()`, `API.getBulkRequest`) : tout le tableau de bord en une
  requête HTTP ; `AudienceReporter::overview()`.
- Cache des réponses (`CachedMatomoClient`, PSR-6) : pool et durée configurables, seuls les rapports absents redemandés,
  échecs jamais mis en cache.
- Recherche en direct : demande du cadre Turbo `matomo-reporting-pages` limitée au rapport des pages (`pages_only`).
- Périodes : aujourd'hui, hier, 7 et 30 derniers jours, mois en cours, mois précédent, année en cours, personnalisée ;
  période précédente de même durée pour les comparaisons ; fuseau horaire configurable.
- `AudienceReporter` : indicateurs et évolution par rapport à la période précédente, évolution par jour / semaine /
  mois, pages (adresses, titres, entrée, sortie) avec recherche et pagination, répartitions (canaux, moteurs de
  recherche, sites référents, appareils, navigateurs).
- Tableau de bord facultatif (route `matomo_reporting_dashboard`, `GET`) : rôle exigé, paramètres validés, Matomo
  indisponible sans erreur serveur, réponse privée et non indexable ; gabarit neutre à blocs remplaçables.
- Twig : `matomo_indicators()` (évolution en % ou en points pour un taux, tendance, évolution favorable ou non), filtres
  `matomo_number` et `matomo_duration`.
- `Comparison::difference()` : écart absolu avec la période précédente.
- `LabelSanitizerInterface` : masquage d'une donnée personnelle dans les libellés avant affichage.
- Tests unitaires, fonctionnels (réponses réelles de Matomo 5.14 enregistrées) et d'intégration (instance réelle).
