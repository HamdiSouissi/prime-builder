# Historique des versions et des vérifications CI

> Archive intégrale du rapport à la révision `2a02837e7062d18cd9933363c11a75c1a6e94c90`, conservée pour les sorties et échecs historiques. Les états de fonctionnalités ci-dessous sont historiques : consulter [`../RETOUR.md`](../RETOUR.md) pour l’état actuel.

# Retour de mission — Prime Builder 0.1.0

## État de la reprise

La livraison reste **partielle et non prête pour la production**. Le fichier `docs/specifications/PRIME-BUILDER-suite-1.md` est maintenant présent et suivi dans Git. Le socle `b413a3f5202111988f1a4b45e288024057e6c0ca` a été accepté par le demandeur ; son [run 44924173858](https://github.com/HamdiSouissi/prime-builder/actions/runs/44924173858) est vert avec les trois jobs de la CI d’alors. Le compte rendu d’acceptation ultérieur de A est conservé ci-dessous comme historique, pas comme preuve que tous les critères détaillés supplémentaires sont remplis.

Le dernier jalon terminé de la suite est **A — corrections**, à la révision `b36500a8522f52f9ffee155ee08164519d9fc802`, [run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806) : huit jobs réussis, PHPUnit/MySQL sur les deux CMS, 34 scénarios navigateur et désinstallation réelle. Il couvre le formulaire fixe, le contrôle de sa capacité et le parcours clavier complet. Cette clôture tient compte des limites et du repli groupé convenus ci-dessous ; elle ne valide ni le catalogue B ni le Theme Builder ou les formulaires complets. La prochaine étape est B ; C à G restent ouverts. Le catalogue B a été **entamé prématurément** dans `22a3824` ; ces ajouts ont ensuite été retirés dans `0c5a6c9`, afin de respecter l’ordre demandé. Leur historique est conservé, mais ils ne font plus partie du code livré ; le run du retrait est vert.

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
- Dernière révision observée verte lors de cette rédaction : `b36500a8522f52f9ffee155ee08164519d9fc802`, [run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806). Un fichier ne peut pas contenir son propre SHA Git ni le résultat futur de sa propre CI. Pour la tête effective, utiliser `git ls-remote origin refs/heads/arena/2d5ecf33-prime-builder`, `git log origin/arena/2d5ecf33-prime-builder -1` et le run associé à ce SHA.
- Pour le jalon A, Prime CMS local était au checkout `1cef617660f8d4f7bbd9d3d659de85d284fb18e7` (1.0.1). Pour la suite n° 2, le checkout local est `93fd804001897597e43145e58eeb50388e75e3be` (1.0.2). Son code n’a pas été modifié ; une copie du plugin a été placée sous `content/plugins/prime-builder/`. La CI clone `main` du CMS : cette référence flottante peut changer.
- Le manifeste de la révision en cours déclare Prime CMS `>=1.0.1`. Sur 1.0.1, un adaptateur du plugin intersecte les capacités de jeton et du compte dans la pile HTTP, uniquement tant qu’il est actif et que son alias n’est pas remplacé ; la matrice PHPUnit/MySQL 1.0.1 est verte. Le noyau 1.0.1 non modifié reste vulnérable hors de cette pile et après désactivation du plugin. Prime CMS 1.0.2 corrige ce point dans le noyau et reste recommandé.

## 2. CI et jalons

### Dernière CI verte observée — formulaire fixe et corrections A

Révision `b36500a8522f52f9ffee155ee08164519d9fc802` : [run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806), `completed` / `success`, huit jobs indépendants. Lignes exactes relevées dans les annotations :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 44 files that can be fixed in 0.980 seconds, 84.00 MB memory used` |
| `phpunit (main)` | `success` | `OK (52 tests, 936 assertions)` |
| `phpunit (1cef617660f8d4f7bbd9d3d659de85d284fb18e7)` | `success` | `OK (52 tests, 936 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `34 passed (1.8m)` ; étapes de désactivation et désinstallation réelles `success` |

La première tentative sur `1933d5a3cedd6d67805ad38b963385233a9d9784`, [run 44965004221](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965004221), est **rouge** : les sept autres jobs réussissent (notamment `OK (52 tests, 936 assertions)` pour chaque CMS), mais les deux derniers scénarios Playwright reçoivent 429 après 32 succès ; la désactivation et la désinstallation ne sont pas exécutées. Le limiteur API du CMS compte les requêtes authentifiées de tous les scénarios pour le même compte sur une minute. Le correctif `b36500a` augmente sa limite à 10 000 **uniquement dans l’installation CI jetable**, sans le désactiver ni modifier les limites propres aux formulaires, testées séparément par PHPUnit. Le run suivant ci-dessus est vert ; aucun scénario n’a été sauté.

Vérifications locales : les deux harnais PHP-WASM/SQLite affichent `HARNESS PASSED: 52 tests (not PHPUnit)` ; deux scénarios Chromium/SQLite distincts passent pour le formulaire et le menu exclusivement clavier. Les captures à 1440 et 375 px du formulaire public et de la boîte d’envois ont été ouvertes et examinées ; elles montrent notamment le message malveillant affiché comme texte. Elles ne constituent pas un audit manuel WCAG ou sécurité. Un premier essai local a révélé une icône `mail` absente du sprite CMS, qui provoquait une erreur 500 dans l’administration en mode debug : remplacée par `inbox` et protégée par un test du menu. Un autre échec d’aperçu venait d’une table `zones` absente du harnais SQLite ; la sortie thématique se repliait alors sur une page d’aperçu du noyau incompatible avec l’iframe. Enfin le serveur PHP-WASM a interrompu une connexion ; il a été relancé pour les deux parcours réussis. Ces observations locales ne remplacent pas MySQL.

### CI verte avant la tranche de formulaires

Révision `54718b0e370c6c9bcfe6f172b77f7eb7aa4ec6a0` : [run 44962226021](https://github.com/HamdiSouissi/prime-builder/actions/runs/44962226021), huit jobs `lint`, `cs`, `js`, `i18n`, `smoke`, `phpunit (main)`, `phpunit (1cef617660f8d4f7bbd9d3d659de85d284fb18e7)` et `e2e` terminés avec `success`. Les lignes finales n’ont pas été relevées pour ce run. Il ne comprend pas la présente tranche de formulaires.

### CI précédente avec lignes finales relevées

Révision `14a8adc5c7dd6885f04cc3c46497cae9df963da4` : [run 44961832140](https://github.com/HamdiSouissi/prime-builder/actions/runs/44961832140), `completed` / `success`, huit jobs, dont deux versions PHPUnit/MySQL indépendantes. Lignes relevées dans les annotations :

| Job | Conclusion | Sortie observée |
|---|---|---|
| `lint` | `success` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `success` | `Found 0 of 38 files that can be fixed in 0.926 seconds, 80.00 MB memory used` |
| `phpunit (main)` | `success` | `OK (48 tests, 808 assertions)` |
| `phpunit (1cef617660f8d4f7bbd9d3d659de85d284fb18e7)` | `success` | `OK (48 tests, 808 assertions)` |
| `js` | `success` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `success` | `Catalogue de traduction cohérent.` |
| `smoke` | `success` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `success` | `32 passed (1.5m)` |

La révision précédente `e9e4cffa0ca3fd6a7a9291d6e36e6edce2f8a84c` est verte : [run 44960481455](https://github.com/HamdiSouissi/prime-builder/actions/runs/44960481455), 47 tests/748 assertions par CMS et `31 passed (1.3m)`. La révision de rapport `086f7f919af940b61d13cddca919ade6f7103746` était également verte : [run 44957139010](https://github.com/HamdiSouissi/prime-builder/actions/runs/44957139010), 44 tests/644 assertions par CMS et 30 scénarios Playwright.

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

### Widget HTML filtré — vérifié avant la clôture de A

Les modifications introduisent un widget distinct assaini avec `HtmlSanitizer::sanitizeBasic`, même pour un compte possédant `prime_builder.unfiltered_html` ; aucun script, style, iframe, attribut événementiel ou URL dangereuse n’est autorisé par ce profil. La capacité protège ce widget, pas l’emploi des mêmes balises filtrées dans le texte riche. La validation du CMS n’ayant ni acteur ni entrée, le widget est signé pour son entrée et son identifiant : l’API Builder exige le droit pour ajouter ou modifier, tandis qu’un éditeur sans ce droit peut conserver, déplacer ou supprimer un widget existant valide. Les signatures sont retirées des modèles partagés. Une clé `APP_KEY` valide est requise ; sa rotation invalide les signatures.

Les tests couvrent le refus 403 et le succès de l’API Builder, le retrait du droit après émission d’un jeton, un jeton volontairement restreint, le rejeu d’une version ancienne et le rejeu entre entrées, les modèles, la duplication, l’autosauvegarde et la restauration. Le noyau valide avant le filtre et ne permet pas à un filtre de rejeter une écriture par une exception : pour un rejeu non autorisé passé au service ou à l’API du noyau, le filtre conserve le contenu précédent mais le noyau peut répondre 200 et modifier d’autres champs. En création/duplication par le noyau, l’identifiant cible n’existe pas encore : le widget signé est converti en texte riche filtré, pas conservé comme widget HTML. L’autosauvegarde du noyau échappe au filtre, mais la restauration appelle `update` et passe par le filtre.

Observations locales sur ce travail : deux harnais PHP-WASM/SQLite affichent chacun `HARNESS PASSED: 47 tests (not PHPUnit)` sur le CMS 1.0.1 figé et le checkout 1.0.2 ; l’échec initial de rejeu entre entrées a été corrigé par vérification de la signature liée à la destination. PHPStan 2.3.5 sous PHP-WASM sur sept fichiers modifiés affiche `[OK] No errors` ; Node 14/14 et `git diff --check` passent. Ces harnais ne sont pas PHPUnit/MySQL.

Le [run 44960150978](https://github.com/HamdiSouissi/prime-builder/actions/runs/44960150978) sur `badfe897ba4089616d3a16b8fb601a940dfd6bde` est **rouge** : PHPUnit/MySQL réussit sur les deux CMS (`OK (47 tests, 748 assertions)` chacun), PHP-CS-Fixer affiche `Found 0 of 35 files`, Node 14/14, catalogue et smoke passent ; PHPStan échoue sur l’accès à un groupe de capture dans un test, et le job Playwright échoue. Les annotations navigateur ont été tronquées avant le motif de l’échec, et le téléchargement des logs et artefacts a renvoyé `EOF` : sa cause exacte n’est pas encore connue. L’accès au groupe a été corrigé et le scénario HTML utilise désormais une page isolée ; le diagnostic CI a été fractionné pour permettre de lire une éventuelle erreur. Le correctif `e9e4cffa0ca3fd6a7a9291d6e36e6edce2f8a84c` et son [run 44960481455](https://github.com/HamdiSouissi/prime-builder/actions/runs/44960481455) sont ensuite verts, y compris `31 passed (1.3m)` ; cette réussite après isolation ne permet pas d’affirmer la cause de l’échec navigateur initial. Le scénario automatisé ouvre l’éditeur, prévisualise, enregistre, rouvre et vérifie le rendu public. Aucune vérification manuelle supplémentaire n’est revendiquée.

### Première fonction Theme Builder — vérifiée, jalon D toujours ouvert

Une première fonction réelle permet de choisir des copies statiques de modèles partagés pour les partials `header` et `footer`, de les appliquer sur le site et d’exclure des contenus par identifiant. La configuration exige `prime_builder.theme_builder`, le CSRF de l’administration et, pour une copie contenant le widget HTML filtré, `prime_builder.unfiltered_html`. Les copies sont indépendantes de la bibliothèque ; les styles sont servis séparément par empreinte et les identifiants des widgets et ancres sont séparés pour chaque partie. Les listes d’articles et widgets de contenu actuel sont refusés dans ces copies. Les liens internes des widgets bouton/carte sont adaptés aux ancres renommées, pas les liens dans le texte riche. Un autre plugin qui a déjà remplacé le partial conserve la priorité. Les conditions par type ou terme, les priorités et les modèles de contenu/archive/recherche/404 ne sont pas implémentés.

Les harnais locaux PHP-WASM/SQLite indiquent chacun `HARNESS PASSED: 48 tests (not PHPUnit)` sur les deux CMS ; le scénario couvre 403/succès, CSRF, exclusions, copie indépendante, CSS, scripts et capacité HTML. Le [run 44961539247](https://github.com/HamdiSouissi/prime-builder/actions/runs/44961539247) sur `16312109c56038341fa88a6b83890000a13f5199` est **rouge** : les deux PHPUnit/MySQL passent (`OK (48 tests, 808 assertions)`), de même que PHP-CS-Fixer (`Found 0 of 38 files`), Node, catalogue et smoke ; PHPStan signale quatre types dans le nouveau test, et Playwright échoue sur le contraste de l’avatar du CMS dans le nouvel écran (`color-contrast: .pc-avatar`) après 31 autres scénarios verts. Les types et le contraste ciblé ont été corrigés dans `14a8adc5c7dd6885f04cc3c46497cae9df963da4` ; son [run 44961832140](https://github.com/HamdiSouissi/prime-builder/actions/runs/44961832140) est entièrement vert : PHPUnit/MySQL `OK (48 tests, 808 assertions)` sur chaque CMS et Playwright `32 passed (1.5m)`. Le scénario automatisé ouvre l’écran, configure en-tête et pied, vérifie leur rendu et une exclusion, et la CI vérifie le retour d’Aurora après désactivation. Aucune vérification manuelle dédiée supplémentaire n’est revendiquée. Le jalon D reste non terminé.

### Suite 1 — jalon A : terminé avec les limites convenues

L’état `0c5a6c9` et son run vert ci-dessus incluent les modèles partagés ainsi que la récupération d’un bloc indisponible entre des voisins dont les rendus correspondent. Les tests `IT::testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `IT::testEditorAndTemplateAccessHaveSeparateCapabilities` et E2E `context menu saves a reusable template with keyboard, reload and deletion` couvrent les opérations effectivement implémentées. La bibliothèque est lisible et insérable avec `prime_builder.edit` ; création, import et suppression exigent `prime_builder.templates`. Les réglages ont leur capacité et leurs tests séparés.

Une première fonction réelle de formulaires et ses tests 403/succès `prime_builder.forms` sont maintenant présents et verts en PHPUnit/MySQL sur les deux CMS ; le parcours navigateur est également vert dans le [run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806). Une première fonction réelle d’en-tête/pied et ses tests `prime_builder.theme_builder` sont verts dans le run `44961832140`. Le widget HTML filtré et ses tests 403/succès sont verts depuis le run `44960481455` ; la CI ci-dessus complète les vérifications demandées pour A. Le demandeur a autorisé ces réalisations avant la clôture de A ; aucun faux point d’accès n’a été créé. Un utilisateur autorisé à éditer un document peut toujours composer un JSON équivalent à un modèle : la capacité contrôle les opérations de bibliothèque, pas toutes les compositions possibles. Le relecteur a déjà vérifié une conversion sur le site de démonstration (résultat attribué ci-dessus) ; les nouveaux modèles n’ont pas été inspectés manuellement depuis. Les parcours navigateur de la CI sont automatisés, pas une vérification manuelle supplémentaire.

| Correction demandée | Statut | Preuve ou limite |
|---|---|---|
| Conversion automatique sans perte du HTML | **Fait et testé selon le repli choisi** | [Run 44956474944](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956474944) : PHPUnit et E2E couvrent le groupe en lecture seule, sauvegarde, réouverture, sortie publique et falsification. Les limites de taille et de clé bloquent toujours la conversion ; les réglages originaux ne sont pas conservés et le dynamique est figé. L’hypothèse sur la récupération individuelle est au §9. |
| Aucun `confirm()`, `alert()` ni `prompt()` ; dialogue accessible | **Fait et testé** | E2E `departure dialog traps and restores focus and closes on Escape`, parcours d’import et de modèles ; le navigateur peut encore afficher son avertissement natif `beforeunload`. |
| Couleurs référencées au kit, dimensions avec unité | **Fait et testé** | E2E `color picker uses kit references and spacing has numeric units`, `DT::testKitAndCustomBreakpointsAreSafe`. Le kit complet du §3.3 reste partiel. |
| Noms lisibles dans « Déplacer vers » | **Fait et testé** | E2E `existing three-block page is converted without losing its saved HTML` et `navigator renames, folds and hides elements with persisted accessible controls`. |
| `edit`, `templates`, `settings` : refus/succès effectifs | **Fait et testé** | IT `testEditorAndTemplateAccessHaveSeparateCapabilities`, `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi`. |
| `theme_builder` : refus/succès effectifs | **Fait et testé pour ce sous-ensemble** | [Run 44961832140](https://github.com/HamdiSouissi/prime-builder/actions/runs/44961832140), IT `testThemePartialsUseRealTemplatesAndRequireTheirOwnCapability` et E2E `static theme header and footer replace Aurora except on excluded content`. La fonction est partielle ; D reste ouvert. |
| `forms` : refus/succès effectifs | **Fait et testé pour ce sous-ensemble** | `IT::testSubmissionInboxRequiresItsOwnCapabilityAndCsrf` vérifie 403, succès, CSRF et révocation sur les deux CMS/MySQL ; E2E `simple contact form sends anonymously and the protected inbox receives it` ouvre, reçoit et supprime un vrai message. [Run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806). |
| `unfiltered_html` : refus/succès effectifs | **Fait et testé** | [Run 44960481455](https://github.com/HamdiSouissi/prime-builder/actions/runs/44960481455) : PHPUnit/MySQL sur les deux CMS et E2E HTML filtré verts. Tests de capacité, scellement, révocation, jeton restreint, API du noyau, copie et restauration ; limites de l’API noyau ci-dessus. |
| Indicateur court « Non enregistré » | **Fait et testé** | E2E `departure dialog traps and restores focus and closes on Escape` et conversion de page existante. |
| Menu contextuel et parcours sans souris | **Fait et testé** | E2E `context menu supports every action using only the keyboard` utilise le clavier depuis la connexion et exerce copie, collage, style, duplication, modèle, réinitialisation, suppression et sauvegarde ; passage local et [run 44965292806](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965292806). |
| Minimum Prime `>=1.0.1` et CI sur `main` | **Fait et testé, avec réserve** | Le manifeste déclare `>=1.0.1` ; [run 44956820076](https://github.com/HamdiSouissi/prime-builder/actions/runs/44956820076) : PHPUnit/MySQL vert sur 1.0.1 et `main`. L’adaptation protège la pile HTTP API sous 1.0.1 uniquement lorsqu’elle est active ; limites et risque de désactivation au §7. |

### Suite 1 — jalon B : catalogue, non terminé

Sept versions simples avaient été ajoutées prématurément dans `22a3824` / [run 44951581745](https://github.com/HamdiSouissi/prime-builder/actions/runs/44951581745), puis retirées dans `0c5a6c9` avant clôture de A. Les tests verts de cette révision historique ne décrivent donc plus le catalogue actuel. B reste non terminé ; la liste exacte des widgets manquants et des versions partielles figure au tableau §5. Aucun SHA/run de jalon complet ni vérification manuelle de ces widgets ne peut être donné.

### Suite 1 — jalon C : non terminé

Pas de widgets globaux synchronisés, balises dynamiques générales ni conditions d’affichage. Aucun SHA/run de réalisation complète ni vérification manuelle dédiée ; le SHA `0c5a6c9` et le [run 44952710921](https://github.com/HamdiSouissi/prime-builder/actions/runs/44952710921) ne testent que le socle partiel. Leurs lignes finales sont au tableau ci-dessus.

### Suite 1 — jalon D : non terminé

Un premier sous-ensemble vérifié branche `site.partial` pour les en-têtes et pieds statiques, avec exclusions par contenu et copies de modèles partagés. Les conditions par type ou terme, les priorités et les modèles contenu/archive/recherche/404 restent absents. Aucun SHA/run de jalon complet ni vérification manuelle dédiée ne peut être donné.

### Suite 1 — jalon E : non terminé

Un formulaire fixe nom/e-mail/message, une boîte protégée, des quotas, un pot de miel, des jetons à usage unique et une purge planifiée de 30 jours existent maintenant. Deux parcours Chromium/SQLite et les deux harnais PHP-WASM/SQLite passent localement ; les captures publique et boîte aux lettres ont été examinées sur ordinateur et mobile. La CI MySQL et la désinstallation réelle passent dans `44965292806`. Le constructeur de champs, les fichiers privés, les étapes, la file d’e-mails, la recherche et l’export manquent : aucun jalon E complet n’est revendiqué.

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

**Écart de méthode important** : le travail sur le socle, la bibliothèque et l’éditeur avait déjà commencé avant que les sept jobs indépendants existent et avant que PHPUnit utilise effectivement MySQL. Le [run 44928027104](https://github.com/HamdiSouissi/prime-builder/actions/runs/44928027104), `65aa75dab75ca11dbf4e281d4db828ef3983228e`, avait sept jobs verts mais la fixture PHPUnit utilisait encore SQLite malgré une vérification de connexion MySQL. Après correction de la fixture, le [run 44940270714](https://github.com/HamdiSouissi/prime-builder/actions/runs/44940270714), `5af6ab85859e99142cb6d103035bfa10dbef1173`, a exécuté les sept jobs avec succès et PHPUnit sur MySQL. Il est donc faux d’affirmer que les jalons initiaux 0 à 5 ont été franchis dans l’ordre demandé. Pour cette ancienne série, le jalon 0 est le dernier dont les sorties techniques sont observées comme réunies ; aucun passage de cette série dans l’ordre prescrit n’est revendiqué. Le jalon A de la suite est distinct et son état est indiqué plus haut.

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

Révision `79d21cabc9c0bc25c567fa64e5261ed29e5ee469`, [run 44942912139](https://github.com/HamdiSouissi/prime-builder/actions/runs/44942912139) : même tableau historique de jobs et lignes de synthèse du §2. Neuf compositions et leur import/export existent, ainsi qu’une page de réglages et une documentation partielle, mais, à cette révision historique, l’enregistrement de modèles utilisateurs et les écrans Modèles/Envois manquaient. Des modèles partagés et une première boîte d’envois ont été ajoutés depuis, sans couvrir toutes les exigences du jalon. README, mesures, audit et documentation ne couvrent pas toutes les exigences finales. Les fonctions partielles précédentes ne ferment pas ce jalon.

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
# Reproduire toutes les fixtures et variables E2E_* de .github/workflows/ci.yml,
# notamment les pages vierges formulaire-test (E2E_FORM_ENTRY_ID) et menu-clavier
# (E2E_MENU_ENTRY_ID). Ces tests publient, modifient et suppriment des données :
# ne jamais viser une base réelle.
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
| 3.5 Types de modèles et conditions du Theme Builder | **Non fait** — partiel : premières copies statiques d’en-tête/pied et exclusions par contenu vérifiées dans `44961832140` ; autres types, conditions et priorités absents. | `src/Theme/Partials.php` |
| 3.5 Branchement au thème et remplacement en-tête/pied | **Non fait — partiel** : branchement `site.partial` pour deux parties statiques, test navigateur vert dans le run `44961832140` ; les autres templates restent absents. | `src/Plugin.php`, `src/Controller/ThemeBuilder.php` |
| 3.6 Champs de formulaire | **Non fait — partiel** : trois champs fixes nom, e-mail et message ; pas de constructeur ni de fichiers ou étapes. | `src/Widgets/Renderer.php` |
| 3.6 API publique, pot de miel, délai et débit | **Fait et testé pour le formulaire fixe** : jeton HMAC 3–1 800 s à usage unique, pot de miel et quotas ; quatre tests d’intégration sur deux CMS/MySQL et E2E dans `44965292806`. | `src/Controller/ContactForms.php`, `src/Forms/` |
| 3.6 Actions, envois, e-mails et export | **Non fait — partiel** : stockage et suppression dans une boîte protégée ; pas d’e-mail, recherche ou export. | `src/Forms/Submissions.php`, `views/admin/submissions.php` |
| 3.6 Conservation et purge des données personnelles | **Non fait — partiel** : purge fixe après 30 jours si le scheduler fonctionne, sans réglage de durée ni gestion des sauvegardes. | `src/Forms/Submissions.php`, `src/Plugin.php` |
| 3.7 Popups, déclencheurs, conditions et fréquence | **Non fait**. | — |
| 3.7 Dialogue de popup accessible | **Non fait** : les dialogues de l’éditeur ne sont pas un système de popup publique. | — |
| 3.8 Balises dynamiques dans les champs | **Non fait** — partiel : le widget `current_content` ne fournit que titre, extrait, date et auteur. E2E `recent posts and current content refresh safely with a no-JS fallback`. | `src/Widgets/Content.php` |
| 3.8 Conditions de visibilité | **Non fait** : `hidden` est un simple masquage HTML, pas une condition ni un contrôle d’accès. | `src/Widgets/Renderer.php` |
| 3.9 Enregistrer/insérer/exporter/importer les modèles | **Non fait** — partiel : modèles partagés enregistrés, insérés et supprimés ; export/import JSON avec droits dédiés. E2E `context menu saves a reusable template with keyboard, reload and deletion`, `template library supports insertion and JSON export/import`, IT `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf` ; les médias référencés ne sont pas réimportés. | `src/Document/{Library,SavedTemplates}.php`, `src/Controller/Templates.php`, `assets/js/editor.js` |
| 3.9 Six sections et trois pages originales, licences | **Fait et testé** pour les fichiers livrés : `CT::testAllShippedTemplatesValidate` vérifie neuf documents ; licence et absence de photo contrôlées dans les fichiers. Les contacts sont statiques. | `templates/`, `assets/images/landscape.svg`, `LICENSES.md` |
| 3.10 Menu Modèles/Envois/Kit/Réglages | **Non fait** — partiel : tableau de bord, bibliothèque dans l’éditeur, boîte d’envois et page de réglages/kit réduit ; pas de menu complet de gestion des modèles. E2E `dashboard exposes the current features and system status`, `plugin settings save and remain usable on desktop and mobile`. | `src/Controller/{Dashboard,Settings,Templates}.php` |
| 3.10 Six capacités | **Non fait** — partiel : les six sont enregistrées ; `edit`, `settings` et `templates` protègent les routes effectives. Le widget HTML filtré et ses tests 403/succès sont verts (`44960481455`) ; les tests du Theme Builder partiel sont verts (`44961832140`) et les tests MySQL couvrent la capacité `forms` pour la boîte d’envois dans `44965292806`. IT `testEditorAndTemplateAccessHaveSeparateCapabilities`, `testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf`, `testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi`. | `src/Plugin.php`, `src/Controller/` |
| 3.10 Réglages du plugin | **Non fait** — partiel : types, ruptures et petit kit ; pas polices, rétention ni CSS de l’éditeur. E2E `plugin settings save and remain usable on desktop and mobile`. | `src/Controller/Settings.php`, `views/admin/settings.php` |
| 3.10 Diagnostic et bienvenue | **Fait sans test automatique** pour l’ensemble de la puce : `IT::testDashboardAndSystemChecksReportRealState` couvre le diagnostic ; la notification d’activation a été vérifiée à l’écran, sans test automatique dédié. | `src/Plugin.php` |
| 4 Sécurité | **Non fait** — partiel : validation, CSRF, droits et cas malveillants testés ; fonctions absentes et audit indépendant non couverts, voir §7. | `src/Document/Validator.php`, `src/Controller/` |
| 4 Performance | **Non fait** — partiel : scripts conditionnels et une requête partagée pour les articles ; pas de budget réseau complet, cache global complet, ni dimensions sur toutes les images de galerie/carte. | `src/Widgets/Content.php`, `src/Plugin.php` |
| 4 Accessibilité | **Non fait** — partiel : axe sérieux/critique vert sur les scénarios indiqués ; pas d’audit WCAG 2.2 AA complet. | `tests/e2e/editor.spec.mjs`, `assets/` |
| 4 Robustesse/désactivation | **Fait et testé** pour les cas couverts : `DT::testCrashingExtensionDoesNotBreakOtherElements`, IT et parcours CLI de désactivation ; pas de garantie universelle pour les extensions ou thèmes. | `src/Widgets/Renderer.php`, `tests/e2e/lifecycle-check.php` |
| 5 PHPUnit | **Non fait** — partiel : validateurs, rendu, CSS, accès et cycle de vie couverts ; tests de formulaire fixe verts sur MySQL dans `44965292806` ; conditions de thème complètes, uploads et balises absents. | `tests/*.php` |
| 5 JavaScript | **Non fait** — partiel : modèle, historique et responsive ; pas de tests unitaires de toutes les interactions et raccourcis. | `tests/js/model.test.js` |
| 5 Playwright + axe | **Non fait** — partiel : construction souris/clavier, import et premier thème statique ; formulaire fixe et clavier intégral passés localement et en CI ; popup absent. | `tests/e2e/` |
| 5 PHPStan 8 et PHP-CS-Fixer | **Fait et testé** sur le PHP analysé/configuré, résultats exacts au §2. PHPStan vise `src`, `views`, `lang`, `migrations`, `tests`, `tools` et la configuration du fixer ; le lint et le fixer couvrent les fichiers PHP du plugin. | `phpstan.neon`, `.github/workflows/ci.yml` |
| 5 CI complète | **Non fait** — partiel : huit jobs indépendants et artefact navigateur ; couverture fonctionnelle complète impossible tant que les fonctions manquent. | `.github/workflows/ci.yml` |
| 6 Livrables et version | **Non fait** — partiel : structure, langues, tests et docs présents ; version 0.1.0 et pas les modules manquants ni gabarits d’e-mail demandés. | `plugin.json`, `CHANGELOG.md`, `src/`, `views/` |
| 6 Filtre de widgets tiers | **Non fait** — partiel : filtre, schéma, rendu et script local conditionnel existent ; `DT::testCrashingExtensionDoesNotBreakOtherElements` et JS `extension-defined containers support nesting and safe reparenting` couvrent des cas limités, pas un plugin tiers complet avec son script de bout en bout. Un callback PHP tiers est du code de confiance. | `src/Widgets/Registry.php`, `docs/developpement.md` |
| 7.1 Présentation et captures | **Non fait** — partiel : présentation présente ; captures finales dans le README absentes, Theme Builder incomplet. | `README.md` |
| 7.2 Prérequis | **Fait et testé, avec réserve** : le manifeste déclare `>=1.0.1`, avec adaptation HTTP limitée pour le défaut de jeton de 1.0.1 ; PHPUnit/MySQL sur les deux versions et limites au §7. | `README.md`, `plugin.json`, `src/Security/` |
| 7.3 Trois installations et capacités | **Non fait** — partiel : procédures documentées, ZIP CLI testé ; téléversement ZIP par interface non vérifié. | `README.md`, `.github/workflows/ci.yml` |
| 7.4 Prise en main complète | **Non fait** — partiel : page, import, premier Theme Builder et formulaire fixe documentés ; pas de prise en main complète du Theme Builder, du constructeur de formulaires ou des popups. | `README.md` |
| 7.5 Mise à jour, désactivation, désinstallation | **Non fait** — partiel : désactivation/désinstallation documentées et testées ; parcours de mise à jour complet non démontré. | `README.md`, `tests/e2e/lifecycle-check.php` |
| 7.6 Développement et création d’un widget | **Non fait** — partiel : documentation et exemple fournis, commandes CI exécutées ; l’exemple d’un plugin tiers complet avec son script n’a pas été testé de bout en bout. Voir les tests limités du filtre ci-dessus. | `docs/developpement.md` |

## 5. Liste et conformité de chaque widget demandé

Le statut concerne le **périmètre indiqué dans la ligne**, pas une parité complète de tous les réglages Contenu/Style/Avancé. `D` désigne `DT::testAllBuiltInWidgetsRenderWithoutThrowing`, qui vérifie surtout une enveloppe avec valeurs par défaut ; `P` désigne E2E `built-in default widgets save and remain accessible together` (24 enveloppes et axe dans le run historique `44960481455`, 25 attendues avec le formulaire ajouté ensuite). Un « Non fait — partiel » ne doit pas être lu comme une livraison complète. JS signifie script **public du plugin** ; tous les widgets présents partagent seulement un sous-ensemble des contrôles Style/Avancé.

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
| HTML privilégié | **Fait et testé** pour le HTML filtré : `DT::testFilteredHtmlSealsAreSanitizedEntryBoundAndCannotBeForged`, IT `testFilteredHtmlRequiresCapabilityAndRemainsSafeAcrossCoreAndBuilderApis` et E2E `filtered HTML is previewed, sanitized, saved and reopened` dans `44960481455`. Le profil strict interdit scripts, styles et iframes. | Non |
| Zone du thème | **Non fait**. | — |
| Grille d’articles configurable | **Non fait** — partiel : articles publics récents, nombre 1–12, extrait ; pas type/termes/auteur/tri/pagination/mises en page. `IT::testLiveContentHasSafeFallbackAndNeverListsDraftPosts`, `IT::testRecentPostsUseOneBoundedSharedQueryAndEscapeTitles`. | Oui, `content.js` |
| Portfolio filtrable | **Non fait**. | — |
| Diaporama | **Non fait**. | — |
| Carrousel de médias | **Non fait**. | — |
| Carrousel de témoignages | **Non fait**. | — |
| Avis | **Non fait**. | — |
| Formulaire | **Non fait — partiel** : formulaire fixe, protections, boîte et purge ; tests locaux `testContactForm…`, `testSubmissionInboxRequiresItsOwnCapabilityAndCsrf` et E2E. Pas de constructeur de champs ni e-mail/fichiers. | Oui, `forms.js` |
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
- **Fichiers et formulaires** : un formulaire fixe enregistre nom, e-mail et message en base, sans IP dans le message, derrière jeton HMAC à usage unique, délai, pot de miel et quotas. Sa boîte exige `prime_builder.forms`, la suppression un CSRF ; les messages sont échappés et une purge est prévue après 30 jours. Tests locaux sur deux CMS/SQLite, puis PHPUnit/MySQL sur les deux CMS et parcours Chromium dans `44965292806`. Ces protections ne garantissent pas l’absence de spam, les sauvegardes peuvent conserver des données au-delà de 30 jours, et le planificateur doit fonctionner. Aucun upload ni e-mail de formulaire n’existe. Le sélecteur média existant est celui du CMS.
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

`SiteView::partial(string $name, array $data = [])` applique `site.partial` dans le CMS 1.0.1 et Aurora appelle les partiels `header` et `footer`. Le nouveau sous-ensemble du plugin utilise ce filtre ; il est couvert par le run `44961832140`. Il ne faut pas prétendre qu’un hook de remplacement d’en-tête manque dans cette version.

## 9. Écarts, bugs connus et vérifications manquantes

- Jalon 2 et suivants incomplets ; les fonctionnalités absentes sont listées aux §§4–5. Aucun équivalent complet d’Elementor + Pro n’est livré.
- L’aperçu n’est partiel que pour une modification isolée et non structurelle ; les autres cas ou une désynchronisation provoquent un rendu complet. Les thèmes sans `.pb-layout` ni `.aurora-prose` utilisent `main` comme repli, ce qui peut être inadapté. Les paramètres avancés et toutes les variantes de déplacement et de navigateur ne sont pas exhaustivement couverts.
- Pour un seul bloc dont le moteur est indisponible, le fragment est déduit si les rendus actuels des autres blocs correspondent aux préfixe et suffixe sauvegardés. Cela suppose que leurs rendus n’ont pas changé : le HTML historique est stocké comme une seule chaîne. Si plusieurs moteurs sont indisponibles ou si la comparaison échoue, le repli choisi conserve tout le HTML en un seul élément non éditable ; le parcours est couvert par le run vert `44956474944`. Les réglages d’origine sont perdus à l’enregistrement et la sortie dynamique figée. Des limites de taille ou une clé `APP_KEY` invalide continuent à bloquer ; extraction de texte et rejeu sont des points à revoir. `source_hash` est facultatif et non atomique.
- La bibliothèque applique `prime_builder.templates` côté serveur à la création, la suppression et la validation d’import, mais une personne autorisée à éditer peut composer un JSON équivalent. Les modèles partagés sont stockés ; les images externes ne sont pas réimportées.
- Le kit et les styles sont limités ; certaines images de galerie/carte n’ont pas de dimensions explicites. Les contenus actuels peuvent être périmés si le script/API est indisponible. Les anciennes URL CSS sans instantané ne sont pas reconstructibles après changement.
- Le formulaire nécessite JavaScript et une API disponible ; sans eux, ou si le plugin est désactivé, il ne peut pas envoyer. Il ne fournit pas de notification e-mail et ne doit pas être présenté comme une solution complète de gestion des données personnelles.
- Le navigateur utilise `localStorage` pour les brouillons, qui n’est ni une sauvegarde serveur ni une garantie de persistance. `beforeunload` reste natif pour certains départs. Les effets des écouteurs de réglages après écriture ne sont pas transactionnels.
- Pas de test de téléversement ZIP dans l’administration, de mise à jour complète, d’audit WCAG manuel, d’audit sécurité indépendant, de mesure réseau/LCP, ni de test de charge ou de compatibilité exhaustive des thèmes/CMS. Le cycle ZIP CLI et la désinstallation ont été testés en CI.
- Les warnings GitHub Actions relatifs à la dépréciation Node.js 20 sont non bloquants ; les actions devront être mises à jour. La CI clone une branche CMS flottante.

## 10. Parcours concret de vérification

1. Sur une **instance jetable** Prime CMS 1.0.2 avec MySQL 8, installer/activer le plugin par une des méthodes du `README.md` ; donner `prime_builder.edit`, les droits de contenu et, selon le cas, `prime_builder.templates` / `prime_builder.settings`. Créer une page et l’enregistrer d’abord dans le CMS.
2. Ouvrir **Prime Builder**, puis **Réglages** ; vérifier pages activées, points de rupture et couleurs, modifier puis remettre les valeurs. Depuis la page du noyau, ouvrir **Modifier avec Prime Builder** et constater l’iframe du thème.
3. Ajouter un conteneur, puis titre, texte, bouton et séparateur. Dans le canevas ou **Structure**, déplacer un élément avant/après un autre ou dans le conteneur ; vérifier les repères de dépôt. Essayer également **Déplacer vers**, **Monter** et **Descendre** au clavier. Changer une taille sur ordinateur puis mobile, vérifier l’indication d’héritage, publier et ouvrir la page publique. Refaire sur une autre page avec la palette `Ctrl/⌘ K` et le clavier. Vérifier au préalable les liens et contenus saisis.
4. Insérer un modèle de section, exporter puis réimporter le JSON ; les compositions de contact restent statiques. Sur une page jetable, ajouter le widget de formulaire, publier, envoyer un message, vérifier et supprimer l’envoi avec `prime_builder.forms`. Vérifier également le comportement sans JavaScript et essayer la liste d’articles avec et sans JavaScript.
5. Sur une copie sauvegardée, ouvrir une page de blocs Prime existants ; vérifier l’ordre et les ancres, puis sauvegarder seulement si la conversion est proposée. Une conversion impossible doit bloquer l’enregistrement.
6. Désactiver le plugin et demander une URL publique fraîche : le HTML sauvegardé doit rester lisible, sans garantie de style/interactivité. Réactiver, puis sur une installation jetable seulement essayer la désinstallation et vérifier réglages/capacités/fichiers et conservation du contenu.
7. Exécuter les commandes du §3 et consulter le dernier run pour la branche. La CI crée ses propres comptes et pages éphémères ; aucun identifiant de production n’a été créé pour ce rapport.


## Progression du jalon B jusqu’au carrousel

### Catalogue de base, CSS, vidéo, galerie et carrousel

La révision `57eea116b5a74c50e3d30ee85877fffbbd016880`, [run 45003137277](https://github.com/HamdiSouissi/prime-builder/actions/runs/45003137277), ajoute icône, liste d’icônes, boîte image, témoignage, bascule, réseaux sociaux et note par étoiles. Les huit jobs sont verts ; cela **ne clôt pas B**.

- Syntaxe : `No syntax errors detected in content/plugins/prime-builder/views/admin/builder.php` ; PHPStan : `[OK] No errors`.
- PHP-CS-Fixer : `Found 0 of 44 files that can be fixed in 1.022 seconds, 86.00 MB memory used`.
- PHPUnit/MySQL sur CMS main et 1.0.1 : `OK (61 tests, 1004 assertions)` pour chaque version.
- Node : `# tests 14`, `# pass 14`, `# fail 0` ; i18n : `Catalogue de traduction cohérent.`
- Smoke : `Smoke: all routes, public content and document CSS passed.` ; E2E : `36 passed (1.8m)` et désactivation/désinstallation réussies. Les E2E ouvrent les sept widgets et leurs trois onglets, et exercent la bascule au clavier avec et sans JavaScript.

Le découpage conditionnel des CSS a ensuite été poussé à `adedba0b0ea79b627521d80eab14f29609c363bd` et vérifié par le [run 45003718933](https://github.com/HamdiSouissi/prime-builder/actions/runs/45003718933), huit jobs verts : syntaxe `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 45 files that can be fixed in 1.006 seconds, 84.00 MB memory used`, PHPUnit/MySQL `OK (63 tests, 1029 assertions)` sur chacune des deux versions CMS, Node `# tests 14` / `# pass 14` / `# fail 0`, i18n `Catalogue de traduction cohérent.`, smoke `Smoke: all routes, public content and document CSS passed.`, Playwright `36 passed (1.8m)`, désactivation et désinstallation réussies. Les tests couvrent les widgets imbriqués, la déduplication, les parties de thème et la feuille injectée dans l’aperçu. Les feuilles communes de structure et de kit restent partagées.

Les lecteurs YouTube/Vimeo à activation explicite sont dans `2a37769cc1ed3341b0e555121dced66a1077631f`, [run 45004152169](https://github.com/HamdiSouissi/prime-builder/actions/runs/45004152169), huit jobs verts : syntaxe `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 45 files that can be fixed in 1.404 seconds, 88.00 MB memory used`, PHPUnit/MySQL `OK (65 tests, 1057 assertions)` pour chaque version CMS, Node `# tests 14` / `# pass 14` / `# fail 0`, i18n `Catalogue de traduction cohérent.`, smoke `Smoke: all routes, public content and document CSS passed.`, Playwright `37 passed (1.8m)`, désactivation et désinstallation réussies. Le parcours surveille les requêtes avant activation et simule ensuite les deux lecteurs externes ; la lecture effective chez les fournisseurs n’a pas été vérifiée. Après clic, ces services peuvent recevoir des données, dont l’origine du site. Sans JavaScript, les liens directs restent disponibles.

La galerie avec lightbox est dans `763b3d01f2ef5c09265b6fbb66beeb1e064054aa`, [run 45004577481](https://github.com/HamdiSouissi/prime-builder/actions/runs/45004577481), huit jobs verts : syntaxe `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 45 files that can be fixed in 0.879 seconds, 88.00 MB memory used`, PHPUnit/MySQL `OK (67 tests, 1077 assertions)` pour chaque version CMS, Node `# tests 14` / `# pass 14` / `# fail 0`, i18n `Catalogue de traduction cohérent.`, smoke `Smoke: all routes, public content and document CSS passed.`, Playwright `38 passed (2.1m)`, désactivation et désinstallation réussies. L’E2E ouvre le dialogue au clavier, vérifie le piège du focus, les flèches, Échap, la restitution du focus et le repli en liens sans JavaScript ; axe ne signale pas de violation sérieuse/critique sur cet état. Les images et leur description sont encore saisies comme liste d’URL.

Le carrousel d’images est dans `38901ab0096524518ff77271aea54f455ebb5f4b`, [run 45005022349](https://github.com/HamdiSouissi/prime-builder/actions/runs/45005022349), huit jobs verts : syntaxe `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 45 files that can be fixed in 1.039 seconds, 88.00 MB memory used`, PHPUnit/MySQL `OK (69 tests, 1098 assertions)` pour chacune des deux versions CMS, Node `# tests 14` / `# pass 14` / `# fail 0`, i18n `Catalogue de traduction cohérent.`, smoke `Smoke: all routes, public content and document CSS passed.`, Playwright `39 passed (2.1m)`, désactivation et désinstallation réussies. Le parcours contrôle les boutons, les flèches, le focus et le repli sans JavaScript ; le carrousel ne tourne pas automatiquement.

### Zone du thème : première CI rouge

La révision `ade3ef362668128cad38cc9acdd19e62ac01b40c`, [run 45006537790](https://github.com/HamdiSouissi/prime-builder/actions/runs/45006537790), a sept jobs verts et `e2e` rouge : `40 passed (2.3m)`, un échec. Le scénario de zone a atteint l’assertion d’unicité des identifiants, mais sa fonction `evaluateAll` référençait `searchId` sans le transmettre au contexte de la page (`ReferenceError: searchId is not defined`). Les étapes de désactivation et désinstallation ont été sautées. Sur chaque CMS, PHPUnit/MySQL affiche `OK (73 tests, 1175 assertions)` ; PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 46 files that can be fixed in 1.473 seconds, 88.00 MB memory used`, Node `# tests 14` / `# pass 14` / `# fail 0`, i18n et smoke sont verts. La correction transmet explicitement l’identifiant à l’évaluation navigateur ; elle doit être revue dans un run ultérieur.

### Zone du thème : correction du test et CI verte

La révision `b22f761017e5738ecd551aac8d004fdc9fca73c4`, [run 45006872816](https://github.com/HamdiSouissi/prime-builder/actions/runs/45006872816), est `completed` / `success` avec les huit jobs verts. `lint` : `No syntax errors detected in content/plugins/prime-builder/views/admin/builder.php` et `[OK] No errors` ; `cs` : `Found 0 of 46 files that can be fixed in 1.512 seconds, 88.00 MB memory used` ; chaque `phpunit` sur MySQL : `OK (73 tests, 1175 assertions)` ; `js` : `# tests 14`, `# pass 14`, `# fail 0` ; `i18n` : `Catalogue de traduction cohérent.` ; `smoke` : `Smoke: all routes, public content and document CSS passed.` ; `e2e` : `41 passed (2.1m)`, puis désactivation et désinstallation réussies. Le test de zone couvre la mise à jour et les références d’identifiants, avec axe et un contexte sans JavaScript ; la zone reste partielle (ressources des widgets imbriqués et partials non traités).

### Appel à l’action et prix

La révision `da1def996362acaa335cdb15665d567db3da37c3`, [run 45042052075](https://github.com/HamdiSouissi/prime-builder/actions/runs/45042052075), est `completed` / `success` avec huit jobs verts. `lint` : `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` : `Found 0 of 46 files that can be fixed in 1.492 seconds, 90.00 MB memory used` ; chaque PHPUnit/MySQL : `OK (78 tests, 1239 assertions)` ; `js` : `# tests 14`, `# pass 14`, `# fail 0` ; `i18n` : `Catalogue de traduction cohérent.` ; `smoke` : `Smoke: all routes, public content and document CSS passed.` ; `e2e` : `42 passed (2.5m)`, puis désactivation et désinstallation réussies. Un parcours CMS couvre les onglets de réglage, le focus, axe et le repli sans JavaScript ; les prix sont des textes d’affichage, pas un système de paiement.

### Formulaire de recherche

La révision `e34dc8a9c2fed3cc1190e68f359c9073c1d2b061`, [run 45043366114](https://github.com/HamdiSouissi/prime-builder/actions/runs/45043366114), est `completed` / `success` avec les huit jobs verts. `lint` : `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` : `Found 0 of 46 files that can be fixed in 1.446 seconds, 90.00 MB memory used` ; chacun des PHPUnit/MySQL : `OK (80 tests, 1280 assertions)` ; `js` : `# tests 14`, `# pass 14`, `# fail 0` ; `i18n` : `Catalogue de traduction cohérent.` ; `smoke` : `Smoke: all routes, public content and document CSS passed.` ; `e2e` : `43 passed (2.3m)`, puis désactivation et désinstallation réussies. Le parcours ouvre l’éditeur et le site, utilise la recherche du CMS avec et sans JavaScript et change le préfixe réel par l’API CMS en vérifiant saisie, focus, axe et mobile. Hors ligne ou sans JS, l’adresse sauvegardée peut être obsolète ; les partials ne sont pas pris en charge.

### Grille : première vérification échouée

La révision `c2e2f38360f31c179b7d5938f374955ffba08901`, [run 45045178101](https://github.com/HamdiSouissi/prime-builder/actions/runs/45045178101), est `completed` / `failure` : sept jobs verts, dont chacun des PHPUnit/MySQL `OK (84 tests, 1360 assertions)`, PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 47 files that can be fixed in 1.036 seconds, 90.00 MB memory used`, Node 14/14, i18n et smoke verts. Playwright : `43 passed (3.4m)` et un échec par dépassement du délai sur `getByLabel('Pagination', { exact: true })`. Le nom accessible du contrôle est bien « Pagination », mais le sélecteur Playwright exact par libellé englobe aussi le texte des options ; le test a été corrigé pour cibler le rôle `combobox` et son nom accessible. Désactivation et désinstallation n’ont pas été exécutées dans ce run.

### Grille : correction et CI verte

La révision `6b0c37eef31460ee3dddf899fcd93c1359d8641f`, [run 45045723884](https://github.com/HamdiSouissi/prime-builder/actions/runs/45045723884), est `completed` / `success` avec huit jobs verts. `lint` : `No syntax errors detected in content/plugins/prime-builder/views/admin/builder.php` et `[OK] No errors` ; `cs` : `Found 0 of 47 files that can be fixed in 1.585 seconds, 90.00 MB memory used` ; chacun des PHPUnit/MySQL : `OK (84 tests, 1360 assertions)` ; `js` : `# tests 14`, `# pass 14`, `# fail 0` ; `i18n` : `Catalogue de traduction cohérent.` ; `smoke` : `Smoke: all routes, public content and document CSS passed.` ; `e2e` : `44 passed (2.0m)`, puis désactivation et désinstallation réussies. Les tests comptent dans le fournisseur 2 requêtes pour un ou douze articles paginés et 4 pour un ou douze résultats de pages à deux niveaux d’ancêtres, avec un générateur d’URL neuf et des permaliens en mémoire : HTTP, authentification et lecture des réglages ne sont pas inclus. Le parcours CMS vérifie configuration, filtres, tri, pagination au clavier, mise en page, axe, mobile et repli sans JS. Les limites de ce repli et des pages inaccessibles sont dans `RETOUR.md` et `README.md`.

### Rapport de la grille

La révision `07dd91f8a07601422834d0fd2b8eb0250d61757a`, qui ne modifie que `RETOUR.md` et cet historique, est `completed` / `success` sur [45046152527](https://github.com/HamdiSouissi/prime-builder/actions/runs/45046152527), avec les huit jobs verts. Ce run ne comprend pas le compte à rebours développé ensuite.

### Compte à rebours UTC

La révision `0e95497dc5d76fb28b1077f0d058e0a8823b73c9`, [run 45047142483](https://github.com/HamdiSouissi/prime-builder/actions/runs/45047142483), est `completed` / `success` avec huit jobs : `lint` — `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` — `Found 0 of 48 files that can be fixed in 1.577 seconds, 94.00 MB memory used` ; les deux PHPUnit/MySQL — `OK (87 tests, 1439 assertions)` ; Node — `# tests 17`, `# pass 17`, `# fail 0` ; i18n — `Catalogue de traduction cohérent.` ; smoke — `Smoke: all routes, public content and document CSS passed.` ; Playwright — `45 passed (2.4m)`, désactivation et désinstallation réussies. Le navigateur CMS vérifie la saisie UTC, l’horloge simulée à Honolulu, l’expiration, axe, le mobile et le repli sans JavaScript ; les tests PHP vérifient aussi les ressources dédupliquées dans les parties de thème. Les valeurs dépendent de l’horloge du visiteur ; sans JS seule l’échéance enregistrée reste affichée.

### Rapport du compte à rebours

La révision `009a76158c5e3de4da6bf76ad0ede5e098434300`, qui ne modifie que `RETOUR.md` et cet historique, est `completed` / `success` sur [45047540453](https://github.com/HamdiSouissi/prime-builder/actions/runs/45047540453), avec huit jobs verts. Ce run ne comprend pas les widgets avis et boîte retournable développés ensuite.

### Avis et boîte retournable

La révision `a0fae2a001c2251b6d12858fc5c4d62ef8b2a677`, [run 45048321515](https://github.com/HamdiSouissi/prime-builder/actions/runs/45048321515), est `completed` / `success` avec huit jobs : `lint` — `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` — `Found 0 of 48 files that can be fixed in 1.661 seconds, 94.00 MB memory used` ; les deux PHPUnit/MySQL — `OK (90 tests, 1508 assertions)` ; Node — `# tests 19`, `# pass 19`, `# fail 0` ; i18n — `Catalogue de traduction cohérent.` ; smoke — `Smoke: all routes, public content and document CSS passed.` ; Playwright — `46 passed (2.0m)` ; désactivation et désinstallation réussies. Le navigateur CMS teste édition, aperçu, clavier, mouvement réduit, axe, mobile et repli sans JS. Une fixture isolée a été examinée à 1440 et 375 px et vérifie également les collisions d’ID et la restitution du focus ; elle ne remplace pas le parcours CMS. L’avis est éditorial et son authenticité n’est pas vérifiée.

### Rapport des avis et de la boîte retournable

La révision `3a64aaaa05fb9be1611dae6ed5eb5e021fac2364`, qui consigne les résultats de l’étape précédente, est `completed` / `success` sur [45048675511](https://github.com/HamdiSouissi/prime-builder/actions/runs/45048675511), avec huit jobs verts. Ce run ne comprend pas les widgets titre animé et code développés ensuite.

### Titre animé et code — première CI rouge

La révision `4119c972f3c92bc29d053edd682c858a29d2bd02` a échoué sur [45049953250](https://github.com/HamdiSouissi/prime-builder/actions/runs/45049953250) : sept jobs sont verts, dont les deux PHPUnit/MySQL `OK (93 tests, 1606 assertions)` et Node `# tests 19` / `# pass 19` / `# fail 0`. Playwright indique `13 failed`, `34 passed (2.4m)` : les anciens sélecteurs non exacts « Ajouter Titre » correspondent aussi au nouveau « Ajouter Titre animé » ; le sélecteur de favoris est également ambigu. La correction rend les sélecteurs de l’ancien titre exacts, sans supprimer de test. Nouvelle CI nécessaire.

### Titre animé et code — correction vérifiée

La correction `0fd5ee62cdf6a87b03d90f5de8b4aee5abda37a7`, [run 45050382272](https://github.com/HamdiSouissi/prime-builder/actions/runs/45050382272), est `completed` / `success` avec huit jobs : `lint` — `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` — `Found 0 of 49 files that can be fixed in 0.950 seconds, 94.00 MB memory used` ; les deux PHPUnit/MySQL — `OK (93 tests, 1606 assertions)` ; Node — `# tests 19`, `# pass 19`, `# fail 0` ; i18n — `Catalogue de traduction cohérent.` ; smoke — `Smoke: all routes, public content and document CSS passed.` ; Playwright — `47 passed (2.8m)` ; désactivation et désinstallation réussies. La CI teste l’édition, l’aperçu, l’échappement du code, le clavier, axe, le mobile, le mouvement réduit et le repli sans JS. Une fixture isolée a aussi été examinée à 1440 et 375 px. La coloration est bornée et volontairement légère ; elle ne remplace pas un analyseur syntaxique complet.

### Rapport du titre animé et du code

La révision `e4a54aa87ffaba1be12da9f30064663de44670a5`, qui consigne les résultats de l’étape précédente, est `completed` / `success` sur [45050793706](https://github.com/HamdiSouissi/prime-builder/actions/runs/45050793706), avec huit jobs verts. Ce run ne comprend pas le carrousel de témoignages développé ensuite.

### Carrousel de témoignages

La révision `9befec2c066a7361aed8b1279320db11834ca392`, [run 45052644807](https://github.com/HamdiSouissi/prime-builder/actions/runs/45052644807), est `completed` / `success` avec huit jobs : `lint` — `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` et `[OK] No errors` ; `cs` — `Found 0 of 50 files that can be fixed in 1.341 seconds, 94.00 MB memory used` ; les deux PHPUnit/MySQL — `OK (95 tests, 1667 assertions)` ; Node — `# tests 19`, `# pass 19`, `# fail 0` ; i18n — `Catalogue de traduction cohérent.` ; smoke — `Smoke: all routes, public content and document CSS passed.` ; Playwright — `48 passed (2.7m)` ; désactivation et désinstallation réussies. Le parcours CMS teste l’édition et l’aperçu, le clavier, axe, le mobile et le repli sans JavaScript. Le téléchargement des artefacts vers cet environnement échoue (hôte de stockage inaccessible). Une fixture isolée a été examinée à 1440/375 px ; les captures du vrai CMS ont été récupérées ensuite, voir ci-dessous.

### Captures du carrousel de témoignages

Les révisions `013756b6f9205818ba01bf2d0ae87b693deec92a` ([45053716458](https://github.com/HamdiSouissi/prime-builder/actions/runs/45053716458)) et `a7ac53a45ebe4253d9ad5a5348bf1e98c7d3bb01` ([45054198207](https://github.com/HamdiSouissi/prime-builder/actions/runs/45054198207)) ont chacune huit jobs verts. Sur le second run : PHPStan `[OK] No errors` ; PHP-CS-Fixer `Found 0 of 50 files that can be fixed in 0.909 seconds, 94.00 MB memory used` ; chacun des PHPUnit/MySQL `OK (95 tests, 1667 assertions)` ; Node `# tests 19`, `# pass 19`, `# fail 0` ; i18n `Catalogue de traduction cohérent.` ; smoke `Smoke: all routes, public content and document CSS passed.` ; Playwright `48 passed (2.7m)` ; désactivation et désinstallation réussies. Des annotations temporaires ont permis de reconstituer les JPEG : aperçu éditeur à 1440 px du premier run et page publique à 1440/375 px du second, examinés visuellement. La capture mobile de l’éditeur du premier run ne montre pas le widget, hors champ ; elle ne prouve donc pas son rendu. Ces captures proviennent d’un parcours automatisé, non d’une interaction manuelle.

### Carrousel de médias — première CI rouge

La révision `1edc68f5cade2ee62f339bb72448b3c11303d9a2` a échoué sur [45055808483](https://github.com/HamdiSouissi/prime-builder/actions/runs/45055808483) : sept jobs verts, dont PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 51 files that can be fixed in 1.663 seconds, 96.00 MB memory used`, chacun des PHPUnit/MySQL `OK (97 tests, 1728 assertions)`, Node `# tests 22`, `# pass 22`, `# fail 0`, i18n `Catalogue de traduction cohérent.` et smoke `Smoke: all routes, public content and document CSS passed.`. Playwright : `1 failed`, `48 passed (2.6m)` ; le test attendait encore l’ancien texte alternatif « Icône Prime Builder », alors que la donnée saisie était devenue « Paysage abstrait ». Le sélecteur a été corrigé sans retirer d’assertion ; la lecture réelle et la mise en pause de la vidéo doivent encore passer en CI. Les étapes de désactivation et de désinstallation n’ont pas été exécutées dans ce run.
