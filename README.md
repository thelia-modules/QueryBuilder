# QueryBuilder

Moteur de règles générique pour Thelia 2.5+ : l'administrateur édite des arbres
de conditions (lib [react-querybuilder](https://react-querybuilder.js.org) en
mode buildless) qui sont compilés en SQL paramétré côté serveur et déclenchent
des actions (liste de produits, traitements…) sur les hooks du front-office ou
via un endpoint JSON.

Le module est autonome et sans dépendance projet : les spécificités d'une
instance (champs ERP, filtres catalogue…) sont apportées par les autres
modules via les points d'extension ci-dessous.

## Concepts

| Concept | Support | Description |
|---|---|---|
| Rule | table `query_builder_rule` | Contexte (PRODUCT, CART…), hooks déclencheurs, arbre de conditions optionnel (vide = toujours), activation |
| Action | table `query_builder_action` | Rattachée à une règle : code → classe PHP, type `display`/`action`/`filter`, arbre sélectionnant les produits, paramètres JSON |
| Context | enum `QueryBuilder\Enum\Context` | GLOBAL, CATEGORY, BRAND, PRODUCT, CART, ORDER, CUSTOMER — filtre hooks, champs et actions disponibles |
| Field / Join | dictionnaire YAML | Champs proposés dans l'éditeur et graphe de jointures pour le SQL |

## Dictionnaire de données (YAML)

Le fichier de base est `Config/query_builder.yml` (tables core Thelia). Tout
module **actif** peut exposer son propre `Config/query_builder.yml`, fusionné
par-dessus (l'en-tête du fichier de base documente la syntaxe complète :
`joins`, `fields`, `contexts`, `disabled`, `disabled_hooks`).

Deux types de champs :

- `field: table.column` — jointures ajoutées automatiquement au `FROM` en
  remontant le graphe `joins` jusqu'à `product` ;
- `expression: <SQL>` — expression scalaire autonome (sous-requête autorisée),
  pouvant référencer des **placeholders runtime** : `:customer_id`,
  `:cart_product_ids`, `:product_id`, `:locale`… plus ceux fournis par les
  `RuntimeParameterProviderInterface` du projet.

Un placeholder runtime sans valeur dans le contexte d'exécution fait échouer
la compilation (règle ignorée, warning loggé). Exception : `:product_id` est
bindé à la sentinelle `0` hors fiche produit, si bien que « Est le produit de
la fiche courante » reste exécutable partout — `= faux` laisse tout passer,
`= vrai` ne matche rien.

Une `expression` peut contenir le token **`:value`** : la valeur saisie dans
l'éditeur est alors injectée dans l'expression (un placeholder bindé par
élément) au lieu de lui être comparée. Le champ doit restreindre ses
`operators` à `in`/`notIn`, qui ne portent plus que la polarité : `parmi`
garde les produits pour lesquels l'expression est vraie, `pas parmi` les
autres (liste vide : `parmi` ne matche rien, `pas parmi` laisse tout passer).
Cas d'usage : « Catégorie du produit déjà achetée par le client (3 derniers
mois) » `pas parmi [Fromages à pizza, Pâtons]` — exclut les produits d'une
catégorie choisie dont le client a déjà acheté un produit de la même
catégorie, sans jamais impacter les produits des autres catégories (champ
déclaré dans l'override Scal, son `values_query` s'appuyant sur les
catalogues clients).

Un champ peut déclarer `values_query: <SQL>` (colonne `value` obligatoire,
`label` et `group` optionnelles, `:locale` autorisé) : l'éditeur BO propose
alors une liste déroulante des valeurs possibles (multiselect pour
`in`/`notIn`) au lieu d'un champ libre. Une colonne `group` découpe la liste
en sections `<optgroup>`, dans l'ordre des lignes SQL (ex : catégories
groupées par catalogue client). La requête n'est exécutée que sur les écrans
d'édition ; au-delà de 300 valeurs ou en cas d'erreur SQL, retour silencieux
au champ libre (loggé).

