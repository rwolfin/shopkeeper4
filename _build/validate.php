<?php
if(($options[\xPDO\Transport\xPDOTransport::PACKAGE_ACTION] ?? '')===\xPDO\Transport\xPDOTransport::ACTION_UNINSTALL) return true;
$modx=$transport->xpdo;$version=$modx->getVersionData();$errors=[];
if((int)($version['version'] ?? 0)!==3) $errors[]='Shopkeeper 4 requires MODX 3.x.';
if(PHP_VERSION_ID<80100 || PHP_INT_SIZE<8) $errors[]='64-bit PHP 8.1 or newer is required.';
foreach(['pdo_mysql','mbstring','json'] as $extension) if(!extension_loaded($extension)) $errors[]='Required extension: '.$extension;
foreach($errors as $error) $modx->log(1,$error);
return !$errors;
