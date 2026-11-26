# Migrations

La migration `2026_11_21_000001_create_prime_builder_form_tables.php` crée trois tables préfixées par le CMS :

- `prime_builder_submissions` : contenu, widget, nom, adresse e-mail, message et date UTC. L’adresse IP n’y est pas enregistrée ;
- `prime_builder_form_tokens` : empreintes des jetons déjà utilisés et leur expiration, séparées des messages pour empêcher un rejeu après suppression ;
- `prime_builder_form_limits` : compteurs temporaires par clé HMAC et expiration, sans IP en clair.

La migration `2026_11_21_000002_create_prime_builder_form_files.php` ajoute `prime_builder_form_files` : nom, type détecté, taille et octets encodés en base64, liés à un envoi. Il n’y a pas de fichier dans la médiathèque ou le dossier public. Cette migration est déclenchée sur une installation active lors de la mise à niveau du plugin vers `0.1.1` et à l’activation d’une nouvelle installation.

La migration `2026_11_21_000003_create_prime_builder_form_notifications.php` ajoute `prime_builder_form_notifications` : référence à l’envoi, type, destinataire et identifiant de tâche du CMS. Le destinataire n’est pas dans le payload de la file, qui ne contient qu’un identifiant technique. Elle est appliquée à la mise à niveau vers `0.1.2` et à l’activation d’une nouvelle installation ; les messages et fichiers existants sont conservés.

Le gestionnaire de plugins du CMS exécute les migrations à l’activation et leurs méthodes `down()` à la désinstallation. **La désinstallation détruit tous les envois, pièces jointes, notifications et compteurs** ; sauvegarder et vérifier la restauration au préalable et interrompre le trafic public et les workers. Le CMS peut continuer après une erreur du hook : contrôler les journaux et le résultat, car la synchronisation n’est pas garantie dans ce cas. La désactivation seule conserve ces données. La tâche `prime-builder.purge-submissions` supprime les messages, leurs pièces jointes et notifications ainsi que les tâches associées lorsqu’ils dépassent la durée configurée (30 jours par défaut, de 1 à 3 650 jours) ; elle nettoie aussi les jetons et compteurs expirés. Le worker refuse de livrer une notification déjà expirée même si la purge n’a pas encore tourné. Un e-mail déjà transmis ne peut pas être rappelé ; les journaux, les fournisseurs et les sauvegardes restent hors de cette purge et doivent être gérés séparément.

Les instantanés de styles publics sont des fichiers sous le stockage privé du CMS ; le hook de désinstallation du plugin les supprime et la tâche `prime-builder.prune-styles` nettoie les fichiers anciens. Le CMS gère la suppression des réglages et capacités préfixés à la désinstallation. La vérification réelle du cycle de vie est exécutée sur une installation MySQL jetable par le workflow CI ; consulter `RETOUR.md` pour les résultats effectivement observés.
