# Développement de Prime Builder

Ce document décrit l’implémentation 0.1.0, et non les fonctions encore absentes. Les classes et hooks du CMS cités ici ont été repérés dans le code de Prime CMS 1.0.1 et 1.0.2. Le code du CMS n’est pas modifié par le plugin.

## Structure

- `plugin.json` : manifeste et compatibilité déclarée ; `src/Plugin.php` : enregistrements et cycle de vie.
- `src/Controller/` : tableau de bord, éditeur, API, feuilles CSS.
- `src/Document/` : extraction du bloc, validation, modèles ; `src/Widgets/` : catalogue, rendu, contenu et pont limité vers des blocs Prime.
- `src/Styles/` : compilation autorisée, kit et instantanés CSS ; `views/`, `assets/` et `lang/{fr,en}/` : interface et traductions.
- `templates/` : modèles JSON ; `tests/` : tests PHP, modèle JS et Playwright/axe ; `.github/workflows/ci.yml` : CI.
- `src/Forms/`, `src/Controller/ContactForms.php` et `migrations/` : formulaire fixe, jetons, quotas, envois et purge ; `migrations/README.md` décrit les tables et leur suppression.

## Format et limites

Le document sauvegardé est un document Prime v1 contenant un unique bloc `prime-builder/layout` :

```json
{"version":1,"blocks":[{"id":"pb_layout","type":"prime-builder/layout","data":{"version":1,"elements":[{"id":"intro","type":"heading","settings":{"text":"Bonjour","level":2},"styles":{"desktop":{"color":"#123456"}},"children":[]}]}}]}
```

`Validator` limite les données de mise en page encodées à 1 Mio, 500 éléments et une profondeur de 12 ; les identifiants doivent être uniques et ne contenir que lettres, chiffres, `_` et `-`. Il vérifie le catalogue, les champs, les enfants et les propriétés CSS autorisées. Les métadonnées facultatives `label` (nom de l’élément dans le navigateur) et `hidden` (booléen) sont validées ; un élément masqué conserve son HTML avec l’attribut `hidden`, et ce mécanisme ne protège pas des données confidentielles. Les champs `rich` passent par `Prime\Security\HtmlSanitizer` ; les URL et valeurs CSS ont leurs propres listes de validation. Le `Renderer` échappe les sorties et isole les erreurs de rendu d’un widget.

Le bloc est enregistré avec `PluginContext::blocks()` ; `EntryService::update()` valide et sauvegarde le document, le HTML rendu, le texte et les révisions du CMS. `Converter` tente de convertir les blocs existants sans écrire à l’ouverture : huit types statiques peuvent devenir des widgets `prime_block` si leur rendu normalisé est identique au fragment attribué ; les ancres des titres sont conservées. Les autres blocs peuvent être conservés en lecture seule avec leur HTML authentifié et chiffré, lié à l’entrée et à `APP_KEY`. Si un seul moteur de bloc est indisponible, le fragment est déduit par comparaison des rendus actuels des autres blocs avec le préfixe et le suffixe sauvegardés : cela suppose que ces rendus n’ont pas changé. Le CMS ne conserve pas séparément les fragments ; avec plusieurs moteurs indisponibles ou une comparaison impossible, le HTML sauvegardé entier est conservé dans un unique widget `original_group` non éditable. Le marqueur de groupe est authentifié, l’éditeur signale ses limites à chaque ouverture, et les blocs ne peuvent être séparés dans cet élément. Les réglages d’origine sont perdus à l’enregistrement et la sortie dynamique figée. Un échec de clé, de validation ou de taille continue à bloquer l’enregistrement : le chiffrement peut rendre un HTML inférieur à 1 Mio trop volumineux pour le document. Une modification de `APP_KEY` rend les fragments conservés illisibles. Depuis Prime CMS 1.0.1, `EntryView::content()` détecte les blocs dynamiques par leur type décodé ; le plugin ne modifie plus la représentation JSON des contenus.

## Routes et sécurité

