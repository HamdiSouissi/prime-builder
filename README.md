# Prime Builder

Constructeur de pages visuel complet pour **Prime CMS 1.0.0+**, sans aucune dépendance externe au runtime (PHP 8.2+ natif et JavaScript ES2022).

## Prérequis
- Prime CMS >= 1.0.0
- PHP 8.2+ avec extensions `mbstring`, `pdo_mysql`, `json`, `intl`

## Installation
1. **Par copie** : copiez ce dépôt dans `content/plugins/prime-builder/` de votre installation Prime CMS.
2. **Par archive ZIP** : compressez le dépôt et importez-le depuis **Plugins › Installer une archive**.
3. **Par CLI** : `php bin/prime plugin:install prime-builder.zip`

Puis activez le plugin :
```bash
php bin/prime plugin:activate prime-builder
```

## Prise en main
- **Modifier une page** : ouvrez une page dans l'administration et cliquez sur **Modifier avec Prime Builder**.
- **Modèles & Theme Builder** : accédez à **Prime Builder › Modèles**.
- **Formulaires** : consultez les envois dans **Prime Builder › Envois de formulaires** (export CSV disponible).
- **Kit du site** : configurez les couleurs et polices globales dans **Prime Builder › Kit du site**.

## Développement
```bash
node --test tests/js/editor.test.mjs
```
Pour ajouter un widget personnalisé depuis un plugin tiers, utilisez le filtre `prime_builder.widgets` (voir `docs/widgets.md`).
