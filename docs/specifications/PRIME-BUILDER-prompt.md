# Mission : développer **Prime Builder**, le constructeur de pages de Prime CMS

Tu es développeur PHP / JavaScript senior. Tu vas écrire, **en une seule fois**, un plugin complet pour
Prime CMS : un constructeur de pages visuel qui réunit, dans **un seul plugin**, les fonctionnalités
d'**Elementor et d'Elementor Pro**. Ce document est ta seule consigne : lis-le en entier avant d'écrire
une ligne, puis travaille jusqu'au bout sans attendre de validation intermédiaire.

À la fin, ton travail sera vérifié par Claude, l'architecte du projet, à partir du fichier `RETOUR.md`
que tu rédigeras (section 9). Il exécutera lui-même les tests, la CI et les parcours dans le navigateur :
**n'écris dans ce fichier que ce que tu as réellement exécuté et vu fonctionner.**

---

## 1. Les deux dépôts

| Dépôt | Rôle | Droits |
|---|---|---|
| `https://github.com/HamdiSouissi/prime-cms` (branche `main`, version **1.0.0**) | Le CMS. Tu le clones pour l'étudier et pour lancer tes tests. | **Lecture seule.** Tu n'y pousses rien et tu ne modifies pas son code. |
| `<URL_DU_DEPOT_DU_PLUGIN>` (fourni par Hamdi) | Le dépôt du plugin. | Tu y pousses tout ton travail, sur la branche indiquée par Hamdi (par défaut `main`). |

La **racine du dépôt du plugin est le dossier du plugin** : `plugin.json` est à la racine. Installer le
plugin revient à copier ce dépôt dans `content/plugins/prime-builder/` du CMS (ou à envoyer son ZIP).

---

## 2. Comprendre Prime CMS (à faire avant de coder)

Prime CMS est un CMS **100 % original** inspiré de WordPress dans son principe (contenus, thèmes,
plugins), écrit en **PHP 8.2+ natif, sans framework, sans aucune dépendance au runtime**, avec MySQL 8 /
MariaDB 10.6+. L'administration est en JavaScript vanilla (modules ES, sans bundler), en français et en
anglais.

### 2.1 Lectures obligatoires dans le dépôt du CMS
1. `README.md`, `CHANGELOG.md`
2. `docs/plugins.md` — **en entier**, surtout le tableau « Points d'extension (récapitulatif v1) »
3. `docs/plugin-starter.md` et **tout le code de `content/plugins/prime-starter/`** : c'est le modèle
   de plugin à suivre (structure, nommage, tests, traductions, assets, API, éditeur alternatif)
4. `docs/editor.md` (format JSON des documents et des blocs, `HtmlSanitizer`)
5. `docs/themes.md` et `content/themes/aurora/` (hiérarchie de templates, `$site`, zones, menus)
6. `docs/hooks.md` (tous les événements et filtres), `docs/api.md`, `docs/security.md`,
   `docs/security-audit.md`, `docs/design-system.md`, `docs/i18n.md`, `docs/media.md`

### 2.2 Les API réelles que tu utiliseras (vérifie chaque signature dans le code avant de l'appeler)
- `Prime\Plugins\AbstractPlugin` et `register(PluginContext $context)`, `activate()`, `deactivate()`, `uninstall()`.
- `PluginContext` : `hooks()` (`filter()`, `on()`), `router()` (`adminGroup(callback, capability:)`,
  `frontGroup()`), `api()` (`get/post/patch/delete(path, handler, permission:, public:, options:)`,
  `schema()`), `blocks()` (`register(new BlockDefinition(...))`, `enqueueScript()`), `editors()`
  (`register(name, label, render, scripts, styles)` — nom obligatoirement `prime-builder` ou
  `prime-builder-…`), `capabilities()`, `settings()`, `views()`, `url()`, `path()`, `db()`, `notices()`,
  `contentTypes()`, `renderPage()`, `app()` (conteneur : `app()->get(Classe::class)`).
- Filtres du site : `site.template_candidates`, `site.template_paths`, `site.resolve`, `site.head`,
  `site.foot`, `site.csp`, `site.dynamic_blocks` / `blocks.dynamic` ; événement `site.assets`
  (`ThemeAssetRegistry::style()` / `script()`).
