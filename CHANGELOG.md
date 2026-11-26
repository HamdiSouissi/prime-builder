# Journal des changements

## 0.1.0 — prototype partiel

- Intégration à l’éditeur alternatif et au bloc Prime v1 ; validation bornée, rendu HTML sauvegardé, permissions par contenu, API d’enregistrement et aperçu dans le thème actif.
- Interface de composition avec recherche, favoris, conteneurs flex/grid, propriétés responsive, navigation, déplacements, historique local, brouillon local, commande rapide, publication et planification via le CMS. Les changements isolés utilisent un rendu serveur partiel après validation complète, avec repli sur un rendu complet ; le glisser-déposer permet de réordonner et imbriquer des éléments, avec commandes clavier et repères de destination.
- Catalogue de widgets, dont médias, onglets, articles récents, contenu actuel et pont limité vers huit blocs statiques Prime. Chargement conditionnel de JavaScript public et replis lisibles sans JavaScript ; voir le tableau détaillé et ses limites dans `RETOUR.md`.
- Petit kit global, CSS de contenu à empreinte, instantanés publics et purge planifiée ; six modèles de section et trois modèles de page importables/exportables en JSON.
- Tableau de bord, diagnostics, catalogues français/anglais, sélecteur de médias du CMS et tests automatisés avec workflow CI.
- Prérequis Prime CMS 1.0.1 ; 1.0.2 ou ultérieur corrigé recommandé pour la révocation des droits et les chargements concurrents. Un adaptateur HTTP limité protège les jetons sous 1.0.1 tant que le plugin reste actif.
- Theme Builder avec modèles d’en-tête, pied, contenu, archive, recherche et 404, éditeur et conditions ; anciennes copies conservées. Formulaire de contact fixe avec stockage des soumissions, recherche et export CSV protégés, conservation réglable et purge planifiée.

**Non inclus ou incomplets :** constructeur de formulaires, popups, widgets globaux, tags dynamiques et visibilité conditionnelle généralisés, ainsi que plusieurs widgets et commandes avancées. Voir [RETOUR.md](RETOUR.md) avant toute utilisation.
