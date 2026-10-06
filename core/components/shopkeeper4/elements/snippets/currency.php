<?php
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
try {
    $app=new \Shopkeeper4\Application($modx);$s=$app->store->settings()['data'];
    $base=\Shopkeeper4\Settings::find($s['currencies'],$s['general']['base_currency'],'code');
    $target=\Shopkeeper4\Settings::find($s['currencies'],$scriptProperties['currency'] ?? $app->cart()->view()['currency'],'code');
    return \Shopkeeper4\Decimal::text(\Shopkeeper4\Decimal::convert(\Shopkeeper4\Decimal::scaled($input ?? $scriptProperties['value'] ?? '0'),\Shopkeeper4\Decimal::scaled($base['rate'],4),\Shopkeeper4\Decimal::scaled($target['rate'],4)));
} catch(Throwable $e) {return '';}
