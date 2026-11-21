# Retour de mission — Prime Builder 0.1.0

## 1. État et conformité globale

Le dépôt contient un **prototype fonctionnel mais incomplet**, pas la totalité du constructeur demandé. L’édition et la publication de mises en page simples fonctionnent dans les scénarios testés. Le Theme Builder, les formulaires, les popups, les widgets globaux, les tags dynamiques et la visibilité conditionnelle généralisés, ainsi que de nombreux widgets et interactions avancées, restent à construire. Six capacités `prime_builder.*` sont enregistrées ; seule `prime_builder.edit` est actuellement vérifiée pour l’éditeur/API. La bibliothèque de modèles n’a pas de contrôle distinct `prime_builder.templates` et les réglages du CMS utilisent `settings.manage` plutôt que `prime_builder.settings`. Il ne faut pas considérer cette version comme prête pour une production sans travail et audit supplémentaires.

## 2. Branche, sources et état distant

- Branche de travail : `arena/2d5ecf33-prime-builder`, créée depuis `95c54eb9021c990373685e06f3a5e7994b775e48`.
- Tête distante observée par `git ls-remote` avant cette mise à jour du rapport : `bd9730cb1445712bbf57092ede8f3fe3f013fa08`. Ce rapport et d’éventuelles corrections ultérieures ajoutent des commits ; pour la tête actuelle, exécuter `git ls-remote origin refs/heads/arena/2d5ecf33-prime-builder`.
- Référence locale Prime CMS : `3c7e26d7632ab4c78d486dc254c5242a77296131`, version du noyau 1.0.0. Le code du CMS n’a pas été modifié ; une copie du plugin a été placée dans son dossier de plugins pour les essais.
- Dernier succès CI complet confirmé avant cette mise à jour : [run 44923960284](https://github.com/HamdiSouissi/prime-builder/actions/runs/44923960284), commit `bd9730cb1445712bbf57092ede8f3fe3f013fa08`. Les trois jobs, l’installation ZIP et les contrôles après désactivation et désinstallation ont réussi.

## 3. Intégration et édition

Le filtre `content.editor` active l’éditeur pour les pages par défaut, avec réglages pour articles et autres types. Le champ `content_json` caché est conservé dans l’éditeur alternatif. La route plein écran vérifie la capacité `prime_builder.edit` et l’autorisation de modification propre à l’entrée ; l’iframe de même origine charge l’aperçu du thème courant. La sauvegarde passe par l’API du CMS, la protection CSRF de session, la validation complète puis `EntryService::update()` et ses révisions.

L’interface comprend recherche, catégories et favoris, panneaux Contenu/Style/Avancé, conteneurs flex/grid imbriqués, glisser-déposer, structure et sélection du parent au clavier, annuler/rétablir, lien vers les révisions CMS, formats d’aperçu, palette de commandes, brouillon local, avertissement de départ et états brouillon/publié/planifié en UTC. La planification dépend du planificateur du CMS. Le parcours clavier complet, un menu contextuel complet et toutes les interactions exigées ne sont pas démontrés. Sur un thème sans `.pb-layout` ni `.aurora-prose`, l’aperçu remplace le contenu de `main` : son adaptation à tous les thèmes n’est pas garantie.

## 4. Widgets et bibliothèque

| Groupe | Fonctions présentes |
|---|---|
| Structure et base | Conteneur, titre, texte, image, bouton, séparateur, espace, citation, alerte, progression, ancre, liste. |
| Compléments | Accordéon, carte, boîte à icône, compteur, onglets, galerie, vidéo, audio. |
| Contenu | Articles récents, contenu actuel, pont « Bloc Prime » limité à huit blocs statiques non privilégiés. |

Il y a **23 widgets** enregistrés. Le pont Prime n’expose ni HTML libre, ni blocs dynamiques, ni tous les blocs et extensions du CMS. La galerie accepte jusqu’à 20 lignes URL/texte alternatif ; vidéo et audio acceptent une transcription, la vidéo une piste WebVTT. Les onglets sont lisibles sans JavaScript. La liste d’articles partage une requête bornée à 12 articles publics ; sans JavaScript ou API disponible, elle affiche un lien vers l’archive plutôt qu’une copie de titres susceptible de devenir privée. Le contenu actuel peut rester périmé en mode de repli.

Six sections et trois pages JSON sont fournies, avec une illustration vectorielle originale ; les modèles sont validés et peuvent être insérés, importés et exportés. Les modèles de contact ne sont pas des formulaires. L’extension `prime_builder.widgets` et ses limites sont documentées dans `docs/developpement.md`.

## 5. Documents, CSS et désactivation

Le document Prime v1 ne contient qu’un bloc `prime-builder/layout`. La validation limite le JSON de mise en page à 1 Mio, 500 éléments et une profondeur de 12 ; identifiants, types, champs, URL et styles sont contrôlés. Les styles acceptés sont compilés en CSS séparé pour le document et le kit, par empreinte. Des instantanés publics sont conservés dans le stockage privé du CMS ; la tâche `prime-builder.prune-styles` retire les fichiers inutilisés depuis plus de 366 jours. Une feuille privée reste `no-store` et n’est pas archivée. Si le stockage est non inscriptible, la réponse courante ne prétend pas être immuable. Les anciennes URL dont aucun instantané n’a été conservé ne sont pas reconstruites après modification.

Le HTML statique est enregistré par le CMS. Dans l’essai local HTTP-WASM, après sauvegarde du mot exact `dynamic`, une **nouvelle requête sans activation du plugin** a bien renvoyé `<h2>dynamic</h2>` sans assets du plugin. Le run CI `44923074044` a également réussi une nouvelle requête HTTP après désactivation réelle du plugin ; un test de restauration de révision puis de désactivation couvre ce cas. Le contournement n’est appliqué qu’aux nouvelles sauvegardes mono-bloc pendant que le plugin est actif : des documents ou révisions plus anciens peuvent encore déclencher la détection par chaîne du noyau et perdre leur repli. Les fonctionnalités interactives et le style du plugin ne subsistent pas après désactivation.

## 6. Sécurité et limites connues

- La validation du document, les contrôles par entrée, les tests d’accès non autorisé/CSRF et l’assainissement HTML/URL sont présents ; les données affichées par les widgets de contenu sont échappées. Les scripts publics du plugin sont externes et conditionnels ; la prévisualisation utilise des attributs de style, pas de feuille `<style>` injectée.
- Les requêtes à l’origine pour les CSS historiques restent soumises au statut public actuel du contenu. Une feuille déjà mise en cache publiquement dans un navigateur ou un CDN ne peut toutefois pas être révoquée avant son expiration, même si le contenu devient privé. Le contenu courant d’un brouillon n’est accessible à la route `live` qu’avec les droits de modification. Le fournisseur d’articles ne persiste pas d’extraits d’autres contenus dans le HTML de repli.
- Le code PHP d’un widget tiers est de confiance ; son résultat HTML est filtré, mais ce mécanisme n’est pas un bac à sable. La politique CSP effective dépend aussi du CMS, du thème et des URL des médias saisis.
- Il n’existe pas de formulaire : aucune sécurité d’upload privé, anti-spam, file d’e-mails, rétention, export CSV ou suppression de soumissions n’a été implémentée ni testée. Il n’existe pas non plus de gestion des déclencheurs et fréquences de popups.
- Les tests automatisés ne constituent ni un audit de sécurité indépendant ni une preuve de conformité WCAG 2.2 AA complète.

## 7. Tests et CI observés

Le [run 44923960284](https://github.com/HamdiSouissi/prime-builder/actions/runs/44923960284) a terminé avec succès les trois jobs : modèle JavaScript, PHPUnit réel/PHPStan niveau 8/PHP-CS-Fixer/traductions strictes, et Playwright/axe. Sur un CMS de test avec MySQL 8, le job navigateur a installé le ZIP par la CLI, activé le plugin, puis vérifié après désactivation et désinstallation les réponses publiques et le repli `<h2>dynamic</h2>`. La désinstallation a aussi été vérifiée pour les fichiers du plugin, les assets publiés, les instantanés CSS, un réglage, une capacité attribuée et la conservation du contenu et du droit administrateur. Les captures et le journal serveur sont conservés dans l’artefact `prime-builder-e2e-results` (ID `14714652462`). Le dépôt déclare 29 méthodes de test PHP, 8 tests du modèle JS et 13 scénarios Playwright ; les nombres détaillés dans les journaux distants ne sont pas reproduits ici.

En local, le ZIP du commit `bd9730c` a aussi été inspecté et installé par `PluginInstaller` dans une fixture PHP-WASM/SQLite : 83 entrées, aucun avertissement. Ce n’est pas une preuve du parcours de téléversement depuis l’interface d’administration, qui n’a pas été testé séparément.

Le [run 44922704633](https://github.com/HamdiSouissi/prime-builder/actions/runs/44922704633) a échoué au test navigateur ajouté pour les CSS historiques : son sélecteur visait à la fois le CSS de base et le CSS du document. Les jobs PHP et JavaScript ont réussi ; le sélecteur a ensuite été corrigé dans `b5cf80f`, dont le run `44923074044` a réussi. Ne pas interpréter cet ancien échec comme un succès.

En local, `npm test` a exécuté 8 tests du modèle avec succès ; PHPStan niveau 8 a signalé zéro erreur et PHP-CS-Fixer local n’a demandé aucune modification après correction. Un harnais d’assertions PHP-WASM a exécuté 29 méthodes de test avec succès, **mais ce harnais n’est pas PHPUnit**. Le contrôle ciblé des traductions n’a signalé ni clé inconnue ni chaîne en dur ; le contrôle strict réel appartient à la CI. Le serveur PHP-WASM de fixture a présenté des erreurs `accept/poll` et a dû être redémarré.

Les scénarios navigateur observés incluent l’éditeur et le site à 1440 px, un éditeur à 375 px, le tableau de bord mobile, l’iframe réelle du thème, le sélecteur de médias simulé, la publication, les onglets au clavier et le mode sans JavaScript. Les contrôles axe des parcours réussis ne remontaient pas de violation sérieuse/critique ; un contrôle local isolé a toutefois signalé une fois `target-size`, non reproduit lors d’une relance de la même page. Un audit manuel reste nécessaire.

## 8. Mesures et performances

Mesures de fichiers non compressés effectuées avec `wc -c` : `assets/js/tabs.js` **2 069 octets**, `assets/js/content.js` **1 050 octets**, soit **3 119 octets** si les deux scripts publics du plugin sont nécessaires ; `assets/css/public.css` **2 042 octets**. Cela exclut le thème, le CMS, les extensions, les réponses de l’API, les médias et le JavaScript de l’éditeur. Aucune mesure de poids réseau complet, de LCP, de temps serveur ou de charge n’a été effectuée ; aucune garantie de performance globale n’est revendiquée.

Dans la fixture locale, une URL CSS publique a été relue après modification et a renvoyé les mêmes octets avec `Cache-Control: public, max-age=31536000, immutable`. L’invalidation des pages et l’absence de N+1 pour les seules listes d’articles implémentées ne prouvent pas le comportement d’un futur système général de contenu croisé.

## 9. Points d’extension du CMS et propositions

Les points existants utilisés comprennent `content.editor`, `content.saving`, `settings.register`, `site.assets`, `system.checks`, `scheduler.register`, les registres de blocs/éditeurs et l’API des plugins. `site.template_candidates` et `site.template_paths` existent dans `src/Site/TemplateResolver.php`, mais ne remplacent pas à eux seuls un en-tête ou pied de page universel : le thème Aurora les appelle dans `templates/layouts/base.php` via `$site->partial('header')` et `$site->partial('footer')`.

Propositions à discuter côté CMS, **non implémentées** ici :

1. Dans `src/Site/View/SiteView.php::partial(string $name, array $data = []): string`, un filtre par exemple `site.partial` recevant `(string $html, string $name, PageContext $context): string` permettrait aux plugins de remplacer un partial utilisé par le thème. Pour couvrir aussi les thèmes ne passant pas par `partial()`, il faudrait un contrat explicite de slots sémantiques header/footer dans les layouts. Les permissions, conditions, priorités, échappement et repli doivent être définis avant usage.
2. Dans `src/Site/View/EntryView.php::content()`, détecter les blocs dynamiques à partir des **types des blocs décodés**, et non de la sous-chaîne brute `"dynamic"`. Cela éliminerait la collision et permettrait de traiter correctement les documents historiques sans réécriture par le plugin.
3. Pour un futur rendu serveur de contenus croisés, dans `src/Cache/CachePurgeSubscriber.php` / `PageCache`, prévoir une API de dépendances permettant d’associer une page et les IDs de contenus utilisés, puis d’invalider les pages dépendantes lors des événements de contenu. Les widgets actuels utilisent une actualisation sans cache côté client plutôt que ce mécanisme.

## 10. Reste à faire et chemin de vérification

Priorités : implémenter et tester le Theme Builder avec conditions/priorités ; concevoir les formulaires complets et leur sécurité/stockage ; ajouter popups accessibles et règles de fréquence ; widgets globaux, tags dynamiques et visibilité ; compléter le catalogue, le menu contextuel et les alternatives clavier ; réaliser audits WCAG, sécurité et performance sur plusieurs thèmes. Prévoir migrations et nettoyage pour toutes les futures données.

Pour reproduire : suivre les trois méthodes d’installation du `README.md`, puis les commandes de `docs/developpement.md` dans un CMS et une base de test. Contrôler le statut de la dernière CI avec `gh run list --branch arena/2d5ecf33-prime-builder` et télécharger l’artefact E2E depuis le run concerné. Vérifier aussi la désactivation sur une copie contenant des contenus et révisions antérieurs au correctif ; aucun résultat universel n’a été établi pour ceux-ci.
