<?php
require_once __DIR__.'/manager-base.class.php';
class Shopkeeper4IndexManagerController extends Shopkeeper4BaseManagerController
{
    public function getTemplateFile() { return dirname(__DIR__).'/templates/home.tpl'; }
}