Les hooks de la section `contexts:` se déclarent soit par leur code seul,
soit en `{code, label}` : le libellé fonctionnel est ce que voit l'admin dans
l'éditeur de règles (défaut : le code). La fusion des overrides fait l'union
par code, le dernier module actif gagnant sur le libellé. Politique : **un
hook déclaré = un emplacement réellement branché côté front** — ne pas
déclarer un hook « en avance », l'admin cocherait un emplacement sans effet.

Sécurité : seuls les champs du dictionnaire et une liste blanche d'opérateurs
sont acceptés ; toutes les valeurs sont bindées (PDO). Aucun SQL ne provient
du JSON stocké en base. Les `values_query` proviennent des YAML commités
(même niveau de confiance que les `expression`), jamais d'une saisie
utilisateur.

## Points d'extension (services autoconfigurés)

- `QueryBuilder\Query\QueryScopeInterface` — restreint toutes les requêtes
  produits (ex : catalogue du client connecté) ;
- `QueryBuilder\Query\RuntimeParameterProviderInterface` — expose des
  placeholders projet (ex : `:customer_typology_id`) ;
- `QueryBuilder\Query\ProductOrderProviderInterface` — le classement projet
  des produits sélectionnés (ex : classement par typologie de client) ;
- `QueryBuilder\Query\ProductFamilyProviderInterface` — la notion projet de
  « famille » d'un produit (ex : niveau 2 de l'arbre catalogue), pour le panachage ;
- `QueryBuilder\Action\ActionInterface` — nouvelle action prédéfinie
  (`DisplayProductsList` et `ApplyDiscount` sont fournies).

## Sélection des produits (`ProductSelector`)

Toute sélection de produits — bloc d'affichage, produits remisés d'une action
limitée ou persistée — passe par `QueryBuilder\Service\ProductSelector`, seule
source du classement :

1. **Classement SQL** : expressions des `ProductOrderProviderInterface` (projet),
   puis les produits *promus* par l'appelant (les actions d'affichage promeuvent
   les produits actuellement remisés par une règle : à qualification égale, un
   produit en offre passe devant), puis la rotation des cycles sticky (jamais
   proposé d'abord, puis fin de cycle la plus ancienne), puis `product.id`.
   Le `LIMIT` d'une sélection s'applique après ce tri.
2. **Panachage PHP** : chaque place prend le meilleur candidat dont aucune
   famille n'est déjà présente (familles des produits déjà engagés dans le
   bloc comprises) ; si tous les candidats répètent une famille présente, le
   mieux classé est pris quand même plutôt que de laisser la place vide.

Le classement ne s'applique **jamais** dans `SqlBuilder::compile()` seul ni dans
un scope : l'éligibilité des règles et les requêtes brutes restent non triées.
Les remises se sélectionnent sans produits promus (elles sont ce que l'on
résout : les promouvoir bouclerait). Chemin sticky (`StickySelectionService`,
tronc commun des suggestions affichées et des remises persistées : cycles
actifs encore affichables, puis refill enregistré) : le refill suit la
sélection, la persistance et la rotation ne changent pas, le bloc assemblé est
re-trié pour l'affichage seulement.

Événement Symfony `QueryBuilder\Event\QueryBuilderRulesChangedEvent` :
dispatché après toute mutation back-office d'une règle ou d'une action
(création, sauvegarde, activation/désactivation, suppression). À écouter côté
projet pour invalider les caches qui embarquent des résultats de règles —
ex. un écouteur projet qui invalide le cache des fiches produit.

## Remises (action `ApplyDiscount`)

Une règle peut porter une action de type `action` au code `ApplyDiscount`
(paramètres `discount_rate` requis, `discount_label`, `discount_cumulative`) :
les produits sélectionnés par l'arbre de l'action sont remisés pour tout
client éligible à la règle. Par défaut la remise est **stateless** — elle vaut
tant que la règle est active, les hooks de la règle sont ignorés pour ce type
d'action.

