# Retour de mission — Prime Builder 0.1.0

## État actuel

**Dernier jalon terminé : A — corrections**, accepté par le demandeur à `2a02837e7062d18cd9933363c11a75c1a6e94c90`. Son [run 44965923577](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965923577) est `completed` / `success`. L’historique détaillé des révisions et des runs est archivé dans [docs/HISTORIQUE-CI.md](docs/HISTORIQUE-CI.md). Un rapport ne peut pas contenir son propre SHA ni les résultats futurs de sa CI : consulter le run associé à la tête de la branche.

| Job | Ligne finale observée sur `44965923577` |
|---|---|
| `lint` | `No syntax errors detected in content/plugins/prime-builder/.php-cs-fixer.dist.php` ; `[OK] No errors` |
| `cs` | `Found 0 of 44 files that can be fixed in 0.814 seconds, 84.00 MB memory used` |
| `phpunit (main)` | `OK (52 tests, 936 assertions)` sur MySQL |
| `phpunit (1.0.1)` | `OK (52 tests, 936 assertions)` sur MySQL |
| `js` | `# tests 14` ; `# pass 14` ; `# fail 0` |
| `i18n` | `Catalogue de traduction cohérent.` |
| `smoke` | `Smoke: all routes, public content and document CSS passed.` |
| `e2e` | `34 passed (1.8m)` ; désactivation et désinstallation réelles réussies |

Le relecteur indique séparément, sur CMS 1.0.2 et MySQL, `OK (52 tests, 903 assertions)` et l’ouverture des écrans Theme Builder et Envois sans erreur console. Ce résultat est attribué au relecteur, distinct de la CI ci-dessus.

**Reste :** B catalogue complet, puis C widgets globaux/dynamique, D Theme Builder, E formulaires complets, F popups et G finitions. B est en cours : sept widgets de base et leurs tests ont été poussés et vérifiés par la CI ci-dessous. Les lecteurs YouTube/Vimeo à activation explicite sont verts en CI. La galerie, sa lightbox et le carrousel sont verts en CI. La carte OpenStreetMap est en cours ; la zone de thème reste à faire. Les feuilles propres aux widgets ont été séparées et leur chargement conditionnel vérifié par la CI ci-dessous. Le formulaire, le widget HTML et le Theme Builder actuels ne sont que des premiers sous-ensembles. Le plugin reste partiel et n’est pas prêt pour la production.

## Jalon A — corrections : terminé

