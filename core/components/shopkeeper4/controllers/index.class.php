<?php
class Shopkeeper4IndexManagerController extends \MODX\Revolution\modExtraManagerController
{
    public function checkPermissions() { return $this->modx->hasPermission('settings'); }
    public function getPageTitle() { return 'Shopkeeper 4'; }
    public function getLanguageTopics() { return ['shopkeeper4:default']; }
    public function loadCustomCssJs()
    {
        $base=$this->modx->getOption('assets_url').'components/shopkeeper4/';
        $this->addCss($base.'mgr/shopkeeper4.css');
        $this->addHtml('<script>window.Shopkeeper4Config='.json_encode(['connector'=>$base.'connector.php','download'=>$base.'download.php','author'=>'rwolfin'],JSON_HEX_TAG|JSON_HEX_AMP).';</script>');
        $this->addLastJavascript($base.'mgr/shopkeeper4.js');
    }
    public function getTemplateFile() { return dirname(__DIR__).'/templates/home.tpl'; }
}