L'action accepte aussi `limit` et `persist_days` (mêmes champs que Product
display) : au plus N produits remisés à un instant donné, chacun conservant
sa remise `persist_days` × 24 h glissantes (pas de frontière calendaire) ou
jusqu'à son achat, via les cycles `query_builder_suggestion`. Un slot libéré
(achat, expiration, produit devenu invendable) est comblé par rotation : les
produits jamais remisés d'abord, puis les anciens cycles du plus vieux au
plus récent — un produit expiré ne revient pas immédiatement. Un produit
remisé présent au panier voit son cycle prolongé à chaque résolution : son
prix tient jusqu'à la commande (un panier dormant plus de `persist_days`
sans aucune visite perd la remise). La persistance exige un client
identifié : un visiteur anonyme n'obtient **aucune** remise d'une action
persistée (jamais de retour au comportement illimité). Sans ces paramètres,
comportement historique inchangé.

La résolution se fait à la demande via
`QueryBuilder\Service\DiscountResolutionService::getDiscounts($context, $productIds)`
(map `productId → Discount`, le meilleur taux gagne, memoïzation par requête).
C'est au projet de brancher cette map sur son pricing (prix affichés, panier,
export ERP) — l'endpoint JSON et le plugin Smarty exposent déjà les offres
des produits retournés (`offers`). Le refill des suggestions display et celui
des remises suivent la même sélection (`ProductSelector`, section ci-dessus).

Les cycles en cours ne re-vérifient **pas** l'arbre de conditions (seuls les
scopes globaux — visibilité, prix, catalogue — sont re-contrôlés). Pour éviter
qu'une modification de règle continue de servir des produits désormais exclus,
la sauvegarde d'une action dont l'arbre de conditions a changé expire
automatiquement ses cycles actifs : les slots sont re-remplis au prochain
affichage avec les nouvelles conditions (l'historique de rotation est
conservé). Même expiration quand le code de l'action ou `persist_days` change
(ajout, retrait, autre durée) ou quand l'action est désactivée (sauvegarde ou interrupteur de
la liste) : un cycle ne survit pas à la persistance qui l'a créé, réactiver
repart d'une sélection propre. Une sauvegarde ne touchant que les autres
paramètres (limite, libellé, taux…) ne réinitialise rien.

⚠️ L'arbre d'une action remise est évalué sur **toutes** les surfaces, y
compris à l'ajout au panier, à la suppression d'une ligne (les lignes
restantes sont alors re-remisées) et à la création de commande. Les conditions de
sélection reco y sont auto-destructrices : « produit présent dans le panier =
faux » annule la remise à l'instant où le produit entre au panier, « acheté
récemment = faux » l'annule à la création de commande (la ligne venant d'être
créée). Réserver l'arbre d'une remise à des critères stables (typologie,
nature, marque, visibilité…). Exception : « Catégorie du produit déjà achetée
par le client (3 derniers mois) » ne compte que les commandes abouties
(statuts hors non payée/annulée), la commande en cours de création ne
l'invalide donc pas.

## Remise globale panier (action `ApplyCartDiscount`)

Une règle peut porter une action au code `ApplyCartDiscount` : un pourcentage
sur le **total des produits du panier** (`cart_discount_rate`, TTC, port exclu)
et/ou les **frais de port offerts** (`cart_discount_free_shipping`), pour tout
client éligible à la règle. Cas d'usage type : remplacer un coupon automatique
(« remise première commande »). L'arbre de l'action est ignoré (pas de produits
cibles) — l'éligibilité est portée par les conditions de la **règle**. Si
plusieurs règles s'appliquent : le meilleur taux gagne, les frais de port sont
offerts dès qu'une règle les offre.

Contrairement à `ApplyDiscount`, l'application est fournie par le module
(`EventListener/CartDiscountListener`), via le canal discount core de Thelia
(`cart.discount` / `order.discount`) — **sans aucune dépendance à la mécanique
coupon** ; un coupon classique et une remise de règle coexistent, leurs
montants s'additionnent :

