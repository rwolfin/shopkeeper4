<?php
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
try {
    $app=new \Shopkeeper4\Application($modx);$front=new \Shopkeeper4\Frontend($app);$message='';
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['sk4_action']) && empty($GLOBALS['shopkeeper4_post_handled'])) {
        $GLOBALS['shopkeeper4_post_handled']=true;$app->cart()->verify($_POST['csrf'] ?? null);
        if($_POST['sk4_action']==='checkout') {
            if(!empty($_POST['website'])) throw new RuntimeException('Форма отклонена.');
            $result=$app->checkout($_POST);$message='Заказ №'.$result['id'].' принят.';
        } else $app->cart()->mutate((string)$_POST['sk4_action'],$_POST);
    }
    $base=$modx->getOption('assets_url').'components/shopkeeper4/';
    $context=$modx->context->get('key');
    $modx->regClientCSS($base.'web/shopkeeper4.css');
    $modx->regClientStartupHTMLBlock('<script>window.Shopkeeper4Web='.json_encode(['url'=>$base.'web.php?context='.rawurlencode($context)],JSON_HEX_TAG|JSON_HEX_AMP).';</script>');
    if(($scriptProperties['js'] ?? '1')!=='0') $modx->regClientScript($base.'web/shopkeeper4.js');
    $mode=$scriptProperties['mode'] ?? 'cart';
    $html=$mode==='checkout'?$front->form():$front->cart($mode==='compact');
    return '<section class="shopkeeper4"><div data-sk4-message role="status">'.\Shopkeeper4\Html::escape($message).'</div><div'.($mode==='checkout'?'':($mode==='compact'?' data-sk4-compact':' data-sk4-cart')).'>'.$html.'</div></section>';
} catch(Throwable $e) { $modx->log(1,'Shopkeeper 4: '.$e->getMessage());return '<p class="sk4-error">'.\Shopkeeper4\Html::escape($e->getMessage()).'</p>'; }
