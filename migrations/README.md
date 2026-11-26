# Migrations

La migration `2026_11_21_000001_create_prime_builder_form_tables.php` crée trois tables préfixées par le CMS :

- `prime_builder_submissions` : contenu, widget, nom, adresse e-mail, message et date UTC. L’adresse IP n’y est pas enregistrée ;
- `prime_builder_form_tokens` : empreintes des jetons déjà utilisés et leur expiration, séparées des messages pour empêcher un rejeu après suppression ;
- `prime_builder_form_limits` : compteurs temporaires par clé HMAC et expiration, sans IP en clair.

Le gestionnaire de plugins du CMS exécute la migration à l’activation et sa méthode `down()` à la désinstallation. **La désinstallation détruit tous les envois et compteurs** ; sauvegarder et vérifier la restauration au préalable. La désactivation seule conserve ces données. La tâche `prime-builder.purge-submissions` supprime les messages dépassant la durée configurée (30 jours par défaut, de 1 à 3 650 jours) ainsi que les jetons et compteurs expirés ; elle dépend du planificateur du CMS et ne remplace pas la gestion des sauvegardes ni des obligations de confidentialité.

Les instantanés de styles publics sont des fichiers sous le stockage privé du CMS ; le hook de désinstallation du plugin les supprime et la tâche `prime-builder.prune-styles` nettoie les fichiers anciens. Le CMS gère la suppression des réglages et capacités préfixés à la désinstallation. La vérification réelle du cycle de vie est exécutée sur une installation MySQL jetable par le workflow CI ; consulter `RETOUR.md` pour les résultats effectivement observés.