Révision et run : `2a02837e7062d18cd9933363c11a75c1a6e94c90`, [44965923577](https://github.com/HamdiSouissi/prime-builder/actions/runs/44965923577) ; les huit jobs et leurs lignes finales sont donnés ci-dessus. Les tests vérifient conversion et repli convenu, 403/succès des capacités, dialogue, styles et parcours complet du menu au clavier. Les captures du formulaire public et de sa boîte ont été examinées à 1440 et 375 px sur une fixture locale SQLite ; la CI teste aussi MySQL. Les audits automatisés et captures ne constituent pas un audit manuel complet WCAG ou sécurité.

La limite API générale est augmentée uniquement dans la fixture E2E jetable ; les limites propres au formulaire sont testées séparément. Sous Prime CMS 1.0.1, l’adaptateur HTTP est actif seulement tant que le plugin est chargé et que l’alias n’est pas remplacé ; 1.0.2 est recommandé. Si les frontières des anciens blocs sont indéductibles, leur HTML est conservé dans un seul élément en lecture seule : réglages originaux perdus et rendu dynamique figé.

## Jalon B — en cours, sept widgets de base

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

## Prochaines étapes et vérifications

- **B** : base, Pro, puis contenu courant ; un test PHPUnit par widget, interaction clavier/ARIA et ressources conditionnelles. La grille doit prouver l’absence de N+1 par comptage des requêtes.
- **C** : widgets globaux synchronisés, balises dynamiques et conditions.
- **D** : modèles et conditions complets ; remplacer les identifiants exclus saisis manuellement par un sélecteur à recherche et étiquettes accessibles, raccourcir le libellé du menu et distinguer les trois icônes.
- **E** : constructeur de champs, fichiers privés, étapes, e-mails en file, recherche/export et rétention réglable. La base actuelle est un formulaire fixe sans e-mail ni fichier ; sa purge à 30 jours dépend du planificateur.
- **F/G** : popups accessibles et finitions, documentation et version finale. Ne pas commencer un jalon avant que le précédent soit vert.

Commandes reproductibles et fixtures jetables : voir [`docs/developpement.md`](docs/developpement.md) et [`.github/workflows/ci.yml`](.github/workflows/ci.yml). Les tests publient et suppriment des données ; ne jamais viser une base de production.

## 4. Tableau de conformité des sections 3 à 7

Statuts demandés : **Fait et testé**, **Fait sans test automatique**, **Non fait**. Une ligne « Non fait — partiel » signifie qu’une partie existe et est nommée, mais que la puce entière n’est pas remplie. Les tests cités sont dans `tests/DocumentTest.php` (DT), `tests/IntegrationTest.php` (IT), `tests/CatalogTest.php` (CT), `tests/js/model.test.js` (JS) ou `tests/e2e/editor.spec.mjs` (E2E). Les chemins principaux sont indiqués dans chaque ligne.

| Point de la spécification | Statut et preuve ou reste | Fichiers principaux |
|---|---|---|
| 3.1 Produit complet et ergonomie | **Non fait** — partiel : interface originale et socle ; pas l’ensemble des fonctions demandées. | `views/admin/`, `assets/`, `src/` |
| 3.2.1 Activation par type | **Fait et testé** : pages par défaut, autres types réglables ; `IT::testActivationRegistersEditorBlockAndPageFilter`, `IT::testSettingsBundleIsAtomicAndReadsOlderIndividualKeys`. | `src/Plugin.php`, `src/Controller/Settings.php` |
| 3.2.2 Écran du noyau, aperçu et textarea | **Fait et testé** : `IT::testActivationRegistersEditorBlockAndPageFilter`, E2E `the core content screen embeds the alternative editor on desktop and mobile`. | `views/admin/alternate.php`, `assets/css/alternate.css` |
| 3.2.3 Éditeur plein écran et iframe du thème | **Fait et testé** : E2E `compose and publish a page using the CMS theme`, IT `testEditorAndTemplateAccessHaveSeparateCapabilities`. L’adaptation à tous les thèmes reste à vérifier. | `src/Controller/Documents.php`, `views/admin/builder.php` |
| 3.2.4 Document Prime, validation et repli | **Fait et testé** : `DT::testValidTreeAndCss`, `IT::testApiPersistsRenderedHtmlAndHashedCss`, `IT::testDeactivationKeepsReadableHtmlAndUninstallCleansSettingsAndCapabilities`. Conversion existante limitée, voir « Limites techniques et sécurité ». | `src/Document/`, `src/Widgets/` |
| 3.2.5 API, CSRF, révisions et statuts | **Fait et testé** pour les parcours implémentés : `IT::testOwnerChecksAndSessionCsrf`, E2E `keyboard reparenting, favorites, commands and scheduling` et test de publication. Le planificateur du CMS reste nécessaire. | `src/Controller/Documents.php` |
| 3.2.6 CSS par contenu et kit, survol et animations | **Non fait** — partiel : empreintes, responsive et instantanés testés par `IT::testHistoricalStylesRemainImmutableAndStillRespectVisibility` ; survol et animations absents. | `src/Styles/`, `src/Plugin.php` |
| 3.2.7 JS public à la demande | **Fait et testé** pour les widgets présents : `IT::testInteractiveScriptIsLoadedOnlyForPagesWithTabs`, `IT::testLiveContentHasSafeFallbackAndNeverListsDraftPosts`. Échantillon documenté dans `docs/HISTORIQUE-CI.md`, pas de mesure réseau globale. | `src/Plugin.php`, `assets/js/{tabs,content}.js` |
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
| 4 Sécurité | **Non fait** — partiel : validation, CSRF, droits et cas malveillants testés ; fonctions absentes et audit indépendant non couverts, voir « Limites techniques et sécurité ». | `src/Document/Validator.php`, `src/Controller/` |
| 4 Performance | **Non fait** — partiel : scripts conditionnels et une requête partagée pour les articles ; pas de budget réseau complet, cache global complet, ni dimensions sur toutes les images de galerie/carte. | `src/Widgets/Content.php`, `src/Plugin.php` |
| 4 Accessibilité | **Non fait** — partiel : axe sérieux/critique vert sur les scénarios indiqués ; pas d’audit WCAG 2.2 AA complet. | `tests/e2e/editor.spec.mjs`, `assets/` |
| 4 Robustesse/désactivation | **Fait et testé** pour les cas couverts : `DT::testCrashingExtensionDoesNotBreakOtherElements`, IT et parcours CLI de désactivation ; pas de garantie universelle pour les extensions ou thèmes. | `src/Widgets/Renderer.php`, `tests/e2e/lifecycle-check.php` |
| 5 PHPUnit | **Non fait** — partiel : validateurs, rendu, CSS, accès et cycle de vie couverts ; tests de formulaire fixe verts sur MySQL dans `44965292806` ; conditions de thème complètes, uploads et balises absents. | `tests/*.php` |
| 5 JavaScript | **Non fait** — partiel : modèle, historique et responsive ; pas de tests unitaires de toutes les interactions et raccourcis. | `tests/js/model.test.js` |
| 5 Playwright + axe | **Non fait** — partiel : construction souris/clavier, import et premier thème statique ; formulaire fixe et clavier intégral passés localement et en CI ; popup absent. | `tests/e2e/` |
| 5 PHPStan 8 et PHP-CS-Fixer | **Fait et testé** sur le PHP analysé/configuré, résultats exacts dans « État actuel ». PHPStan vise `src`, `views`, `lang`, `migrations`, `tests`, `tools` et la configuration du fixer ; le lint et le fixer couvrent les fichiers PHP du plugin. | `phpstan.neon`, `.github/workflows/ci.yml` |
| 5 CI complète | **Non fait** — partiel : huit jobs indépendants et artefact navigateur ; couverture fonctionnelle complète impossible tant que les fonctions manquent. | `.github/workflows/ci.yml` |
| 6 Livrables et version | **Non fait** — partiel : structure, langues, tests et docs présents ; version 0.1.0 et pas les modules manquants ni gabarits d’e-mail demandés. | `plugin.json`, `CHANGELOG.md`, `src/`, `views/` |
| 6 Filtre de widgets tiers | **Non fait** — partiel : filtre, schéma, rendu et script local conditionnel existent ; `DT::testCrashingExtensionDoesNotBreakOtherElements` et JS `extension-defined containers support nesting and safe reparenting` couvrent des cas limités, pas un plugin tiers complet avec son script de bout en bout. Un callback PHP tiers est du code de confiance. | `src/Widgets/Registry.php`, `docs/developpement.md` |
| 7.1 Présentation et captures | **Non fait** — partiel : présentation présente ; captures finales dans le README absentes, Theme Builder incomplet. | `README.md` |
| 7.2 Prérequis | **Fait et testé, avec réserve** : le manifeste déclare `>=1.0.1`, avec adaptation HTTP limitée pour le défaut de jeton de 1.0.1 ; PHPUnit/MySQL sur les deux versions et limites dans « Limites techniques et sécurité ». | `README.md`, `plugin.json`, `src/Security/` |
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
| Vidéo fichier/YouTube/Vimeo sans suivi | **Fait et testé** pour fichier et lecteur distant à activation explicite : `DT::testExternalVideoProvidersHaveInertAccessibleFallbacksBeforeActivation`, IT assets et E2E dans `45004152169`. Les fournisseurs sont simulés ; après clic ils peuvent recevoir des données. | Oui, `video.js` si vidéo |
| Bouton | **Fait et testé** : texte, lien ; D, E2E `build and publish four widgets with the mouse`. | Non |
| Séparateur | **Fait et testé** : élément simple, styles communs ; D, E2E quatre widgets. | Non |
| Espaceur | **Fait et testé** : styles communs, sans réglage spécifique ; D, P. | Non |
| Icône | **Fait et testé** pour la version simple : icône seule et description accessible ; `DT::testIconRendersAnAccessibleOrDecorativeSymbol` dans `45003137277`. | Non |
| Boîte icône | **Fait et testé** pour la version simple : quatre symboles, titre, texte ; D, P. | Non |
| Boîte image | **Fait et testé** pour la version simple : image, dimensions, titre, texte et lien ; `DT::testImageBoxRendersSafeImageDimensionsContentAndLink` dans `45003137277`. | Non |
| Liste d’icônes | **Fait et testé** pour la version simple : symboles décoratifs et lignes textuelles ; `DT::testIconListRendersEscapedItemsWithDecorativeIcons` dans `45003137277`. | Non |
| Compteur | **Fait et testé** pour une valeur statique : valeur, préfixe, suffixe, titre ; D, P. Pas d’animation. | Non |
| Barre de progression | **Fait et testé** : libellé, valeur 0–100, `<progress>` ; D, P. | Non |
| Témoignage | **Fait et testé** pour la version simple : citation, auteur, fonction et portrait ; `DT::testTestimonialRendersQuotationAttributionAndOptionalPortrait` dans `45003137277`. | Non |
| Onglets | **Fait et testé** pour 2–3 panneaux : titres et textes ; E2E `tabs work with keyboard and remain readable without JavaScript`, `DT::testNewWidgetsProduceSafeReadableFallbacks`. | Oui, `tabs.js` |
| Accordéon | **Fait et testé** pour un `<details>` : titre, texte ; `DT::testNewWidgetsProduceSafeReadableFallbacks`, P. | Non |
| Bascule | **Fait et testé** pour la version simple : `<details>` natif, ouvert/fermé ; `DT::testToggleUsesNativeDetailsWithAnOptionalOpenInitialState` et E2E clavier dans `45003137277`. | Non |
| Alerte | **Fait et testé** pour texte filtré simple ; D, P. | Non |
| Réseaux sociaux | **Fait et testé** pour la version simple : navigation nommée et cinq liens ; `DT::testSocialLinksUseNamedNavigationAndRejectUnsafeUrls` dans `45003137277`. | Non |
| Carte OpenStreetMap | **Non fait — en cours** : coordonnées, chargement explicite, lien de repli et CSP ciblée ajoutés ; CI à observer. | Oui, `map.js` si carte |
| Note par étoiles | **Fait et testé** pour la version simple : 0–5 étoiles et libellé accessible ; `DT::testStarRatingRendersOneAccessibleLabelAndFiveVisualStars` et test de localisation dans `45003137277`. | Non |
| Galerie et lightbox | **Fait et testé** pour 20 URL/alternatifs : liens de repli, dialogue et navigation clavier ; `DT::testGalleryRendersAccessibleImageLinksAsNoScriptFallback`, IT assets et E2E dans `45004577481`. | Oui, `gallery.js` si galerie |
| Carrousel d’images | **Fait et testé** : images sans rotation automatique, commandes clavier et repli sans JavaScript ; `DT::testImageCarouselRendersEverySlideWithoutJavaScript`, IT assets et E2E dans `45005022349`. | Oui, `image_carousel.js` si carrousel |
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

## Limites techniques et sécurité

- L’éditeur reste partiel (§3.3) : CSS et contrôles avancés, mode aperçu séparé, certains comportements de glisser-déposer et l’accessibilité exhaustive manquent. L’aperçu peut se replier sur `main` pour un thème différent d’Aurora.
- `source_hash` est facultatif et non atomique ; les sauvegardes concurrentes peuvent encore se perdre. La clé `APP_KEY` est nécessaire aux fragments historiques et aux signatures HTML ; sa rotation les invalide.
- Le formulaire fixe protège les envois par HMAC, délai, jeton unique, pot de miel et quotas ; il ne garantit pas l’absence de spam. Les sauvegardes et obligations de confidentialité restent à la charge de l’opérateur.
- Un élément masqué reste dans le HTML. Une CSS déjà mise en cache publiquement ne peut pas être révoquée immédiatement. Les extensions PHP sont de confiance.
- Les méthodes de validation de bloc du CMS ne reçoivent pas le contexte de l’entrée et `EntryService::update()` ne fournit pas de compare-and-swap ; les limites et propositions détaillées restent dans l’archive. Aucun code du noyau n’a été modifié.
- Pas de test de mise à jour complète ni de téléversement ZIP par interface, d’audit de sécurité indépendant, d’audit WCAG complet, de mesure réseau/LCP ou de test de charge. La CI clone `main` du CMS, qui peut évoluer.