- Contenus : `EntryService::create()` / `update()` (révisions, verrous, statuts), `EntryRepository`,
  `EntryQuery` (**immuable** : `$q = $q->type('post')->limit(6)`), statuts `Entry::STATUS_*`
  (`published`, jamais `'publish'`). Filtre `content.editor` (reçoit le nom de l'éditeur et le
  `ContentType`).
- Éditeur alternatif : la fonction `render` reçoit `['entry', 'type', 'document', 'contentJson']` et
  doit produire un `<textarea name="content_json" data-prime-custom-editor-input hidden>` que le noyau
  enregistre. S'il plante ou disparaît, le noyau revient à Prime Editor en gardant le HTML.
- Médias : `MediaRepository`, sélecteur JS de l'admin `Prime.media.open({ multiple, kinds, selected, onSelect })` ;
  notifications `Prime.toast(message, type)`.
- Sécurité : `HtmlSanitizer` (`sanitize()`, `sanitizeBasic()`), CSRF du noyau sur les formulaires,
  en-tête `X-Prime-CSRF` pour l'API appelée avec la session.
- Mail : `Mailer::sendTemplate('prime-builder::mail/…', …)`, file de tâches (`jobs.register_handlers`).

**Règle anti-invention.** Avant d'utiliser une classe, une méthode ou un hook, trouve-le dans le code
du CMS (`grep -rn`). N'utilise rien qui n'existe pas (pas d'Eloquent, pas de `app()->singleton()` à la
Laravel, pas de `event()`). Si un point d'extension te manque, **ne modifie pas le CMS** : contourne-le
proprement dans le plugin si c'est possible, et décris le besoin dans `RETOUR.md` (section 9).

### 2.3 Règles du projet
- PHP `declare(strict_types=1)`, PHPStan **niveau 8** sans erreur, style PSR-12 (configuration
  `.php-cs-fixer.dist.php` du CMS).
- **Zéro dépendance au runtime** : pas de jQuery, pas de bibliothèque JS ou PHP tierce dans le plugin
  livré (carrousel, glisser-déposer, sélecteur de couleur, animations : écrits par toi). Les outils de
  test (PHPUnit, Playwright, axe) sont permis en développement.
- **CSP du site public** : `script-src 'self'`, `style-src 'self'` (aucune balise `<style>` ni script
  en ligne ; les attributs `style=""` sont tolérés mais à éviter). Tout le CSS et le JS sont des
  fichiers. Une source externe (carte, vidéo) s'ajoute par le filtre `site.csp`, au plus juste.
- Toute sortie est échappée. Tout HTML venant d'un utilisateur passe par `HtmlSanitizer`.
- Toute chaîne visible passe par les traductions du plugin (`lang/fr/messages.php`,
  `lang/en/messages.php`, clés `plugin::prime-builder.…`), avec les mêmes clés en FR et EN.
- Nommage : capacités `prime_builder.…`, réglages `plugin.prime-builder.…`, tables
  `{préfixe}prime_builder_…`, tâches et travaux `prime-builder.…`, classes CSS `pb-…`, attributs JS
  `data-pb-…`, namespace PHP `PrimeBuilder\`.
- Accessibilité : **WCAG 2.2 AA** pour l'éditeur et pour les pages produites.
- La désinstallation ne laisse aucune trace (tables par migration, réglages, capacités, travaux : le
  noyau nettoie ce qui suit les conventions de nommage ci-dessus).

---

## 3. Ce que tu construis

### 3.1 Le principe
Prime Builder reproduit **l'ergonomie et l'ensemble des fonctionnalités** d'Elementor + Elementor Pro :
même organisation de l'écran (panneau des widgets et des réglages à gauche, page en direct au centre,
barre d'outils en bas, navigateur de structure, modes responsive, historique), mêmes familles de
widgets, Theme Builder, popups, formulaires, contenu dynamique, bibliothèque de modèles.

**Mais tout est original** : aucun code, aucune feuille de style, aucune icône, aucun texte, aucun nom
ni logo d'Elementor ou de WordPress. Le visuel suit le **design system de Prime CMS**
(`docs/design-system.md`, `resources/assets/admin/css/`) : mêmes couleurs (corail `#ED6647` pour
l'accent, `#C9441F` / `--brand-700` pour les actions et le texte), mêmes polices (Plus Jakarta Sans,
Inter), mêmes rayons, mêmes composants. L'éditeur doit avoir l'air d'une partie native de Prime CMS,
premium et sobre.

