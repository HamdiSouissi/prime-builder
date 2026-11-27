# Journal des changements

## En cours — suite n° 5, non livrée

- Les modèles statiques peuvent utiliser le cache anonyme du CMS ; invalidation conservatrice lors des changements pris en charge, avec contournement maintenu pour les zones enregistrées et les contenus dynamiques. Les limites des mutations directes, des requêtes concurrentes et des caches externes sont documentées.
- Un test navigateur vérifie les vrais MISS/HIT, les modifications de modèle, widget global et menu, ainsi que l’isolation des visiteurs. L’index des modèles et les évolutions des formulaires restent à faire.

## 1.0.0 — widgets partagés, contenu dynamique et administration

- Widgets globaux édités une fois et référencés sur plusieurs pages, avec purge du cache interne ; balises de site, contenu, champs autorisés et visiteur, et conditions de connexion, rôle, appareil et dates.
- Popups composés dans l’éditeur, conditions, cinq déclencheurs et fréquence par navigateur. Dialogue natif avec Échap et gestion du focus ; les déclencheurs automatiques ouvrent directement la fenêtre et une option exclut les visiteurs connectés. Les formulaires dans les popups restent à faire.
- Menu d’administration regroupé, sélection entre police héritée et Inter locale du CMS, palette CSS filtrée de l’éditeur, documentation et captures. Le kit et les contrôles avancés restent limités.
- Les aperçus natifs gardent un repli statique ; l’appareil est filtré par CSS et ne protège pas les données, les caches externes ne sont pas purgés. Cette version reste partielle : voir [README.md](README.md) et [RETOUR.md](RETOUR.md) avant utilisation.

## 0.1.2 — notifications de formulaire

- Notification administrateur et accusé facultatifs par la file du CMS, avec gabarits génériques ; une adresse administrateur est conservée dans les réglages privés, pas dans le document public.
- Transaction de l’envoi et des tâches, annulation à la suppression ou à la purge et migration des installations précédentes ; le worker, le transport et le planificateur doivent être configurés et surveillés.
- Une livraison externe suivie d’un crash peut être répétée ; journaux, fournisseurs et sauvegardes peuvent conserver des traces après suppression. Les popups, widgets globaux et balises dynamiques restent à faire ; voir [RETOUR.md](RETOUR.md).

## 0.1.1 — fichiers de formulaire privés

- Champ fichier borné à quatre pièces et 512 Kio au total, validation de l’extension et du type détecté, stockage privé en base, téléchargement réservé à `prime_builder.forms`, suppression et purge liées à l’envoi. Migration appliquée lors de la mise à niveau.
- Message de confirmation et redirection interne configurables sur le formulaire publié ; les formulaires antérieurs conservent leur message par défaut.
- Les notifications par e-mail, les popups, les widgets globaux, les balises dynamiques et plusieurs commandes avancées restent incomplets. Voir [RETOUR.md](RETOUR.md).

## 0.1.0 — prototype partiel

- Intégration à l’éditeur alternatif et au bloc Prime v1 ; validation bornée, rendu HTML sauvegardé, permissions par contenu, API d’enregistrement et aperçu dans le thème actif.
- Interface de composition avec recherche, favoris, conteneurs flex/grid, propriétés responsive, navigation, déplacements, historique local, brouillon local, commande rapide, publication et planification via le CMS. Les changements isolés utilisent un rendu serveur partiel après validation complète, avec repli sur un rendu complet ; le glisser-déposer permet de réordonner et imbriquer des éléments, avec commandes clavier et repères de destination.
- Catalogue de widgets, dont médias, onglets, articles récents, contenu actuel et pont limité vers huit blocs statiques Prime. Chargement conditionnel de JavaScript public et replis lisibles sans JavaScript ; voir le tableau détaillé et ses limites dans `RETOUR.md`.
- Petit kit global, CSS de contenu à empreinte, instantanés publics et purge planifiée ; six modèles de section et trois modèles de page importables/exportables en JSON.
- Tableau de bord, diagnostics, catalogues français/anglais, sélecteur de médias du CMS et tests automatisés avec workflow CI.
- Prérequis Prime CMS 1.0.1 ; 1.0.2 ou ultérieur corrigé recommandé pour la révocation des droits et les chargements concurrents. Un adaptateur HTTP limité protège les jetons sous 1.0.1 tant que le plugin reste actif.
- Theme Builder avec modèles d’en-tête, pied, contenu, archive, recherche et 404, éditeur et conditions ; anciennes copies conservées. Formulaires de contact avec champs personnalisés et étapes (hors fichiers), stockage des soumissions, recherche et export CSV protégés, conservation réglable et purge planifiée. Les anciens formulaires fixes restent utilisables.

**Non inclus ou incomplets dans 0.1.0 :** fichiers et actions des formulaires, popups, widgets globaux, tags dynamiques et visibilité conditionnelle généralisés, ainsi que plusieurs widgets et commandes avancées. Voir [RETOUR.md](RETOUR.md) avant toute utilisation.
