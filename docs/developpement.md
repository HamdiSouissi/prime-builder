# Développement de Prime Builder

Ce document décrit l’implémentation 0.1.0, et non les fonctions encore absentes. Les classes et hooks du CMS cités ici ont été repérés dans le code de Prime CMS 1.0.2. Le CMS n’est pas modifié par le plugin.

## Structure

- `plugin.json` : manifeste et compatibilité déclarée ; `src/Plugin.php` : enregistrements et cycle de vie.
- `src/Controller/` : tableau de bord, éditeur, API, feuilles CSS.
- `src/Document/` : extraction du bloc, validation, modèles ; `src/Widgets/` : catalogue, rendu, contenu et pont limité vers des blocs Prime.
- `src/Styles/` : compilation autorisée, kit et instantanés CSS ; `views/`, `assets/` et `lang/{fr,en}/` : interface et traductions.
- `templates/` : modèles JSON ; `tests/` : tests PHP, modèle JS et Playwright/axe ; `.github/workflows/ci.yml` : CI.
- Aucune migration SQL n’est requise par les fonctions présentes. `migrations/README.md` explique cet état.

## Format et limites

Le document sauvegardé est un document Prime v1 contenant un unique bloc `prime-builder/layout` :

```json
{"version":1,"blocks":[{"id":"pb_layout","type":"prime-builder/layout","data":{"version":1,"elements":[{"id":"intro","type":"heading","settings":{"text":"Bonjour","level":2},"styles":{"desktop":{"color":"#123456"}},"children":[]}]}}]}
```

`Validator` limite les données de mise en page encodées à 1 Mio, 500 éléments et une profondeur de 12 ; les identifiants doivent être uniques et ne contenir que lettres, chiffres, `_` et `-`. Il vérifie le catalogue, les champs, les enfants et les propriétés CSS autorisées. Les métadonnées facultatives `label` (nom de l’élément dans le navigateur) et `hidden` (booléen) sont validées ; un élément masqué conserve son HTML avec l’attribut `hidden`, et ce mécanisme ne protège pas des données confidentielles. Les champs `rich` passent par `Prime\Security\HtmlSanitizer` ; les URL et valeurs CSS ont leurs propres listes de validation. Le `Renderer` échappe les sorties et isole les erreurs de rendu d’un widget.

Le bloc est enregistré avec `PluginContext::blocks()` ; `EntryService::update()` valide et sauvegarde le document, le HTML rendu, le texte et les révisions du CMS. `Converter` tente de convertir les blocs existants sans écrire à l’ouverture : huit types statiques peuvent devenir des widgets `prime_block` si leur rendu normalisé est identique au fragment enregistré ; les ancres des titres sont conservées. Les autres blocs peuvent être conservés en lecture seule avec leur HTML authentifié et chiffré, lié à l’entrée et à `APP_KEY`. Il refuse l’enregistrement si les fragments enregistrés ne peuvent être reconstitués sans ambiguïté ; ce n’est pas une conversion universelle. Une modification de `APP_KEY` rend les fragments conservés illisibles. Depuis Prime CMS 1.0.1, `EntryView::content()` détecte les blocs dynamiques par leur type décodé ; le plugin ne modifie plus la représentation JSON des contenus.

## Routes et sécurité

| Route | Usage et contrôle |
|---|---|
| `GET /admin/prime-builder` | Tableau de bord, administration et `prime_builder.edit`. |
| `GET /admin/prime-builder/edit/{id}` | Éditeur plein écran ; capacité du plugin et `EntryService::canEdit()` pour l’entrée et son type. |
| `GET/POST /admin/prime-builder/settings` | Réglages et kit ; `prime_builder.settings`, CSRF du CMS sur l’écriture, validation des valeurs avant enregistrement. |
| `PATCH /api/prime-builder/v1/documents/{id}` | Document v1 mono-bloc ; API du CMS, `prime_builder.edit`, contrôle par entrée, CSRF en session, validation et `EntryService::update()`. |
| `POST /api/prime-builder/v1/documents/{id}/render` | Même contrôle ; valide tout l’arbre et retourne l’aperçu HTML sans enregistrer. Le champ facultatif `target` demande le rendu d’un seul sous-arbre existant ; sans lui, la réponse contient aussi le document normalisé. |
| `GET /api/prime-builder/v1/documents/{id}/live` | Retours HTML des widgets de contenu ; entrée publiquement visible ou droits de modification ; `private, no-store`. La pile API du CMS applique sa limite de débit. |
| `GET /prime-builder/css/{id}-{empreinte}.css` | CSS de contenu. Entrée publique ou droit d’édition ; aperçu privé `no-store`. |
| `GET /prime-builder/kit/{empreinte}.css` | CSS du kit global. |

