<?php
require_once dirname(__DIR__,3).'/config.core.php';
require_once MODX_CORE_PATH.'vendor/autoload.php';
require_once MODX_CORE_PATH.'components/shopkeeper4/autoload.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    $context=$_GET['context'] ?? 'web';
    if(!is_string($context) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$context) || $context==='mgr') throw new RuntimeException('Недопустимый контекст.');
    $modx=new \MODX\Revolution\modX();
    if(!$modx->getObject(\MODX\Revolution\modContext::class,['key'=>$context])) throw new RuntimeException('Контекст не найден.');
    $modx->initialize($context);
    $app=new \Shopkeeper4\Application($modx);$cart=$app->cart();$result=[];
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>100000) throw new RuntimeException('Запрос слишком большой.');
        $input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)) throw new RuntimeException('Неверный запрос.');
        $cart->verify($input['csrf'] ?? null);
        $action=(string)($input['sk4_action'] ?? '');
        if($action==='checkout') {
            if(!empty($input['website'])) throw new RuntimeException('Форма отклонена.');
            $result=$app->checkout($input);
        } else { $cart->mutate($action,$input);$app->event('OnShopkeeper4CartChanged',['action'=>$action]); }
    } elseif($_SERVER['REQUEST_METHOD']!=='GET') {http_response_code(405);exit;}
    $front=new \Shopkeeper4\Frontend($app);
    echo \Shopkeeper4\Database::json(['success'=>true,'object'=>$result,'cart'=>$cart->view(),'html'=>$front->cart(),'summary'=>$front->cart(true),'csrf'=>$cart->csrf(),'checkout_token'=>$cart->checkoutToken()]);
} catch(Throwable $e) {
    http_response_code(400);
    if(isset($modx)) $modx->log(1,'Shopkeeper 4 storefront: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
