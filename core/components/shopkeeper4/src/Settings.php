<?php
namespace Shopkeeper4;

final class Settings
{
    public static function defaults(): array
    {
        return [
            'general' => ['price_tv' => 'price', 'inventory_tv' => 'inventory', 'options_tv' => 'sk4_options', 'track_inventory' => false, 'fractional_quantity' => false, 'base_currency' => 'RUB', 'first_status' => 1, 'manager_email' => '', 'manager_template' => 'Shopkeeper4Mail', 'order_page' => 0],
            'currencies' => [['code' => 'RUB', 'label' => 'руб.', 'rate' => '1.0000']],
            'statuses' => [
                ['id'=>1,'label'=>'Новый','color'=>'#D9E9FF','template'=>'Shopkeeper4Mail','cancelled'=>false],
                ['id'=>2,'label'=>'Ожидает оплаты','color'=>'#FFF1C2','template'=>'Shopkeeper4Mail','cancelled'=>false],
                ['id'=>3,'label'=>'Отправлен','color'=>'#E8DFFF','template'=>'Shopkeeper4Mail','cancelled'=>false],
                ['id'=>4,'label'=>'Выполнен','color'=>'#D4F3E0','template'=>'Shopkeeper4Mail','cancelled'=>false],
                ['id'=>5,'label'=>'Отменён','color'=>'#FFDADA','template'=>'Shopkeeper4Mail','cancelled'=>true],
                ['id'=>6,'label'=>'Оплачен','color'=>'#D1F1F2','template'=>'Shopkeeper4Mail','cancelled'=>false],
            ],
            'delivery' => [['id'=>1,'label'=>'Самовывоз','price'=>'0.00','free_from'=>'0.00','active'=>true]],
            'payments' => [['id'=>1,'label'=>'При получении','active'=>true]],
            'contacts' => [['name'=>'fullname','label'=>'Имя','type'=>'text','required'=>true],['name'=>'email','label'=>'Эл. почта','type'=>'email','required'=>true],['name'=>'phone','label'=>'Телефон','type'=>'text','required'=>true],['name'=>'address','label'=>'Адрес','type'=>'textarea','required'=>false],['name'=>'comment','label'=>'Комментарий','type'=>'textarea','required'=>false]],
            'columns' => [['name'=>'id','label'=>'№','visible'=>true],['name'=>'status_id','label'=>'Статус','visible'=>true],['name'=>'created_at','label'=>'Дата','visible'=>true],['name'=>'total','label'=>'Сумма','visible'=>true],['name'=>'quantity','label'=>'Кол-во','visible'=>true],['name'=>'email','label'=>'Эл. почта','visible'=>true],['name'=>'customer_id','label'=>'Пользователь','visible'=>true]],
        ];
    }
    public static function find(array $rows, $id, string $key = 'id'): array
    {
        foreach ($rows as $row) if ((string)$row[$key] === (string)$id) return $row;
        throw new \InvalidArgumentException('Значение не найдено в настройках: ' . (string)$id);
    }
    public static function validate(array $value): array
    {
        foreach (array_keys(self::defaults()) as $key) if (!isset($value[$key]) || !is_array($value[$key])) throw new \InvalidArgumentException('Не заполнены настройки: ' . $key);
        if (strlen(Database::json($value)) > 100000) throw new \InvalidArgumentException('Слишком большой объём настроек.');
        foreach (['statuses','currencies','delivery','payments','contacts','columns'] as $group) {
            if (!$value[$group] || count($value[$group]) > 100) throw new \InvalidArgumentException('Список должен содержать 1–100 строк: ' . $group);
            $seen = [];
            foreach ($value[$group] as &$row) {
                $key = $group === 'currencies' ? 'code' : (in_array($group,['contacts','columns'],true) ? 'name' : 'id');
                $id = (string)($row[$key] ?? '');
                if (!preg_match($key === 'id' ? '/^[1-9][0-9]{0,8}$/D' : '/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $id) || isset($seen[$id])) throw new \InvalidArgumentException('Пустой/повторный идентификатор: ' . $group);
                $seen[$id] = true;
                if (empty($row['label']) || mb_strlen($row['label']) > 100) throw new \InvalidArgumentException('Заполните название: ' . $group);
                if ($group === 'currencies' && (strlen($id) > 12 || Decimal::scaled($row['rate'],4) < 1 || Decimal::scaled($row['rate'],4) > 10000000)) throw new \InvalidArgumentException('Неверный курс.');
                if ($group === 'statuses' && !preg_match('/^#[0-9a-fA-F]{6}$/D', $row['color'] ?? '')) throw new \InvalidArgumentException('Цвет должен иметь формат #AABBCC.');
                if ($group === 'delivery') { Decimal::scaled($row['price']); Decimal::scaled($row['free_from']); }
                if ($group === 'contacts' && !in_array($row['type'], ['text','email','textarea'],true)) throw new \InvalidArgumentException('Неверный тип контактного поля.');
                foreach (['cancelled','active','required','visible'] as $boolean) if (array_key_exists($boolean,$row)) $row[$boolean] = filter_var($row[$boolean], FILTER_VALIDATE_BOOLEAN);
            }
            unset($row);
        }
        foreach (['price_tv','inventory_tv','options_tv'] as $field) if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,99}$/D',$value['general'][$field] ?? '')) throw new \InvalidArgumentException('Неверное имя TV: ' . $field);
        foreach (['track_inventory','fractional_quantity'] as $key) $value['general'][$key] = filter_var($value['general'][$key], FILTER_VALIDATE_BOOLEAN);
        self::find($value['currencies'], $value['general']['base_currency'], 'code');
        $initial = self::find($value['statuses'], $value['general']['first_status']);
        if (!empty($initial['cancelled'])) throw new \InvalidArgumentException('Первый статус не может быть отменённым.');
        if ($value['general']['manager_email'] && !filter_var($value['general']['manager_email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Неверный email менеджера.');
        $allowed=['id','status_id','created_at','total','quantity','email','customer_id','currency','delivery_name','payment_name','tracking','context_key'];
        foreach($value['columns'] as $column) if(!in_array($column['name'],$allowed,true)) throw new \InvalidArgumentException('Неизвестный столбец заказа.');
        return $value;
    }
}
