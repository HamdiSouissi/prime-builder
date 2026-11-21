# Journal des changements

## 0.1.0 — prototype partiel

- Intégration à l’éditeur alternatif et au bloc Prime v1 ; validation bornée, rendu HTML sauvegardé, permissions par contenu, API d’enregistrement et aperçu dans le thème actif.
- Interface de composition avec recherche, favoris, conteneurs flex/grid, propriétés responsive, navigation, déplacements, historique local, brouillon local, commande rapide, publication et planification via le CMS. Les changements isolés utilisent un rendu serveur partiel après validation complète, avec repli sur un rendu complet ; le glisser-déposer permet de réordonner et imbriquer des éléments, avec commandes clavier et repères de destination.
- 23 widgets inclus, dont médias, onglets, articles récents, contenu actuel et pont limité vers huit blocs statiques Prime. Chargement conditionnel de JavaScript public et replis lisibles sans JavaScript.
- Petit kit global, CSS de contenu à empreinte, instantanés publics et purge planifiée ; six modèles de section et trois modèles de page importables/exportables en JSON.
- Tableau de bord, diagnostics, catalogues français/anglais, sélecteur de médias du CMS et tests automatisés avec workflow CI.
- Prérequis relevé à Prime CMS 1.0.2 : les droits effectifs des jetons sont maintenant ceux fournis par le noyau, sans double intersection dans le plugin. Le texte extrait sépare les éléments de bloc avant de normaliser les espaces.

**Non inclus :** Theme Builder, formulaires et stockage des soumissions, popups, widgets globaux, tags dynamiques et visibilité conditionnelle généralisés, ainsi que de nombreux widgets et commandes avancées. Voir [RETOUR.md](RETOUR.md) avant toute utilisation.
