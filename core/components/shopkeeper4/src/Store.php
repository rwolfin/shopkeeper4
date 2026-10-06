<?php
namespace Shopkeeper4;

final class Store
{
    public function __construct(public Database $db, private ProductProvider $products) {}
    public function settings(): array
    {
        $row = $this->db->one('SELECT * FROM ' . $this->db->table('config') . ' WHERE id=1');
        if (!$row) throw new \RuntimeException('Shopkeeper 4 не установлен: отсутствуют настройки.');
        return ['version'=>(int)$row['version'],'data'=>Database::decode($row['data'])];
    }
    public function saveSettings(array $data, int $version): array
    {
        $data = Settings::validate($data);
        return $this->db->transaction(function() use($data,$version) {
            $row = $this->db->one('SELECT * FROM ' . $this->db->table('config') . ' WHERE id=1' . $this->db->lock());
            if ((int)$row['version'] !== $version) throw new \RuntimeException('Настройки изменены другим пользователем. Обновите страницу.');
            $ids = array_column($data['statuses'],'id');
            foreach ($this->db->all('SELECT DISTINCT status_id FROM ' . $this->db->table('orders')) as $order) if (!in_array($order['status_id'],$ids)) throw new \RuntimeException('Нельзя удалить статус, используемый заказами.');
            $old = Database::decode($row['data']);
            foreach ($old['statuses'] as $status) {
                foreach ($data['statuses'] as $new) if ($new['id']==$status['id'] && (bool)$new['cancelled'] !== (bool)$status['cancelled'] && $this->db->one('SELECT id FROM ' . $this->db->table('orders') . ' WHERE status_id=?',[$status['id']])) throw new \RuntimeException('Признак отмены используемого статуса менять нельзя.');
            }
            $this->db->run('UPDATE ' . $this->db->table('config') . ' SET data=?,version=version+1 WHERE id=1',[Database::json($data)]);
            return ['data'=>$data,'version'=>$version+1];
        });
    }
    public function quote(array $cart, string $context, string $currency, int $deliveryId): array
    {
        if (count($cart) > 200) throw new \InvalidArgumentException('В корзине может быть не более 200 позиций.');
        $settings = $this->settings()['data'];
        $base = Settings::find($settings['currencies'],$settings['general']['base_currency'],'code');
        $target = Settings::find($settings['currencies'],$currency ?: $base['code'],'code');
        $baseRate=Decimal::scaled($base['rate'],4); $rate=Decimal::scaled($target['rate'],4);
        $items=[]; $subtotal=0; $quantity=0;
        foreach ($cart as $key=>$row) {
            $qty = Decimal::scaled($row['quantity'] ?? '1',3);
            if ($qty < 1 || (!$settings['general']['fractional_quantity'] && $qty%1000)) throw new \InvalidArgumentException('Недопустимое количество.');
            $item = $this->products->quote((int)($row['product_id'] ?? 0),$row['selected'] ?? [],$context,$settings);
            $item['price'] = Decimal::convert($item['price'],$baseRate,$rate);
            foreach ($item['options'] as &$option) $option['price']=Decimal::convert($option['price'],$baseRate,$rate);
            unset($option);
            $item['quantity']=$qty; $item['key']=(string)$key;
            $item['total']=Decimal::line($item['price']+array_sum(array_column($item['options'],'price')),$qty);
            $items[]=$item; $subtotal+=$item['total']; $quantity+=$qty;
            if($subtotal>99999999999) throw new \InvalidArgumentException('Сумма заказа превышает допустимый предел.');
        }
        $delivery=Settings::find($settings['delivery'],$deliveryId ?: $settings['delivery'][0]['id']);
        if (empty($delivery['active'])) throw new \InvalidArgumentException('Способ доставки недоступен.');
        $fee=Decimal::convert(Decimal::scaled($delivery['price']),$baseRate,$rate);
        $free=Decimal::convert(Decimal::scaled($delivery['free_from']),$baseRate,$rate);
        if ($free>0 && $subtotal >= $free) $fee=0;
        if (!$items) $fee=0;
        return ['items'=>$items,'subtotal'=>$subtotal,'quantity'=>$quantity,'delivery_fee'=>$fee,'total'=>$subtotal+$fee,'currency'=>$target['code'],'delivery_id'=>(int)$delivery['id'],'delivery_name'=>$delivery['label']];
    }
    public function contacts(array $input, array $settings): array
    {
        $values=[];
        foreach ($settings['contacts'] as $field) {
            $value=$input[$field['name']] ?? '';
            if (!is_scalar($value) || mb_strlen((string)$value)>4000) throw new \InvalidArgumentException('Некорректное контактное поле.');
            $value=trim((string)$value);
            if (!empty($field['required']) && $value==='') throw new \InvalidArgumentException('Заполните поле «' . $field['label'] . '».');
            if ($field['type']==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Некорректный адрес почты.');
            $values[$field['name']]=$value;
        }
        if(strlen(Database::json($values))>60000) throw new \InvalidArgumentException('Контактные данные слишком велики.');
        return $values;
    }
    public function create(array $cart, array $input, string $context, int $userId, string $checkoutKey): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$checkoutKey)) throw new \InvalidArgumentException('Некорректный ключ оформления.');
        return $this->db->transaction(function() use($cart,$input,$context,$userId,$checkoutKey) {
            // Serialize checkout/config transitions, making idempotency safe even for parallel requests.
            $this->db->one('SELECT id FROM ' . $this->db->table('config') . ' WHERE id=1' . $this->db->lock());
            $existing=$this->db->one('SELECT id FROM ' . $this->db->table('orders') . ' WHERE checkout_key=?',[$checkoutKey]);
            if ($existing) return ['id'=>(int)$existing['id'],'duplicate'=>true];
            if (!$cart) throw new \InvalidArgumentException('Корзина пуста.');
            $settings=$this->settings()['data'];
            $quote=$this->quote($cart,$context,(string)($input['currency'] ?? ''),(int)($input['delivery_id'] ?? 0));
            $payment=Settings::find($settings['payments'],(int)($input['payment_id'] ?? $settings['payments'][0]['id']));
            if (empty($payment['active'])) throw new \InvalidArgumentException('Способ оплаты недоступен.');
            $contacts=$this->contacts($input,$settings);
            $now=gmdate('Y-m-d H:i:s');
            $order=['checkout_key'=>$checkoutKey,'context_key'=>$context,'customer_id'=>$userId,'status_id'=>$settings['general']['first_status'],'currency'=>$quote['currency'],'subtotal'=>$quote['subtotal'],'delivery_fee'=>$quote['delivery_fee'],'total'=>$quote['total'],'delivery_id'=>$quote['delivery_id'],'delivery_name'=>$quote['delivery_name'],'payment_id'=>$payment['id'],'payment_name'=>$payment['label'],'contacts'=>Database::json($contacts),'email'=>$contacts['email'] ?? '','note'=>'','tracking'=>'','created_at'=>$now,'updated_at'=>$now,'version'=>1];
            $id=$this->db->insert('orders',$order);
            $this->writeItems($id,$quote['items'],[],false);
            $this->history($id,$userId,'created','Заказ оформлен');
            return ['id'=>$id,'duplicate'=>false];
        });
    }
    public function order(int $id): array
    {
        $order=$this->db->one('SELECT * FROM ' . $this->db->table('orders') . ' WHERE id=? AND deleted_at IS NULL',[$id]);
        if (!$order) throw new \RuntimeException('Заказ не найден.');
        $order['contacts']=Database::decode($order['contacts']);
        $order['items']=$this->db->all('SELECT * FROM ' . $this->db->table('items') . ' WHERE order_id=? ORDER BY id',[$id]);
        foreach ($order['items'] as &$item) $item['options']=Database::decode($item['options']);
        unset($item);
        $order['history']=$this->db->all('SELECT * FROM ' . $this->db->table('history') . ' WHERE order_id=? ORDER BY id DESC',[$id]);
        $order['mail']=$this->db->all('SELECT id,recipient,attempts,sent_at,error,created_at FROM ' . $this->db->table('outbox') . ' WHERE order_id=? ORDER BY id DESC',[$id]);
        return $order;
    }
    private function writeItems(int $id, array $items, array $old, bool $cancelled): void
    {
        $deltas=[];
        foreach ($old as $item) if ($item['stock_key']!=='') {
            $key=$item['provider'].'|'.$item['product_id'].'|'.$item['stock_key'];
            $deltas[$key]=($deltas[$key] ?? 0)-(int)$item['reserved'];
        }
        foreach ($items as &$item) {
            $item['reserved']=(!$cancelled && $item['stock_key']!=='') ? (int)$item['quantity'] : 0;
            if ($item['stock_key']!=='') {
                $key=$item['provider'].'|'.$item['product_id'].'|'.$item['stock_key'];
                $deltas[$key]=($deltas[$key] ?? 0)+$item['reserved'];
            }
        }
        unset($item); ksort($deltas);
        foreach ($deltas as $key=>$delta) { [$provider,$product,$stock]=explode('|',$key,3); if($delta) $this->products->adjustStock((int)$product,$stock,$delta); }
        $this->db->run('DELETE FROM ' . $this->db->table('items') . ' WHERE order_id=?',[$id]);
        foreach ($items as $item) $this->db->insert('items',['order_id'=>$id,'product_id'=>$item['product_id'],'provider'=>$item['provider'],'name'=>$item['name'],'price'=>$item['price'],'quantity'=>$item['quantity'],'total'=>$item['total'],'options'=>Database::json($item['options']),'stock_key'=>$item['stock_key'],'reserved'=>$item['reserved']]);
    }
    public function update(int $id, array $input, int $actor): array
    {
        return $this->db->transaction(function() use($id,$input,$actor) {
            $locked=$this->db->one('SELECT * FROM ' . $this->db->table('orders') . ' WHERE id=? AND deleted_at IS NULL' . $this->db->lock(),[$id]);
            if (!$locked || (int)$locked['version']!==(int)($input['version'] ?? 0)) throw new \RuntimeException('Заказ изменён другим сотрудником или удалён. Откройте его заново.');
            $old=$this->order($id); $settings=$this->settings()['data'];
            $status=Settings::find($settings['statuses'],(int)($input['status_id'] ?? $old['status_id']));
            $items=[];$subtotal=0;
            if (!isset($input['items']) || !is_array($input['items']) || !$input['items'] || count($input['items'])>200) throw new \InvalidArgumentException('Заказ должен содержать от 1 до 200 позиций.');
            foreach($input['items'] as $row) {
                $product=(int)($row['product_id'] ?? 0); $stock='';$provider='resource';
                // Keep stock metadata from the original item, independent of current global settings.
                $prior=null;
                foreach($old['items'] as $candidate) if ((int)$candidate['id']===(int)($row['id'] ?? 0) && (int)$candidate['product_id']===$product) { $prior=$candidate;break; }
                if($prior) { $stock=$prior['stock_key']; $provider=$prior['provider']; }
                elseif($product>0) { $selected=[];foreach($row['options'] ?? [] as $opt) if(!empty($opt['name'])) $selected[$opt['name']]=$opt['id'] ?? $opt['value'] ?? ''; $trusted=$this->products->quote($product,$selected, $old['context_key'], $settings); $stock=$trusted['stock_key'];$provider=$trusted['provider']; }
                $name=trim((string)($row['name'] ?? ''));
                if($name==='' || mb_strlen($name)>255) throw new \InvalidArgumentException('Заполните название позиции.');
                $qty=Decimal::scaled($row['quantity'],3);$price=Decimal::scaled($row['price']);$options=[];
                if(count($row['options'] ?? [])>30) throw new \InvalidArgumentException('Слишком много параметров.');
                foreach($row['options'] ?? [] as $opt) $options[]=['id'=>mb_substr((string)($opt['id'] ?? ''),0,100),'name'=>mb_substr((string)($opt['name'] ?? ''),0,100),'label'=>mb_substr((string)($opt['label'] ?? ''),0,100),'value'=>mb_substr((string)($opt['value'] ?? ''),0,255),'price'=>Decimal::scaled($opt['price'] ?? '0')];
                $total=Decimal::line($price+array_sum(array_column($options,'price')),$qty);
                $items[]=['product_id'=>$product,'provider'=>$provider,'stock_key'=>$stock,'name'=>$name,'price'=>$price,'quantity'=>$qty,'total'=>$total,'options'=>$options];$subtotal+=$total;
                if($subtotal>99999999999) throw new \InvalidArgumentException('Сумма заказа превышает допустимый предел.');
            }
            $contacts=$this->contacts($input['contacts'] ?? [],$settings);
            $fee=Decimal::scaled($input['delivery_fee'] ?? '0');
            $deliveryId=(int)($input['delivery_id'] ?? $old['delivery_id']);$paymentId=(int)($input['payment_id'] ?? $old['payment_id']);
            $deliveryName=$deliveryId===(int)$old['delivery_id']?$old['delivery_name']:Settings::find($settings['delivery'],$deliveryId)['label'];
            $paymentName=$paymentId===(int)$old['payment_id']?$old['payment_name']:Settings::find($settings['payments'],$paymentId)['label'];
            $this->writeItems($id,$items,$old['items'],!empty($status['cancelled']));
            $this->db->run('UPDATE ' . $this->db->table('orders') . ' SET status_id=?,contacts=?,email=?,note=?,tracking=?,delivery_id=?,delivery_name=?,payment_id=?,payment_name=?,subtotal=?,delivery_fee=?,total=?,version=version+1,updated_at=? WHERE id=?',[$status['id'],Database::json($contacts),$contacts['email'] ?? '',mb_substr((string)($input['note'] ?? ''),0,10000),mb_substr((string)($input['tracking'] ?? ''),0,255),$deliveryId,$deliveryName,$paymentId,$paymentName,$subtotal,$fee,$subtotal+$fee,gmdate('Y-m-d H:i:s'),$id]);
            $this->history($id,$actor,'updated','Заказ изменён; статус ' . $old['status_id'] . ' → ' . $status['id']);
            return ['id'=>$id,'status_changed'=>(int)$status['id']!==(int)$old['status_id']];
        });
    }
    public function status(array $versions, int $statusId, int $actor): array
    {
        $status=Settings::find($this->settings()['data']['statuses'],$statusId);
        if(!$versions || count($versions)>200) throw new \InvalidArgumentException('Выберите 1–200 заказов.');
        return $this->db->transaction(function() use($versions,$status,$actor) {
            ksort($versions);$changed=[];
            foreach($versions as $id=>$version) {
                $row=$this->db->one('SELECT * FROM ' . $this->db->table('orders') . ' WHERE id=? AND deleted_at IS NULL' . $this->db->lock(),[(int)$id]);
                if(!$row || (int)$row['version']!==(int)$version) throw new \RuntimeException('Заказ №' . $id . ' уже изменён. Обновите список.');
                if((int)$row['status_id']===(int)$status['id']) continue;
                $order=$this->order((int)$id);
                $this->writeItems((int)$id,$order['items'],$order['items'],!empty($status['cancelled']));
                $this->db->run('UPDATE ' . $this->db->table('orders') . ' SET status_id=?,version=version+1,updated_at=? WHERE id=?',[$status['id'],gmdate('Y-m-d H:i:s'),$id]);
                $this->history((int)$id,$actor,'status','Статус ' . $row['status_id'] . ' → ' . $status['id']);$changed[]=(int)$id;
            }
            return $changed;
        });
    }
    public function remove(array $versions, int $actor): int
    {
        if(!$versions || count($versions)>200) throw new \InvalidArgumentException('Выберите заказы.');
        return $this->db->transaction(function() use($versions,$actor) {
            ksort($versions);
            foreach($versions as $id=>$version) {
                $row=$this->db->one('SELECT id,version FROM ' . $this->db->table('orders') . ' WHERE id=? AND deleted_at IS NULL' . $this->db->lock(),[(int)$id]);
                if(!$row || (int)$row['version']!==(int)$version) throw new \RuntimeException('Список устарел. Обновите заказы.');
                $order=$this->order((int)$id);
                $this->writeItems((int)$id,$order['items'],$order['items'],true);
                $this->db->run('UPDATE ' . $this->db->table('orders') . ' SET deleted_at=?,version=version+1 WHERE id=?',[gmdate('Y-m-d H:i:s'),$id]);
                $this->history((int)$id,$actor,'removed','Заказ перемещён в архив; остатки возвращены');
            }
            return count($versions);
        });
    }
    public function history(int $id,int $actor,string $action,string $message): void
    {
        $this->db->insert('history',['order_id'=>$id,'actor_id'=>$actor,'action'=>$action,'message'=>$message,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public function filters(array $input): array
    {
        if(!empty($input['date_from']) && !empty($input['date_to']) && $input['date_from']>$input['date_to']) throw new \InvalidArgumentException('Начало периода позже окончания.');
        $where=['o.deleted_at IS NULL'];$args=[];
        foreach(['date_from'=>'>=','date_to'=>'<='] as $key=>$op) if(!empty($input[$key])) {
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$input[$key]);
            if(!$date || $date->format('Y-m-d')!==$input[$key]) throw new \InvalidArgumentException('Некорректная дата.');
            $where[]='o.created_at '.$op.' ?';$args[]=$input[$key].($key==='date_from'?' 00:00:00':' 23:59:59');
        }
        if(!empty($input['statuses'])) {
            $ids=is_array($input['statuses'])?$input['statuses']:explode(',',(string)$input['statuses']);
            $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
            if($ids) {$where[]='o.status_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=array_merge($args,$ids);}
        }
        if(!empty($input['query'])) { $term=mb_substr((string)$input['query'],0,200);$where[]='(o.email LIKE ? OR o.contacts LIKE ? OR o.id=?)';$args[]='%'.$term.'%';$args[]='%'.$term.'%';$args[]=(int)$term; }
        return [implode(' AND ',$where),$args];
    }
    public function orders(array $input, bool $export=false): array
    {
        [$where,$args]=$this->filters($input);
        $count=(int)$this->db->one('SELECT COUNT(*) AS n FROM '.$this->db->table('orders').' o WHERE '.$where,$args)['n'];
        $sort=in_array($input['sort'] ?? '',['id','created_at','total','status_id','email','customer_id'],true)?$input['sort']:'id';
        $direction=strtoupper($input['dir'] ?? 'DESC')==='ASC'?'ASC':'DESC';
        $limit=$export?10001:max(1,min(200,(int)($input['limit'] ?? 25)));$start=$export?0:max(0,(int)($input['start'] ?? 0));
        if($export && $count>10000) throw new \RuntimeException('В экспорте более 10 000 заказов. Уменьшите период.');
        $rows=$this->db->all('SELECT o.*, (SELECT COALESCE(SUM(i.quantity),0) FROM '.$this->db->table('items').' i WHERE i.order_id=o.id) AS quantity FROM '.$this->db->table('orders').' o WHERE '.$where.' ORDER BY o.'.$sort.' '.$direction.' LIMIT '.$limit.' OFFSET '.$start,$args);
        return ['results'=>$rows,'total'=>$count];
    }
    public function stats(array $input): array
    {
        $input['date_from']=$input['date_from'] ?? gmdate('Y-m-01',strtotime('-11 months'));
        $input['date_to']=$input['date_to'] ?? gmdate('Y-m-d');
        if(strtotime($input['date_to'])-strtotime($input['date_from'])>366*5*86400) throw new \InvalidArgumentException('Для статистики выберите период не более 5 лет.');
        [$where,$args]=$this->filters($input);
        return $this->db->all('SELECT SUBSTR(o.created_at,1,7) AS month,o.status_id,o.currency,COUNT(*) AS count,SUM(o.total) AS amount FROM '.$this->db->table('orders').' o WHERE '.$where.' GROUP BY SUBSTR(o.created_at,1,7),o.status_id,o.currency ORDER BY month',$args);
    }
}