### 3.2 Architecture imposée
1. **Activation par type de contenu.** Réglage « Types modifiables avec Prime Builder » (pages par
   défaut). Le filtre `content.editor` renvoie `prime-builder` pour ces types.
2. **Dans l'écran d'édition du noyau**, l'éditeur `prime-builder` affiche un aperçu de la page et un
   bouton principal « Modifier avec Prime Builder », et conserve le `textarea content_json`.
3. **L'éditeur visuel** est une page plein écran du plugin : `GET /admin/prime-builder/edit/{id}`
   (capacité `prime_builder.edit` + droit de modifier ce contenu). La page est rendue dans une
   `iframe` du même domaine, avec le vrai thème actif, pour un rendu fidèle.
4. **Format des données.** Le document du contenu reste un document Prime valide :
   `{"version":1,"blocks":[{"id":"…","type":"prime-builder/layout","data":{"version":1,"elements":[…]}}]}`.
   Le bloc `prime-builder/layout` est déclaré par `blocks()->register()` : son **validateur** contrôle
   tout l'arbre (types de widgets connus, réglages typés, bornes, HTML filtré) et son **rendu** produit
   le HTML final, enregistré par le noyau dans `content_html`. Un contenu reste donc lisible même si le
   plugin est désactivé.
5. **Enregistrement** depuis l'éditeur par l'API du plugin, avec la session et `X-Prime-CSRF` :
   `PUT/PATCH /api/prime-builder/v1/documents/{id}` → `EntryService::update()` (révisions, verrou,
   brouillon / publication / planification).
6. **CSS généré** : les styles de chaque élément (y compris par point de rupture, survol, animations)
   sont compilés en une feuille par contenu, stockée par le plugin et servie par une route publique en
   cache longue durée avec empreinte (`/prime-builder/css/{id}-{empreinte}.css`), ajoutée par
   `site.assets`. Pareil pour les styles globaux (kit du site). Aucun `<style>` en ligne.
7. **JS public minimal et à la demande** : chaque widget déclare son script ; seuls les scripts des
   widgets présents sur la page sont chargés. Budget : < 30 Ko de JS pour une page type, sans
   dépendance.

### 3.3 L'éditeur
- **Panneau gauche** : recherche de widgets, catégories (Structure, Base, Médias, Contenu dynamique,
  Formulaires, Site, Pro…), favoris ; quand un élément est sélectionné : onglets **Contenu**,
  **Style**, **Avancé** (marges, padding, largeur, position, z-index, ID et classes CSS, CSS
  personnalisé filtré, visibilité par appareil, animations d'entrée, effets au survol et au
  défilement, conditions d'affichage, attributs).
- **Structure** : conteneurs flexbox **et** grid imbriquables (direction, alignements, espacement,
  retour à la ligne, largeur boîtée / pleine, hauteur minimale, fond couleur / dégradé / image /
  vidéo, superposition, bordures, ombres, formes de séparation), plus colonnes rapides.
- **Glisser-déposer** dans le canevas et dans le navigateur, avec **alternative clavier complète**
  (déplacer, dupliquer, supprimer, monter / descendre, entrer dans un conteneur).
- **Barre d'outils de l'élément** au survol / à la sélection, **menu contextuel** (copier, coller,
  coller le style, dupliquer, enregistrer comme modèle, réinitialiser le style, supprimer).
- **Navigateur** (arbre de la page) renommable, repliable, avec masquage.
- **Modes responsive** : ordinateur, tablette, mobile (points de rupture réglables) ; toute valeur de
  style peut varier par mode, avec héritage visible.
- **Historique** : annuler / rétablir (`Ctrl+Z` / `Ctrl+Maj+Z`), liste des actions, accès aux
  révisions du noyau.
- **Kit du site** : couleurs et typographies globales (réutilisées par référence dans les widgets),
  styles de boutons, d'images, de formulaires, mise en page (largeur du contenu, espacements), points
  de rupture.
- **Raccourcis** et **recherche d'actions** (type palette de commandes), **mode aperçu**, mode sombre
  de l'éditeur facultatif.
- Enregistrement : brouillon, publication, planification, aperçu ; avertissement en cas de
  modifications non enregistrées ; enregistrement automatique local des brouillons.

### 3.4 Widgets (tous livrés, chacun avec Contenu / Style / Avancé)
- **Base** : titre, éditeur de texte (riche, filtré), image, vidéo (fichier, YouTube et Vimeo en
  `nocookie` / sans pistage tant que l'on ne clique pas), bouton, séparateur, espaceur, icône, boîte
  icône, boîte image, liste d'icônes, compteur, barre de progression, témoignage, onglets, accordéon,
  bascule, alerte, réseaux sociaux, carte (OpenStreetMap en iframe), note par étoiles, galerie
  d'images (lightbox accessible), carrousel d'images, ancre de menu, HTML (réservé à une capacité
  dédiée, filtré), zone du thème.
- **Pro** : grille d'articles (requête configurable : type, termes, auteur, tri, nombre, pagination,
  mise en page cartes / liste), portfolio filtrable, diaporama (slides), carrousel de médias,
  carrousel de témoignages, avis, **formulaire** (section 3.6), menu de navigation (menus du noyau,
  sous-menus, version mobile accessible), appel à l'action, boîte retournable, table de prix, liste de
  prix, compte à rebours, titre animé, citation, boutons de partage, sommaire automatique, code avec
  coloration, fil d'Ariane, formulaire de recherche, logo du site, titre du site, et les widgets de
  **contenu courant** : titre, extrait, contenu, image à la une, informations (date, auteur,
  catégories), navigation précédent / suivant, boîte auteur, titre et liste d'archive.
- **Widgets globaux** : un widget enregistré une fois et réutilisé partout ; le modifier met à jour
  toutes les pages.
- Les **blocs Prime Editor** existants restent utilisables via un widget « Bloc Prime ».

### 3.5 Theme Builder
- Modèles **en-tête, pied de page, contenu seul (par type), archive, recherche, 404**, avec
  **conditions d'affichage** (tout le site, type de contenu, terme, contenu précis, page d'accueil,
  exclusions) et priorité.
