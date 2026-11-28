# Prime Builder — Reprise par un nouvel agent

Tu reprends un projet que l'agent précédent n'a pas réussi à mener à bien. Ce document est ta
**feuille de route**. Hamdi t'envoie aussi `PRIME-BUILDER-prompt.md`, le **cahier des charges
complet**, qui fait foi pour tout ce qui concerne le produit. Lis les deux en entier avant d'écrire
une ligne.

- CMS, en **lecture seule** : https://github.com/HamdiSouissi/prime-cms (branche `main`, version 1.0.0).
- Plugin : https://github.com/HamdiSouissi/prime-builder. Pars de la branche
  `arena/4bd469b1-prime-builder` (dernier commit `ecc4213`) et pousse ton travail sur **la branche que
  ta plateforme t'attribue**.

---

## 1. Pourquoi tu es là

L'agent précédent a fait trois livraisons, toutes refusées :
1. Le plugin ne se chargeait pas : erreur de syntaxe PHP, API du CMS inventées.
2. Tous les écrans d'administration et l'éditeur renvoyaient une erreur 500. PHPUnit affichait
   4 erreurs et 0 assertion. L'éditeur affichait du texte brut au lieu de la page.
3. **Son rapport annonçait des corrections qui n'existaient pas.** Le commit contenait un seul
   fichier (`tests/smoke.sh`). Les correctifs des erreurs 500, le chargeur de classes des tests et la
   nouvelle CI n'avaient jamais été poussés.

C'est pourquoi le projet est confié à un nouvel agent, et c'est pourquoi les règles de la section 2 ne
se négocient pas.

## 2. Règles de confiance (une seule infraction suffit à refuser la livraison)
1. **Rien n'existe tant que ce n'est pas poussé.** Avant chaque compte rendu, lance
   `git status` (il doit être propre), puis `git log origin/<ta-branche> -1` et
   `git show --stat <sha>`. Le relecteur fait exactement la même chose.
2. **Rien ne passe tant que tu ne l'as pas vu passer.** Pour chaque outil, recopie dans `RETOUR.md` la
   ligne finale exacte (par exemple `OK (57 tests, 312 assertions)` ou `[OK] No errors`) et le lien du
   run de CI correspondant.
3. **Aucune API inventée.** Chaque appel au CMS doit correspondre à une méthode que tu as ouverte dans
   `src/` du CMS. `content/plugins/prime-starter/` montre l'usage correct de chaque point d'extension.
4. **Pas de contournement** : pas de `@phpstan-ignore`, pas de baseline, pas de test sauté ou
   désactivé, pas de job de CI en `continue-on-error`.
5. **Tu exécutes le CMS.** Un écran qui n'a pas été ouvert n'est pas livré.

## 3. Ce que tu trouves dans la branche
Environ 4 200 lignes, de qualité inégale. Ce que tu peux garder après relecture :
- `DocumentValidator`, `CssCompiler` (PHPStan niveau 8 est vert) ;
- les traductions ;
- la migration ;
- les 9 modèles JSON de `templates/`.

Défauts connus, à corriger en premier :
- `AdminController.php:209` et `EditorController.php:56` appellent `$this->context->url('')`.
  `PluginContext::url()` exige le chemin d'un fichier et lève une exception sur une chaîne vide :
  **c'est la cause des erreurs 500.**
- `tests/PrimeBuilderTest.php` ne charge pas les classes du plugin. Fais comme
  `prime-starter/tests/PrimeStarterTest.php` (`ApiTestFixture`, `PluginManager` ou `require_once`).
- PHP-CS-Fixer est rouge : lance-le avec la configuration du CMS (`.php-cs-fixer.dist.php`) en passant
  le chemin du plugin.
- `php bin/prime lang:check --strict` signale 29 erreurs : textes en dur dans
  `views/editor/workspace.php` et `views/mail/*.php`, entre autres.
- L'éditeur (`assets/js/editor/*.js`, environ 420 lignes) est un brouillon : le canevas n'est pas une
  iframe, il n'y a pas de glisser-déposer, pas de contrôles Style et Avancé. **Réécris-le** selon la
  section 3.3 du cahier des charges.
