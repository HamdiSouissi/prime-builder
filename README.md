# Prime Builder

Plugin de composition visuelle pour Prime CMS 1.0.0 et versions ultérieures compatibles. Il utilise le moteur de contenu et le thème actifs de Prime CMS, sans dépendance PHP ou JavaScript tierce à l’exécution.

> **État : prototype partiel, version 0.1.0.** Il ne constitue pas encore un remplacement complet d’un constructeur visuel professionnel. Le Theme Builder, les formulaires, les popups, les widgets globaux et de nombreuses fonctions demandées ne sont pas implémentés. Ne l’utilisez pas en production sans revue, sauvegarde et tests adaptés à votre site. Voir [RETOUR.md](RETOUR.md).

## Prérequis

- Prime CMS 1.0.0 avec PHP 8.2–8.x et les extensions requises par le CMS. Le manifeste déclare Prime ≥ 1.0.0 et PHP `^8.2` ; la compatibilité des futures versions n’a pas été vérifiée.
- Accès à l’administration et aux capacités nécessaires : `prime_builder.edit` **et** les permissions de modification du contenu concerné. La publication requiert en outre la permission de publication du type de contenu. La bibliothèque n’a pas de contrôle distinct `prime_builder.templates` ; les réglages utilisent `settings.manage` du CMS, et non `prime_builder.settings`.
- API REST du CMS activée pour enregistrer et actualiser les aperçus. Le dossier de stockage de Prime doit être accessible en écriture pour conserver les anciennes feuilles CSS à empreinte.
- Un navigateur moderne avec JavaScript pour l’éditeur. Le contenu statique enregistré reste lisible sans JavaScript ; les listes d’articles utilisent alors un lien de repli vers l’archive.

## Installation

La racine de ce dépôt est le dossier du plugin : `plugin.json` doit se trouver directement dans `content/plugins/prime-builder/`. Choisir **une** des méthodes suivantes, puis activer le plugin. Prévoir une sauvegarde et utiliser une instance de test.

### 1. Copie du dossier

Copier les fichiers du dépôt dans `content/plugins/prime-builder/`, sans `.git`, `.arena` ni `node_modules`, puis, depuis le dossier du CMS :

```bash
php bin/prime plugin:activate prime-builder
```

Si nécessaire, `php bin/prime plugin:publish-assets prime-builder` republie les fichiers statiques. Cette méthode ne modifie pas le code du CMS.

### 2. Téléversement ZIP dans l’administration

Depuis un checkout Git du dépôt, produire une archive dont l’unique dossier racine est `prime-builder/` :

```bash
git archive --format=zip --prefix=prime-builder/ HEAD -o prime-builder.zip
```

Dans Prime CMS, ouvrir **Extensions**, téléverser l’archive par l’écran d’installation, puis activer Prime Builder. Le ZIP généré contient l’état committé, pas les modifications de travail non committées.

### 3. Installation ZIP en ligne de commande

Avec la même archive, depuis le dossier du CMS :

```bash
php bin/prime plugin:install /chemin/vers/prime-builder.zip
php bin/prime plugin:activate prime-builder
```

Le CMS installe le ZIP inactif. Voir son aide avant d’utiliser `--replace` ou `--force` pour remplacer une installation existante.

## Premiers pas

1. Dans **Prime Builder**, vérifier les types activés. Les pages sont activées par défaut ; articles et autres types peuvent l’être dans les réglages généraux. Enregistrer d’abord tout nouveau contenu.
2. Ouvrir le contenu dans l’administration, puis **Modifier avec Prime Builder**. L’aperçu est une iframe de même origine utilisant le thème du site.
3. Ajouter des éléments depuis la recherche ou les catégories. Un conteneur sélectionné reçoit les nouveaux éléments ; le panneau **Structure** et la commande **Déplacer vers** permettent aussi de réorganiser au clavier. Les onglets **Contenu**, **Style** et **Avancé** exposent les réglages disponibles.
4. Choisir ordinateur, tablette ou mobile pour ajuster les valeurs correspondantes. Les valeurs absentes héritent des tailles supérieures. Les points de rupture et le petit kit global de couleurs/taille se règlent dans le CMS.
5. Choisir brouillon, publié ou planifié (date saisie en UTC), puis **Enregistrer**. L’historique local annuler/rétablir est distinct des révisions du CMS, accessibles par le lien de révisions. Le CMS doit avoir son planificateur opérationnel pour les publications programmées.

Raccourcis : `Ctrl/⌘ S` enregistrer, `Ctrl/⌘ K` ouvrir les commandes, `Ctrl/⌘ Z` annuler et `Ctrl/⌘ Y` ou `Ctrl/⌘ Maj Z` rétablir hors des champs de texte. Les modifications non enregistrées déclenchent un avertissement ; une copie locale par utilisateur et contenu peut être proposée à la réouverture. Ce stockage navigateur n’est pas une sauvegarde serveur.

**Attention à la conversion :** un contenu utilisant d’autres blocs Prime n’est pas converti automatiquement. Le constructeur demande confirmation avant de remplacer son document par une mise en page Prime Builder. Exporter ou sauvegarder le contenu d’origine avant de confirmer.

## Éléments et modèles disponibles

Les 23 widgets inclus sont : conteneur, titre, texte, image, bouton, séparateur, espace, citation, alerte, progression, ancre, liste, accordéon, carte, boîte à icône, compteur, onglets, galerie, vidéo, audio, articles récents, contenu actuel et bloc Prime. Les médias locaux peuvent être choisis avec le sélecteur du CMS ; la vidéo accepte une piste WebVTT et une transcription. Les onglets restent lisibles sans JavaScript.

- **Articles récents** affiche au maximum 12 articles publics ; sans JavaScript ou si l’API est indisponible, un lien vers l’archive est conservé à la place de titres potentiellement périmés.
- **Contenu actuel** affiche titre, extrait, auteur ou date. La valeur sauvegardée peut être ancienne si JavaScript ou l’API est indisponible.
- **Bloc Prime** accepte un objet JSON pour l’un des huit types statiques autorisés : paragraphe, titre, liste, citation, code, séparateur, tableau ou encadré. Le bloc HTML libre, les blocs dynamiques et les autres types ne sont pas pris en charge par ce pont.
- La bibliothèque contient six sections et trois pages. Les modèles de contact ne sont que des compositions statiques : ils ne fournissent **aucun formulaire fonctionnel**. Les compositions s’importent et s’exportent en JSON ; les URL d’images ne sont pas embarquées dans l’export.

## Désactivation et désinstallation

```bash
php bin/prime plugin:deactivate prime-builder
# Facultatif et destructif pour les réglages/capacités/fichiers du plugin :
php bin/prime plugin:uninstall prime-builder
```

Le HTML rendu est enregistré dans les contenus par le CMS et sert normalement de repli lorsque le plugin est désactivé ; l’apparence, les onglets interactifs et l’actualisation des données ne sont alors plus garantis. Un correctif protège les nouveaux enregistrements du mot exact `dynamic` ; les documents ou révisions enregistrés avant ce correctif ne sont pas réparés automatiquement. Testez la désactivation sur une copie du site. La désinstallation retire également les instantanés CSS du plugin, ses réglages et capacités ; elle ne supprime pas les contenus enregistrés.

## Développement et vérification

Consulter [docs/developpement.md](docs/developpement.md) pour le format, les hooks, l’API, les commandes de test et l’extension des widgets. [RETOUR.md](RETOUR.md) distingue les vérifications réellement observées des fonctionnalités absentes. Les licences et provenance des éléments sont décrites dans [LICENSES.md](LICENSES.md).
