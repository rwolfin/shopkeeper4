<?php
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
$group=$scriptProperties['group'] ?? 'delivery';
if(!in_array($group,['delivery','payments','currencies'],true)) return '';
return (new \Shopkeeper4\Frontend(new \Shopkeeper4\Application($modx)))->select($group,$scriptProperties['name'] ?? ($group==='delivery'?'delivery_id':($group==='payments'?'payment_id':'currency')),$scriptProperties['selected'] ?? null);
