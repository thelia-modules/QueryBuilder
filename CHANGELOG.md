# Changelog

All notable changes to this module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] 2026-09-24

Product discounts served through the catalog price contract of the core. Requires Thelia 3.2.

### Added
- The module decorates `Thelia\Domain\Pricing\CatalogPriceResolverInterface` (`DiscountCatalogPriceResolver`): the sale elements of the products an ApplyDiscount rule selects are priced for the visitor the core names, and the core substitutes the price everywhere it prices a sale element (product loops, product page, front product resources, cart lines), applies the customer discount and the tax on top, and freezes the line price on the order.
- The module decorates `Thelia\Domain\Pricing\PricingActivityChecker` (`DiscountPricingActivityChecker`): a running discount rule counts as a public, visitor-dependent pricing, so the core asks the resolver and the shared API cache of the front product collections steps aside while one runs.
- Stackable and non-stackable policies measured against a catalog price rule of the core as they are against a catalog promotion: a non-stackable rule replaces the core rule price only when it is better, a stackable rule applies on top of it and follows its end date.

### Changed
- Requires Thelia 3.2 (`thelia/core` ^3.2, `<thelia>3.2.0</thelia>`); activation is refused on an older core with the message naming the required version.
- The cart discount fragment names the product discount charged on a line by recomputing what the core wrote from it (the module price, then the customer discount) instead of comparing the line with the module's own writing.
- The rules of a discount are evaluated without a current product on every surface: the core asks for a batch of sale elements, never for a page.

### Fixed
- A request of the stateless API (no session cookie) no longer fails on a product read: the `QueryBuilderProductOffer` addon and the query builder products endpoint asked the session for the cart and restored it, which created a cart and threw without a session. The runtime context now reads the cart the session names, never restores one, and treats a visit without cart as an empty cart; every session access of the module is guarded the way the core guards its own.

### Removed
- The `PseByProductEvent` and `ModelToResourceEvent` listeners that showed the discount on the product page and on the front product resources, and the cart listener that wrote the discounted price on the cart lines at every cart event: the core does all three from the decorated resolver. Kept, they would stack a stackable discount twice and hand a line priced by a catalog price rule of the core back to the catalog.
- `QueryBuilder\Api\FrontRead`, which only the removed resource listener used.

## [2.0.1] 2026-09-24

### Fixed
- Saving a rule or an action whose tree holds a "list of values" pick on a `:value` field (`product_default_category`, `cart_has_brand`, `cart_has_category`, `context_product_shared_feature_value`) failed with `operator "=" is not allowed for field`. The editor stores that pick as the `=` it stands for, and the compiler only accepted `in` and `notIn` on these fields: an equality on a `:value` expression now compiles as a one-item `in`, `!=` as a one-item `notIn`, each accepted when its inclusion is declared. Such a field may also declare `=` and `!=` outright.

## [2.0.0] 2026-09-16

Port of the module to Thelia 3. The Thelia 2 line (1.0.0 to 1.2.0) lived in the project the module was written for and is not published.

