# Retour de mission — Prime Builder

## État

Branche `arena/2d5ecf33-prime-builder`. **A terminé ; B clos pour la V1 par décision du demandeur ; D, E et F acceptés.** C et G ont ensuite été réalisés sur cette branche avant réception de la suite n° 5. La version 1.0.0 reste partielle et n’est pas garantie prête pour la production.

**Suite n° 5 / F2 : en cours, non livré.** Les popups directes ont une CI verte ; le cache a été testé localement mais sa CI reste à observer. L’index des modèles et les formulaires restent à faire. C et G déjà présents doivent être préservés et soumis à leurs tests de non-régression. La suite n° 4 citée par le demandeur n’est pas présente dans ce dépôt ; sa structure de rapport exacte ne peut donc pas être vérifiée ici.

## Dernière révision observée avant F2

`ce7dc7264ae229a4377fbca10382cd3dec31712c` — [CI 46005332417](https://github.com/HamdiSouissi/prime-builder/actions/runs/46005332417), `completed/success`. Elle précède la suite n° 5. Le point d’étape popup `9752f2d4c945b597963118e005c3bd41a9cff438` a ensuite passé ses neuf jobs : [CI 46052131826](https://github.com/HamdiSouissi/prime-builder/actions/runs/46052131826). Un fichier ne peut pas contenir son propre SHA ni les résultats de sa future CI ; vérifier la tête et le run après le dernier push.

| Job | État et sortie finale observée |
|---|---|
| `lint` | `success` ; `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` ; `Found 0 of 105 files that can be fixed in 2.437 seconds, 154.45 MB memory used` |
| `phpunit (main)` | `success` ; MySQL 8 `OK (260 tests, 7574 assertions)` |
| `phpunit (1cef617660f8d4f7bbd9d3d659de85d284fb18e7)` | `success` ; MySQL 8 `OK (260 tests, 7578 assertions)` |
| `js` | `success` ; `# tests 61` ; `# pass 61` ; `# fail 0` |
| `i18n` | `success` ; `Catalogue de traduction cohérent.` |
| `smoke` | `success` ; `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` ; `120 passed (10.5m)` ; courses de notifications, désactivation/réactivation et désinstallation réussies |

GitHub avertit que des actions Node 20 sont forcées sous Node 24. La CI teste une installation ZIP neuve et le cycle de vie, pas l’installation ZIP par l’interface.

## F2 : état initial et reste demandé

| Correction | État avant F2 | Reste |
|---|---|---|
| Cache | Les modèles statiques utilisent le cache interne ; invalidations, cookies d’authentification et repli prudent pour les données dynamiques et zones enregistrées. Parcours réel local MISS/HIT puis modifications de modèle, widget global et menu, isolation connecté/visiteur. | CI du changement à observer ; mutations directes sans événement et écritures concurrentes demeurent des limites. |
| Modèles | Les données complètes des modèles sont lues ensemble et les mises en page sont revalidées au rendu. | Index autoload léger, chargement du gagnant, validation à l’enregistrement, migration et mesure avant/après. |
| Popups | Déclencheurs automatiques directs, file d’attente et option d’exclusion des visiteurs connectés : [CI 46052131826](https://github.com/HamdiSouissi/prime-builder/actions/runs/46052131826) verte. | Formulaires identifiés par modèle/nœud, protections et envois ; revérifier après modification du cache. |
| Formulaires | Pièces jointes ≤ 512 Kio en JSON/base64 et base de données ; valeurs personnalisées concaténées en texte. | Multipart, stockage privé disque, limites configurables, DOCX, avertissements PHP, migration des fichiers et champs JSON/CSV structurés. |
| Rapport | Historique précédent conservé dans [`docs/HISTORIQUE-CI.md`](docs/HISTORIQUE-CI.md). | Maintenir ce rapport à 150 lignes maximum et faire échouer le job `lint` au-delà. |

Le benchmark MySQL 8 du commit cache `62176f17776ebad131b10feedd1f471993941cfe` donne, cache désactivé et cinq mesures : référence `ce7dc72`, 48 requêtes, 19,93–21,14 ms (médiane 20,16) ; après, 51 requêtes, 20,43–21,38 ms (médiane 21,12). Ce point augmente donc les requêtes ; l’index reste à faire et la mesure finale F2 devra être relancée. La CI `46054142317` a échoué à PHPStan sur une assertion redondante ; les deux PHPUnit et le benchmark ont réussi, Playwright était encore en cours lors de la correction. `MenuRepository` n’émet pas les événements auxquels le noyau s’abonne ; l’interception HTTP ne couvre pas les appels directs au dépôt. Le stockage privé est exposé par `Application::storagePath()`.

**Tests locaux du cache en cours :** PHPUnit SQLite `261 tests, 7517 assertions`, PHPUnit ciblé CMS 1.0.1/MySQL local `10 tests, 801 assertions`, PHPStan et fixer ciblés sans erreur, Playwright Chromium `2 passed (41.2s)` sur une installation jetable (modèles puis zones). Le premier parcours a vérifié une actualisation du widget global dans une page et un en-tête via un vrai cache ; il n’est pas la CI du commit suivant.

## Fonctions existantes et limites importantes

- **Theme Builder :** six types, conditions et exclusions, priorité, recherche de contenus ; l’intégration dépend des hooks du thème. Les sauvegardes concurrentes ne sont pas atomiques.
- **Formulaires :** champs et étapes, envois, CSV neutralisé, conservation et notifications par file ; les sauvegardes et journaux externes restent à protéger. Aucune livraison SMTP externe n’a été vérifiée.
- **Popups :** dialogue natif, clavier et fréquence ; les modifications F2 ont été parcourues en Chromium avec axe et vues à 1 440/375 px ; CI `46052131826` verte. Aucun test avec lecteur d’écran réel.
- **Widgets globaux et dynamique :** références partagées bornées, balises et conditions côté serveur ; les aperçus CMS/Customizer et les zones natives utilisent un repli statique. La condition d’appareil par CSS ne protège pas les données.
- **Cache :** les caches externes et CDN ne sont pas purgés. Le noyau ne publie pas d’événement de sauvegarde des zones ; le contournement reste nécessaire. Les mutations directes sans événement et une réponse déjà en cours lors d’une purge peuvent nécessiter une purge manuelle. Les pages sensibles refusent le cache partagé.
- **Administration :** menu, capacités, diagnostic, bienvenue, kit et réglages ; CSS de l’éditeur limité à une palette et choix de police limité au thème ou à Inter locale.
- **Divers :** tous les réglages/widgets demandés ne sont pas complets ; le suivi des médias, la conversion de blocs et l’adaptation aux thèmes sont limités. La compatibilité avec les futures versions du CMS n’est pas garantie.

## Vérification et documentation

Les principaux écrans ont été ouverts à 1 440 et 375 px par Chromium et leurs captures examinées ; ce n’est ni une inspection interactive exhaustive ni un audit WCAG complet. Voir [`README.md`](README.md), [`docs/developpement.md`](docs/developpement.md), les tests sous `tests/` et le workflow [CI](.github/workflows/ci.yml) pour les commandes, prérequis et limites. Les tests modifient leurs fixtures : utiliser uniquement une instance jetable.

Le tableau de conformité détaillé, les anciens runs et les résultats historiques sont archivés dans [`docs/HISTORIQUE-CI.md`](docs/HISTORIQUE-CI.md) ; ils ne sont pas la preuve de la CI de F2.
