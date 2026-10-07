<?php
require_once dirname(__DIR__).'/autoload.php';
abstract class Shopkeeper4BaseManagerController extends \MODX\Revolution\modExtraManagerController
{
    public function checkPermissions() { return $this->modx->user->hasSessionContext('mgr') && $this->modx->hasPermission('settings'); }
    public function getPageTitle() { return $this->modx->lexicon('shopkeeper4'); }
    public function getLanguageTopics() { return ['shopkeeper4:default','shopkeeper4:manager']; }
    public function loadCustomCssJs()
    {
        $base=$this->modx->getOption('assets_url').'components/shopkeeper4/mgr/';
        foreach (['css/bootstrap-custom/css/bootstrap.min.css','js/bootstrap-daterangepicker/daterangepicker-bs3.css','js/ng-table/ng-table.min.css','js/jquery-minicolors/jquery.minicolors.css','css/shk-style.css'] as $file) $this->addCss($base.$file);
        $settings=[];
        try { $settings=(new \Shopkeeper4\Application($this->modx))->store->settings()['data']; }
        catch (\Throwable $e) { $this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR,'Shopkeeper 4 manager settings: '.$e->getMessage()); }
        $aliases=['status_id'=>'status','created_at'=>'date','total'=>'price','quantity'=>'count_total','customer_id'=>'userid'];
        $columns=[];
        foreach (($settings['columns'] ?? []) as $column) if (!empty($column['visible'])) $columns[]=['name'=>$aliases[$column['name']] ?? $column['name'],'label'=>$column['label']];
        if (!$columns) $columns=[['name'=>'id','label'=>'№ заказа'],['name'=>'status','label'=>'Статус'],['name'=>'date','label'=>'Дата'],['name'=>'price','label'=>'Сумма'],['name'=>'count_total','label'=>'Кол-во'],['name'=>'email','label'=>'Эл. почта']];
        $language=(string)$this->modx->getOption('manager_language','en');
        $locale=in_array($language,['en','ru'],true)?$language:'en';
        $settings['order_fields']=$columns;
        foreach (['delivery','payments'] as $group) {
            if (!isset($settings[$group]) || !is_array($settings[$group])) continue;
            foreach ($settings[$group] as &$row) $row['value']=$row['label'];
            unset($row);
        }
        $config=['auth_token'=>$this->modx->user->getUserToken($this->modx->context->get('key')),'assets_url'=>$this->modx->getOption('assets_url'),'manager_url'=>$this->modx->getOption('manager_url'),'manager_language'=>$locale,'settings'=>$settings];
        $this->modx->getService('lexicon','modLexicon');
        $this->modx->lexicon->load($language.':shopkeeper4:manager');
        $config['lang']=$this->modx->lexicon->fetch('shk3.');
        $this->addHtml('<script>var shk_config='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>');
        $files=['js/jquery-1.11.1.min.js','css/bootstrap/js/bootstrap.min.js','js/angular/angular.min.js','js/angular/angular-sanitize.min.js','js/ui-bootstrap-tpls-1.3.3.min.js','js/bootstrap-daterangepicker/momentjs/moment.min.js','js/bootstrap-daterangepicker/momentjs/locale/'.$locale.'.js','js/bootstrap-daterangepicker/daterangepicker.js','js/bootstrap-daterangepicker/daterangepicker_directive.js','js/bootstrap-multiselect.js','js/ng-table/ng-table.min.js','js/ng-table/ng-table-export.src.js','js/jquery-minicolors/jquery.minicolors.min.js','js/jquery-minicolors/angular-minicolors.js','js/angular-spinner/spin.min.js','js/angular-spinner/angular-spinner.min.js'];
        if (static::managerControllerFile()==='stats_controller.js') $files[]='js/c3/d3/d3.min.js';
        if (static::managerControllerFile()==='stats_controller.js') $files[]='js/c3/c3.min.js';
        $files[]='js/shk_mgr_app.js';$files[]='js/app_tpls.js';$files[]='js/controllers/'.static::managerControllerFile();
        foreach ($files as $file) $this->addLastJavascript($base.$file);
    }
    protected static function managerControllerFile() { return 'home_controller.js'; }
}
