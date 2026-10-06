<?php
namespace Shopkeeper4;

final class Cart
{
    private array $state;
    public function __construct(private Store $store, private string $context, array &$session)
    {
        if(!isset($session['shopkeeper4'][$context])) $session['shopkeeper4'][$context]=['items'=>[],'csrf'=>bin2hex(random_bytes(24)),'checkout'=>bin2hex(random_bytes(24)),'currency'=>'','delivery_id'=>0,'completed'=>[]];
        $this->state=&$session['shopkeeper4'][$context];
    }
    public function csrf(): string { return $this->state['csrf']; }
    public function checkoutToken(): string { return $this->state['checkout']; }
    public function verify($token): void { if(!is_string($token) || !hash_equals($this->csrf(),$token)) throw new \RuntimeException('Сессия устарела. Обновите страницу.'); }
    public function view(): array { return $this->store->quote($this->state['items'],$this->context,$this->state['currency'],(int)$this->state['delivery_id']); }
    public function mutate(string $action, array $input): array
    {
        $old=$this->state;
        try {
            if($action==='add') {
                $id=(int)($input['product_id'] ?? 0);$selected=$input['selected'] ?? [];
                if(!is_array($selected)) throw new \InvalidArgumentException('Неверные параметры товара.');
                ksort($selected);$key=hash('sha256',$id.':'.Database::json($selected));
                $qty=Decimal::scaled($input['quantity'] ?? '1',3);
                if($qty<1) throw new \InvalidArgumentException('Количество должно быть больше нуля.');
                $qty+=isset($this->state['items'][$key])?Decimal::scaled($this->state['items'][$key]['quantity'],3):0;
                $this->state['items'][$key]=['product_id'=>$id,'selected'=>$selected,'quantity'=>Decimal::text($qty,3)];
            } elseif($action==='quantity') {
                $key=(string)($input['key'] ?? '');
                if(!isset($this->state['items'][$key])) throw new \InvalidArgumentException('Позиция не найдена.');
                $qty=Decimal::scaled($input['quantity'] ?? '0',3);
                if($qty===0) unset($this->state['items'][$key]); else $this->state['items'][$key]['quantity']=Decimal::text($qty,3);
            } elseif($action==='remove') unset($this->state['items'][(string)($input['key'] ?? '')]);
            elseif($action==='clear') $this->state['items']=[];
            elseif($action==='preferences') {
                $this->state['currency']=(string)($input['currency'] ?? $this->state['currency']);
                $this->state['delivery_id']=(int)($input['delivery_id'] ?? $this->state['delivery_id']);
            } else throw new \InvalidArgumentException('Неизвестное действие корзины.');
            if(isset($this->state['completed'][$this->state['checkout']])) $this->state['checkout']=bin2hex(random_bytes(24));
            return $this->view();
        } catch(\Throwable $e) { $this->state=$old; throw $e; }
    }
    public function checkout(array $input, int $userId): array
    {
        $token=(string)($input['checkout_token'] ?? '');
        if(isset($this->state['completed'][$token])) return array_replace($this->state['completed'][$token],['duplicate'=>true]);
        if(!hash_equals($this->checkoutToken(),$token)) throw new \RuntimeException('Форма оформления устарела. Обновите страницу.');
        $input['currency']=$input['currency'] ?? $this->state['currency'];
        $input['delivery_id']=$input['delivery_id'] ?? $this->state['delivery_id'];
        $result=$this->store->create($this->state['items'],$input,$this->context,$userId,hash('sha256',$this->state['csrf'].':'.$token));
        $this->state['items']=[];
        $this->state['completed'][$token]=$result;
        if(count($this->state['completed'])>10) array_shift($this->state['completed']);
        return $result;
    }
}
