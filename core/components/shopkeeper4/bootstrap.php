<?php
require_once __DIR__ . '/autoload.php';
if (isset($modx->services)) {
    $modx->services->add('shopkeeper4', static fn($container) => new \Shopkeeper4\Application($modx));
}
