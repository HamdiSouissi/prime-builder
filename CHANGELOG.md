# Journal des changements

## 1.0.5 — suite n° 8, cache des zones

- Prime CMS 1.0.3 et ultérieur purge les pages après `zone.saved` : les zones enregistrées ne déclenchent plus de lecture préalable ni de contournement global. Le minimum reste 1.0.1 ; sur 1.0.1/1.0.2, l’ancien contrôle conservateur demeure.
- Les zones Builder rendues propagent l’état temporel : une règle de dates, même dans le repli statique d’une zone native, ou une balise `{{today}}` résolue dans une copie contextuelle empêche le stockage de la réponse concernée. Les protections des visiteurs sont inchangées.
- Tests SQL et navigateur sur les deux générations, avec sauvegarde du pied de page et mesure de HIT avec une zone affichée ; les mutations sans événement, les rendus concurrents et les caches externes gardent leurs limites.

## 1.0.4 — suite n° 7, cache ciblé

- Le filtre de cache n’exécute aucune requête SQL ; une zone déclarée enregistrée continue de contourner le cache sur tout le site, après vérification préalable.
- Une période UTC évaluée, y compris cachée, ou `{{today}}` affiché empêche le stockage de la réponse concernée et impose `private, no-store` ; les autres pages ne sont pas affectées.
- Balises ordinaires et conditions de connexion ou de rôle restent cacheables pour les visiteurs anonymes ; cookies d’authentification et `Authorization` contournent la lecture. Les mutations prises en charge purgent le cache interne ; les caches externes et les rendus concurrents gardent leurs limites.

## 1.0.3 — suite n° 5, périmètre partiel

- Pièces de formulaire sur disque privé, par clé aléatoire et empreinte ; migration progressive des anciennes pièces en base, conservation des valeurs invalides et diagnostic. Verrouillage, synchronisation des fichiers/répertoires et collecte différée ; sauvegarde cohérente base + fichiers et planificateur indispensables.
- Refus d’une troncature multipart déclarée par les clients récents et d’une réponse de succès illisible ; les anciens clients restent compatibles. Avertissement distinct lorsque la limite API suffit au multipart mais pas à l’ancien JSON/base64.
- Comparaison `source_hash` et sauvegarde visuelle des modèles sous le même verrou ; les dernières métadonnées sont conservées. Contrôle de cohérence des widgets globaux entre instances. Les modifications concurrentes des seules métadonnées, les écritures directes et les caches externes gardent leurs limites ; voir la règle de cache 1.0.5 ci-dessus.

## 1.0.2 — formulaires de modèles

- Formulaires d’en-tête, pied, modèle de page et popup identifiés par modèle et nœud, avec protections et revérification du contexte ; migration additive. Les nouveaux champs personnalisés conservent un JSON versionné également exporté en CSV.
- Multipart avec repli JSON historique, limites configurables et DOCX ; validation des pièces sans analyse antivirus.

## 1.0.1 — index et cache des modèles

- Index autoloadé des conditions, lecture sélective des candidats et validation authentifiée des mises en page ; migration différée des modèles existants. Les blocs Prime et données invérifiables restent revalidés.
- Cache anonyme et invalidation conservatrice lors des changements pris en charge ; des tests navigateur vérifient les MISS/HIT réels, les modifications et l’isolation des visiteurs. La règle actuelle figure en 1.0.5 ; les limites des mutations directes, des requêtes concurrentes et des caches externes restent documentées.

## 1.0.0 — widgets partagés, contenu dynamique et administration

- Widgets globaux édités une fois et référencés sur plusieurs pages, avec purge du cache interne ; balises de site, contenu, champs autorisés et visiteur, et conditions de connexion, rôle, appareil et dates.
- Popups composés dans l’éditeur, conditions, cinq déclencheurs et fréquence par navigateur. Dialogue natif avec Échap et gestion du focus ; les déclencheurs automatiques ouvrent directement la fenêtre et une option exclut les visiteurs connectés.
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
