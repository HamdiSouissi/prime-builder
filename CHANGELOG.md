# Journal des changements

## 0.1.0 — prototype partiel

- Intégration à l’éditeur alternatif et au bloc Prime v1 ; validation bornée, rendu HTML sauvegardé, permissions par contenu, API d’enregistrement et aperçu dans le thème actif.
- Interface de composition avec recherche, favoris, conteneurs flex/grid, propriétés responsive, navigation, déplacements, historique local, brouillon local, commande rapide, publication et planification via le CMS.
- 23 widgets inclus, dont médias, onglets, articles récents, contenu actuel et pont limité vers huit blocs statiques Prime. Chargement conditionnel de JavaScript public et replis lisibles sans JavaScript.
- Petit kit global, CSS de contenu à empreinte, instantanés publics et purge planifiée ; six modèles de section et trois modèles de page importables/exportables en JSON.
- Tableau de bord, diagnostics, catalogues français/anglais, sélecteur de médias du CMS et tests automatisés avec workflow CI.
- Protection des nouveaux documents mono-bloc contenant le mot exact `dynamic` contre la détection par chaîne du CMS, y compris après restauration d’une révision pendant que le plugin est actif.

**Non inclus :** Theme Builder, formulaires et stockage des soumissions, popups, widgets globaux, tags dynamiques et visibilité conditionnelle généralisés, ainsi que de nombreux widgets et commandes avancées. Voir [RETOUR.md](RETOUR.md) avant toute utilisation.
