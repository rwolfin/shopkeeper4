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
            if (str_starts_with($task,'legacy/')) return $this->legacy($task,$input,$store,$app,$actor);
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

    private function legacy(string $task,array $input,\Shopkeeper4\Store $store,\Shopkeeper4\Application $app,int $actor)
    {
        $settings=$store->settings();$data=$settings['data'];
        $legacySettings=$this->legacySettings($data,(int)$settings['version']);
        switch (substr($task,7)) {
            case 'getSettings': return $this->success('',$legacySettings);
            case 'saveSettings':
                $posted=$input['data'] ?? [];
                $converted=$this->settingsFromLegacy($posted,$data);
                $saved=$store->saveSettings($converted,(int)($posted['_sk4_version'] ?? 0));
                return $this->success('Настройки сохранены.',['version'=>$saved['version']]);
            case 'getOrdersList':
                $filters=$this->legacyFilters($input['filters'] ?? []);
                $params=['start'=>max(0,((int)($input['page'] ?? 1)-1)*(int)($input['count'] ?? 15)),'limit'=>max(1,min(200,(int)($input['count'] ?? 15)))];
                $params=array_merge($params,$filters);
                $sort=(array)($input['sorting'] ?? ['id'=>'desc']);$sortKey=(string)array_key_first($sort);$sortDir=strtoupper((string)($sort[$sortKey] ?? 'DESC'));
                $params['sort']=['status'=>'status_id','date'=>'created_at','price'=>'total','count_total'=>'id','userid'=>'customer_id','username'=>'customer_id'][$sortKey] ?? $sortKey;$params['dir']=$sortDir;
                $list=$store->orders($params);$rows=[];$statuses=array_column($data['statuses'],null,'id');
                foreach($list['results'] as $row){
                    $contacts=\Shopkeeper4\Database::decode($row['contacts']);
                    $item=['id'=>(int)$row['id'],'status'=>(int)$row['status_id'],'status_id'=>(int)$row['status_id'],'date'=>date('d.m.Y H:i:s',strtotime($row['created_at'])),'price'=>\Shopkeeper4\Decimal::text((int)$row['total']),'count_total'=>\Shopkeeper4\Decimal::text((int)$row['quantity'],3),'userid'=>(int)$row['customer_id'],'username'=>(int)$row['customer_id'] ? (string)$row['customer_id'] : 'N/a','email'=>$row['email'],'note'=>$row['note'],'currency'=>$row['currency'],'status_name'=>$statuses[$row['status_id']]['label'] ?? ''];
                    foreach($contacts as $key=>$value)$item['contacts.'.$key]=$value;
                    $rows[]=$item;
                }
                return $this->success('',['object'=>$rows,'total'=>$list['total']]);
            case 'getOrder':
                $order=$store->order((int)($input['order_id'] ?? 0));$contacts=[];$rank=0;
                foreach($order['contacts'] as $name=>$value){$label=$name;foreach($data['contacts'] as $field)if($field['name']===$name){$label=$field['label'];break;}$contacts[]=['name'=>$name,'value'=>$value,'label'=>$label,'rank'=>$rank++];}
                $purchases=[];
                foreach($order['items'] as $item){$options=[];foreach($item['options'] as $n=>$option)$options[$option['name'] ?: 'shk_option'.($n+1)]=[$option['value'] ?: $option['label'],\Shopkeeper4\Decimal::text((int)$option['price'])];$purchases[]=['id'=>(int)$item['id'],'p_id'=>(int)$item['product_id'],'name'=>$item['name'],'count'=>\Shopkeeper4\Decimal::text((int)$item['quantity'],3),'price'=>\Shopkeeper4\Decimal::text((int)$item['price']),'options'=>$options];}
                $delivery=\Shopkeeper4\Settings::find($data['delivery'],$order['delivery_id']);$payment=\Shopkeeper4\Settings::find($data['payments'],$order['payment_id']);
                $legacy=$order+['date'=>date('d.m.Y H:i:s',strtotime($order['created_at'])),'delivery'=>$delivery['label'],'payment'=>$payment['label'],'delivery_price'=>\Shopkeeper4\Decimal::text((int)$order['delivery_fee']),'contacts'=>$contacts,'purchases'=>$purchases];
                $legacy['status']=(int)$order['status_id'];$legacy['contacts']=$contacts;$legacy['purchases']=$purchases;
                return $this->success('',$legacy);
            case 'saveOrder':
                $order=$input['order'] ?? [];if(empty($order['id']))throw new \InvalidArgumentException('Не указан заказ.');
                $existing=$store->order((int)$order['id']);$delivery=$this->findLegacyValue($data['delivery'],(string)($order['delivery'] ?? ''),'label');$payment=$this->findLegacyValue($data['payments'],(string)($order['payment'] ?? ''),'label');
                $contacts=[];foreach(($order['contacts'] ?? []) as $field)if(!empty($field['name']))$contacts[$field['name']]=$field['value'] ?? '';
                $items=[];foreach(($order['purchases'] ?? []) as $row){$options=[];foreach(($row['options'] ?? []) as $key=>$option){if(!is_array($option)||trim((string)($option[0] ?? ''))==='')continue;$options[]=['id'=>$key,'name'=>$key,'label'=>$key,'value'=>(string)$option[0],'price'=>(string)($option[1] ?? '0')];}$items[]=['id'=>(int)($row['id'] ?? 0),'product_id'=>(int)($row['p_id'] ?? 0),'name'=>(string)($row['name'] ?? ''),'quantity'=>(string)($row['count'] ?? '1'),'price'=>(string)($row['price'] ?? '0'),'options'=>$options];}
                $saved=$store->update((int)$order['id'],['version'=>(int)($order['version'] ?? $existing['version']),'status_id'=>(int)($order['status'] ?? $existing['status_id']),'delivery_id'=>(int)$delivery['id'],'delivery_fee'=>(string)($order['delivery_price'] ?? '0'),'payment_id'=>(int)$payment['id'],'note'=>(string)($order['note'] ?? ''),'tracking'=>(string)($order['tracking'] ?? ''),'contacts'=>$contacts,'items'=>$items],$actor);
                if($saved['status_changed'])$saved['mail_errors']=$app->notify((int)$order['id']);$app->event('OnShopkeeper4OrderUpdated',['order_id'=>(int)$order['id']]);$app->invalidate();
                return $this->success('Заказ сохранён.',$saved);
            case 'updateOrderStatus':
                $ids=array_map('intval',(array)($input['order_id'] ?? []));$versions=$this->versions($store,$ids);
                $changed=$store->status($versions,(int)($input['status'] ?? 0),$actor);
                foreach($changed as $id)$app->notify($id);$app->event('OnShopkeeper4StatusChanged',['order_ids'=>$changed,'status_id'=>(int)($input['status'] ?? 0)]);$app->invalidate();
                return $this->success('Статус заказов обновлён.',['ids'=>$changed]);
            case 'removeOrders':
                $ids=array_map('intval',(array)($input['order_id'] ?? []));$count=$store->remove($this->versions($store,$ids),$actor);$app->invalidate();
                return $this->success('Заказы удалены.',['count'=>$count]);
            case 'getStat':
                $filters=$this->legacyFilters($input['filters'] ?? []);$rows=$store->stats($filters);$columns=[['x']];$names=[];$colors=[];$grouped=[];
                foreach($rows as $row){$key='status'.$row['status_id'];$grouped[$key][$row['month']]=(int)$row['count'];$status=$this->findLegacyValue($data['statuses'],(string)$row['status_id'],'id');$names[$key]=$status['label'];$colors[$key]=$status['color'];}
                $months=[];foreach($grouped as $series)foreach($series as $month=>$count)$months[$month]=true;ksort($months);
                foreach(array_keys($months) as $month)$columns[0][]=$month.'-01';
                foreach($data['statuses'] as $status){$key='status'.$status['id'];$column=[$key];foreach(array_keys($months) as $month)$column[]=$grouped[$key][$month] ?? 0;$columns[]=$column;}
                return $this->success('',['x'=>'x','columns'=>$columns,'names'=>$names,'colors'=>$colors]);
            default: return $this->failure('Неизвестная операция Shopkeeper 3 UI.');
        }
    }

    private function versions(\Shopkeeper4\Store $store,array $ids): array { $versions=[];foreach(array_unique($ids) as $id){$order=$store->order((int)$id);$versions[(int)$id]=(int)$order['version'];}return $versions; }
    private function findLegacyValue(array $rows,string $value,string $key): array { foreach($rows as $row)if((string)$row[$key]===$value)return $row;throw new \InvalidArgumentException('Значение не найдено: '.$value); }
    private function legacySettings(array $data,int $version): array
    {
        $out=['_sk4_version'=>$version,'statuses'=>[],'currency_rate'=>[],'delivery'=>[],'payments'=>[],'contacts_fields'=>[],'order_fields'=>[]];
        foreach($data['statuses'] as $row)$out['statuses'][]=['id'=>$row['id'],'label'=>$row['label'],'tpl'=>$row['template'],'color'=>$row['color'],'cancelled'=>$row['cancelled'] ?? false];
        foreach($data['currencies'] as $i=>$row)$out['currency_rate'][]=['id'=>$i+1,'code'=>$row['code'],'label'=>$row['label'],'value'=>$row['rate']];
        foreach($data['delivery'] as $row)$out['delivery'][]=['id'=>$row['id'],'label'=>$row['label'],'value'=>$row['label'],'price'=>$row['price'],'free_start'=>$row['free_from']];
        foreach($data['payments'] as $row)$out['payments'][]=['id'=>$row['id'],'label'=>$row['label'],'value'=>$row['label']];
        foreach($data['contacts'] as $i=>$row)$out['contacts_fields'][]=$row+['rank'=>$i];
        $aliases=['status_id'=>'status','created_at'=>'date','total'=>'price','quantity'=>'count_total','customer_id'=>'userid','tracking'=>'tracking_num'];
        foreach($data['columns'] as $row)$out['order_fields'][]=['name'=>$aliases[$row['name']] ?? $row['name'],'label'=>$row['label'],'visible'=>$row['visible']];
        return $out;
    }
    private function settingsFromLegacy(array $input,array $current): array
    {
        $data=$current;$data['statuses']=[];
        foreach(array_values($input['statuses'] ?? $current['statuses']) as $i=>$row){$id=(int)($row['id'] ?? 0);if($id<1){$id=1;foreach($current['statuses'] as $status)$id=max($id,(int)$status['id']+1);foreach($data['statuses'] as $status)$id=max($id,(int)$status['id']+1);}$prior=null;foreach($current['statuses'] as $status)if((int)$status['id']===$id)$prior=$status;$data['statuses'][]=array_merge($prior ?? ['cancelled'=>false],['id'=>$id,'label'=>(string)($row['label'] ?? ''),'template'=>(string)($row['tpl'] ?? ($row['template'] ?? '')),'color'=>(string)($row['color'] ?? '#D9E9FF')]);}
        $data['currencies']=[];
        foreach(array_values($input['currency_rate'] ?? []) as $i=>$row){$old=$current['currencies'][(int)($row['id'] ?? ($i+1))-1] ?? null;$label=(string)($row['label'] ?? '');if($label==='')continue;$code=$old['code'] ?? 'CUR'.((int)($row['id'] ?? ($i+1)));
            $data['currencies'][]=['code'=>substr($code,0,12),'label'=>$label,'rate'=>(string)($row['value'] ?? ($old['rate'] ?? '1'))];}
        if(!$data['currencies'])$data['currencies']=$current['currencies'];
        foreach(['delivery','payments'] as $group){$data[$group]=[];foreach(array_values($input[$group] ?? []) as $i=>$row){$id=(int)($row['id'] ?? 0);if($id<1){$id=1;foreach($current[$group] as $prior)$id=max($id,(int)$prior['id']+1);foreach($data[$group] as $prior)$id=max($id,(int)$prior['id']+1);}$old=null;foreach($current[$group] as $prior)if((int)$prior['id']===$id)$old=$prior;$data[$group][]=$group==='delivery'?array_merge($old ?? ['free_from'=>'0.00','active'=>true],['id'=>$id,'label'=>(string)($row['label'] ?? ''),'price'=>(string)($row['price'] ?? '0'),'free_from'=>(string)($row['free_start'] ?? '0'),'active'=>true]):array_merge($old ?? ['active'=>true],['id'=>$id,'label'=>(string)($row['label'] ?? ''),'active'=>true]);}if(!$data[$group])$data[$group]=$current[$group];}
        $data['contacts']=[];foreach(array_values($input['contacts_fields'] ?? []) as $row){if(empty($row['name'])||empty($row['label']))continue;$old=null;foreach($current['contacts'] as $item)if($item['name']===$row['name'])$old=$item;$data['contacts'][]=array_merge($old ?? ['type'=>'text','required'=>false],['name'=>(string)$row['name'],'label'=>(string)$row['label']]);}if(!$data['contacts'])$data['contacts']=$current['contacts'];
        $fieldMap=['status'=>'status_id','date'=>'created_at','price'=>'total','count_total'=>'quantity','userid'=>'customer_id','tracking_num'=>'tracking'];$allowed=['id','status_id','created_at','total','quantity','email','customer_id','currency','delivery_name','payment_name','tracking','context_key'];$data['columns']=[];
        foreach(array_values($input['order_fields'] ?? []) as $row){$name=$fieldMap[$row['name'] ?? ''] ?? ($row['name'] ?? '');if(!in_array($name,$allowed,true)||empty($row['label']))continue;$data['columns'][]=['name'=>$name,'label'=>(string)$row['label'],'visible'=>true];}
        if(!$data['columns'])$data['columns']=$current['columns'];
        return $data;
    }
    private function legacyFilters($filters): array
    {
        if(is_string($filters))$filters=\Shopkeeper4\Database::decode($filters);if(!is_array($filters))return [];$out=[];
        if(!empty($filters['date'])&&is_array($filters['date'])&&count($filters['date'])>=2){foreach(['date_from','date_to'] as $i=>$key){$date=\DateTimeImmutable::createFromFormat('!d/m/Y',(string)$filters['date'][$i]) ?: \DateTimeImmutable::createFromFormat('!d-m-Y',(string)$filters['date'][$i]);if($date)$out[$key]=$date->format('Y-m-d');}}
        if(!empty($filters['status']))$out['statuses']=(array)$filters['status'];return $out;
    }
}
return Shopkeeper4ManageProcessor::class;
