# Prime Builder — Suite n° 1 : le socle est validé, tu termines le constructeur

Ta livraison b413a3f sur arena/2d5ecf33-prime-builder est acceptée comme socle. Bravo : c'est la première version qui fonctionne vraiment, et ton RETOUR.md est honnête. Tu continues sur la même branche. Le cahier des charges PRIME-BUILDER-prompt.md fait toujours foi, et les règles de PRIME-BUILDER-nouvel-agent.md restent en vigueur : rien n'existe tant que ce n'est pas poussé, rien ne passe tant que tu ne l'as pas vu passer, aucune API inventée, aucun contournement.

## 1. Ce que la vérification a confirmé

Vérifié par le relecteur sur un Prime CMS 1.0.0 neuf et sur un site de démonstration :

- php -l, PHPStan niveau 8, PHP-CS-Fixer (avec la configuration du CMS) : aucune erreur.
- PHPUnit réel : OK (29 tests, 237 assertions). lang:check --strict est cohérent. npm test : 8 tests sur 8.
- Dans un navigateur : le tableau de bord, puis l'éditeur avec l'aperçu réel d'Aurora dans l'iframe ; ajout d'un conteneur et d'un titre, modification du texte, enregistrement. La page publique affiche le nouveau titre. Aucune erreur dans la console.
- Sécurité de l'API : live d'un brouillon sans connexion renvoie 404, et PATCH sans connexion renvoie 401.

## 2. Nouveaux points d'extension dans le CMS (Prime CMS 1.0.1, déjà sur main)

Tes propositions 1 et 2 de la section 9 de ton rapport sont faites dans le noyau (PR #12) :

- site.partial : SiteView::partial() applique apply('site.partial', null, string $name, PageContext $context, array $data). Si tu renvoies une chaîne, elle remplace le partial du thème (header et footer dans Aurora) ; si tu renvoies autre chose, le partial du thème est gardé. Le Theme Builder s'appuie sur ce filtre.
- Blocs dynamiques : EntryView::content() décide maintenant d'après les types de blocs décodés, et non plus d'après la présence du mot « dynamic » dans le JSON. Supprime ton contournement (celui qui modifie les sauvegardes contenant ce mot), ainsi que les tests qui ne portent que sur lui. Garde le test qui vérifie qu'une page reste lisible après désactivation du plugin.
- Mets requires.prime à >=1.0.1 dans plugin.json, et fais tourner ta CI sur main du CMS.

Ta proposition 3 (purge du cache selon les dépendances entre contenus) n'est pas retenue pour l'instant : garde l'actualisation côté client de la liste d'articles.

## 3. Corrections demandées sur le socle (à faire en premier)

- Perte de données sur un contenu existant. Ouvrir l'éditeur sur une page qui contient déjà des blocs Prime Editor affiche un confirm() natif, puis remplace ces blocs à l'enregistrement. C'est refusé. À la place :
  - conversion automatique : chaque bloc existant devient un widget « Bloc Prime » dans un conteneur, dans l'ordre. Les blocs que le pont ne sait pas éditer deviennent un élément « Contenu d'origine » non modifiable, qui garde leur HTML enregistré et que l'on peut déplacer ou supprimer ;
  - plus aucun confirm(), alert() ou prompt() natif dans l'éditeur : utilise une boîte de dialogue accessible, cohérente avec le style de l'éditeur (focus piégé puis rendu, Échap) ;
  - un test PHPUnit et un test e2e : une page existante de 3 blocs ouverte puis enregistrée sans modification garde ses 3 contenus sur le site.
- Contrôles de style : les couleurs sont des champs texte libres. Il faut un sélecteur de couleur avec les couleurs du kit (référencées, pas recopiées), et des champs nombre avec unité pour les tailles et espacements.
- « Déplacer vers » affiche des identifiants techniques (Conteneur · pb_7de4d…) : affiche le nom de l'élément dans le navigateur (son nom personnalisé, sinon son type suivi d'un extrait de son texte).
- Capacités : prime_builder.templates doit protéger la bibliothèque (insérer est permis avec edit, mais enregistrer, importer et supprimer exigent templates), prime_builder.settings les réglages du plugin, prime_builder.theme_builder le Theme Builder, prime_builder.forms les envois, et prime_builder.unfiltered_html le widget HTML. Un test par capacité : 403 sans, succès avec.
- Barre du haut : le message « Des modifications ne sont pas enregistrées… » est tronqué et pousse les boutons. Remplace-le par un indicateur court (pastille et libellé « Non enregistré »), et garde le message complet pour l'avertissement au départ de la page.
- Menu contextuel (clic droit et Maj+F10 : copier, coller, coller le style, dupliquer, enregistrer comme modèle, réinitialiser le style, supprimer), et parcours clavier complet démontré par un test e2e sans souris.

