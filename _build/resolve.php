<?php
// Uninstall deliberately keeps business records. Backup and delete tables manually if required.
if(($options[\xPDO\Transport\xPDOTransport::PACKAGE_ACTION] ?? '')===\xPDO\Transport\xPDOTransport::ACTION_UNINSTALL) return true;
try {
    $modx=$transport->xpdo;
    require_once $modx->getOption('core_path').'components/shopkeeper4/autoload.php';
    \Shopkeeper4\Installer::install(new \Shopkeeper4\Database($modx,(string)$modx->getOption('table_prefix')));
    return true;
} catch(Throwable $e) {$transport->xpdo->log(1,'Shopkeeper 4 installation: '.$e->getMessage());return false;}