- Branchement par `site.template_candidates` / `site.template_paths` / `site.resolve`. Vérifie dans
  le code d'Aurora comment l'en-tête et le pied de page sont inclus ; si le noyau ne permet pas de les
  remplacer proprement, fournis la meilleure solution possible dans le plugin (par exemple un modèle
  « page complète » qui rend en-tête + contenu + pied) et décris le point d'extension manquant dans
  `RETOUR.md`.

### 3.6 Formulaires
- Champs : texte, e-mail, téléphone, nombre, zone de texte, liste, cases, boutons radio, date, fichier
  (types et taille limités, stockés hors du dossier public), acceptation, caché, étapes multiples.
- Envoi par l'API publique du plugin (`POST /api/prime-builder/v1/forms/{id}/submit`), compatible avec
  le cache de pages : **pas de jeton CSRF dans le HTML** ; protection par pot de miel, délai minimal,
  limite de débit par IP et validation serveur stricte.
- Actions après envoi : enregistrer (écran **Prime Builder › Envois** avec recherche, export CSV,
  suppression), e-mail à l'administrateur et accusé de réception (gabarits `prime-builder::mail/…`,
  via la file), redirection, message.
- Données personnelles : durée de conservation réglable, purge par tâche planifiée
  `prime-builder.purge-submissions`.

### 3.7 Popups
- Construits avec l'éditeur ; déclencheurs : chargement (délai), défilement (%), clic sur un élément,
  intention de sortie, inactivité ; conditions d'affichage et fréquence (stockée dans le navigateur).
- Dialogue accessible : focus piégé puis rendu, `Échap`, `aria-modal`, pas d'ouverture surprise pour
  les lecteurs d'écran sans déclencheur explicite, respect de `prefers-reduced-motion`.

### 3.8 Contenu dynamique
- Balises dynamiques utilisables dans tout champ texte, lien ou image : titre du site, slogan, titre /
  extrait / date / auteur / image à la une du contenu, champs personnalisés du noyau, termes, URL du
  contenu, date du jour, utilisateur connecté (nom seulement).
- Conditions d'affichage par élément : connecté / non connecté, rôle, appareil, date de début / fin.

### 3.9 Bibliothèque de modèles
- Enregistrer une page, une section ou un conteneur comme modèle ; insérer depuis la bibliothèque ;
  **export / import JSON** (validé comme un document, images référencées par URL et réimportées).
- Livrer **au moins 6 modèles de sections et 3 modèles de pages originaux** (accueil, à propos,
  contact), avec des images **générées** ou libres de droits **sans photo de personne réelle**, et les
  licences listées.

