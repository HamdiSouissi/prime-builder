# Retour de mission — Prime Builder 0.1.0

## État de la reprise

La livraison reste **partielle et non prête pour la production**. Le fichier `docs/specifications/PRIME-BUILDER-suite-1.md` est maintenant présent et suivi dans Git. Le socle `b413a3f5202111988f1a4b45e288024057e6c0ca` a été accepté par le demandeur ; son [run 44924173858](https://github.com/HamdiSouissi/prime-builder/actions/runs/44924173858) est vert avec les trois jobs de la CI d’alors. Le compte rendu d’acceptation ultérieur de A est conservé ci-dessous comme historique, pas comme preuve que tous les critères détaillés supplémentaires sont remplis.

Le dernier état intégralement vert observé est `086f7f919af940b61d13cddca919ade6f7103746`, [run 44957139010](https://github.com/HamdiSouissi/prime-builder/actions/runs/44957139010). Cela ne signifie pas que le jalon A est complet : les contrôles 403/succès des fonctionnalités encore absentes restent à réaliser. Les changements HTML décrits ci-dessous ne sont pas couverts par ce run. Le catalogue B a été **entamé prématurément** dans `22a3824` ; ces ajouts ont ensuite été retirés dans `0c5a6c9`, afin de respecter l’ordre demandé. Leur historique est conservé, mais ils ne font plus partie du code livré ; le run du retrait est vert.

Le demandeur a depuis confirmé le minimum `>=1.0.1`, avec correction vérifiée de la révocation des droits, et autorisé par exception l’implémentation des véritables fonctions HTML, Theme Builder et formulaires nécessaires aux tests de capacités avant clôture de A. Il a également choisi, lorsque les frontières sont irrécupérables, de conserver le HTML enregistré en un seul élément en lecture seule, sans déplacement ni suppression séparés des blocs. Le repli groupé et ses tests ont été ajoutés dans `dc440e6ca95e0431fb772e8289417da3f5f3e63f` : son [run 44955955760](https://github.com/HamdiSouissi/prime-builder/actions/runs/44955955760) est **rouge**, PHPUnit ne trouvant pas `Converter` dans un test isolé. Les six autres jobs sont verts, dont Playwright `30 passed (1.5m)` ; les chargements manquants ont été corrigés dans `2964f7fdf0ccd13613e2ab37f6ddfd6ee5ba87a9`. Son [run 44956214548](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956214548) est également **rouge** : PHPUnit passe, mais le job lint échoue lors de l’installation du CMS avec le code 128, avant les analyses ; le détail du téléchargement des logs renvoie `EOF`. La relance des seuls échecs a été refusée par GitHub. Une nouvelle exécution, [44956474944](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956474944) sur `8f2957e1bd17ab74f95f401f660415c3e46f0eb4`, est verte sur sept jobs, dont PHPUnit `OK (43 tests, 618 assertions)` et Playwright `30 passed (1.4m)`.

L’adaptation de sécurité pour 1.0.1 est dans `3d17a7e826953b9a9eaadc39c16bfd2786c6de5f` : [run 44956820076](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956820076), `completed` / `success`, avec une matrice PHPUnit/MySQL sur `main` et sur le CMS 1.0.1 figé à `1cef617660f8d4f7bbd9d3d659de85d284fb18e7` (`OK (44 tests, 644 assertions)` pour chacun), et Playwright `30 passed (1.6m)` sur `main`. Un harnais local distinct PHP-WASM/SQLite affichait aussi `HARNESS PASSED: 44 tests (not PHPUnit)` sur 1.0.1 et 1.0.2. La protection 1.0.1 porte sur les routes API HTTP quand le plugin est actif, pas sur le service du noyau lui-même : désactivation, mode sans échec ou remplacement de l’alias par une autre extension la font disparaître. Voir §7.

## Suite n° 2 — jalon A accepté (historique)

Le demandeur a accepté le jalon A au commit `7f5c26b62d01604d51d4420ea756504c77eb4e72`. Sur cette révision, le [run 44943357270](https://github.com/HamdiSouissi/prime-builder/actions/runs/44943357270) est `completed` / `success` : les sept jobs `lint`, `cs`, `phpunit`, `js`, `i18n`, `smoke` et `e2e` sont verts. Synthèses relevées dans ce run :

- PHPStan : `[OK] No errors` ; PHP-CS-Fixer : `Found 0 of 30 files that can be fixed in 0.576 seconds, 78.00 MB memory used`.
- PHPUnit : `OK (38 tests, 466 assertions)` ; Node : `# tests 14`, `# pass 14`, `# fail 0` ; Playwright : `27 passed (1.5m)`.
- Traductions : `Catalogue de traduction cohérent.` ; smoke : `Smoke: all routes, public content and document CSS passed.`

Le demandeur rapporte également une vérification locale distincte sur MySQL (`OK (38 tests, 445 assertions)`) et la conversion réussie de la page d’accueil de démonstration. Ces résultats sont ceux du relecteur, pas une nouvelle exécution de notre environnement.

La suite demandée enchaîne B (catalogue), C (widgets globaux, dynamique et conditions), D (Theme Builder), E (formulaires), F (popups), puis G (finitions). Au moment de la rédaction historique de cette section, `PRIME-BUILDER-suite-1.md` manquait ; il est désormais suivi dans le dépôt et ses critères détaillés ont été confrontés à l’implémentation. Le cahier des charges complet et les précisions ultérieures restent applicables.

> **Livraison partielle, non prête pour la production.** Le socle existe et les écrans livrés ont été ouverts, mais l’éditeur ne couvre pas tout le §3.3 et le catalogue, le Theme Builder, les formulaires, les popups et les fonctions dynamiques demandés ne sont pas complets. Les modèles de contact ne sont pas des formulaires. Les résultats ci-dessous sont ceux réellement observés, pas une certification de sécurité ou d’accessibilité.

## 1. Dépôt, branche et révision vérifiée

- Dépôt : <https://github.com/HamdiSouissi/prime-builder>, branche `arena/2d5ecf33-prime-builder`, issue de `95c54eb9021c990373685e06f3a5e7994b775e48`.
- Dernière révision observée verte lors de cette rédaction : `086f7f919af940b61d13cddca919ade6f7103746`, [run 44957139010](https://github.com/HamdiSouissi/prime-builder/actions/runs/44957139010). Un fichier ne peut pas contenir son propre SHA Git ni le résultat futur de sa propre CI. Pour la tête effective, utiliser `git ls-remote origin refs/heads/arena/2d5ecf33-prime-builder`, `git log origin/arena/2d5ecf33-prime-builder -1` et le run associé à ce SHA.
- Pour le jalon A, Prime CMS local était au checkout `1cef617660f8d4f7bbd9d3d659de85d284fb18e7` (1.0.1). Pour la suite n° 2, le checkout local est `93fd804001897597e43145e58eeb50388e75e3be` (1.0.2). Son code n’a pas été modifié ; une copie du plugin a été placée sous `content/plugins/prime-builder/`. La CI clone `main` du CMS : cette référence flottante peut changer.
- Le manifeste de la révision en cours déclare Prime CMS `>=1.0.1`. Sur 1.0.1, un adaptateur du plugin intersecte les capacités de jeton et du compte dans la pile HTTP, uniquement tant qu’il est actif et que son alias n’est pas remplacé ; la matrice PHPUnit/MySQL 1.0.1 est verte. Le noyau 1.0.1 non modifié reste vulnérable hors de cette pile et après désactivation du plugin. Prime CMS 1.0.2 corrige ce point dans le noyau et reste recommandé.

## 2. CI et jalons

### Dernière CI verte observée

Révision `086f7f919af940b61d13cddca919ade6f7103746` : [run 44957139010](https://github.com/HamdiSouissi/prime-builder/actions/runs/44957139010), `completed` / `success`, huit jobs, dont deux versions PHPUnit/MySQL indépendantes. Lignes relevées dans les annotations :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 34 files that can be fixed in 0.769 seconds, 78.00 MB memory used` |
| `phpunit (main)` | `success` | `OK (44 tests, 644 assertions)` |
| `phpunit (1cef617660f8d4f7bbd9d3d659de85d284fb18e7)` | `success` | `OK (44 tests, 644 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `30 passed (1.4m)` |

### CI historique du repli groupé

Révision `8f2957e1bd17ab74f95f401f660415c3e46f0eb4` : [run 44956474944](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956474944), `completed` / `success`, sept jobs. Les modifications ultérieures de compatibilité 1.0.1 ne sont pas comprises dans ce run.

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 33 files that can be fixed in 0.773 seconds, 78.00 MB memory used` |
| `phpunit` | `success` | `OK (43 tests, 618 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `30 passed (1.4m)` |

### CI historique après retrait du catalogue

Révision `0c5a6c9454d28d27ad62b8cc33aa828dbdcfa352` : [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921), `completed` / `success`, sept jobs indépendants. Lignes de synthèse relevées dans les annotations :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 33 files that can be fixed in 0.980 seconds, 78.00 MB memory used` |
| `phpunit` | `success` | `OK (42 tests, 575 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `29 passed (1.3m)` |

Avant le retrait des ajouts B, `a4ab07f296d458a98365f0913cf2ce960dfe492e` / [run 44952442772](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952442772) avait aussi sept jobs verts : PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 33 files that can be fixed in 0.895 seconds, 78.00 MB memory used`, PHPUnit `OK (50 tests, 629 assertions)`, Node `# tests 14` / `# pass 14` / `# fail 0`, Playwright `30 passed (1.7m)`, i18n `Catalogue de traduction cohérent.`, smoke `Smoke: all routes, public content and document CSS passed.` Ces nombres incluent les tests des widgets B ensuite retirés.

Le [run 44952189464](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952189464) de `a863f14b9ed1605f6d494b24cd149286467d9f52` est **rouge** : PHPStan refusait le type `list<string|null>` de `completeFragments` après mise à jour d’un index. Les six autres jobs avaient réussi, dont PHPUnit `OK (50 tests, 629 assertions)` et Playwright `30 passed (1.4m)` ; le type a été corrigé dans `a4ab07f`. Le [run 44951184750](https://github.com/HamdiSouissi/prime-builder/actions/runs/44951184750) de `7b632d135695fb394aa16c9100be8cb3c9a0c73d` avait lui aussi échoué en PHPStan sur un accès nullsafe inutile, corrigé depuis. Aucun de ces deux runs n’est présenté comme vert.

### CI historique après ajout prématuré du catalogue

Révision `22a382414e5ce25b80b1fa87ad5ae2e551eb07ea` : [run 44951581745](https://github.com/HamdiSouissi/prime-builder/actions/runs/44951581745), `completed` / `success`, sept jobs. Lignes relevées :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 32 files that can be fixed in 0.421 seconds, 78.00 MB memory used` |
| `phpunit` | `success` | `OK (48 tests, 575 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `29 passed (1.2m)` |

### Widget HTML filtré — vérifications en cours

Les modifications introduisent un widget distinct assaini avec `HtmlSanitizer::sanitizeBasic`, même pour un compte possédant `prime_builder.unfiltered_html` ; aucun script, style, iframe, attribut événementiel ou URL dangereuse n’est autorisé par ce profil. La capacité protège ce widget, pas l’emploi des mêmes balises filtrées dans le texte riche. La validation du CMS n’ayant ni acteur ni entrée, le widget est signé pour son entrée et son identifiant : l’API Builder exige le droit pour ajouter ou modifier, tandis qu’un éditeur sans ce droit peut conserver, déplacer ou supprimer un widget existant valide. Les signatures sont retirées des modèles partagés. Une clé `APP_KEY` valide est requise ; sa rotation invalide les signatures.

Les tests couvrent le refus 403 et le succès de l’API Builder, le retrait du droit après émission d’un jeton, un jeton volontairement restreint, le rejeu d’une version ancienne et le rejeu entre entrées, les modèles, la duplication, l’autosauvegarde et la restauration. Le noyau valide avant le filtre et ne permet pas à un filtre de rejeter une écriture par une exception : pour un rejeu non autorisé passé au service ou à l’API du noyau, le filtre conserve le contenu précédent mais le noyau peut répondre 200 et modifier d’autres champs. En création/duplication par le noyau, l’identifiant cible n’existe pas encore : le widget signé est converti en texte riche filtré, pas conservé comme widget HTML. L’autosauvegarde du noyau échappe au filtre, mais la restauration appelle `update` et passe par le filtre.

Observations locales sur ce travail : deux harnais PHP-WASM/SQLite affichent chacun `HARNESS PASSED: 47 tests (not PHPUnit)` sur le CMS 1.0.1 figé et le checkout 1.0.2 ; l’échec initial de rejeu entre entrées a été corrigé par vérification de la signature liée à la destination. PHPStan 2.3.5 sous PHP-WASM sur sept fichiers modifiés affiche `[OK] No errors` ; Node 14/14 et `git diff --check` passent. Ces harnais ne sont pas PHPUnit/MySQL. Le nouveau scénario Playwright et la CI complète de ces changements n’ont pas encore été observés ; aucune vérification navigateur manuelle supplémentaire n’est revendiquée.

### Suite 1 — jalon A : corrections, non terminé

L’état `0c5a6c9` et son run vert ci-dessus incluent les modèles partagés ainsi que la récupération d’un bloc indisponible entre des voisins dont les rendus correspondent. Les tests `IT::testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `IT::testEditorAndTemplateAccessHaveSeparateCapabilities` et E2E `context menu saves a reusable template with keyboard, reload and deletion` couvrent les opérations effectivement implémentées. La bibliothèque est lisible et insérable avec `prime_builder.edit` ; création, import et suppression exigent `prime_builder.templates`. Les réglages ont leur capacité et leurs tests séparés.

Restent : réaliser les tests 403/succès de `prime_builder.theme_builder` et `prime_builder.forms` sur de vraies fonctions. Une première implémentation du widget HTML filtré et de ses tests 403/succès est en cours de vérification ; elle ne permet pas à elle seule de clôturer A. Le demandeur a autorisé ces réalisations avant la clôture de A ; aucun faux point d’accès n’a été créé. Un utilisateur autorisé à éditer un document peut toujours composer un JSON équivalent à un modèle : la capacité contrôle les opérations de bibliothèque, pas toutes les compositions possibles. Le relecteur a déjà vérifié une conversion sur le site de démonstration (résultat attribué ci-dessus) ; les nouveaux modèles n’ont pas été inspectés manuellement depuis. Les parcours navigateur de la CI sont automatisés, pas une vérification manuelle supplémentaire.

| Correction demandée | Statut | Preuve ou limite |
|---|---|---|
| Conversion automatique sans perte du HTML | **Fait et testé selon le repli choisi** | [Run 44956474944](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956474944) : PHPUnit et E2E couvrent le groupe en lecture seule, sauvegarde, réouverture, sortie publique et falsification. Les limites de taille et de clé bloquent toujours la conversion ; les réglages originaux ne sont pas conservés et le dynamique est figé. L’hypothèse sur la récupération individuelle est au §9. |
| Aucun `confirm()`, `alert()` ni `prompt()` ; dialogue accessible | **Fait et testé** | E2E `departure dialog traps and restores focus and closes on Escape`, parcours d’import et de modèles ; le navigateur peut encore afficher son avertissement natif `beforeunload`. |
| Couleurs référencées au kit, dimensions avec unité | **Fait et testé** | E2E `color picker uses kit references and spacing has numeric units`, `DT::testKitAndCustomBreakpointsAreSafe`. Le kit complet du §3.3 reste partiel. |
| Noms lisibles dans « Déplacer vers » | **Fait et testé** | E2E `existing three-block page is converted without losing its saved HTML` et `navigator renames, folds and hides elements with persisted accessible controls`. |
| `edit`, `templates`, `settings` : refus/succès effectifs | **Fait et testé** | IT `testEditorAndTemplateAccessHaveSeparateCapabilities`, `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi`. |
| `theme_builder`, `forms` : refus/succès effectifs | **Non fait** | Capacités enregistrées mais fonctions correspondantes absentes ; aucun test 403/succès réel possible pour l’instant. |
| `unfiltered_html` : refus/succès effectifs | **Non fait — en cours de vérification** | Widget HTML filtré et tests de capacité, scellement, révocation, API du noyau, copie et révision ajoutés ; harnais locaux verts mais pas encore de CI PHPUnit/MySQL ou navigateur observée pour cette révision. |
| Indicateur court « Non enregistré » | **Fait et testé** | E2E `departure dialog traps and restores focus and closes on Escape` et conversion de page existante. |
| Menu contextuel et parcours sans souris | **Fait et testé** | E2E `context menu copies elements and styles with keyboard and restores focus`, `context menu saves a reusable template with keyboard, reload and deletion`, `build and publish four widgets using keyboard controls`. Les scénarios de menu combinent souris et clavier ; le parcours de construction est au clavier après connexion. |
| Minimum Prime `>=1.0.1` et CI sur `main` | **Fait et testé, avec réserve** | Le manifeste déclare `>=1.0.1` ; [run 44956820076](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956820076) : PHPUnit/MySQL vert sur 1.0.1 et `main`. L’adaptation protège la pile HTTP API sous 1.0.1 uniquement lorsqu’elle est active ; limites et risque de désactivation au §7. |

### Suite 1 — jalon B : catalogue, non terminé

Sept versions simples avaient été ajoutées prématurément dans `22a3824` / [run 44951581745](https://github.com/HamdiSouissi/prime-builder/actions/runs/44951581745), puis retirées dans `0c5a6c9` avant clôture de A. Les tests verts de cette révision historique ne décrivent donc plus le catalogue actuel. B reste non terminé ; la liste exacte des widgets manquants et des versions partielles figure au tableau §5. Aucun SHA/run de jalon complet ni vérification manuelle de ces widgets ne peut être donné.

### Suite 1 — jalon C : non terminé

Pas de widgets globaux synchronisés, balises dynamiques générales ni conditions d’affichage. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; le SHA `0c5a6c9` et le [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921) ne testent que le socle partiel. Leurs lignes finales sont au tableau ci-dessus.

### Suite 1 — jalon D : non terminé

Pas de Theme Builder, conditions/exclusions/priorités ni branchement `site.partial` / templates. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; référence du code partiel `0c5a6c9`, [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921), lignes au tableau ci-dessus.

### Suite 1 — jalon E : non terminé

Pas de constructeur de formulaires, protections d’envoi, fichiers privés, file d’e-mails, écran Envois ou purge. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; référence du code partiel `0c5a6c9`, [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921), lignes au tableau ci-dessus.

### Suite 1 — jalon F : non terminé

Pas de popups, déclencheurs, fréquence ni dialogue public dédié. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; référence du code partiel `0c5a6c9`, [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921), lignes au tableau ci-dessus.

### Suite 1 — jalon G : non terminé

Administration et kit incomplets, documentation finale et version 1.0.0 non livrées. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; référence du code partiel `0c5a6c9`, [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921), lignes au tableau ci-dessus.

### État historique des jobs de la révision 79d21ca

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469` : [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139), état GitHub `completed`, conclusion `success`. Les sept jobs sont indépendants. Lignes de synthèse relevées dans les annotations de ce run :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 30 files that can be fixed in 0.628 seconds, 78.00 MB memory used` |
| `phpunit` | `success` | `OK (38 tests, 466 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `27 passed (1.1m)` |

L’artefact `prime-builder-e2e-results` (ID `14722213732`) a été publié ; son téléchargement local a échoué sur l’hébergement des artefacts, il n’a donc pas été inspecté ici. Les jobs signalent aussi la dépréciation Node.js 20 de certaines actions. Les commandes et les limites de ces tests figurent au §3.

### Jalon 0 — environnement et CI

**Sorties techniques observées a posteriori**, sans revendiquer le respect de l’ordre imposé. La référence de ce bilan historique est `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : les sept conclusions et les lignes finales sont celles du tableau ci-dessus. PHPUnit y utilise effectivement MySQL 8, et `smoke` installe le CMS, active le plugin et contrôle les routes livrées, la page publique et son CSS.

**Écart de méthode important** : le travail sur le socle, la bibliothèque et l’éditeur avait déjà commencé avant que les sept jobs indépendants existent et avant que PHPUnit utilise effectivement MySQL. Le [run 44928027104](https://github.com/HamdiSouissi/prime-builder/actions/runs/44928027104), `65aa75dab75ca11dbf4e281d4db828ef3983228e`, avait sept jobs verts mais la fixture PHPUnit utilisait encore SQLite malgré une vérification de connexion MySQL. Après correction de la fixture, le [run 44940270714](https://github.com/HamdiSouissi/prime-builder/actions/runs/44940270714), `5af6ab85859e99142cb6d103035bfa10dbef1173`, a exécuté les sept jobs avec succès et PHPUnit sur MySQL. Il est donc faux d’affirmer que les jalons ont été franchis dans l’ordre demandé. Jalon 0 est le dernier dont les sorties techniques sont observées comme réunies ; aucun passage de jalon dans l’ordre prescrit n’est revendiqué.

En navigateur local, les écrans de tableau de bord, réglages, édition du noyau, éditeur et page publique ont été ouverts par Chromium avec une fixture SQLite ; ce n’est pas une installation locale complète sur MySQL. Leurs captures à 1440 et 375 px ont été examinées visuellement, mais elles précèdent les derniers changements de rendu partiel et de glisser-déposer. Les captures, requêtes et audits automatisés ne remplacent pas une vérification manuelle exhaustive.

Deux échecs intermédiaires ont été corrigés : le [run 44939542274](https://github.com/HamdiSouissi/prime-builder/actions/runs/44939542274) signalait un contraste insuffisant sur le lien des révisions, puis le [run 44939955829](https://github.com/HamdiSouissi/prime-builder/actions/runs/44939955829) échouait dans PHPUnit à cause des identifiants MySQL réinitialisés par le bootstrap du CMS. Le run `44940270714` ci-dessus est postérieur aux deux corrections.

### Jalon 1 — socle fonctionnel

**Socle présent et exercé, clôture formelle non revendiquée.** Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même état des sept jobs et mêmes sorties que le tableau ci-dessus. Les tests couvrent notamment validation, rendu et CSS, publication, repli après désactivation, droits, brouillons d’autrui et CSRF ; les tests navigateur ont ouvert les écrans livrés et publié des pages. `tests/smoke.sh` vérifie les routes livrées en HTTP. Cela ne prouve pas exhaustivement toutes les actions et combinaisons de droits, ni le passage séquentiel requis. Le repli après désactivation et la désinstallation sont aussi exercés en CI sur un CMS jetable installé depuis le ZIP, par la CLI.

### Jalon 2 — éditeur réel : **non terminé**

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même tableau historique de jobs et lignes de synthèse du §2. La révision et ce run historiques exercent une partie de ce jalon mais ne le ferment pas. L’iframe utilise le rendu du thème et du serveur : pour une modification isolée sans changement de structure, le serveur valide tout le document puis renvoie le sous-arbre modifié ; les changements structurels, groupés ou les désynchronisations entraînent un rendu complet. Le glisser-déposer dans le canevas et Structure permet maintenant de placer avant/après un frère ou dans un conteneur ; des commandes clavier permettent aussi le changement de parent et l’ordre. Les parcours E2E « build and publish four widgets with the mouse » et « build and publish four widgets using keyboard controls » publient chacun quatre éléments et vérifient la page et axe. Les paramètres avancés, le kit complet et une démonstration exhaustive de l’accessibilité et des interactions restent manquants. Voir le tableau ci-dessous.

### Jalon 3 — tous les widgets : **non terminé**

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même tableau historique de jobs et lignes de synthèse du §2. Des éléments ont déjà été développés et testés avant la clôture du jalon 2 ; ce n’est pas une validation du jalon 3. À cette révision historique, il y avait 23 widgets visibles et un widget interne de conservation ; le catalogue reste incomplet. `DocumentTest::testAllBuiltInWidgetsRenderWithoutThrowing` vérifie les enveloppes et l’absence d’erreur observable pour les valeurs par défaut, **pas** un test fonctionnel complet séparé pour chaque widget demandé. Voir le tableau widget par widget.

### Jalon 4 — Theme Builder, formulaires, popups et dynamique : **non terminé**

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même tableau historique de jobs et lignes de synthèse du §2. Pas de Theme Builder, soumissions, popups, balises dynamiques générales ni conditions d’affichage. Les seuls articles récents et champs du contenu courant sont des widgets limités ; ils ne constituent pas ces systèmes. Aucun test de formulaire, popup ou en-tête global ne peut donc être revendiqué.

### Jalon 5 — bibliothèque, administration et documentation : **non terminé**

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même tableau historique de jobs et lignes de synthèse du §2. Neuf compositions et leur import/export existent, ainsi qu’une page de réglages et une documentation partielle, mais, à cette révision historique, l’enregistrement de modèles utilisateurs et les écrans Modèles/Envois manquaient. Des modèles partagés ont été ajoutés depuis ; les envois restent absents. README, mesures, audit et documentation ne couvrent pas toutes les exigences finales. Les fonctions partielles précédentes ne ferment pas ce jalon.

## 3. Tests observés et commandes

Pour le [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) sur `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, les lignes finales et résultats de chaque outil sont reproduits au tableau du §2 : PHPUnit `OK (38 tests, 466 assertions)` sur MySQL 8, Node `# tests 14` / `# pass 14` / `# fail 0`, Playwright `27 passed (1.1m)`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 30 files that can be fixed in 0.628 seconds, 78.00 MB memory used`, traduction `Catalogue de traduction cohérent.` et smoke `Smoke: all routes, public content and document CSS passed.` Les scénarios Playwright couvrent notamment le rendu partiel, les déplacements relatifs et leur persistance après rechargement, ainsi que les commandes clavier et le focus. Les scans axe ne concernent que les écrans et états exercés.

Depuis un checkout **de test isolé** du CMS avec le plugin copié dans `content/plugins/prime-builder`, PHP 8.2+, Composer et les extensions du CMS :

```bash
# Depuis le CMS : dépendances et tests PHP. Les variables PRIME_TEST_DB_* doivent viser un MySQL de test.
composer install --no-interaction --prefer-dist --no-progress
PB_TEST_MYSQL=1 vendor/bin/phpunit --colors=never --bootstrap tests/bootstrap.php content/plugins/prime-builder/tests
vendor/bin/phpstan analyse --configuration content/plugins/prime-builder/phpstan.neon --no-progress --no-ansi
vendor/bin/php-cs-fixer fix content/plugins/prime-builder --config .php-cs-fixer.dist.php --dry-run --diff --no-ansi
php bin/prime lang:check --strict

# Depuis le dépôt du plugin :
npm test
php tools/benchmark.php /chemin/vers/prime-cms
cd tests/e2e
npm ci --no-audit --no-fund
npx playwright install --with-deps chromium
# Fournir E2E_BASE_URL, E2E_ENTRY_ID, E2E_LEGACY_ENTRY_ID, E2E_MISSING_ENTRY_ID,
# E2E_MOUSE_ENTRY_ID, E2E_KEYBOARD_ENTRY_ID et E2E_DRAG_ENTRY_ID pour des contenus
# de test, ainsi que E2E_ADMIN_EMAIL et E2E_ADMIN_PASSWORD si différents de la
# fixture CI. Les slugs des deux premières pages de parcours sont
# parcours-souris et parcours-clavier. La page bloc-indisponible est préparée
# avec tests/e2e/mark-block-missing.php comme dans le workflow CI.
npm test
```

`PB_TEST_MYSQL=1` crée et détruit des bases aléatoires `prime_builder_test_*` ; le compte MySQL de test doit pouvoir créer et supprimer des bases. La fixture initialise d’abord les données SQLite du CMS, puis recopie tables et données dans MySQL avant les appels testés : ce schéma de fixture ne remplace pas une vérification exhaustive des migrations de production. Sans cette variable, la fixture utilise SQLite. Le CMS du job E2E est, lui, installé sur MySQL via sa CLI. Les tests modifient et publient des contenus : **ne jamais les lancer sur un site ou une base de production**. Le job `smoke` se relance après installation et création d’une page de test avec `SMOKE_BASE_URL=… SMOKE_ENTRY_ID=… SMOKE_ADMIN_EMAIL=… SMOKE_ADMIN_PASSWORD=… bash tests/smoke.sh` ; les commandes exactes de création des fixtures sont dans `.github/workflows/ci.yml`.

En local, PHP natif/MySQL n’étaient pas disponibles : PHP-WASM et une fixture SQLite ont servi aux vérifications rapides. Après le retrait des ajouts B, le harnais local a donné `HARNESS PASSED: 5 tests (not PHPUnit)` pour la conversion et les modèles ; ce n’est ni PHPUnit ni MySQL. Le harnais local (`harness: 38 passed, 0 failed`) n’est **pas PHPUnit**. Le CMS a été ouvert avec cette fixture par Chromium ; les mesures et essais locaux ne remplacent pas MySQL/CI. Le serveur PHP-WASM a parfois émis des erreurs `accept/poll` et a été redémarré ; certaines reprises locales ont échoué sur une connexion refusée, avant les vérifications réussies indiquées.

## 4. Tableau de conformité des sections 3 à 7

Statuts demandés : **Fait et testé**, **Fait sans test automatique**, **Non fait**. Une ligne « Non fait — partiel » signifie qu’une partie existe et est nommée, mais que la puce entière n’est pas remplie. Les tests cités sont dans `tests/DocumentTest.php` (DT), `tests/IntegrationTest.php` (IT), `tests/CatalogTest.php` (CT), `tests/js/model.test.js` (JS) ou `tests/e2e/editor.spec.mjs` (E2E). Les chemins principaux sont indiqués dans chaque ligne.

| Point de la spécification | Statut et preuve ou reste | Fichiers principaux |
|---|---|---|
| 3.1 Produit complet et ergonomie | **Non fait** — partiel : interface originale et socle ; pas l’ensemble des fonctions demandées. | `views/admin/`, `assets/`, `src/` |
| 3.2.1 Activation par type | **Fait et testé** : pages par défaut, autres types réglables ; `IT::testActivationRegistersEditorBlockAndPageFilter`, `IT::testSettingsBundleIsAtomicAndReadsOlderIndividualKeys`. | `src/Plugin.php`, `src/Controller/Settings.php` |
| 3.2.2 Écran du noyau, aperçu et textarea | **Fait et testé** : `IT::testActivationRegistersEditorBlockAndPageFilter`, E2E `the core content screen embeds the alternative editor on desktop and mobile`. | `views/admin/alternate.php`, `assets/css/alternate.css` |
| 3.2.3 Éditeur plein écran et iframe du thème | **Fait et testé** : E2E `compose and publish a page using the CMS theme`, IT `testEditorAndTemplateAccessHaveSeparateCapabilities`. L’adaptation à tous les thèmes reste à vérifier. | `src/Controller/Documents.php`, `views/admin/builder.php` |
| 3.2.4 Document Prime, validation et repli | **Fait et testé** : `DT::testValidTreeAndCss`, `IT::testApiPersistsRenderedHtmlAndHashedCss`, `IT::testDeactivationKeepsReadableHtmlAndUninstallCleansSettingsAndCapabilities`. Conversion existante limitée, voir §9. | `src/Document/`, `src/Widgets/` |
| 3.2.5 API, CSRF, révisions et statuts | **Fait et testé** pour les parcours implémentés : `IT::testOwnerChecksAndSessionCsrf`, E2E `keyboard reparenting, favorites, commands and scheduling` et test de publication. Le planificateur du CMS reste nécessaire. | `src/Controller/Documents.php` |
| 3.2.6 CSS par contenu et kit, survol et animations | **Non fait** — partiel : empreintes, responsive et instantanés testés par `IT::testHistoricalStylesRemainImmutableAndStillRespectVisibility` ; survol et animations absents. | `src/Styles/`, `src/Plugin.php` |
| 3.2.7 JS public à la demande | **Fait et testé** pour les widgets présents : `IT::testInteractiveScriptIsLoadedOnlyForPagesWithTabs`, `IT::testLiveContentHasSafeFallbackAndNeverListsDraftPosts`. Budget de l’échantillon au §6, pas de mesure réseau globale. | `src/Plugin.php`, `assets/js/{tabs,content}.js` |
| 3.3 Panneau, recherche, catégories, favoris et réglages avancés | **Non fait** — partiel : recherche, favoris, onglets et quelques contrôles ; manque notamment position, z-index, ID/classes, CSS personnalisé, visibilité par appareil, animations, effets et attributs. E2E `keyboard reparenting, favorites, commands and scheduling`. | `assets/js/editor.js`, `views/admin/builder.php` |
| 3.3 Conteneurs flex/grid et options de structure | **Non fait** — partiel : flex/grid imbriqués, alignements, largeur, hauteur, espacements ; pas tous les fonds, superpositions, ombres et formes. `DT::testResponsiveContainerStylesAreAllowlistedAndCompiled`. | `src/Styles/`, `assets/js/editor.js` |
| 3.3 Aperçu serveur et rendu partiel | **Non fait** — partiel : rendu ciblé pour une modification isolée, repli complet pour les changements structurels ou multiples ; validation du document entier. `IT::testPartialPreviewRendersOnlyTheRequestedSubtreeAndValidatesTheWholeDocument`, E2E `partial previews preserve siblings, ignore stale responses, and recover a missing node`. Ce n’est pas un rendu partiel pour chaque modification. | `src/Controller/Documents.php`, `assets/js/{editor,model}.js` |
| 3.3 Glisser-déposer et alternative clavier complète | **Non fait** — partiel : avant/après/dans un conteneur dans le canevas et Structure, changement de parent et ordre au clavier, duplication/suppression ; parcours et technologies d’assistance non exhaustifs. E2E `drag ordering, nesting and keyboard alternatives persist in canvas and navigator`, JS `relative drag operations preserve order across parents and reject cycles`. | `assets/js/{editor,model}.js` |
| 3.3 Barre d’élément et menu contextuel | **Non fait** — partiel : barre à la sélection, copie/collage/styles/duplication/suppression et enregistrement comme modèle ; pas de barre au survol. E2E `context menu copies elements and styles with keyboard and restores focus`, `context menu saves a reusable template with keyboard, reload and deletion`. | `views/admin/builder.php`, `assets/js/editor.js` |
| 3.3 Navigateur renommable, repliable, masquage | **Fait et testé** : E2E `navigator renames, folds and hides elements with persisted accessible controls` et `DT::testNavigatorLabelsAndHiddenElementsAreValidatedAndRendered`. Repli local à l’éditeur ; masquage non confidentiel. | `assets/js/editor.js`, `src/Document/Validator.php`, `src/Widgets/Renderer.php` |
| 3.3 Responsive et héritage visible | **Non fait** — partiel : les propriétés disponibles ont trois modes et une indication d’héritage ; toutes les propriétés demandées n’existent pas. E2E `responsive controls show inherited values and their source`, JS `responsive inheritance finds the nearest previous explicit value, including zero`. | `assets/js/{editor,model}.js`, `src/Styles/` |
| 3.3 Historique, annuler/rétablir et révisions | **Fait et testé** pour le contenu : E2E `history lists actions and can jump backward and forward with focus restored`, JS `history labels and jumps preserve redo and discard a divergent future`. Le statut et la planification n’entrent pas dans l’historique local. | `assets/js/{editor,model}.js`, `views/admin/builder.php` |
| 3.3 Kit complet | **Non fait** — partiel : deux couleurs, taille de texte et points de rupture, références couleur ; pas typographies complètes, styles d’images/formulaires ni mise en page globale. `DT::testKitAndCustomBreakpointsAreSafe`. | `src/Styles/Kit.php`, `src/Controller/Settings.php` |
| 3.3 Raccourcis, palette et mode aperçu | **Non fait** — partiel : raccourcis/palette et iframe d’aperçu existent, pas de mode aperçu séparé ; sombre facultatif absent. E2E `keyboard reparenting, favorites, commands and scheduling`. | `assets/js/editor.js` |
| 3.3 Brouillon/publication/planification, avertissement et sauvegarde locale | **Non fait** — partiel : statuts et avertissement testés par E2E `keyboard reparenting, favorites, commands and scheduling` et `departure dialog traps and restores focus and closes on Escape` ; restauration du brouillon navigateur non couverte automatiquement et aperçu séparé absent. | `assets/js/editor.js`, `src/Controller/Documents.php` |
| 3.4 Base, tous les widgets et contrôles | **Non fait** — partiel : voir une ligne par widget au §5 ; contrôles avancés incomplets. | `src/Widgets/`, `assets/js/editor.js` |
| 3.4 Pro et contenu courant, tous les widgets | **Non fait** — partiel : liste d’articles et contenu courant restreints, majorité absente ; voir §5. | `src/Widgets/Content.php` |
| 3.4 Widgets globaux | **Non fait** : aucun stockage ni mise à jour partagée. | — |
| 3.4 Blocs Prime existants | **Non fait** — partiel : pont vers huit types statiques non privilégiés seulement ; `IT::testPrimeBlockPersistsCoreHtmlWithoutAllowingPrivilegedBlocks`. | `src/Widgets/PrimeBlock.php`, `src/Document/Converter.php` |
| 3.5 Types de modèles et conditions du Theme Builder | **Non fait**. | — |
| 3.5 Branchement au thème et remplacement en-tête/pied | **Non fait**. Le hook `site.partial` existe en CMS 1.0.1 et Aurora l’utilise, mais le plugin ne s’y branche pas. | — |
| 3.6 Champs de formulaire | **Non fait**. | — |
| 3.6 API publique, pot de miel, délai et débit | **Non fait**. | — |
| 3.6 Actions, envois, e-mails et export | **Non fait**. | — |
| 3.6 Conservation et purge des données personnelles | **Non fait**. | — |
| 3.7 Popups, déclencheurs, conditions et fréquence | **Non fait**. | — |
| 3.7 Dialogue de popup accessible | **Non fait** : les dialogues de l’éditeur ne sont pas un système de popup publique. | — |
| 3.8 Balises dynamiques dans les champs | **Non fait** — partiel : le widget `current_content` ne fournit que titre, extrait, date et auteur. E2E `recent posts and current content refresh safely with a no-JS fallback`. | `src/Widgets/Content.php` |
| 3.8 Conditions de visibilité | **Non fait** : `hidden` est un simple masquage HTML, pas une condition ni un contrôle d’accès. | `src/Widgets/Renderer.php` |
| 3.9 Enregistrer/insérer/exporter/importer les modèles | **Non fait** — partiel : modèles partagés enregistrés, insérés et supprimés ; export/import JSON avec droits dédiés. E2E `context menu saves a reusable template with keyboard, reload and deletion`, `template library supports insertion and JSON export/import`, IT `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf` ; les médias référencés ne sont pas réimportés. | `src/Document/{Library,SavedTemplates}.php`, `src/Controller/Templates.php`, `assets/js/editor.js` |
| 3.9 Six sections et trois pages originales, licences | **Fait et testé** pour les fichiers livrés : `CT::testAllShippedTemplatesValidate` vérifie neuf documents ; licence et absence de photo contrôlées dans les fichiers. Les contacts sont statiques. | `templates/`, `assets/images/landscape.svg`, `LICENSES.md` |
| 3.10 Menu Modèles/Envois/Kit/Réglages | **Non fait** — partiel : tableau de bord, bibliothèque dans l’éditeur et page de réglages/kit réduit ; pas de menu complet de gestion ni d’envois. E2E `dashboard exposes the current features and system status`, `plugin settings save and remain usable on desktop and mobile`. | `src/Controller/{Dashboard,Settings,Templates}.php` |
| 3.10 Six capacités | **Non fait** — partiel : les six sont enregistrées ; `edit`, `settings` et `templates` protègent les routes effectives. Une première implémentation HTML est en vérification ; les fonctions et tests 403/succès de `theme_builder` et `forms` manquent. IT `testEditorAndTemplateAccessHaveSeparateCapabilities`, `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi`. | `src/Plugin.php`, `src/Controller/` |
| 3.10 Réglages du plugin | **Non fait** — partiel : types, ruptures et petit kit ; pas polices, rétention ni CSS de l’éditeur. E2E `plugin settings save and remain usable on desktop and mobile`. | `src/Controller/Settings.php`, `views/admin/settings.php` |
| 3.10 Diagnostic et bienvenue | **Fait sans test automatique** pour l’ensemble de la puce : `IT::testDashboardAndSystemChecksReportRealState` couvre le diagnostic ; la notification d’activation a été vérifiée à l’écran, sans test automatique dédié. | `src/Plugin.php` |
| 4 Sécurité | **Non fait** — partiel : validation, CSRF, droits et cas malveillants testés ; fonctions absentes et audit indépendant non couverts, voir §7. | `src/Document/Validator.php`, `src/Controller/` |
| 4 Performance | **Non fait** — partiel : scripts conditionnels et une requête partagée pour les articles ; pas de budget réseau complet, cache global complet, ni dimensions sur toutes les images de galerie/carte. | `src/Widgets/Content.php`, `src/Plugin.php` |
| 4 Accessibilité | **Non fait** — partiel : axe sérieux/critique vert sur les scénarios indiqués ; pas d’audit WCAG 2.2 AA complet. | `tests/e2e/editor.spec.mjs`, `assets/` |
| 4 Robustesse/désactivation | **Fait et testé** pour les cas couverts : `DT::testCrashingExtensionDoesNotBreakOtherElements`, IT et parcours CLI de désactivation ; pas de garantie universelle pour les extensions ou thèmes. | `src/Widgets/Renderer.php`, `tests/e2e/lifecycle-check.php` |
| 5 PHPUnit | **Non fait** — partiel : validateurs, rendu, CSS, accès et cycle de vie couverts ; conditions de thème, formulaires, uploads et balises absents. | `tests/*.php` |
| 5 JavaScript | **Non fait** — partiel : modèle, historique et responsive ; pas de tests unitaires de toutes les interactions et raccourcis. | `tests/js/model.test.js` |
| 5 Playwright + axe | **Non fait** — partiel : construction souris/clavier et import ; pas de formulaire, thème global ni popup. | `tests/e2e/` |
| 5 PHPStan 8 et PHP-CS-Fixer | **Fait et testé** sur le PHP analysé/configuré, résultats exacts au §2. PHPStan vise `src`, `views`, `lang`, `tests`, `tools` et la configuration du fixer ; le lint et le fixer couvrent les fichiers PHP du plugin. | `phpstan.neon`, `.github/workflows/ci.yml` |
| 5 CI complète | **Non fait** — partiel : sept jobs indépendants et artefact navigateur ; couverture fonctionnelle complète impossible tant que les fonctions manquent. | `.github/workflows/ci.yml` |
| 6 Livrables et version | **Non fait** — partiel : structure, langues, tests et docs présents ; version 0.1.0 et pas les modules manquants ni gabarits d’e-mail demandés. | `plugin.json`, `CHANGELOG.md`, `src/`, `views/` |
| 6 Filtre de widgets tiers | **Non fait** — partiel : filtre, schéma, rendu et script local conditionnel existent ; `DT::testCrashingExtensionDoesNotBreakOtherElements` et JS `extension-defined containers support nesting and safe reparenting` couvrent des cas limités, pas un plugin tiers complet avec son script de bout en bout. Un callback PHP tiers est du code de confiance. | `src/Widgets/Registry.php`, `docs/developpement.md` |
| 7.1 Présentation et captures | **Non fait** — partiel : présentation présente ; captures finales dans le README absentes, Theme Builder inexistant. | `README.md` |
| 7.2 Prérequis | **Fait et testé, avec réserve** : le manifeste déclare `>=1.0.1`, avec adaptation HTTP limitée pour le défaut de jeton de 1.0.1 ; PHPUnit/MySQL sur les deux versions et limites au §7. | `README.md`, `plugin.json`, `src/Security/` |
| 7.3 Trois installations et capacités | **Non fait** — partiel : procédures documentées, ZIP CLI testé ; téléversement ZIP par interface non vérifié. | `README.md`, `.github/workflows/ci.yml` |
| 7.4 Prise en main complète | **Non fait** — partiel : page et import documentés, pas Theme Builder/formulaire/popup. | `README.md` |
| 7.5 Mise à jour, désactivation, désinstallation | **Non fait** — partiel : désactivation/désinstallation documentées et testées ; parcours de mise à jour complet non démontré. | `README.md`, `tests/e2e/lifecycle-check.php` |
| 7.6 Développement et création d’un widget | **Non fait** — partiel : documentation et exemple fournis, commandes CI exécutées ; l’exemple d’un plugin tiers complet avec son script n’a pas été testé de bout en bout. Voir les tests limités du filtre ci-dessus. | `docs/developpement.md` |

## 5. Liste et conformité de chaque widget demandé

Le statut concerne le **périmètre indiqué dans la ligne**, pas une parité complète de tous les réglages Contenu/Style/Avancé. `D` désigne `DT::testAllBuiltInWidgetsRenderWithoutThrowing`, qui vérifie surtout une enveloppe avec valeurs par défaut ; `P` désigne E2E `built-in default widgets save and remain accessible together` (23 enveloppes et axe dans la dernière CI verte ; la nouvelle attente de 24 enveloppes reste à vérifier). Un « Non fait — partiel » ne doit pas être lu comme une livraison complète. JS signifie script **public du plugin** ; tous les widgets présents partagent seulement un sous-ensemble des contrôles Style/Avancé.

| Widget demandé | Statut, réglages réellement présents, test | JS public |
|---|---|---|
| Titre | **Fait et testé** : texte, niveau 1–6 ; D, E2E `compose and publish a page using the CMS theme`. | Non |
| Texte riche | **Fait et testé** : HTML filtré ; D, `DT::testRichTextIsSanitized`. | Non |
| Image | **Fait et testé** : URL, alternatif, largeur, hauteur ; D, E2E `image selection uses the Prime media picker`. | Non |
| Vidéo fichier/YouTube/Vimeo sans suivi | **Non fait** — partiel : fichier, sous-titres WebVTT et transcription ; ni YouTube ni Vimeo. `DT::testMediaUrlsAndGalleryAreValidated`, D. | Non |
| Bouton | **Fait et testé** : texte, lien ; D, E2E `build and publish four widgets with the mouse`. | Non |
| Séparateur | **Fait et testé** : élément simple, styles communs ; D, E2E quatre widgets. | Non |
| Espaceur | **Fait et testé** : styles communs, sans réglage spécifique ; D, P. | Non |
| Icône | **Non fait** : pas de widget icône seul dans le catalogue actuel. | — |
| Boîte icône | **Fait et testé** pour la version simple : quatre symboles, titre, texte ; D, P. | Non |
| Boîte image | **Non fait** — partiel : `card` fournit image, titre, texte et lien, pas le widget complet ; D, P. | Non |
| Liste d’icônes | **Non fait** — partiel : liste ordonnée ou non, sans icônes ; `DT::testNewWidgetsProduceSafeReadableFallbacks`. | Non |
| Compteur | **Fait et testé** pour une valeur statique : valeur, préfixe, suffixe, titre ; D, P. Pas d’animation. | Non |
| Barre de progression | **Fait et testé** : libellé, valeur 0–100, `<progress>` ; D, P. | Non |
| Témoignage | **Non fait** : une citation simple ne remplace pas le widget demandé. | — |
| Onglets | **Fait et testé** pour 2–3 panneaux : titres et textes ; E2E `tabs work with keyboard and remain readable without JavaScript`, `DT::testNewWidgetsProduceSafeReadableFallbacks`. | Oui, `tabs.js` |
| Accordéon | **Fait et testé** pour un `<details>` : titre, texte ; `DT::testNewWidgetsProduceSafeReadableFallbacks`, P. | Non |
| Bascule | **Non fait** : pas de widget distinct. | — |
| Alerte | **Fait et testé** pour texte filtré simple ; D, P. | Non |
| Réseaux sociaux | **Non fait**. | — |
| Carte OpenStreetMap | **Non fait** : le widget `card` est une carte éditoriale, pas une carte géographique. | — |
| Note par étoiles | **Non fait**. | — |
| Galerie et lightbox | **Non fait** — partiel : liste de 20 URL/alternatifs, pas de lightbox ; `DT::testMediaUrlsAndGalleryAreValidated`. | Non |
| Carrousel d’images | **Non fait**. | — |
| Ancre de menu | **Fait et testé** pour une ancre simple : identifiant validé ; D, P. | Non |
| HTML privilégié | **Non fait — en cours de vérification** : widget HTML filtré sous capacité, tests locaux et navigateur ajoutés ; aucun résultat de CI de cette modification observé. Le profil strict interdit scripts, styles et iframes. | Non |
| Zone du thème | **Non fait**. | — |
| Grille d’articles configurable | **Non fait** — partiel : articles publics récents, nombre 1–12, extrait ; pas type/termes/auteur/tri/pagination/mises en page. `IT::testLiveContentHasSafeFallbackAndNeverListsDraftPosts`, `IT::testRecentPostsUseOneBoundedSharedQueryAndEscapeTitles`. | Oui, `content.js` |
| Portfolio filtrable | **Non fait**. | — |
| Diaporama | **Non fait**. | — |
| Carrousel de médias | **Non fait**. | — |
| Carrousel de témoignages | **Non fait**. | — |
| Avis | **Non fait**. | — |
| Formulaire | **Non fait**. | — |
| Menu de navigation | **Non fait**. | — |
| Appel à l’action | **Non fait** — partiel : composition statique fournie, pas widget complet. `CT::testAllShippedTemplatesValidate`. | Non |
| Boîte retournable | **Non fait**. | — |
| Table de prix | **Non fait**. | — |
| Liste de prix | **Non fait**. | — |
| Compte à rebours | **Non fait**. | — |
| Titre animé | **Non fait**. | — |
| Citation | **Fait et testé** pour une citation simple : texte filtré et auteur ; D, P. | Non |
| Boutons de partage | **Non fait**. | — |
| Sommaire automatique | **Non fait**. | — |
| Code avec coloration | **Non fait** — partiel : le pont `prime_block` accepte un bloc code statique, sans coloration dédiée ; `IT::testPrimeBlockPersistsCoreHtmlWithoutAllowingPrivilegedBlocks` teste le pont paragraphe. | Non |
| Fil d’Ariane | **Non fait** comme widget ; celui d’Aurora appartient au thème. | — |
| Formulaire de recherche | **Non fait** comme widget. | — |
| Logo du site | **Non fait** comme widget. | — |
| Titre du site | **Non fait** comme widget. | — |
| Contenu courant : titre | **Non fait** — partiel : option `title` du widget générique ; E2E `recent posts and current content refresh safely with a no-JS fallback`. | Oui, `content.js` |
| Contenu courant : extrait | **Non fait** — partiel : option `excerpt` du widget générique ; `IT::testLiveContentHasSafeFallbackAndNeverListsDraftPosts`. | Oui, `content.js` |
| Contenu courant : contenu | **Non fait**. | — |
| Contenu courant : image à la une | **Non fait**. | — |
| Contenu courant : date, auteur, catégories | **Non fait** — partiel : date ou auteur dans le widget générique, pas catégories ; D, P. | Oui, `content.js` |
| Contenu courant : précédent/suivant | **Non fait**. | — |
| Contenu courant : boîte auteur | **Non fait**. | — |
| Contenu courant : titre d’archive | **Non fait**. | — |
| Contenu courant : liste d’archive | **Non fait**. | — |
| Widget global | **Non fait**. | — |
| Bloc Prime | **Non fait** — partiel : type et JSON, huit types statiques ; `IT::testPrimeBlockPersistsCoreHtmlWithoutAllowingPrivilegedBlocks`, E2E `a non-privileged Prime block is previewed and persisted`. | Non |

Éléments additionnels livrés mais hors liste exacte : **conteneur** (`layout`, direction, colonnes ; `DT::testResponsiveContainerStylesAreAllowlistedAndCompiled`, pas de JS), **audio** (fichier et transcription ; D/P, pas de JS), **carte éditoriale** (`card`, voir boîte image) et **contenu d’origine** interne non sélectionnable (fragment chiffré, lecture seule ; `IT::testOpeningAndSavingLegacyBlocksPreservesTheirContentsAndProtectedHtml`, pas de JS).

## 6. Mesures et accessibilité

Mesures brutes par `wc -c` sur la révision historique `79d21ca` : éditeur `editor.js` **62 724 o** + `model.js` **7 368 o** = **70 092 o** de JS, `editor.css` **15 695 o** ; `alternate.css` **359 o** sur l’écran du noyau. Public : `tabs.js` **2 069 o**, `content.js` **1 050 o**, `public.css` **2 624 o**, illustration **493 o**. Il s’agit de fichiers non compressés, hors CMS/thème/polices et sans mesure réseau réelle.

Mesure reproductible avec `php tools/benchmark.php /chemin/vers/prime-cms`, une seule exécution locale PHP-WASM/SQLite, cache de page désactivé et fixture du CMS : 30 éléments (5 titres, 5 textes, 5 images, 5 boutons, 5 listes d’articles, 5 onglets ; styles desktop/mobile). Sortie observée :

```text
/atelier-benchmark status=200 queries=63 post_queries=0 time_ms=164.99 body_bytes=11048
/api/prime-builder/v1/documents/4/live status=200 queries=7 post_queries=1 time_ms=8.86 body_bytes=631
layout_elements=30 saved_html_bytes=4669 document_css_bytes=2885 kit_css_bytes=207
```

L’appel `live` est séparé de la page et contient une requête bornée commune pour les cinq listes ; la page sert un lien de repli. Les 63 requêtes incluent la fixture, les réglages, le thème et des tâches du CMS, et peuvent varier ; ces temps à froid sous WASM ne sont ni une mesure MySQL de production ni un test de charge. Pour cet échantillon, les fichiers CSS du plugin représentent 2 624 + 2 885 + 207 = **5 716 o** et les deux scripts publics **3 119 o**, hors thème/CMS, HTML, images, API, compression et réseau. Aucun LCP ni budget complet de page n’a été mesuré.

À 1440 et 375 px, Chromium local a ouvert tableau de bord, réglages, écran de contenu du noyau, éditeur et page publique ; aucun débordement horizontal sur ces captures et **0 violation axe sérieuse/critique** après correction du contraste du shell pour les écrans du plugin. Une première CI du nouvel écran a révélé un contraste insuffisant sur le lien des révisions du noyau ; un correctif CSS limité à l’éditeur alternatif a été vérifié localement avec une révision à ces deux tailles. Les E2E auditent aussi les parcours et dialogues exercés. Ces résultats ne couvrent pas tous les états, navigateurs, technologies d’assistance ou critères WCAG 2.2 AA.

## 7. Sécurité vérifiée et limites

- **Validation/XSS/CSS** : `DT::testMaliciousUrlsAndCssAreRejected`, `testRichTextIsSanitized`, `testOversizedDocumentIsRejected`, `testUnknownWidgetDuplicateIdAndInvalidChildrenAreRejected` et `IT::testInvalidTreeAndPrivateCssAreNotExposed`. Arbre limité à 1 Mio, 500 éléments et profondeur 12 ; styles sur liste fermée, pas de CSS libre. Le HTML riche utilise `HtmlSanitizer`. Pas de preuve générale contre toutes les injections.
- **CSRF/droits/IDOR** : `IT::testOwnerChecksAndSessionCsrf`, `testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi` et `testEditorAndTemplateAccessHaveSeparateCapabilities` couvrent les routes et API implémentées, leur succès autorisé, les refus et des brouillons d’autrui. Les routes publiques `live` et CSS ne sont publiques que pour un contenu visible ; les réponses privées ne sont pas mises en cache.
- **Jetons et retrait de droits** : les tests `IT::testBearerTokenCannotRetainRevokedAccountPermissions` et `testRevokedBearerAbilitiesAreRemovedFromCoreAndPluginApis` couvrent le retrait des droits, les routes du noyau et du plugin, les droits non révoqués, le compte désactivé, un jeton restreint et le joker littéral. Avant l’adaptation, une copie isolée du CMS 1.0.1 (`1cef617660f8d4f7bbd9d3d659de85d284fb18e7`) avec manifeste abaissé dans cette copie donnait `Expected 403, got 200` à la révocation du droit de publication. Dans `3d17a7e`, un harnais PHP-WASM/SQLite donne `HARNESS PASSED: 44 tests (not PHPUnit)` sur 1.0.1 et 1.0.2 ; la CI MySQL des deux versions est verte : [run 44956820076](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956820076), `OK (44 tests, 644 assertions)` pour chaque version.

  Sur 1.0.1 uniquement, `EffectiveApiAuthentication` enveloppe `ApiAuthenticate` via l’alias public `api.auth` du `Kernel`, recharge le compte et intersecte ses droits avec ceux du jeton avant les autorisations HTTP. Il ne modifie pas `ApiTokenService` : les appels directs à ce service restent vulnérables. La protection dépend de l’activation et du bon chargement de Prime Builder ; mode sans échec, désactivation, échec du plugin ou remplacement ultérieur du même alias par une autre extension la désactivent. Le diagnostic système avertit ; une mise à jour du CMS vers 1.0.2, qui corrige le noyau lui-même, reste fortement recommandée. Ce n’est pas une garantie générale de sécurité de 1.0.1.
- **Conversion et fragments historiques** : les tests `IT::testOpeningAndSavingLegacyBlocksPreservesTheirContentsAndProtectedHtml`, `testConversionRetainsHeadingAnchorsAndProtectsChangedNormalizedOutput`, `testUnavailableMiddleBlockKeepsSavedHtmlAndEditableNeighbours`, `testUnavailableBlockIsRecoveredAtEitherEdgeOrWhenItsOutputIsEmpty` et `testUnknownBlockBoundariesAreGroupedAndStaleDocumentIsRejected` vérifient notamment l’association à l’entrée et les jetons falsifiés. Le repli groupé et sa réouverture sont couverts par la CI verte `44956474944`. Les fragments sont du HTML déjà enregistré, pas un bac à sable ; la rotation d’`APP_KEY` les rend illisibles et leur rejeu dans la même entrée reste une limite.
- **Concurrence** : l’éditeur fournit `source_hash` pour détecter un état obsolète, mais l’API l’accepte encore absent et le contrôle n’est pas démontré atomique face à une écriture concurrente. Sauvegarder avant conversion et éviter les éditions simultanées.
- **Fichiers et formulaires** : aucun upload de formulaire, stockage privé, protection anti-spam ou traitement de données personnelles n’existe ; rien n’a été testé dans ces domaines. Le sélecteur média existant est celui du CMS.
- **Confidentialité et cache** : un élément masqué reste dans le HTML ; ce n’est pas une protection d’accès. Une feuille CSS publique déjà mise en cache ne peut être révoquée avant expiration. Le code PHP d’une extension de widget est de confiance.

## 8. Points d’extension ou garanties manquants dans le CMS

Les points 1 et 2 signalés lors du jalon A ont été corrigés dans Prime CMS 1.0.2 ; les points 3 à 7 décrivent des limites et propositions, non des API garanties. Aucun code du noyau n’a été modifié ici.

1. **Capacité par section de réglages — corrigé dans le CMS.** `SettingsRegistry::sectionCapability()` est appliqué par les écrans de réglages et l’API. Le plugin conserve sa page et ses routes dédiées avec `prime_builder.settings`.
2. **Retrait de permissions des jetons — corrigé dans le CMS.** `ApiTokenService::authenticate(string $raw, ?string $ip = null)` intersecte les capacités du jeton avec celles du compte courant et exclut le joker littéral. Le contournement de relecture du compte a été retiré du plugin ; le test de régression est conservé.
3. **Contexte d’entrée au validateur de bloc.** Le validateur d’un `BlockDefinition` reçoit données et `HtmlSanitizer`, pas l’entrée ; l’API du plugin revalide avec l’ID et le rendu vérifie également l’association. Proposition : ajouter un contexte compatible aux appels de validation dans `src/Editor/Renderer.php`/`BlockDefinition.php`, p. ex. `validator(array $data, HtmlSanitizer $sanitizer, array $context)`, pour les nouveaux validateurs. Actuellement le validateur générique ne peut pas vérifier cette association.
4. **Écriture conditionnelle atomique.** `src/Content/EntryService.php::update(int $entryId, array $input, User $actor, bool $bypassLock = false)` ne fournit pas de compare-and-swap sur le contenu. Contournement incomplet : `source_hash` côté éditeur, contrôlé avant `update`. Proposition : paramètre de version attendue et écriture SQL conditionnelle/verrou transactionnel incluant révisions et statut.
5. **Invalidation de dépendances de pages.** Pas d’API utilisée ici pour rattacher une page à tous les contenus externes qu’elle affiche. Contournement : listes d’articles actualisées par une API `no-store`, lien de repli, purge des pages lors des réglages. La proposition d’invalidation des dépendances entre contenus n’a pas été retenue : l’actualisation côté client est conservée. L’invalidation des futurs widgets et modèles globaux reste une exigence distincte du jalon C.
6. **Réglages et effets transactionnels.** `src/Settings/Settings.php::setMany()` appelle `set()` et émet les hooks par valeur ; cache et observateurs ne sont pas différés jusqu’au commit. Contournement : un unique réglage JSON pour la configuration ; l’upsert SQL est atomique mais les effets externes des écouteurs ne le sont pas. Proposition : opération groupée transactionnelle et notification après commit.
7. **Fragments HTML historiques par bloc.** `Prime\Editor\Renderer::renderHtml()` joint les sorties dans une seule chaîne et `EntryService` sauvegarde ce HTML global, sans bornes par bloc. Quand plusieurs moteurs tiers ne sont plus disponibles, leurs limites individuelles ne sont pas déductibles de façon générale. Une future sauvegarde de fragments associés aux identifiants de blocs faciliterait les nouvelles éditions ; elle ne peut pas reconstruire rétroactivement les bornes déjà perdues. Ce n’est pas une API actuelle.

Pour le futur Theme Builder, `SiteView::partial(string $name, array $data = [])` applique déjà `site.partial` dans le CMS 1.0.1 et Aurora appelle les partiels `header` et `footer` ; le plugin ne l’utilise pas encore. Il ne faut donc pas prétendre qu’un hook de remplacement d’en-tête manque dans cette version.

## 9. Écarts, bugs connus et vérifications manquantes

- Jalon 2 et suivants incomplets ; les fonctionnalités absentes sont listées aux §§4–5. Aucun équivalent complet d’Elementor + Pro n’est livré.
- L’aperçu n’est partiel que pour une modification isolée et non structurelle ; les autres cas ou une désynchronisation provoquent un rendu complet. Les thèmes sans `.pb-layout` ni `.aurora-prose` utilisent `main` comme repli, ce qui peut être inadapté. Les paramètres avancés et toutes les variantes de déplacement et de navigateur ne sont pas exhaustivement couverts.
- Pour un seul bloc dont le moteur est indisponible, le fragment est déduit si les rendus actuels des autres blocs correspondent aux préfixe et suffixe sauvegardés. Cela suppose que leurs rendus n’ont pas changé : le HTML historique est stocké comme une seule chaîne. Si plusieurs moteurs sont indisponibles ou si la comparaison échoue, le repli choisi conserve tout le HTML en un seul élément non éditable ; le parcours est couvert par le run vert `44956474944`. Les réglages d’origine sont perdus à l’enregistrement et la sortie dynamique figée. Des limites de taille ou une clé `APP_KEY` invalide continuent à bloquer ; extraction de texte et rejeu sont des points à revoir. `source_hash` est facultatif et non atomique.
- La bibliothèque applique `prime_builder.templates` côté serveur à la création, la suppression et la validation d’import, mais une personne autorisée à éditer peut composer un JSON équivalent. Les modèles partagés sont stockés ; les images externes ne sont pas réimportées.
- Le kit et les styles sont limités ; certaines images de galerie/carte n’ont pas de dimensions explicites. Les contenus actuels peuvent être périmés si le script/API est indisponible. Les anciennes URL CSS sans instantané ne sont pas reconstructibles après changement.
- Le navigateur utilise `localStorage` pour les brouillons, qui n’est ni une sauvegarde serveur ni une garantie de persistance. `beforeunload` reste natif pour certains départs. Les effets des écouteurs de réglages après écriture ne sont pas transactionnels.
- Pas de test de téléversement ZIP dans l’administration, de mise à jour complète, d’audit WCAG manuel, d’audit sécurité indépendant, de mesure réseau/LCP, ni de test de charge ou de compatibilité exhaustive des thèmes/CMS. Le cycle ZIP CLI et la désinstallation ont été testés en CI.
- Les warnings GitHub Actions relatifs à la dépréciation Node.js 20 sont non bloquants ; les actions devront être mises à jour. La CI clone une branche CMS flottante.

## 10. Parcours concret de vérification

1. Sur une **instance jetable** Prime CMS 1.0.2 avec MySQL 8, installer/activer le plugin par une des méthodes du `README.md` ; donner `prime_builder.edit`, les droits de contenu et, selon le cas, `prime_builder.templates` / `prime_builder.settings`. Créer une page et l’enregistrer d’abord dans le CMS.
2. Ouvrir **Prime Builder**, puis **Réglages** ; vérifier pages activées, points de rupture et couleurs, modifier puis remettre les valeurs. Depuis la page du noyau, ouvrir **Modifier avec Prime Builder** et constater l’iframe du thème.
3. Ajouter un conteneur, puis titre, texte, bouton et séparateur. Dans le canevas ou **Structure**, déplacer un élément avant/après un autre ou dans le conteneur ; vérifier les repères de dépôt. Essayer également **Déplacer vers**, **Monter** et **Descendre** au clavier. Changer une taille sur ordinateur puis mobile, vérifier l’indication d’héritage, publier et ouvrir la page publique. Refaire sur une autre page avec la palette `Ctrl/⌘ K` et le clavier. Vérifier au préalable les liens et contenus saisis.
4. Insérer un modèle de section, exporter puis réimporter le JSON dans une page de test ; ne pas confondre les compositions de contact avec des formulaires. Essayer la liste d’articles avec et sans JavaScript.
5. Sur une copie sauvegardée, ouvrir une page de blocs Prime existants ; vérifier l’ordre et les ancres, puis sauvegarder seulement si la conversion est proposée. Une conversion impossible doit bloquer l’enregistrement.
6. Désactiver le plugin et demander une URL publique fraîche : le HTML sauvegardé doit rester lisible, sans garantie de style/interactivité. Réactiver, puis sur une installation jetable seulement essayer la désinstallation et vérifier réglages/capacités/fichiers et conservation du contenu.
7. Exécuter les commandes du §3 et consulter le dernier run pour la branche. La CI crée ses propres comptes et pages éphémères ; aucun identifiant de production n’a été créé pour ce rapport.