| Route | Usage et contrôle |
|---|---|
| `GET /admin/prime-builder` | Tableau de bord, administration et `prime_builder.edit`. |
| `GET /admin/prime-builder/edit/{id}` | Éditeur plein écran ; capacité du plugin et `EntryService::canEdit()` pour l’entrée et son type. |
| `GET/POST /admin/prime-builder/settings` | Réglages et kit ; `prime_builder.settings`, CSRF du CMS sur l’écriture, validation des valeurs avant enregistrement. |
| `GET/POST /admin/prime-builder/theme` | Sélection de copies statiques pour l’en-tête et le pied de page ; `prime_builder.theme_builder`, CSRF, et `prime_builder.unfiltered_html` si une copie contient le widget HTML. |
| `GET /admin/prime-builder/submissions` ; `POST /admin/prime-builder/submissions/{id}/delete` | Boîte limitée aux 100 derniers messages et suppression ; `prime_builder.forms`, CSRF du CMS sur la suppression, réponses privées `no-store`. |
| `GET /api/prime-builder/v1/forms/{id}/start?node=…` ; `POST /api/prime-builder/v1/forms/{id}/submit` | Routes publiques pour un widget de formulaire visible sur une entrée publique ; jeton temporaire, quotas et validation côté serveur, réponses `no-store`. |
| `PATCH /api/prime-builder/v1/documents/{id}` | Document v1 mono-bloc ; API du CMS, `prime_builder.edit`, contrôle par entrée, CSRF en session, validation et `EntryService::update()`. |
| `POST /api/prime-builder/v1/documents/{id}/render` | Même contrôle ; valide tout l’arbre et retourne l’aperçu HTML sans enregistrer. Le champ facultatif `target` demande le rendu d’un seul sous-arbre existant ; sans lui, la réponse contient aussi le document normalisé. |
| `GET /api/prime-builder/v1/documents/{id}/live` | Retours HTML des widgets de contenu ; entrée publiquement visible ou droits de modification ; `private, no-store`. La pile API du CMS applique sa limite de débit. |
| `GET /prime-builder/css/{id}-{empreinte}.css` | CSS de contenu. Entrée publique ou droit d’édition ; aperçu privé `no-store`. |
| `GET /prime-builder/kit/{empreinte}.css` | CSS du kit global. |
| `GET /prime-builder/theme/{empreinte}.css` | CSS public des parties de thème, adressé par son empreinte et sans texte du modèle. |

Les réglages du plugin ne sont pas enregistrés dans le catalogue générique du CMS : ils passent par une page dédiée, avec leur propre contrôle. Depuis Prime CMS 1.0.2, le catalogue générique applique lui aussi la capacité déclarée pour chaque section. Après validation, l’ensemble est enregistré en un seul réglage JSON `plugin.prime-builder.configuration` ; le noyau émet un seul `settings.updated` pour cette clé. Tant qu’une clé n’est pas présente dans ce groupe, les anciennes valeurs individuelles sont lues en repli. Après un premier enregistrement via la page, modifier directement une ancienne clé individuelle ne remplace pas la valeur présente dans le groupe. L’atomicité porte sur l’écriture SQL du groupe ; les effets externes des écouteurs du CMS et les exceptions qu’ils peuvent lever ne sont pas transactionnels. La bibliothèque (y compris les modèles personnalisés partagés) est lisible par `prime_builder.edit`. Les routes de création, suppression et validation d’import exigent aussi `prime_builder.templates` ; l’import vérifie en outre les droits sur l’entrée. Chaque modèle validé est stocké dans un réglage non autoloadé distinct `plugin.prime-builder.saved_template.<id>`, supprimé à la désinstallation, avec un maximum nominal de 50 modèles et une requête de création limitée à environ 128 Kio. Le contrôle du nombre n’est pas atomique entre deux créations simultanées. Les fragments historiques protégés liés à une entrée ne sont pas stockés dans la bibliothèque partagée. Un client autorisé à modifier un document peut toujours composer lui-même un JSON équivalent : la capacité protège les opérations de la bibliothèque, pas l’expression de toute composition possible.

