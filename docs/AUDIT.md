# Audit qualité — sylius-bulk-actions-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`.
> **Revue qualitative effectuée par l'orchestrateur (lecture directe des fichiers sources).**

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 59 tests, 1074 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 23 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (transitifs : `behat/transliterator`, `php-http/message-factory`) |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 23 fichiers signalés relèvent de règles `FunctionFirstClassCallableRector` — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

Les 2 paquets abandonnés sont des dépendances transitives héritées de Sylius ; non maîtrisables directement.

## 2. Audit qualitatif (revue par l'orchestrateur)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **9/10** | `declare(strict_types=1)` + classes `final` sur tous les fichiers `src/`, value object immuable `BulkActionResult`, interface bien séparée de l'implémentation, responsabilités fines. |
| Intégration Symfony / Sylius | **8/10** | Autoconfiguration, `tagged_iterator`, `BulkActionInterface` interfacée, réutilisation des événements Sylius Resource (`pre_/post_`). Un point de vigilance sur le `max_items` contrôlé deux fois (controller + processor). |
| Sécurité (OWASP 2025) | **8/10** | CSRF activé sur `BulkActionType`, `denyAccessUnlessGranted(ROLE_ADMINISTRATION_ACCESS)` en garde, validation et sanitisation des ids, `safeRedirect` contre les redirections ouvertes. Risque résiduel sur les actions d'état financier. |

### Points forts

- `BulkActionResult` : value object immuable (named constructors `applied/skipped/failed`), callback `afterFlush` en closure, aucun état mutable.
- `BulkActionProcessor` : gestion correcte des erreurs par item (log + `$manager->refresh()`), abort propre sur flush échoué, flush par lot.
- `BulkActionController` : `safeRedirect()` bloque les redirections cross-domain et les chemins relatifs, IDs validés par regex `/^\d{1,18}$/`, CSRF protégé par `BulkActionType`.
- `TransitionApplier` : `can()` avant `apply()`, pre-event stoppable traduit en `skipped`, post-event différé en callback afterFlush.
- `ResourceEventDispatcher` : filtre les paramètres non-scalaires avant de les exposer en flash.
- Autoconf : `BulkActionInterface` → `tmonier_bulk_actions.action` tag sans code manuel dans les classes action.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Fuite de message d'exception en flash** (`BulkActionProcessor.php:161`, OWASP A07:2025 / Mishandling of Exceptional Conditions). La méthode `failed($exception->getMessage())` stocke le message brut de l'exception dans la session flash et peut le rendre dans le rapport visible par l'administrateur. Un message d'exception interne (trace SQL, chemin de fichier, clé de configuration) peut fuire en interface. → Remplacer par `BulkActionResult::failed('tmonier_bulk_actions.fail.unexpected_error')` et logger le vrai message ; réserver le message brut aux erreurs métier connues.

2. **Pas d'ownership vérifié sur les actions d'état financier** (`CompletePaymentAction.php`, `CompleteOrderPaymentAction.php`, OWASP A01:2025). L'action `payment_complete` marque n'importe quel paiement comme payé dès que l'admin en a l'id, sans vérifier que le paiement appartient à une commande valide dans un canal auquel l'admin a accès. Dans un contexte multi-boutique/multi-canal, un admin d'un canal pourrait compléter des paiements d'un autre canal. → Ajouter un contrôle de canal dans `supports()` (ou dans la pre-condition Sylius Resource) si le projet est multi-canal avec des admins restreints.

3. **`max_items` contrôlé en double avec dérive possible** (`BulkActionController.php:61-62` et `BulkActionProcessor.php:42-43`). Le controller vérifie la taille de la liste initiale POST, mais si les ids sont manipulés entre `prepare` et `execute` (formulaire tampered), le processor est la vraie garde. Cette redondance est saine mais la limite dans le controller (`$this->processor->getMaxItems()`) fait une dépendance de service inutile pour une vérification de présentation. → Documenter le contrat : le controller fait une UX guard (fast-fail avant rendu), le processor fait le vrai enforcement. Envisager d'exposer la limite via un paramètre injecté dans le controller plutôt que via l'interface du processor.

### 🟡 Mineur

- **`BulkActionResult::failed` avec message brut d'exception** (`BulkActionProcessor.php:93`) : lors d'un flush échoué, le message d'exception DB (ex. contrainte SQL) est passé tel quel comme raison de l'item. Même risque que le point 1 ci-dessus, cohérence à assurer.
- **`CompleteOrderPaymentAction.php:63-67`** : la boucle sur `OPEN_STATES` appelle `getLastPayment()` 3 fois successivement ; un pattern `null coalescing` serait plus lisible : `$item->getLastPayment(STATE_NEW) ?? $item->getLastPayment(STATE_PROCESSING) ?? ...`.
- **`TrackingCodeShipper`** : pas de vérification que `$shipAction` est bien `ShipShipmentAction` (uniquement par convention). Si le code est appelé avec une autre action, le comportement peut être inattendu. → Typer l'argument ou ajouter un `Assert`.
- **`ItemLabeler.php:21-22`** : `getOrder()?->getNumber()` peut retourner `null` sur un paiement orphelin — la chaîne `'?'` est acceptable en fallback mais pas documentée comme cas normal.
- Appliquer les 23 suggestions Rector (`make rector-fix`) pour first-class callables.

### ℹ️ Pour information (choix de conception assumés)

- L'action `shipment_ship` depuis la grille ne passe pas de tracking code (normal : un code unique pour N colis n'a pas de sens) ; la page dédiée compense.
- Le registre mémoïse les actions au premier appel (`$this->actions = $actions`) — choix correct pour les requêtes multiples.
- L'abort sur flush échoué est intentionnel (entity manager potentiellement corrompu) et correctement documenté.

## 4. Verdict

Plugin **bien structuré et discipliné** : outillage au vert (ECS, PHPStan level max, 59 tests unitaires), value objects immuables, CSRF actif, redirections protégées, ids sanitisés. Le principal axe d'amélioration est la **fuite de messages d'exception** dans les flash bags (OWASP A07), qui doit être adressée avant une mise en production sur un environnement exposé. Le risque d'**ownership cross-canal** sur les actions financières est pertinent uniquement dans une configuration multi-boutique restreinte. Rien de bloquant côté outillage.
