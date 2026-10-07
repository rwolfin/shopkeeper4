<?php
/** php _build/build.php /path/to/modx/core /path/to/output */
use xPDO\Transport\xPDOTransport as T;
use MODX\Revolution\Transport\modTransportVehicle as Vehicle;
use MODX\Revolution\{modNamespace,modCategory,modMenu,modSnippet,modChunk,modEvent};
$core=str_replace('\\','/',realpath($argv[1] ?? '') ?: '').'/';
if(!is_file($core.'vendor/autoload.php')) throw new RuntimeException('Provide a MODX 3 core directory with Composer dependencies.');
define('MODX_CORE_PATH',$core);require $core.'vendor/autoload.php';
$output=rtrim($argv[2] ?? dirname(__DIR__).'/dist','/\\').'/';if(!is_dir($output)) mkdir($output,0755,true);
$output=str_replace('\\','/',realpath($output)).'/';$source=dirname(__DIR__).'/';$signature='shopkeeper4-4.0.0-beta1';
if(is_dir($output.$signature)) throw new RuntimeException('Use a fresh output directory for each build.');
$xpdo=new \xPDO\xPDO('mysql:host=127.0.0.1;port=65534;dbname=build','', '',[\xPDO\xPDO::OPT_CACHE_PATH=>$output.'cache/']);
$xpdo->setPackage('MODX\\Revolution',$core.'src/','modx_','MODX\\');$xpdo->setLogTarget('ECHO');$xpdo->setLogLevel(1);
$package=new T($xpdo,$signature,$output);
$common=[T::UPDATE_OBJECT=>true,T::PRESERVE_KEYS=>false,T::ABORT_INSTALL_ON_VEHICLE_FAIL=>true,'namespace'=>'shopkeeper4'];
$namespace=$xpdo->newObject(modNamespace::class);$namespace->fromArray(['name'=>'shopkeeper4','path'=>'{core_path}components/shopkeeper4/','assets_path'=>'{assets_path}components/shopkeeper4/'],'',true);
$v=new Vehicle($namespace,array_replace($common,[T::PRESERVE_KEYS=>true,T::UNIQUE_KEY=>'name']));$v->validate('php',['source'=>__DIR__.'/validate.php']);$package->put($v->fetch(),$v->compile());
$category=$xpdo->newObject(modCategory::class);$category->set('category','Shopkeeper 4');
$snippets=[];
foreach(['Shopkeeper4'=>'cart','Shopkeeper4Product'=>'product','Shopkeeper4Options'=>'options','Shopkeeper4FormIt'=>'formit','Shopkeeper4Number'=>'number','Shopkeeper4Currency'=>'currency'] as $name=>$file){
    $snippet=$xpdo->newObject(modSnippet::class);$code=file_get_contents($source.'core/components/shopkeeper4/elements/snippets/'.$file.'.php');
    $snippet->fromArray(['name'=>$name,'description'=>'Shopkeeper 4 · rwolfin · GPL-3.0-only','snippet'=>preg_replace('/^<\?php\s*/','',$code)]);$snippets[]=$snippet;
}
$chunk=$xpdo->newObject(modChunk::class);$chunk->fromArray(['name'=>'Shopkeeper4Mail','description'=>'Order notification · Shopkeeper 4','snippet'=>file_get_contents($source.'core/components/shopkeeper4/elements/chunks/mail.tpl')]);
$category->addMany($snippets,'Snippets');$chunks=[$chunk];$category->addMany($chunks,'Chunks');
$v=new Vehicle($category,$common+[T::UNIQUE_KEY=>'category',T::RELATED_OBJECTS=>true,T::RELATED_OBJECT_ATTRIBUTES=>[
    'Snippets'=>[T::PRESERVE_KEYS=>false,T::UPDATE_OBJECT=>true,T::UNIQUE_KEY=>'name'],
    'Chunks'=>[T::PRESERVE_KEYS=>false,T::UPDATE_OBJECT=>false,T::UNIQUE_KEY=>'name']
]]);
$v->resolve('file',['source'=>$source.'core/components/shopkeeper4','target'=>"return MODX_CORE_PATH . 'components/';"]);
$v->resolve('file',['source'=>$source.'assets/components/shopkeeper4','target'=>"return MODX_ASSETS_PATH . 'components/';"]);
$v->resolve('php',['source'=>__DIR__.'/resolve.php']);$package->put($v->fetch(),$v->compile());
foreach(['OnShopkeeper4CartChanged','OnShopkeeper4OrderCreated','OnShopkeeper4OrderUpdated','OnShopkeeper4StatusChanged'] as $name){
    $event=$xpdo->newObject(modEvent::class);$event->fromArray(['name'=>$name,'service'=>6,'groupname'=>'Shopkeeper 4'],'',true);
    $v=new Vehicle($event,array_replace($common,[T::PRESERVE_KEYS=>true,T::UNIQUE_KEY=>'name']));$package->put($v->fetch(),$v->compile());
}
$menu=$xpdo->newObject(modMenu::class);$menu->fromArray(['text'=>'Shopkeeper 4','parent'=>'components','action'=>'index','namespace'=>'shopkeeper4','description'=>'shopkeeper4.menu_desc','permissions'=>'settings','menuindex'=>0],'',true);
$v=new Vehicle($menu,array_replace($common,[T::PRESERVE_KEYS=>true,T::UNIQUE_KEY=>'text']));$package->put($v->fetch(),$v->compile());
foreach(['readme'=>'README.ru.md','changelog'=>'CHANGELOG.md','license'=>'LICENSE'] as $key=>$file) $package->setAttribute($key,file_get_contents($source.$file));
$package->setAttribute('requires',['modx'=>'>=3.0.0,<4.0.0','php'=>'>=8.1.0']);$package->setAttribute('author','rwolfin');$package->writeManifest();
$zip=new ZipArchive();if($zip->open($output.$signature.'.transport.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create ZIP.');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output.$signature,FilesystemIterator::SKIP_DOTS)) as $file) if($file->isFile()){
    $relative=str_replace('\\','/',substr($file->getPathname(),strlen($output)));
    if(str_starts_with($relative,'assets/components/shopkeeper4/mgr/mgr/')) continue;
    if(!$zip->addFromString($relative,file_get_contents($file->getPathname()))) throw new RuntimeException('ZIP entry failed: '.$relative);
}
if(!$zip->close()) throw new RuntimeException('ZIP close failed.');echo $output.$signature.'.transport.zip'.PHP_EOL;
