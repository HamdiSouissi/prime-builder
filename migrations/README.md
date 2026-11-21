# Migrations

La version 0.1.0 n’ajoute aucune table SQL : elle s’appuie sur les contenus, révisions, réglages et capacités de Prime CMS. Ce dossier ne contient donc pas de migration exécutable.

Les fonctions de formulaires et leurs tables demandées ne sont pas implémentées. Les instantanés de styles publics sont des fichiers sous le stockage privé du CMS ; le hook de désinstallation du plugin les supprime et la tâche possédée `prime-builder.prune-styles` nettoie les fichiers anciens. Le CMS gère la suppression des réglages et capacités préfixés lors de la désinstallation.