- Il manque tous les widgets Pro, les popups réelles (`popup.js` n'est utilisé par rien), le
  constructeur de formulaires et les tests e2e.

Tu as le droit de réécrire n'importe quel fichier. Le code existant n'a aucune valeur s'il ne passe
pas les tests.

---

## 4. Méthode : 6 jalons, dans l'ordre, dans cette même session
Tu enchaînes les jalons sans attendre de validation. Mais **tu ne commences un jalon que lorsque le
précédent est vert en CI.** À la fin de chaque jalon :
1. commit et push ;
2. attente de la CI : `gh run watch`, ou `gh run view --log-failed` pour lire un échec ;
3. ajout dans `RETOUR.md` du SHA, du lien du run et de l'état de chaque job.

### Jalon 0 — Environnement et CI
1. Installe PHP 8.2 ou plus, ses extensions et MariaDB :
   `apt-get update && apt-get install -y php-cli php-mbstring php-mysql php-intl php-gd php-xml php-curl mariadb-server`.
   À défaut, utilise `npx -y @php-wasm/cli@latest`, et le job `smoke` de la CI pour la base.
2. Clone le CMS, copie le plugin dans `content/plugins/prime-builder/`, installe le CMS en ligne de
   commande, active le plugin et lance le serveur :
   `php -S 127.0.0.1:8091 -t public public/index.php`.
3. Écris `tests/smoke.sh` : connexion admin avec `curl` (cookie et `_csrf_token` de `/admin/login`),
   puis **200 obligatoire** sur chaque route admin du plugin, sur l'éditeur d'une page, sur une page
   publique construite et sur une URL `/prime-builder/css/…`. Le script s'arrête en erreur à la
   première réponse différente de 200.
4. Écris `.github/workflows/ci.yml` avec **7 jobs indépendants** (pas de `needs` entre eux) :
   - `lint` : `php -l` et PHPStan niveau 8 ;
   - `cs` : PHP-CS-Fixer en `--dry-run` ;
   - `phpunit` : avec MySQL 8 ;
   - `js` ;
   - `i18n` : `lang:check --strict` ;
   - `smoke` ;
   - `e2e` : Playwright et axe, actif à partir du jalon 2, avec captures et traces en artefact.

**Sortie** : `lint`, `cs`, `phpunit`, `js`, `i18n` et `smoke` sont verts.

### Jalon 1 — Socle fonctionnel
- Les écrans d'administration s'affichent et leurs actions fonctionnent.
- Une page Prime Builder s'enregistre par l'API, se publie et s'affiche avec Aurora. Son CSS compilé
  est servi par `/prime-builder/css/{id}-{empreinte}.css`.
- Une fois le plugin désactivé, la page reste lisible.
- **PHPUnit** : validateur (cas valides, invalides et malveillants), rendu, CSS, et contrôle d'accès de
  chaque route et de chaque appel d'API (403 sans le droit, succès avec).

### Jalon 2 — L'éditeur réel
Toute la section 3.3 du cahier des charges, notamment :
- le canevas en iframe avec le rendu serveur réel et un rendu partiel à chaque modification ;
- le glisser-déposer avec son alternative clavier ;
- la barre d'outils de l'élément et le menu contextuel ;
- les onglets Contenu, Style et Avancé générés à partir de contrôles typés ;
- les valeurs par appareil ;
- l'historique, le navigateur, la palette de commandes, l'enregistrement automatique local et
  l'avertissement en cas de modifications non enregistrées.

**e2e** : construire une page de 4 widgets à la souris **et** au clavier, la publier, la voir sur le
site. axe ne doit relever aucune violation sérieuse ou critique, ni sur l'éditeur ni sur la page.

### Jalon 3 — Tous les widgets
Toute la section 3.4 du cahier des charges : widgets de base, widgets Pro, widgets de contenu courant,
widgets globaux et « Bloc Prime ». La grille d'articles ne doit pas faire de requêtes N+1. **Un test
de rendu par widget.**

### Jalon 4 — Theme Builder, formulaires, popups, contenu dynamique
Sections 3.5 à 3.8 du cahier des charges, avec :
- les tests PHPUnit des conditions d'affichage, de l'envoi de formulaire, du pot de miel, de la
  limite de débit, des fichiers et des balises dynamiques ;
- les tests e2e : en-tête appliqué sur Aurora, formulaire visible dans **Envois**, popup ouverte par
  son déclencheur puis fermée avec `Échap`.

### Jalon 5 — Bibliothèque, administration, documentation
Sections 3.9 et 3.10 du cahier des charges, avec un test e2e d'import de modèle, le `README.md`
complet (section 7) et le `RETOUR.md` final (section 9).

## 5. Si tu manques de temps
Arrête-toi **sur un jalon vert**. Dans `RETOUR.md`, indique le dernier jalon terminé (SHA et lien du
run vert) et la liste exacte de ce qui reste. Une livraison partielle et vraie est acceptée ; une
livraison complète sur le papier est refusée.

## 6. `RETOUR.md`
Reprends la structure de la section 9 du cahier des charges, et ajoute :
- une section par jalon : SHA, lien du run, état de chaque job, lignes finales des outils, et ce que
  tu as vérifié à la main dans le navigateur ;
- un tableau de conformité avec **une ligne par puce** des sections 3.3 à 3.10 et une ligne par widget.
  Chaque ligne a un statut : « Fait et testé » (avec le nom du test), « Fait sans test automatique »
  ou « Non fait ».
