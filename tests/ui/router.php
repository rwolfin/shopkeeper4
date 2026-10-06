<?php
// Local test harness only. Run with PHP's built-in server. Never deploy this directory.
$source=dirname(__DIR__,2);$workspace=dirname($source,2);
$modxCore=getenv('SK4_MODX_CORE') ?: $workspace.'/work/modx/revolution-3.2.3-pl/core';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/ext/')) {
    $base=realpath(dirname($modxCore).'/manager/assets/ext3');$file=realpath($base.'/'.substr($path,5));
    if(!$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file)){http_response_code(404);exit;}
    header('Content-Type: '.(str_ends_with($file,'.js')?'application/javascript':(str_ends_with($file,'.css')?'text/css':'application/octet-stream')));readfile($file);exit;
}
if(in_array($path,['/manager.js','/manager.css','/web.js','/web.css'],true)) {
    $parts=explode('.',substr($path,1));$type=$parts[0]==='manager'?'mgr':'web';header('Content-Type: '.($parts[1]==='js'?'application/javascript':'text/css'));readfile($source.'/assets/components/shopkeeper4/'.$type.'/shopkeeper4.'.$parts[1]);exit;
}
require $modxCore.'/vendor/autoload.php';
require $source.'/core/components/shopkeeper4/autoload.php';require dirname(__DIR__).'/FixtureProducts.php';
use Shopkeeper4\{Database,Installer,Store,Decimal};
session_start();$pdo=new PDO('sqlite:'.__DIR__.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db=new Database($pdo,'fixture_','sqlite');Installer::install($db);
$db->run('CREATE TABLE IF NOT EXISTS fixture_stock (id INTEGER PRIMARY KEY,quantity INTEGER)');
if(!$db->one('SELECT id FROM fixture_stock LIMIT 1')){$db->run('INSERT INTO fixture_stock VALUES(1,1000000),(2,1000000)');}
$store=new Store($db,new FixtureProducts($db));
if(!$db->one('SELECT id FROM '.$db->table('orders').' LIMIT 1')) {
    for($i=0;$i<8;$i++) {
        $id=$store->create([['product_id'=>1,'quantity'=>(string)($i+1)]],['fullname'=>'Покупатель '.$i,'email'=>'client'.$i.'@example.test','phone'=>'+79990000000'],'web',0,hash('sha256','fixture'.$i))['id'];
        $db->run('UPDATE '.$db->table('orders').' SET created_at=?,status_id=? WHERE id=?',['2026-0'.($i%6+1).'-10 12:30:00',($i%4)+1,$id]);
    }
}
// Invoke the production processor and HTML renderer using a small MODX service adapter.
$fake=new class($pdo,$db) {
    public $user,$services,$context,$error;public array $config=[];
    public function __construct(public $pdo,public $db){
        $this->error=new class {function success($message='',$object=[]){return ['success'=>true,'message'=>$message,'object'=>$object];}function failure($message='',$object=[]){return ['success'=>false,'message'=>$message,'object'=>$object];}};
        $this->user=new class {function hasSessionContext($ctx){return true;}function get($key){return 1;}};
        $this->context=new class {function get($key){return 'web';}};
        $this->services=new class($db) {function __construct(private $db){}function has($key){return true;}function get($key){return new FixtureProducts($this->db);}};
    }
    function prepare($sql){return $this->pdo->prepare(str_replace(' FOR UPDATE','',$sql));}
    function beginTransaction(){return $this->pdo->beginTransaction();}function commit(){return $this->pdo->commit();}function rollBack(){return $this->pdo->rollBack();}function lastInsertId(){return $this->pdo->lastInsertId();}
    function getOption($key){return ['table_prefix'=>'fixture_','assets_url'=>'/','emailsender'=>'test@example.test','site_name'=>'Fixture'][$key] ?? '';}
    function hasPermission($key){return true;}function log($level,$message){error_log($message);}function invokeEvent($name,$params){}function getChunk($name,$params){return '<p>Fixture mail</p>';}function getService($name,$class){return null;}
    function getCacheManager(){return new class {function refresh($params){}};}
    function lexicon($key){return $key;}function makeUrl($id){return '/storefront';}
};
if($path==='/api'){
    header('Content-Type: application/json');require $source.'/core/components/shopkeeper4/processors/manage.class.php';
    $r=new ReflectionClass('Shopkeeper4ManageProcessor');$processor=$r->newInstanceWithoutConstructor();$processor->modx=$fake;$processor->setProperties($_REQUEST);
    $result=$processor->process();echo is_string($result)?$result:json_encode($result);exit;
}
$app=new Shopkeeper4\Application($fake);$front=new Shopkeeper4\Frontend($app);
if($path==='/web-api'){
    header('Content-Type: application/json');
    try{$input=json_decode(file_get_contents('php://input'),true);$app->cart()->verify($input['csrf'] ?? null);$result=$input['sk4_action']==='checkout'?$app->checkout($input):$app->cart()->mutate($input['sk4_action'],$input);echo json_encode(['success'=>true,'object'=>$result,'html'=>$front->cart(),'summary'=>$front->cart(true),'csrf'=>$app->cart()->csrf(),'checkout_token'=>$app->cart()->checkoutToken()]);}
    catch(Throwable $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}exit;
}
if($path==='/storefront'){
    echo '<!doctype html><meta charset="utf-8"><title>Shopkeeper 4 storefront fixture</title><link rel="stylesheet" href="/web.css"><main style="max-width:1000px;margin:auto"><h1>Магазин — проверка корзины</h1><form data-sk4-form><input type="hidden" name="csrf" value="'.$app->cart()->csrf().'"><input type="hidden" name="sk4_action" value="add"><input type="hidden" name="product_id" value="1"><input name="quantity" value="1"><button>В корзину</button></form><section data-sk4-cart>'.$front->cart().'</section>'.$front->form().'</main><script>window.Shopkeeper4Web={url:"/web-api"};</script><script src="/web.js"></script>';exit;
}
echo '<!doctype html><html><head><meta charset="utf-8"><title>Shopkeeper 4 — ExtJS integration fixture</title><link rel="stylesheet" href="/ext/resources/css/ext-all.css"><link rel="stylesheet" href="/manager.css"><style>body{font:14px Arial;background:#f5f7fa;padding:20px}h2{font-size:24px;padding:16px}small{font-size:16px;color:#667085}</style><script src="/ext/adapter/ext/ext-base.js"></script><script src="/ext/ext-all.js"></script><script>Ext.BLANK_IMAGE_URL="/ext/resources/images/default/s.gif";window.MODx={Panel:Ext.Panel,siteId:"fixture"};window.Shopkeeper4Config={connector:"/api"};</script></head><body><div id="shopkeeper4-manager"></div><script src="/manager.js"></script></body></html>';