## 4. Ce qu'il reste à construire (par jalons, chacun vert en CI avant le suivant)

### Jalon A — Corrections de la section 3

Sortie : CI verte, et les tests demandés en section 3 présents et verts.

### Jalon B — Catalogue complet de widgets

Tous les widgets de la section 3.4 du cahier des charges qui manquent :

- base : icône, boîte image, liste d'icônes, témoignage, bascule, réseaux sociaux, carte OpenStreetMap, note par étoiles, carrousel d'images, HTML (sous capacité dédiée), zone du thème ;
- Pro : grille d'articles complète (requête configurable, pagination, cartes ou liste, sans N+1), portfolio filtrable, diaporama, carrousel de médias, carrousel de témoignages, avis, menu de navigation (menus du noyau, version mobile accessible), appel à l'action, boîte retournable, table de prix, liste de prix, compte à rebours, titre animé, boutons de partage, sommaire, code avec coloration, fil d'Ariane, recherche, logo et titre du site ;
- contenu courant : titre, extrait, contenu, image à la une, informations, précédent / suivant, boîte auteur, titre et liste d'archive.

Pour chaque widget : onglets Contenu, Style et Avancé ; un test PHPUnit de rendu ; motif ARIA et clavier pour les widgets interactifs ; JS et CSS chargés seulement si le widget est présent.

### Jalon C — Widgets globaux et contenu dynamique

Widgets globaux : modifier une fois met à jour partout, avec invalidation du cache de pages.
Balises dynamiques et conditions d'affichage (section 3.8 du cahier des charges), avec leurs tests.

### Jalon D — Theme Builder

Section 3.5 du cahier des charges, avec site.partial pour l'en-tête et le pied de page, et site.template_candidates / site.template pour contenu seul, archive, recherche et 404. Conditions, exclusions et priorités. Tests PHPUnit des conditions, et e2e : un en-tête construit remplace celui d'Aurora sur tout le site sauf une page exclue ; désactiver le plugin rend l'en-tête d'Aurora.

### Jalon E — Formulaires

Section 3.6 du cahier des charges, en entier : constructeur de champs, fichiers stockés hors du dossier public, étapes multiples, API publique sans CSRF dans le HTML, pot de miel, délai minimal, limite de débit, e-mails par la file, écran Envois (recherche, export CSV, suppression), conservation et purge planifiée. Tests de chaque protection et parcours e2e d'un envoi.

### Jalon F — Popups

Section 3.7 du cahier des charges : tous les déclencheurs, conditions et fréquence, dialogue accessible. Test e2e : ouverture par un déclencheur, Échap, focus rendu, et pas de réouverture selon la fréquence.

### Jalon G — Finitions

Section 3.10 du cahier des charges complète (menu, réglages, état du système), README et RETOUR finaux, version 1.0.0 du plugin.

## 5. Si tu manques de temps

Arrête-toi sur un jalon vert. Le RETOUR.md indique le dernier jalon terminé (SHA et run vert) et la liste exacte de ce qui reste, comme tu l'as fait cette fois-ci.

## 6. RETOUR.md

Garde ta structure actuelle. Ajoute :

- une section par jalon : SHA, lien du run, lignes finales des outils et vérifications manuelles ;
- le tableau de conformité ligne par ligne (une ligne par puce des sections 3.3 à 3.10 du cahier des charges et une ligne par widget), avec le statut « Fait et testé », « Fait sans test automatique » ou « Non fait ».
