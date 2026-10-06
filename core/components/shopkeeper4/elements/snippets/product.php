<?php
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
try {return (new \Shopkeeper4\Frontend(new \Shopkeeper4\Application($modx)))->product((int)($scriptProperties['id'] ?? $modx->resource->get('id')));}
catch(Throwable $e) {$modx->log(1,'Shopkeeper 4 product: '.$e->getMessage());return '';}
