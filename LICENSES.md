# Licences et provenance

## Plugin et éléments fournis

Le manifeste `plugin.json` déclare la licence `proprietary`. Aucune licence libre ni autorisation de redistribution supplémentaire n’est accordée par ce fichier ; demander au titulaire des droits avant toute redistribution ou utilisation non autorisée. Les noms Prime CMS et Prime Builder identifient ici le CMS et ce plugin ; aucune affiliation à un autre constructeur n’est revendiquée.

- `assets/icon.svg` et `assets/images/landscape.svg` sont des dessins vectoriels originaux réalisés pour ce dépôt. Le paysage est une illustration abstraite sans personne. Les modèles JSON utilisent cet élément ou des liens saisis dans le modèle ; aucune photographie de tiers n’est fournie.
- Le code du CMS et son sélecteur de médias ne sont pas copiés dans ce dépôt. Le plugin appelle le sélecteur fourni par l’installation de Prime CMS ; les conditions de licence du CMS s’appliquent séparément. La police Inter locale optionnelle utilise le fichier fourni par le CMS (`public/assets/fonts/inter/InterVariable.woff2`) ; son fichier `OFL.txt` dans cette même installation contient sa licence SIL Open Font License.
- Les médias ajoutés par les utilisateurs ou les extensions restent soumis aux droits de leurs titulaires. Un export JSON conserve leurs URL, sans transférer de licence ni copier les fichiers.

## Outils de test et de développement

Ces outils ne sont pas embarqués comme dépendances d’exécution du plugin :

| Outil | Licence déclarée par le paquet | Provenance |
|---|---|---|
| `@playwright/test`, Playwright | Apache-2.0 | [microsoft/playwright](https://github.com/microsoft/playwright), versions verrouillées dans `tests/e2e/package-lock.json` |
| `@axe-core/playwright`, axe-core | MPL-2.0 | [dequelabs/axe-core-npm](https://github.com/dequelabs/axe-core-npm) et [dequelabs/axe-core](https://github.com/dequelabs/axe-core) |
| PHPUnit | BSD-3-Clause | Dépendance de développement du CMS |
| PHPStan, PHP-CS-Fixer | MIT | Dépendances de développement du CMS |

Le fichier de verrouillage E2E et le fichier `composer.lock` du CMS utilisé pour les tests recensent aussi les dépendances transitives et leurs versions. Vérifier leurs notices et licences avant redistribution des outils ou des artefacts de test.