### Added
- Back-office screens on the default-twig theme (Twig, Bootstrap 5): rule list, rule in three steps, action screen, entry in the Tools menu. Screens and menu entry follow the right granted on the module itself in the administrator profiles.
- On the rule screen, an (i) mark after each action name opens a popover reading the product selection of the action, the way the action screen does above its editor, followed by its discount rate, free shipping and stackable options when set.
- Condition editor provided by `openstudio/query-builder-bundle` (`QueryBuilderType`, `native` processor, per-field operators), with a readable summary of the tree, a condition counter and the hook grid filtered by context. The editor script and stylesheet are loaded on the rule and action screens only, never on the rule list nor on the rest of the back-office.
- Front rendering through the `theme_hook()` points of Flexy (`ThemeHookInterface`) with a product list template based on the Flexy cross-selling component, overridable by the theme.
- Twig function `query_builder_products()` returning the hook result (ids, offers, actions).
- API Platform resource `GET /api/front/query_builder/products/{hookCode}` with the product resources embedded, and two front addons: `QueryBuilderCartDiscount` on the cart, `QueryBuilderProductOffer` on the products.
- Product discounts applied on the cart lines (promotion columns), with the stackable and non-stackable policies against a catalog promotion.
- Product discounts shown on the product page (`PseByProductEvent`), in the listings and on the front product resources (`ModelToResourceEvent`): the discounted product is read as a promotion at the discounted price, computed with the same policy as the cart lines.
- Discount label shown in the cart discount fragment of the checkout pages, which now lists the product discounts charged on the cart lines.
- Free shipping on `CART_SET_POSTAGE` and through the postage estimator of the core; the delivery options of the delivery step are priced at zero as well, so the option cards and the summary agree.
- Cart discount fragment shown on the checkout pages (`checkout.top`, `cart.bottom`).
- Dictionary fields for the cart products total (`:cart_total`) and the delivery country (`:delivery_country_id`); `--cart-total` and `--delivery-country` options on the debug commands.
- Dictionary fields per context: the selected product against the context product (same brand, shared category, same main category, accessory, same template, same feature value, product already in the cart), the context category and its sub-tree, the context brand; cart fields (line and item counts, product quantity, brands and categories in the cart, promotional line, same brand, shared category or accessory of a cart product) and customer fields (reseller, discount rate, seniority, orders, last order, newsletter, products, brands and categories already bought) available in every context; brand id, main category, new, promotion and stock flags on the product.
- `usage` key on the dictionary fields (`rule`, `action`): a field can be reserved to the trigger conditions of a rule or to the product selection of an action; the editors and `SqlBuilder::validateTree()` honor it.
- Field labels prefixed with their translated group (the part of the code before the first underscore) in the editors.
- `--usage` option on `querybuilder:dictionary`; `--cart`, `--order`, `--category` and `--brand` options on `querybuilder:compile`.
- Flexy hook codes declared per context in the base dictionary.
- English and French translations of every label (`querybuilder` and `querybuilder.bo.default-twig` domains).
- Unit tests (SQL compiler, dictionary, discount arithmetic), a module activation integration test and a GitHub Actions workflow running them on a fresh shop.
- `composer install` at the module root installs the dev dependencies (`thelia/core`, PHPUnit) for the unit suite and static analysis; the module `vendor/` directory and the Propel models are excluded from the service discovery so a checkout linked into a shop keeps booting it.
- Activation is refused on a Thelia 2 core (or a core whose version cannot be read) with a message naming the module version, the minimum core version and the running one; the `<thelia>` bound of `module.xml` alone is not enforced by every 2.x core.

### Changed
- Requires PHP 8.3, Thelia 3 and `openstudio/query-builder-bundle` ^1.1.
- Dictionary overrides are looked up in the directory of each module, wherever Composer installed it.
- A `datetime` field is entered as a date and compared on its date part.
- Dictionary labels, context labels and action labels are translation keys.
- `contexts` on a dictionary field defaults to `[GLOBAL]`, which means every context (an empty list had the same effect); an unknown context value is refused with an explicit message.
- Cart discount amounts are computed by a single service shared by the cart listener, the API addon and the theme hook.

### Removed
- Smarty templates, plugin and vendored react-querybuilder build of the Thelia 2 line.
- The `/query_builder/products/{hookCode}` Symfony route, replaced by the API Platform resource.
- The `product.top` and `product.bottom` `BaseHook` front hooks, replaced by the theme hook implementation.

[2.1.0]: https://github.com/thelia-modules/QueryBuilder/releases/tag/2.1.0
[2.0.1]: https://github.com/thelia-modules/QueryBuilder/releases/tag/2.0.1
[2.0.0]: https://github.com/thelia-modules/QueryBuilder/releases/tag/2.0.0