### 3.10 Administration du plugin
- Menu **Prime Builder** : Modèles (avec Theme Builder et popups, filtrés par type), Envois de
  formulaires, Kit du site, Réglages.
- Capacités : `prime_builder.edit`, `prime_builder.templates`, `prime_builder.theme_builder`,
  `prime_builder.forms`, `prime_builder.settings`, `prime_builder.unfiltered_html`.
- Réglages (section dans **Réglages › Général** ou page dédiée) : types de contenus, points de
  rupture, chargement des polices, conservation des envois, CSS de l'éditeur.
- Ligne dans **Outils › État du système** (`system.checks`) ; notification de bienvenue à l'activation.

---

## 4. Qualité, sécurité, performance
- **Sécurité** : validation serveur de tout l'arbre ; aucun réglage ne permet d'injecter du script
  (URL filtrées, CSS personnalisé débarrassé de `expression`, `url(javascript:…)`, `@import`,
  `</style>`), capacité dédiée pour le HTML brut, contrôle d'accès sur chaque route et chaque appel
  d'API, IDOR (un éditeur ne modifie que ce qu'il a le droit de modifier), limites de taille du
  document.
- **Performance** : rendu serveur sans requête par widget (pas de N+1 dans la grille d'articles) ;
  CSS et JS uniquement des widgets présents ; images en `loading="lazy"` avec dimensions ;
  compatible avec le cache de pages du noyau (invalidation à l'enregistrement d'un modèle global, d'un
  widget global ou du kit).
- **Accessibilité** : 0 violation axe sérieuse ou critique sur l'éditeur et sur les pages produites ;
  focus visible ; tous les widgets interactifs (onglets, accordéons, carrousels, lightbox, menus,
  popups) suivent les motifs ARIA et fonctionnent au clavier.
- **Robustesse** : un widget qui plante ne fait jamais planter la page (rendu vide + journal) ;
  désactiver le plugin laisse le HTML enregistré lisible.

---

## 5. Tests (obligatoires, et exécutés)
1. **PHPUnit** dans `tests/` du plugin : validateur de l'arbre (cas valides, invalides, malveillants),
   rendu de chaque widget, CSS généré, conditions du Theme Builder, formulaires (envoi, pot de miel,
   débit, fichiers), balises dynamiques, import / export, désinstallation propre, contrôle d'accès de
   chaque route et chaque appel d'API. Tu peux réutiliser les classes de support des tests du CMS
   (`Prime\Tests\Support\ApiTestFixture`, voir `content/plugins/prime-starter/tests/`).
2. **Tests JavaScript** de l'éditeur (modèle de document, historique, responsive, glisser-déposer,
   raccourcis) avec l'exécuteur `node` du CMS (`tests/js/run.mjs` comme modèle) ou `node --test`.
3. **Playwright + axe** dans `tests/e2e/` : construire une page avec plusieurs widgets au clavier et à
   la souris, la publier, la voir sur le site ; formulaire envoyé et reçu ; en-tête du Theme Builder
   appliqué ; popup ; import d'un modèle ; audit axe de l'éditeur et des pages produites.
4. **PHPStan niveau 8** et **PHP-CS-Fixer** sur tout le PHP du plugin.

