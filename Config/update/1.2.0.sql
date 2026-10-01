# 1.2.0 : les hooks orphelins (jamais consommés côté front) sont retirés
# du dictionnaire ; purge des codes fantômes encore cochés par des règles.

UPDATE `query_builder_rule`
SET `hooks` = JSON_REMOVE(`hooks`, JSON_UNQUOTE(JSON_SEARCH(`hooks`, 'one', 'cart.addtocart.recommendations')))
WHERE JSON_SEARCH(`hooks`, 'one', 'cart.addtocart.recommendations') IS NOT NULL;

UPDATE `query_builder_rule`
SET `hooks` = JSON_REMOVE(`hooks`, JSON_UNQUOTE(JSON_SEARCH(`hooks`, 'one', 'customer.login.recommendations')))
WHERE JSON_SEARCH(`hooks`, 'one', 'customer.login.recommendations') IS NOT NULL;
