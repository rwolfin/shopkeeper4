<?php
namespace Shopkeeper4;

final class Application
{
    public Database $db;
    public Store $store;
    public function __construct(public $modx)
    {
        $this->db=new Database($modx,(string)$modx->getOption('table_prefix'));
        $provider=new ResourceProducts($modx,$this->db);
        // An installed MODX 3 plugin can register an alternative product provider in the DI container.
        if(isset($modx->services) && $modx->services->has('shopkeeper4.products')) $provider=$modx->services->get('shopkeeper4.products');
        if(!$provider instanceof ProductProvider) throw new \RuntimeException('Провайдер товаров должен реализовать Shopkeeper4\\ProductProvider.');
        $this->store=new Store($this->db,$provider);
    }
    public function cart(): Cart { return new Cart($this->store,(string)$this->modx->context->get('key'),$_SESSION); }
    public function notify(int $id, bool $created=false): array
    {
        try { return $this->deliverNotifications($id,$created); }
        catch(\Throwable $e) { $this->modx->log(1,'Shopkeeper 4 notify: '.$e->getMessage());return [$e->getMessage()]; }
    }
    private function deliverNotifications(int $id, bool $created): array
    {
        $order=$this->store->order($id);$settings=$this->store->settings()['data'];
        $status=Settings::find($settings['statuses'],$order['status_id']);
        $recipients=[];
        if($order['email'] && !empty($status['template'])) $recipients[]=[$order['email'],$status['template']];
        if($created && !empty($settings['general']['manager_email']) && !empty($settings['general']['manager_template'])) $recipients[]=[$settings['general']['manager_email'],$settings['general']['manager_template']];
        $errors=[];
        foreach($recipients as [$email,$template]) {
            try {
                $items='';foreach($order['items'] as $item) $items.='<tr><td>'.Html::escape($item['name']).'</td><td>'.Html::escape(Decimal::text((int)$item['quantity'],3)).'</td><td>'.Html::money($item['total']).'</td></tr>';
                $contacts='';foreach($order['contacts'] as $key=>$value) $contacts.='<p>'.Html::escape($key).': '.Html::escape($value).'</p>';
                $body=$this->modx->getChunk($template,['id'=>(int)$id,'status'=>Html::escape($status['label']),'items'=>$items,'contacts'=>$contacts,'total'=>Html::money($order['total']),'currency'=>Html::escape($order['currency']),'delivery'=>Html::escape($order['delivery_name']),'tracking'=>Html::escape($order['tracking'])]);
                if(!$body) throw new \RuntimeException('Не найден шаблон письма '.$template);
                $mailId=$this->db->insert('outbox',['order_id'=>$id,'recipient'=>$email,'template'=>$template,'subject'=>'Заказ №'.$id.' — '.$status['label'],'body'=>$body,'attempts'=>0,'error'=>'','created_at'=>gmdate('Y-m-d H:i:s')]);
                $this->sendMail($mailId);
            } catch(\Throwable $e) { $errors[]=$e->getMessage();$this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR,'Shopkeeper 4 mail: '.$e->getMessage()); }
        }
        return $errors;
    }
    public function sendMail(int $id): void
    {
        // Serialize a queue item so two manager retries cannot send it simultaneously.
        $error=$this->db->transaction(function() use($id) {
            $row=$this->db->one('SELECT * FROM '.$this->db->table('outbox').' WHERE id=?'.$this->db->lock(),[$id]);
            if(!$row || $row['sent_at']) return '';
            try {
                $mail=$this->modx->getService('mail',\MODX\Revolution\Mail\modPHPMailer::class);
                if(!$mail) throw new \RuntimeException('Почтовый сервис MODX недоступен.');
                $mail->reset();
                $mail->set(\MODX\Revolution\Mail\modMail::MAIL_FROM,$this->modx->getOption('emailsender'));
                $mail->set(\MODX\Revolution\Mail\modMail::MAIL_FROM_NAME,$this->modx->getOption('site_name'));
                $mail->set(\MODX\Revolution\Mail\modMail::MAIL_SUBJECT,$row['subject']);
                $mail->set(\MODX\Revolution\Mail\modMail::MAIL_BODY,$row['body']);
                $mail->address('to',$row['recipient']);$mail->setHTML(true);
                if(!$mail->send()) throw new \RuntimeException('Отправка не удалась. Проверьте SMTP MODX.');
                $mail->reset();
                $this->db->run('UPDATE '.$this->db->table('outbox').' SET sent_at=?,attempts=attempts+1,error=? WHERE id=?',[gmdate('Y-m-d H:i:s'),'',$id]);
                return '';
            } catch(\Throwable $e) {
                $this->db->run('UPDATE '.$this->db->table('outbox').' SET attempts=attempts+1,error=? WHERE id=?',[mb_substr($e->getMessage(),0,1000),$id]);
                return $e->getMessage();
            }
        });
        if($error) throw new \RuntimeException($error);
    }
    public function event(string $name,array $params): void
    {
        // Post-commit hooks cannot roll back the completed operation or turn success into an error response.
        try { $this->modx->invokeEvent($name,$params); }
        catch(\Throwable $e) { $this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR,'Shopkeeper 4 event '.$name.': '.$e->getMessage()); }
    }
    public function checkout(array $input): array
    {
        $cart=$this->cart();$cart->verify($input['csrf'] ?? null);
        $context=$this->modx->context->get('key');
        $user=$this->modx->user->hasSessionContext($context)?(int)$this->modx->user->get('id'):0;
        $result=$cart->checkout($input,$user);
        if(empty($result['duplicate'])) {
            try {$this->notify($result['id'],true);} catch(\Throwable $e) {$this->modx->log(1,'Shopkeeper 4 notify: '.$e->getMessage());}
            $this->event('OnShopkeeper4OrderCreated',['order_id'=>$result['id']]);
            $this->invalidate();
        }
        return $result;
    }
    public function invalidate(): void
    {
        try { $this->modx->getCacheManager()->refresh(['resource'=>[]]); }
        catch(\Throwable $e) { $this->modx->log(1,'Shopkeeper 4 cache: '.$e->getMessage()); }
    }
}
