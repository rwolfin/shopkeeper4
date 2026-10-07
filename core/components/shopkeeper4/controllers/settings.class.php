<?php
require_once __DIR__.'/manager-base.class.php';
class Shopkeeper4SettingsManagerController extends Shopkeeper4BaseManagerController
{
    protected static function managerControllerFile() { return 'settings_controller.js'; }
    public function getTemplateFile() { return dirname(__DIR__).'/templates/settings.tpl'; }
}