L’API accepte les modes d’authentification du CMS, notamment une session avec en-tête `X-Prime-CSRF` ou un jeton porteur autorisé. Depuis Prime CMS 1.0.2, le noyau intersecte les capacités d’un jeton avec celles du compte courant et ne traite pas un `*` stocké comme un rôle administrateur. Sur 1.0.1 uniquement, le plugin enregistre dans `Kernel::registerMiddlewareAlias()` un adaptateur de l’alias `api.auth` : il délègue d’abord l’authentification et le CSRF à `ApiAuthenticate`, recharge le compte actif, retire le joker et intersecte les capacités avant les autres middlewares et contrôleurs API. Cela protège les routes HTTP du noyau et du plugin tant que cet adaptateur est effectif ; ce n’est pas une correction du service de jetons lui-même. Le plugin désactivé, en échec ou en mode sans échec, ou une extension qui remplace ultérieurement le même alias, réexpose le défaut du noyau 1.0.1. Les appels directs à `ApiTokenService::authenticate()` ne sont pas corrigés non plus. Mettre le CMS à jour vers 1.0.2 reste vivement recommandé. Le diagnostic système signale ce risque ; les tests et la portée de la CI sont indiqués dans `RETOUR.md`.

Le widget `html` applique toujours `HtmlSanitizer::sanitizeBasic` : son nom de capacité `prime_builder.unfiltered_html` n’autorise ni scripts ni HTML non filtré. La validation et le rendu refiltrent le contenu. Le texte riche ordinaire utilise le même profil ; cette capacité protège le widget et ses opérations, pas toutes les balises permises dans les autres widgets. L’API du constructeur retourne 403 pour une création, modification ou duplication non autorisée, ainsi que pour l’import ou l’enregistrement comme modèle contenant ce widget. Un contenu existant authentifié peut être conservé, déplacé ou supprimé après retrait du droit.

Le validateur `BlockDefinition` ne reçoit ni acteur ni entrée. `HtmlContent` authentifie donc le HTML filtré avec une clé dérivée de `APP_KEY`, l’identifiant du widget et celui de l’entrée : le constructeur ne signe qu’après autorisation ; la validation du noyau refuse les données non signées ou falsifiées et le rendu vérifie l’entrée. Les signatures ne sont pas conservées dans les modèles partagés ; une personne autorisée les recrée lors de l’enregistrement. La rotation d’`APP_KEY` invalide les signatures et peut empêcher les sauvegardes ou rendus ultérieurs : sauvegarder la clé et les contenus avant toute rotation.

Le filtre `content.saving` protège également les enregistrements par le service du noyau, sans modifier celui-ci. Comme `Hooks` isole les exceptions des plugins, ce filtre ne peut pas refuser l’opération par une exception : si un acteur sans droit rejoue un ancien widget signé, il restitue le contenu précédent, mais **l’API du noyau peut répondre 200 et les autres champs peuvent être modifiés**. Le constructeur, lui, répond 403 avant la sauvegarde. Lors d’une création ou duplication par le noyau, l’ID de destination n’existe pas encore : les widgets HTML signés sont convertis en texte riche filtré pour conserver leur contenu, sans conserver leur type. Un acteur autorisé qui copie un widget signé vers une entrée existante par l’API du noyau le fait signer pour l’entrée cible. L’autosauvegarde du noyau ne passe pas par ce filtre, mais la restauration passe par `update`. Ces limites et les tests observés sont consignés dans `RETOUR.md`.

Le premier sous-ensemble du Theme Builder utilise `site.partial` pour `header` et `footer` quand le thème appelle ce hook. Le formulaire valide des modèles partagés, puis en stocke une copie indépendante dans `plugin.prime-builder.theme_partials` : un gestionnaire de bibliothèque ne peut pas changer la copie active en supprimant le modèle source. Un filtre antérieur qui a déjà produit une chaîne conserve la priorité. `Partials` revalide les copies, refuse les widgets de contenu dynamique et le formulaire ainsi que les ancres explicites en double, applique les exclusions par identifiant et espace de noms les identifiants et ancres pour chaque partie. Les liens des widgets vers ces ancres sont réécrits, pas les liens dans les champs HTML riche. La configuration déclenche `settings.updated` et la purge du cache de pages. Les CSS sont compilés et conservés sous `theme-<empreinte>.css` ; les scripts des widgets présents sont enregistrés depuis `site.assets`. Les modèles de contenu, archives, recherche et 404, conditions par type ou terme et priorités ne sont pas implémentés.

