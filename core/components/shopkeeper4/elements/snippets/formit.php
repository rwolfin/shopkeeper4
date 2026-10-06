<?php
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
try {
    if(!isset($hook)) return false;
    $values=$hook->getValues();$result=(new \Shopkeeper4\Application($modx))->checkout($values);
    $hook->setValue('shopkeeper4_order_id',$result['id']);return true;
} catch(Throwable $e) { if(isset($hook)) $hook->addError('shopkeeper4',$e->getMessage());return false; }