### CI du dépôt du plugin (GitHub Actions)
Un workflow qui : clone `HamdiSouissi/prime-cms` (`main`), copie le plugin dans
`content/plugins/prime-builder/`, installe le CMS (MySQL 8 en service), puis lance PHPStan,
PHP-CS-Fixer, PHPUnit, les tests JS, `php bin/prime lang:check --strict` du CMS, et la suite
Playwright (captures et traces en artefact en cas d'échec). **Tous les jobs doivent être verts sur ton
dernier commit.**

Si ton environnement n'a pas PHP, utilise `npx -y @php-wasm/cli@latest` pour exécuter PHP, `bin/prime`
et PHPUnit, et appuie-toi sur la CI. Ne déclare jamais un test « passé » sans l'avoir vu passer.

---

## 6. Livrables dans le dépôt du plugin

```text
prime-builder/                (racine du dépôt)
├── plugin.json               slug prime-builder, namespace PrimeBuilder\, requires prime >=1.0.0
├── README.md                 installation et utilisation (section 7)
├── RETOUR.md                 rapport de fin de travail pour Claude (section 9)
├── CHANGELOG.md              version 1.0.0 du plugin
├── LICENSES.md               licences des éventuels éléments tiers (polices, images, icônes)
├── src/                      PHP (Plugin.php, éditeur, rendu, widgets, Theme Builder, formulaires…)
├── views/                    vues admin, éditeur, gabarits d'e-mail
├── assets/                   css/ et js/ (éditeur et public), icônes SVG originales
├── lang/fr/messages.php, lang/en/messages.php
├── migrations/
├── templates/                modèles de pages et de sections livrés
├── tests/                    PHPUnit + JS ; tests/e2e/ Playwright
├── docs/                     guide développeur : créer un widget, format des données, hooks du plugin
└── .github/workflows/ci.yml
```

Un plugin tiers doit pouvoir **ajouter ses propres widgets** par un filtre documenté
(`prime_builder.widgets`), avec son schéma de réglages, son rendu serveur et son script.

---

## 7. Le `README.md`
En français, clair pour un administrateur, avec :
1. Présentation et captures d'écran (éditeur, Theme Builder, page produite).
2. Prérequis (Prime CMS ≥ 1.0.0, PHP 8.2+, extensions).
3. **Installation** pas à pas, des trois façons : copie dans `content/plugins/prime-builder/` ;
   archive ZIP par **Plugins › Installer une archive** ; `php bin/prime plugin:install <zip>` ; puis
   activation (`php bin/prime plugin:activate prime-builder` ou bouton), réglages et capacités à donner
   aux rôles.
4. Prise en main : créer une page, utiliser le Theme Builder, un formulaire, une popup, importer un modèle.
5. Mise à jour, désactivation, désinstallation (ce qui est supprimé).
6. Développement : lancer les tests en local contre un clone du CMS, créer un widget.

---

## 8. Méthode de travail
- Une seule mission, sans phases à faire valider : tu livres le plugin **complet**.
- Commite souvent avec des messages clairs (`feat(editor): …`, `feat(widgets): …`, `test: …`) et
  pousse régulièrement : ton travail ne doit jamais exister seulement sur ta machine.
- Construis dans cet ordre pour avoir tôt un tout qui fonctionne : format des données + validateur +
  rendu serveur → éditeur minimal (conteneur, titre, texte, image, bouton) enregistrant pour de vrai
  → widgets → Theme Builder → formulaires → popups → contenu dynamique → bibliothèque → finitions,
  accessibilité, performance, documentation.
- Vérifie dans un navigateur, à 1440 px et 375 px, chaque écran que tu livres.
- Si tu dois t'arrêter avant la fin (limite de temps ou de contexte), pousse ce qui est fait, et
  rédige quand même `RETOUR.md` avec l'état exact.

---

## 9. Le fichier `RETOUR.md` (pour la vérification par Claude)
Il doit contenir, dans cet ordre :
1. **Dépôt, branche et hash du dernier commit**, vérifié avec `git ls-remote origin`.
2. **CI** : nom et résultat de chaque job sur ce commit, copiés tels quels depuis GitHub, avec le lien
   de l'exécution.
3. **Tests** : nombre de tests PHPUnit, JS et Playwright, résultat de PHPStan, PHP-CS-Fixer et
   `lang:check --strict`, avec les commandes exactes pour les relancer.
4. **Tableau de conformité** reprenant chaque point des sections 3 à 7 : **terminé** (exécuté, testé,
   CI verte), **partiel** (ce qui manque) ou **non fait**, avec les fichiers principaux concernés.
5. **Liste des widgets** livrés, avec pour chacun : réglages principaux, script public oui / non,
   test associé.
6. **Mesures** : poids du JS et du CSS de l'éditeur et d'une page type, nombre de requêtes SQL et
   temps de rendu d'une page de 30 widgets, résultat axe.
7. **Sécurité** : ce que tu as vérifié et comment (injections, XSS, CSRF, accès, IDOR, envois de
   fichiers), avec les tests correspondants.
8. **Points d'extension manquants dans le CMS** : pour chacun, le besoin, le contournement choisi, et
   la modification du noyau que tu proposes (fichier, hook, signature).
9. **Écarts** par rapport à ce document, **bugs connus**, et **ce que tu n'as pas pu vérifier**.
10. **Parcours de vérification** : une liste d'étapes concrètes que Claude peut suivre (installer,
    activer, construire telle page, vérifier tel résultat), avec les identifiants de test si tu en crées.

Un `RETOUR.md` honnête et incomplet vaut mieux qu'un rapport optimiste : chaque affirmation sera
vérifiée.
