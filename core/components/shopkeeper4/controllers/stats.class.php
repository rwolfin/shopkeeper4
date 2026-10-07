<?php
require_once __DIR__.'/manager-base.class.php';
class Shopkeeper4StatsManagerController extends Shopkeeper4BaseManagerController
{
    protected static function managerControllerFile() { return 'stats_controller.js'; }
    public function loadCustomCssJs() { parent::loadCustomCssJs(); $this->addCss($this->modx->getOption('assets_url').'components/shopkeeper4/mgr/js/c3/c3.css'); }
    public function getTemplateFile() { return dirname(__DIR__).'/templates/stats.tpl'; }
}