Les réglages du plugin ne sont pas enregistrés dans le catalogue générique du CMS : ils passent par une page dédiée, avec leur propre contrôle. Depuis Prime CMS 1.0.2, le catalogue générique applique lui aussi la capacité déclarée pour chaque section. Après validation, l’ensemble est enregistré en un seul réglage JSON `plugin.prime-builder.configuration` ; le noyau émet un seul `settings.updated` pour cette clé. Tant qu’une clé n’est pas présente dans ce groupe, les anciennes valeurs individuelles sont lues en repli. Après un premier enregistrement via la page, modifier directement une ancienne clé individuelle ne remplace pas la valeur présente dans le groupe. L’atomicité porte sur l’écriture SQL du groupe ; les effets externes des écouteurs du CMS et les exceptions qu’ils peuvent lever ne sont pas transactionnels. L’accès à la bibliothèque, à l’import et à l’export dans l’interface requiert `prime_builder.templates` ; il n’existe pas encore d’API de modèles enregistrés. Un client autorisé à modifier un document peut toujours composer lui-même un JSON équivalent.

L’API accepte les modes d’authentification du CMS, notamment une session avec en-tête `X-Prime-CSRF` ou un jeton porteur autorisé. Depuis Prime CMS 1.0.2, le noyau intersecte les capacités d’un jeton avec celles du compte courant et ne traite pas un `*` stocké comme un rôle administrateur. Le plugin utilise directement l’utilisateur effectif fourni par cette API, sans reconstruire ses capacités. La route `live` ne transmet pas les brouillons des articles listés : `EntryQuery` effectue une seule requête bornée aux articles publics pour les widgets de la requête. Le navigateur actualise les widgets concernés une fois par document ; le HTML de repli des articles ne contient pas de copie persistante des titres d’autres contenus.

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

Les types de champs reconnus sont `text`, `multiline`, `rich`, `integer`, `enum`, `href`, `url`, `slug`, `media_list` et `json_object`. Un `enum` fournit une liste `values` et peut fournir des `option_labels` dont les valeurs sont des clés de traduction. Le rendu fourni par un callback est encore filtré par `sanitizeBasic` : le HTML interactif arbitraire n’est donc pas garanti. Le code PHP d’une extension est du code de confiance ; il ne constitue pas un bac à sable. Un `script` public éventuel doit être un chemin local `/assets/plugins/{slug}/...js`, sans `..` ; il est enregistré uniquement si le widget est présent. Les champs sont génériques : il n’y a pas encore d’API d’éditeur personnalisée complète pour les widgets.

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
# Fournir E2E_BASE_URL, E2E_ENTRY_ID, E2E_LEGACY_ENTRY_ID,
# E2E_MOUSE_ENTRY_ID, E2E_KEYBOARD_ENTRY_ID et E2E_DRAG_ENTRY_ID, et si nécessaire
# E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD. Les trois identifiants de parcours pointent
# vers des pages de test vierges ; les deux premières ont les slugs
# parcours-souris et parcours-clavier.
npm test
```

Avec `PB_TEST_MYSQL=1`, chaque fixture d’intégration crée puis supprime une base aléatoire `prime_builder_test_*` : le compte MySQL doit avoir les droits `CREATE DATABASE` et `DROP DATABASE`. Ne jamais pointer ces tests vers une instance contenant des données réelles. PHPStan niveau 8 analyse le code source, les vues, les langues, les tests, les outils et la configuration du fixer ; sa configuration utilise l’autoloader de développement du CMS installé par Composer. La CI clone le CMS, installe MySQL 8 et les dépendances de développement, crée des contenus de test, démarre le serveur, puis exécute ces contrôles. Le job navigateur installe le plugin à partir d’un ZIP via la CLI et vérifie ensuite la désactivation, la désinstallation et la conservation du contenu dans une instance jetable ; il ne teste pas le téléversement ZIP dans l’administration. Les résultats Playwright et le journal du serveur sont publiés comme artefact. Les scans axe sont limités aux violations sérieuses/critiques des écrans exercés : ils ne remplacent pas un audit WCAG manuel. Le `RETOUR.md` donne les résultats observés et les limites du prototype.
