# Retour de mission — Prime Builder

## État

Branche `arena/2d5ecf33-prime-builder`. **A terminé ; B clos pour la V1 par décision du demandeur ; D, E et F acceptés.** C et G ont ensuite été réalisés sur cette branche avant réception de la suite n° 5. La version 1.0.2 en cours reste partielle et n’est pas garantie prête pour la production.

**Suite n° 5 / F2 : en cours, non livré.** Le cache et l’index ont une CI verte ; les formulaires de modèles et popups sont implémentés localement, mais leur CI et les fichiers restent à faire. C et G déjà présents doivent être préservés et soumis à leurs nouvelles vérifications après F2. La suite n° 4 citée par le demandeur n’est pas présente dans ce dépôt ; sa structure de rapport exacte ne peut donc pas être vérifiée ici.

## Dernière révision observée avant F2

`ce7dc7264ae229a4377fbca10382cd3dec31712c` — [CI 46005332417](https://github.com/HamdiSouissi/prime-builder/actions/runs/46005332417), `completed/success`. Elle précède la suite n° 5. Les popups `9752f2d4c945b597963118e005c3bd41a9cff438` ont passé [CI 46052131826](https://github.com/HamdiSouissi/prime-builder/actions/runs/46052131826), le cache corrigé `af99be4a451e1fee460132926e984bbb80c6aa2e` a passé ses neuf jobs : [CI 46054562556](https://github.com/HamdiSouissi/prime-builder/actions/runs/46054562556), et l’index `80fdd6810662461f788e2cdb278b5d52b1f72c56` : [CI 46057681077](https://github.com/HamdiSouissi/prime-builder/actions/runs/46057681077), neuf jobs verts. Un fichier ne peut pas contenir son propre SHA ni les résultats de sa future CI ; vérifier la tête et le run après le dernier push.

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

## F2 : état et reste demandé

| Correction | État observé | Reste |
|---|---|---|
| Cache | Modèles statiques cacheables ; invalidations et repli prudent pour les données dynamiques et zones. CI `46054562556` verte, parcours réel local MISS/HIT puis modifications de modèle, widget global et menu, isolation connecté/visiteur. | Mutations directes sans événement et rendus concurrents demeurent des limites. |
| Modèles | Index autoload signé, lecture sélective, validation à l’enregistrement et migration différée implémentés et testés localement. Sources anciennes conservées ; blocs Prime et données invérifiables revalidés. | Tester clé absente/rotée, reconstruction échouée, multi-instance et écritures externes ; le contrôle `source_hash` reste non atomique. |
| Popups | Déclencheurs automatiques directs, file d’attente et option d’exclusion des visiteurs connectés : [CI 46052131826](https://github.com/HamdiSouissi/prime-builder/actions/runs/46052131826) verte. | Formulaires de modèle/nœud implémentés localement et parcourus en navigateur ; CI à observer après ce jalon. |
| Formulaires | Identité modèle/nœud, preuve et revérification de visibilité ; les fichiers restent ≤ 512 Kio en JSON/base64 et base de données, et les valeurs personnalisées sont concaténées en texte. | Multipart, stockage privé disque, limites configurables, DOCX, avertissements PHP, migration des fichiers et champs JSON/CSV structurés. |
| Rapport | Historique précédent conservé dans [`docs/HISTORIQUE-CI.md`](docs/HISTORIQUE-CI.md). | Maintenir ce rapport à 150 lignes maximum et faire échouer le job `lint` au-delà. |

Le benchmark MySQL 8 du commit cache `62176f17776ebad131b10feedd1f471993941cfe` donne, cache désactivé et cinq mesures : référence `ce7dc72`, 48 requêtes, 19,93–21,14 ms (médiane 20,16) ; après, 51 requêtes, 20,43–21,38 ms (médiane 21,12). Ce point augmente donc les requêtes ; la mesure finale F2 devra être relancée. La CI `46054142317` a échoué à PHPStan sur une assertion redondante ; les deux PHPUnit et le benchmark ont réussi, Playwright était encore en cours lors de la correction. `MenuRepository` n’émet pas les événements auxquels le noyau s’abonne ; l’interception HTTP ne couvre pas les appels directs au dépôt. Le stockage privé est exposé par `Application::storagePath()`.

**Tests locaux de l’index :** PHPUnit SQLite `263 tests, 7562 assertions` ; PHPUnit ciblé MySQL 5.7 sur CMS 1.0.1 et 1.0.2, `13 tests, 623 assertions` chacun ; PHPStan niveau 8 et fixer ciblé sans erreur ; Chromium `2 passed (45.4s)` sur une installation jetable, modèles puis zones. Deux processus MySQL 5.7 concurrents ont créé 25 modèles chacun : les 50 ID sont présents dans l’index, sans dépassement de la limite. Le parcours navigateur a vérifié l’actualisation du widget global dans une page et un en-tête via un vrai cache. La CI de l’index est verte.

**Mesure locale de l’index :** même installation jetable MySQL 5.7, trois modèles, cache désactivé, cinq requêtes HTTP simulées par série : `ce7dc72`, 48 SQL, 26,63–68,19 ms (médiane 33,15) ; arbre de travail indexé, 48 SQL, 27,76–68,80 ms (médiane 33,38), HTTP 200, 4 303 octets. Ces temps chargés et variables ne démontrent pas un gain ; le test ciblé vérifie que les modèles non gagnants ne sont pas lus. En CI MySQL 8, trois modèles : 48 SQL et 4 272 octets avant/après ; médianes 22,01 ms avant et 23,42 ms après. Aucun gain général n’est démontré.

**Formulaires de modèles, travail local :** SQLite complet 265 tests/7 639 assertions ; ciblé MySQL 5.7 sur CMS 1.0.1 et 1.0.2, 7 tests/234 assertions chacun ; Chromium sur chaque CMS, 1 parcours réussi (en-tête, popup caché puis ouvert, visiteur et connecté, entrée conditionnelle). Les tests serveur couvrent aussi archive, 404 et page de plugin ; la preuve de cette dernière atteste un rendu antérieur sans confirmer que sa route existe encore. La mise à niveau réelle 1.0.1 → 1.0.2 et la CI de ce travail restent à effectuer.

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
