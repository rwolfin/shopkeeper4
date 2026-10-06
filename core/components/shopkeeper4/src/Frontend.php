<?php
namespace Shopkeeper4;

final class Frontend
{
    public function __construct(private Application $app) {}
    private function hidden(): string
    {
        return '<input type="hidden" name="csrf" value="'.Html::escape($this->app->cart()->csrf()).'">';
    }
    public function cart(bool $compact=false): string
    {
        $view=$this->app->cart()->view();$e=[Html::class,'escape'];
        if($compact) return '<span data-sk4-summary>'.count($view['items']).' поз. · '.Html::money($view['total']).' '.$e($view['currency']).'</span>';
        if(!$view['items']) return '<p>Ваша корзина пуста.</p>';
        $out='<table class="sk4-cart"><thead><tr><th>Товар</th><th>Количество</th><th>Сумма</th><th></th></tr></thead><tbody>';
        foreach($view['items'] as $item) {
            $options='';foreach($item['options'] as $option) $options.='<div class="sk4-muted">'.$e($option['label']).': '.$e($option['value']).'</div>';
            $out.='<tr><td>'.$e($item['name']).$options.'</td><td><form method="post" data-sk4-form>'.$this->hidden().'<input type="hidden" name="sk4_action" value="quantity"><input type="hidden" name="key" value="'.$e($item['key']).'"><input aria-label="Количество" type="number" step="0.001" min="0" max="100000" name="quantity" value="'.$e(Decimal::text($item['quantity'],3)).'"><button type="submit">Обновить</button></form></td><td>'.Html::money($item['total']).'</td><td><form method="post" data-sk4-form>'.$this->hidden().'<input type="hidden" name="sk4_action" value="remove"><input type="hidden" name="key" value="'.$e($item['key']).'"><button type="submit">Убрать</button></form></td></tr>';
        }
        $out.='</tbody></table><p>Доставка: '.Html::money($view['delivery_fee']).'</p><p><strong>Итого: '.Html::money($view['total']).' '.$e($view['currency']).'</strong></p><form method="post" data-sk4-form>'.$this->hidden().'<input type="hidden" name="sk4_action" value="clear"><button type="submit">Очистить корзину</button></form>';
        $page=(int)$this->app->store->settings()['data']['general']['order_page'];
        if($page) $out.='<p><a href="'.$e($this->app->modx->makeUrl($page)).'">Оформить заказ</a></p>';
        return $out;
    }
    public function select(string $group,string $name,$selected=null): string
    {
        $settings=$this->app->store->settings()['data'];$out='<select name="'.Html::escape($name).'" data-sk4-preference>';
        foreach($settings[$group] as $row) {
            if(isset($row['active']) && !$row['active']) continue;
            $value=$group==='currencies'?$row['code']:$row['id'];
            $out.='<option value="'.Html::escape($value).'"'.((string)$selected===(string)$value?' selected':'').'>'.Html::escape($row['label']).'</option>';
        }
        return $out.'</select>';
    }
    public function form(): string
    {
        $settings=$this->app->store->settings()['data'];$cart=$this->app->cart();$view=$cart->view();
        $out='<form method="post" data-sk4-form class="sk4-checkout">'.$this->hidden().'<input type="hidden" name="sk4_action" value="checkout"><input type="hidden" name="checkout_token" value="'.Html::escape($cart->checkoutToken()).'">';
        foreach($settings['contacts'] as $field) {
            $name=Html::escape($field['name']);$required=$field['required']?' required':'';
            $out.='<label>'.Html::escape($field['label']).($field['required']?' *':'');
            $out.=$field['type']==='textarea'?'<textarea name="'.$name.'" maxlength="4000"'.$required.'></textarea>':'<input type="'.Html::escape($field['type']).'" name="'.$name.'" maxlength="4000"'.$required.'>';
            $out.='</label>';
        }
        $out.='<label>Валюта'.$this->select('currencies','currency',$view['currency']).'</label><label>Доставка'.$this->select('delivery','delivery_id',$view['delivery_id']).'</label><label>Оплата'.$this->select('payments','payment_id').'</label>';
        return $out.'<label class="sk4-honey" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><button type="submit">Оформить заказ</button><p data-sk4-message role="status"></p></form>';
    }
    public function product(int $id): string
    {
        $settings=$this->app->store->settings()['data'];
        $resource=$this->app->modx->getObject(\MODX\Revolution\modResource::class,['id'=>$id,'published'=>1,'deleted'=>0,'context_key'=>$this->app->modx->context->get('key')]);
        if(!$resource || !$resource->checkPolicy('view')) return '';
        $out='<form method="post" data-sk4-form>'.$this->hidden().'<input type="hidden" name="sk4_action" value="add"><input type="hidden" name="product_id" value="'.$id.'">';
        $options=Database::decode($resource->getTVValue($settings['general']['options_tv']) ?: '[]');
        foreach($options as $option) {
            $out.='<label>'.Html::escape($option['label']).'<select name="selected['.Html::escape($option['name']).']">';
            foreach($option['values'] as $value) $out.='<option value="'.Html::escape($value['id']).'">'.Html::escape($value['label']).'</option>';
            $out.='</select></label>';
        }
        return $out.'<input aria-label="Количество" type="number" name="quantity" value="1" min="'.($settings['general']['fractional_quantity']?'0.001':'1').'" step="'.($settings['general']['fractional_quantity']?'0.001':'1').'"><button type="submit">В корзину</button><span data-sk4-message role="status"></span></form>';
    }
}