Le formulaire de contact est un premier sous-ensemble fixe, sans upload ni e-mail. `forms.js` demande un jeton à `start` avec `credentials: 'omit'`, puis envoie du JSON sans cookie. Le jeton HMAC, dérivé de `APP_KEY`, lie contenu, nœud, IP, nonce et horodatage ; le serveur impose 3–1 800 secondes et vérifie que le nœud et ses parents ne sont pas masqués dans une entrée publique. Les quotas sont de 30 démarrages par minute et 5 soumissions par 5 minutes pour chaque IP ; le pot de miel est ignoré silencieusement. Les empreintes de jetons utilisés sont insérées sous contrainte unique dans une transaction avec le message, puis conservées séparément pendant au moins la durée de validité maximale. Supprimer un message ne réactive pas son jeton. Les champs sont strictement validés et l’affichage admin échappé. Les compteurs utilisent des clés HMAC plutôt que des adresses en clair ; la résolution de l’IP dépend de la configuration de confiance proxy du CMS. Les tables, la purge après 30 jours et la désinstallation sont décrites dans `migrations/README.md`. Aucun mécanisme n’élimine tout spam et les contrôles de charge, conservation des sauvegardes et obligations de confidentialité restent à la charge de l’opérateur.

L’avis est un contenu éditorial statique : la note est bornée à 0–5 et les étoiles décoratives ont une étiquette textuelle localisée. Il n’émet pas de données structurées de notation ni ne vérifie l’authenticité des avis. La boîte retournable enregistre les deux faces et son lien en HTML lisible ; `flip_box.js` ajoute un bouton natif avec `aria-expanded` et un identifiant de panneau sans collision. Le focus est restitué si le verso contenant le focus est masqué. L’animation déclenchée par l’utilisateur respecte `prefers-reduced-motion` ; sans script, aucun contenu n’est masqué.

Le portfolio accepte au plus 24 cartes saisies manuellement et 12 catégories, avec titre et catégorie obligatoires ; texte échappé et URL filtrées. `portfolio.js` crée des boutons natifs `aria-pressed` et une annonce du nombre de cartes, sans masquer le contenu si JavaScript est indisponible. Il n’interroge pas les contenus du CMS.

Le menu de navigation utilise `MenuRepository::all()` pour le sélecteur et `MenuRenderer::renderLocation()` pour les liens publics, leur hiérarchie et `aria-current`. Le document stocke un identifiant de menu et un repli vers l’accueil, jamais une copie des titres : un lien vers une entrée redevenue privée disparaît ainsi au prochain chargement live. `content.js` crée les boutons de divulgation et les identifiants sans collision, conserve le focus pendant un chargement en cours et fournit un bouton mobile sous 768 px. Sans JavaScript, si l’API échoue ou si le menu a été supprimé, seul le lien d’accueil est disponible. Ce widget est exclu des parties de thème tant que celles-ci n’ont pas de route live adaptée ; sa bascule mobile n’utilise pas encore le breakpoint configurable du constructeur.

Le carrousel de témoignages valide au plus 20 lignes textuelles (auteur, fonction et portrait facultatifs). Les portraits redondants ont un texte alternatif vide ; les URL passent par le filtre de ressources du CMS. Le carrousel de médias valide au plus 20 lignes d’images ou de fichiers vidéo natifs, avec alternative, légende, piste WebVTT et transcription selon le type ; ses textes sont échappés au rendu et ses URL filtrées. Le diaporama valide également 20 lignes au plus : image, alternative, titre, description et lien facultatif dont l’URL et le libellé sont fournis ensemble. Son texte est échappé, ses URL sont filtrées et le niveau de titre est borné à 2–6. Les trois partagent `image_carousel.js` avec le carrousel d’images : les ressources sont dédupliquées, aucun défilement automatique n’est lancé et toutes les diapositives restent présentes sans script. Une vidéo masquée est mise en pause ; ses propres touches ne sont pas interceptées. La CSP du CMS bloque par défaut les ressources distantes, et les pistes WebVTT externes exigent une configuration CORS adaptée.

Le titre animé conserve un seul titre sémantique lisible ; ses deux effets CSS sont désactivés par `prefers-reduced-motion`. Le champ typé `code` préserve le texte et ses espaces (maximum 20 000 octets, octet nul refusé) : il n’est **pas** un HTML assaini et tout rendu d’une extension utilisant ce type doit l’échapper. `CodeHighlighter` échappe chaque fragment, ne génère que ses classes fixes et colore au maximum 1 000 unités ; c’est un colorateur léger et non un parseur complet. Le contenu restant est affiché sans coloration et le conteneur défilant est accessible au clavier.