- événements panier (`CART_ADDITEM`/`UPDATEITEM`/`DELETEITEM`,
  `CUSTOMER_LOGIN`, `AddressEvent::POST_UPDATE`) à **priorité 1** : le core
  (`Thelia\Action\Coupon::updateOrderDiscount`, prio 10) vient de réinitialiser
  `cart.discount` de façon absolue, le listener additionne la remise de règle
  par-dessus. ⚠️ Invariant anti-accumulation : ne jamais abonner ce listener à
  un événement où la colonne n'est pas réinitialisée en amont ;
- `ORDER_SET_POSTAGE` à **priorité 133** (avant le check coupon du core à 132)
  pour les frais de port offerts (`setPostage(0)` + arrêt de propagation).

## Front

- Hooks Smarty `product.top` / `product.bottom` : rendu via
  `templates/frontOffice/default/query-builder/product-list.html`
  (surchargeable dans le thème) ;
- Endpoint JSON `GET /query_builder/products/{hookCode}` pour les fronts
  javascript — déclarer un hook « virtuel » dans le YAML (ex :
  `cart.recommendations`) et y rattacher une règle ;
- Plugin Smarty `{queryBuilderProducts hook="..."}` — même exécution et même
  structure que l'endpoint JSON (service partagé `HookResultPresenter`),
  assigne `$qb_hook`, `$qb_product_ids`, `$qb_offers`, `$qb_actions` au
  template. Paramètres de contexte optionnels : `product_id`, `order_id`,
  `category_id`, `brand_id`. En cas d'erreur, assigne des listes vides et
  loggue (ne casse jamais la page).

## Back-office

`/admin/query_builder` (entrée de menu ajoutée automatiquement) : CRUD des
règles, actions imbriquées, éditeur react-querybuilder. Les assets JS (react,
react-dom, react-querybuilder ESM + CSS) sont vendorés dans
`templates/backOffice/default/assets/js/vendor/` — aucune dépendance CDN.

Le gabarit de la règle est découpé en 3 étapes (identification / déclenchement /
conditions) et l'éditeur est habillé aux couleurs du back-office par
`assets/css/query-builder-admin.css`, chargée après la CSS vendorée dont elle
surcharge les variables. `query-builder-field.js` produit en plus un résumé
lisible de l'arbre (`data-summary`), un compteur (`data-counter`) et un état vide
(`data-empty-hint`) : ces trois cibles sont facultatives, l'éditeur fonctionne
sans elles.

Un champ présent dans l'arbre stocké mais absent du dictionnaire du contexte
courant (champ supprimé ou restreint après coup) est matérialisé dans le select
de l'éditeur par une entrée « champ indisponible dans ce contexte » — sans quoi
la lib retomberait sur le premier champ de la liste et la sauvegarde écraserait
la condition. Le résumé signale le même cas en rouge. Côté serveur, la
sauvegarde (`SqlBuilder::validateTree($tree, $context)`) rejette tout champ non
disponible dans le contexte de la règle — le JSON posté n'est pas présumé
respecter le filtre de l'éditeur.

⚠️ Les bundles esm.sh peuvent importer des polyfills node par chemin absolu
(ex : `import "/node/process.mjs"` dans react-querybuilder) : chaque chemin de
ce type doit être mappé dans l'importmap vers un shim local (voir
`process-shim.js` et `query-builder/editor-assets.html`), sinon le graphe de
modules casse silencieusement et l'éditeur ne s'affiche pas.

## Commandes de debug

```bash
php Thelia querybuilder:dictionary [CONTEXT]   # dictionnaire fusionné
php Thelia querybuilder:compile '<json>' --customer=42 --ordered --execute
php Thelia querybuilder:run product.top --customer=42 --product=123
```

## Installation sur un nouveau projet

1. Copier `local/modules/QueryBuilder/` ;
2. `php Thelia module:refresh && php Thelia module:activate QueryBuilder` ;
3. Purger les caches routing et Propel puis booter le kernel ;
4. Créer le `Config/query_builder.yml` du module projet (champs, hooks
   virtuels, scopes éventuels).
