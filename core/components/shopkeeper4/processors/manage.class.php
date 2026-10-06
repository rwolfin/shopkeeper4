<?php
require_once dirname(__DIR__).'/autoload.php';
class Shopkeeper4ManageProcessor extends \MODX\Revolution\Processors\Processor
{
    public function checkPermissions() { return $this->modx->user->hasSessionContext('mgr') && $this->modx->hasPermission('settings'); }
    public function process()
    {
        try {
            $app=new \Shopkeeper4\Application($this->modx);$store=$app->store;
            $task=(string)$this->getProperty('task','orders');
            $input=$this->getProperties();
            $payload=\Shopkeeper4\Database::decode((string)$this->getProperty('payload','{}'));
            $actor=(int)$this->modx->user->get('id');
            $result=[];
            switch($task) {
                case 'settings': $result=$store->settings();break;
                case 'settings/save': $result=$store->saveSettings($payload['data'] ?? [],(int)($payload['version'] ?? 0));break;
                case 'orders':
                    $result=$store->orders($input);
                    foreach($result['results'] as &$row) { $row['total']=\Shopkeeper4\Decimal::text((int)$row['total']);$row['quantity']=\Shopkeeper4\Decimal::text((int)$row['quantity'],3);unset($row['checkout_key'],$row['contacts']); }
                    return $this->outputArray($result['results'],$result['total']);
                case 'order':
                    $result=$store->order((int)$this->getProperty('id'));
                    $result['delivery_fee']=\Shopkeeper4\Decimal::text((int)$result['delivery_fee']);
                    foreach($result['items'] as &$row) { $row['price']=\Shopkeeper4\Decimal::text((int)$row['price']);$row['quantity']=\Shopkeeper4\Decimal::text((int)$row['quantity'],3);foreach($row['options'] as &$opt) $opt['price']=\Shopkeeper4\Decimal::text((int)$opt['price']);unset($opt); }
                    unset($row,$result['checkout_key']);break;
                case 'order/save':
                    $result=$store->update((int)($payload['id'] ?? 0),$payload,$actor);
                    if($result['status_changed']) $result['mail_errors']=$app->notify($result['id']);
                    $app->event('OnShopkeeper4OrderUpdated',['order_id'=>$result['id']]);$app->invalidate();break;
                case 'status':
                    $ids=$store->status($payload['versions'] ?? [],(int)($payload['status_id'] ?? 0),$actor);$result=['ids'=>$ids,'mail_errors'=>[]];
                    foreach($ids as $id) $result['mail_errors']=array_merge($result['mail_errors'],$app->notify($id));
                    $app->event('OnShopkeeper4StatusChanged',['order_ids'=>$ids,'status_id'=>(int)$payload['status_id']]);$app->invalidate();break;
                case 'remove': $result=['count'=>$store->remove($payload['versions'] ?? [],$actor)];$app->invalidate();break;
                case 'stats': $result=['rows'=>$store->stats($input)];break;
                case 'mail/retry': $app->sendMail((int)$this->getProperty('id'));$result=['sent'=>true];break;
                case 'export':
                    $data=$store->orders($input,true)['results'];
                    $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");
                    fputcsv($stream,['ID','Дата UTC','Статус','Сумма','Валюта','Email','Доставка','Оплата','Контакты'],';','"','');
                    foreach($data as $row) {
                        $fields=[$row['id'],$row['created_at'],$row['status_id'],\Shopkeeper4\Decimal::text((int)$row['total']),$row['currency'],$row['email'],$row['delivery_name'],$row['payment_name'],$row['contacts']];
                        $fields=array_map(static fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$fields);
                        fputcsv($stream,$fields,';','"','');
                    }
                    rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
                    if(strlen($csv)>8*1024*1024) throw new \RuntimeException('CSV превышает 8 МБ. Уменьшите период экспорта.');
                    $token=bin2hex(random_bytes(24));
                    $_SESSION['shopkeeper4_exports']=array_filter($_SESSION['shopkeeper4_exports'] ?? [],static fn($e)=>$e['expires']>time());
                    if(count($_SESSION['shopkeeper4_exports'])>=3) array_shift($_SESSION['shopkeeper4_exports']);
                    $_SESSION['shopkeeper4_exports'][$token]=['csv'=>$csv,'expires'=>time()+600];
                    $result=['url'=>$this->modx->getOption('assets_url').'components/shopkeeper4/download.php?token='.$token];break;
                default: return $this->failure('Неизвестная операция.');
            }
            return $this->success('',$result);
        } catch(\Throwable $e) { $this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR,'Shopkeeper 4: '.$e->getMessage());return $this->failure($e->getMessage()); }
    }
}
return Shopkeeper4ManageProcessor::class;