Le compte à rebours stocke une date à la minute sans fuseau au format `AAAA-MM-JJTHH:MM`, interprétée explicitement en UTC ; le validateur refuse les dates impossibles et les années hors 0001–9999. Le HTML enregistré contient un `<time>` UTC lisible sans script. `countdown.js`, chargé seulement pour ce widget, compare l’échéance à `Date.now()` sur le navigateur et partage un seul intervalle entre les comptes ; un retour de visibilité recalcule le temps absolu. Les valeurs ne forment pas une région live, seul le message de fin est annoncé par `role="status"`. L’horloge locale peut être fausse ; ni le décompte ni son message ne sont un contrôle d’accès côté serveur. Sans JavaScript ou dans l’aperçu statique, seule la date est affichée.

La route `live` ne transmet que les contenus publics : `EntryQuery` filtre type, termes et auteur, puis applique tri, limite et pagination. Les widgets de configuration de requête identique partagent les résultats dans le fournisseur ; les configurations différentes nécessitent chacune leur propre requête. Sans pagination, le fournisseur lit jusqu’à 12 résultats en une requête ; avec pagination, il faut un comptage et une sélection. Pour les pages, les ancêtres absents sont chargés par lots, une requête par niveau (au plus 11), puis transmis à `UrlGenerator::primePageCache()` : on n’effectue pas une requête par résultat. Les pages orphelines, cycliques, trop profondes ou dont un ancêtre est privé sont écartées après la requête ; le comptage de pagination du noyau peut donc inclure ces pages inaccessibles et une page affichée peut être vide. Les tests d’intégration comptent les requêtes du fournisseur sur un et douze résultats, y compris des parents et grands-parents distincts, avec un nouveau générateur d’URL pour chaque mesure et des permaliens en mémoire ; ces nombres ne comprennent pas les requêtes HTTP, d’authentification ou de lecture des réglages. Le navigateur actualise les widgets concernés une fois par document ; seuls leurs paramètres de pagination bornés sont transmis à l’API, pas les autres paramètres de la page. Le HTML de repli ne contient pas de copie persistante des titres d’autres contenus.

Le CSS public est compilé à partir d’une liste fermée, sans interpolation CSS libre. Les instantanés publics sont conservés dans `storage/prime-builder/styles/` (ou le stockage configuré du CMS), vérifiés par empreinte et relus pour les anciennes URL. Les CSS d’aperçu privé ne sont pas archivés. La tâche `prime-builder.prune-styles` supprime les instantanés inutilisés depuis plus de 366 jours ; la désinstallation les retire. Si l’écriture échoue, la route actuelle répond sans cache immuable : prévoir stockage et planificateur opérationnels. Les anciennes URL créées avant l’ajout des instantanés ne peuvent pas être recréées si leurs données ont changé. Le serveur refuse les requêtes ultérieures pour une entrée devenue privée, mais une feuille déjà mise en cache publiquement dans un navigateur ou un CDN ne peut pas être révoquée avant son expiration.

Les scripts publics du plugin sont externes et chargés conditionnellement ; aucun script ou bloc `<style>` public n’est injecté par ce plugin. Le thème et le CMS ont leurs propres politiques CSP. L’éditeur applique des attributs de style dans l’iframe pour les modifications non enregistrées. Pour une modification d’un seul élément sans changement de structure, il transmet l’arbre entier pour validation mais ne demande et ne remplace que son sous-arbre ; les changements structurels ou concurrents déclenchent un rendu complet. Les réponses périmées sont ignorées et un élément manquant provoque une réconciliation complète. L’iframe est de même origine ; en l’absence de `.pb-layout`, l’aperçu remplace `.aurora-prose`, sinon le contenu de `main`, ce qui peut être inadapté à d’autres thèmes.

## Ajouter un widget tiers

Un autre plugin actif peut filtrer `prime_builder.widgets`. Exemple dans son `register(PluginContext $context)` (les clés de traduction appartiennent au plugin tiers) :

```php
use Prime\Security\HtmlSanitizer;

$context->hooks()->filter('prime_builder.widgets', static function (mixed $widgets): mixed {
    if (!is_array($widgets)) {
        return $widgets;
    }
    $widgets['acme_note'] = [
        'label' => 'plugin::acme.widgets.note',
        'category' => 'base', // structure, base ou pro
        'container' => false,
        'fields' => [
            'message' => ['type' => 'text', 'max' => 300, 'label' => 'plugin::acme.fields.message'],
        ],
        'render' => static function (array $settings, HtmlSanitizer $sanitizer): string {
            $message = is_string($settings['message'] ?? null) ? $settings['message'] : '';
            return '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        },
    ];
    return $widgets;
});
```

Parmi les types de champs reconnus figurent `text`, `multiline`, `rich`, `integer`, `enum`, `href`, `url`, `slug`, `media_list`, `price_items`, `testimonial_items`, `media_carousel_items`, `slideshow_items`, `portfolio_items`, `code` et `json_object`. `price_items` est un champ texte multiligne validé, limité à 30 lignes non vides `nom | prix | description facultative` ; ce sont des textes, pas des montants calculables. Un `enum` fournit une liste `values` et peut fournir des `option_labels` dont les valeurs sont des clés de traduction. Le rendu fourni par un callback est encore filtré par `sanitizeBasic` : le HTML interactif arbitraire n’est donc pas garanti. Le code PHP d’une extension est du code de confiance ; il ne constitue pas un bac à sable. Un `script` public éventuel doit être un chemin local `/assets/plugins/{slug}/...js`, sans `..` ; il est enregistré uniquement si le widget est présent. Les champs sont génériques : il n’y a pas encore d’API d’éditeur personnalisée complète pour les widgets.

## Vérifier dans une instance isolée

Les commandes suivantes supposent une copie du plugin dans un checkout **de test** de Prime CMS, avec ses dépendances de développement installées. Les tests E2E modifient et publient leur page : ne pas les lancer contre un site réel.

```bash
# Depuis le CMS de test
composer install --no-interaction --prefer-dist
# Par défaut, les tests utilisent la fixture SQLite du CMS.
vendor/bin/phpunit --bootstrap tests/bootstrap.php content/plugins/prime-builder/tests
# Pour exécuter les tests d’intégration sur une instance MySQL de test isolée,
# renseigner PRIME_TEST_DB_HOST, PRIME_TEST_DB_PORT, PRIME_TEST_DB_USERNAME
# et PRIME_TEST_DB_PASSWORD dans l’environnement :
PB_TEST_MYSQL=1 vendor/bin/phpunit --bootstrap tests/bootstrap.php content/plugins/prime-builder/tests
vendor/bin/phpstan analyse --configuration content/plugins/prime-builder/phpstan.neon --no-progress
vendor/bin/php-cs-fixer fix content/plugins/prime-builder --config .php-cs-fixer.dist.php --dry-run --diff --no-ansi
php bin/prime lang:check --strict

# Depuis le dépôt du plugin
npm test
# Mesure indicative sur la fixture SQLite du CMS (copie du plugin à jour requise)
php tools/benchmark.php /chemin/vers/prime-cms
cd tests/e2e
npm ci --no-audit --no-fund
npx playwright install --with-deps chromium
# Reproduire les fixtures et variables E2E_* de .github/workflows/ci.yml,
# dont E2E_FORM_ENTRY_ID (page vierge formulaire-test) et E2E_MENU_ENTRY_ID
# (page vierge pour le parcours clavier complet). Les tests publient et modifient
# ces pages et suppriment un envoi : ne jamais viser une installation réelle.
npm test
```

Avec `PB_TEST_MYSQL=1`, chaque fixture d’intégration crée puis supprime une base aléatoire `prime_builder_test_*` : le compte MySQL doit avoir les droits `CREATE DATABASE` et `DROP DATABASE`. Ne jamais pointer ces tests vers une instance contenant des données réelles. PHPStan niveau 8 analyse le code source, les vues, les langues, les tests, les outils et la configuration du fixer ; sa configuration utilise l’autoloader de développement du CMS installé par Composer. La CI clone le CMS, installe MySQL 8 et les dépendances de développement, crée des contenus de test, démarre le serveur, puis exécute ces contrôles. Le job navigateur installe le plugin à partir d’un ZIP via la CLI et vérifie ensuite la désactivation, la désinstallation et la conservation du contenu dans une instance jetable ; il ne teste pas le téléversement ZIP dans l’administration. Les résultats Playwright et le journal du serveur sont publiés comme artefact. Les scans axe sont limités aux violations sérieuses/critiques des écrans exercés : ils ne remplacent pas un audit WCAG manuel. Le `RETOUR.md` donne les résultats observés et les limites du prototype.
