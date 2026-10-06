<?php
namespace Shopkeeper4;

use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarResource;

final class ResourceProducts implements ProductProvider
{
    public function __construct(private $modx, private Database $db) {}
    public function quote(int $id, array $selected, string $context, array $settings): array
    {
        $resource = $this->modx->getObject(modResource::class, ['id'=>$id,'context_key'=>$context,'published'=>1,'deleted'=>0]);
        if (!$resource || !$resource->checkPolicy('view')) throw new \InvalidArgumentException('Товар недоступен: ' . $id);
        $price = Decimal::scaled($resource->getTVValue($settings['general']['price_tv']));
        $definitions = Database::decode($resource->getTVValue($settings['general']['options_tv']) ?: '[]');
        $options = [];
        if (count($selected) > 30) throw new \InvalidArgumentException('Слишком много параметров товара.');
        foreach ($selected as $name => $choice) {
            $definition = Settings::find($definitions,$name,'name');
            $variant = Settings::find($definition['values'],(string)$choice);
            $options[] = ['name'=>(string)$name,'label'=>(string)$definition['label'],'value'=>(string)$variant['label'],'id'=>(string)$variant['id'],'price'=>Decimal::scaled($variant['price'] ?? '0')];
        }
        foreach ($definitions as $definition) if (!empty($definition['required']) && !array_key_exists($definition['name'],$selected)) throw new \InvalidArgumentException('Выберите ' . $definition['label']);
        return ['product_id'=>$id,'provider'=>'resource','name'=>$resource->get('pagetitle'),'price'=>$price,'options'=>$options,'stock_key'=>$settings['general']['track_inventory'] ? $settings['general']['inventory_tv'] : ''];
    }
    public function adjustStock(int $id, string $key, int $delta): void
    {
        if ($key === '' || $delta === 0) return;
        $tv = $this->modx->getObject(modTemplateVar::class,['name'=>$key]);
        if (!$tv) throw new \RuntimeException('Не найден TV остатков: ' . $key);
        $table = $this->modx->getTableName(modTemplateVarResource::class);
        if($this->db->driver==='mysql') {
            $engine=$this->db->one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[trim($table,'`')]);
            if(strtoupper($engine['ENGINE'] ?? '')!=='INNODB') throw new \RuntimeException('Для учёта остатков таблица TV должна использовать InnoDB.');
        }
        $row = $this->db->one('SELECT id,value FROM ' . $table . ' WHERE contentid=? AND tmplvarid=?' . $this->db->lock(),[$id,$tv->get('id')]);
        // Require an explicit stock row, not a shared TV default, for reliable locking.
        if (!$row) throw new \RuntimeException('Для товара ' . $id . ' не задан явный остаток в TV ' . $key);
        $balance = Decimal::scaled($row['value'],3) - $delta;
        if ($balance < 0) throw new \RuntimeException('Недостаточно товара на складе: ' . $id);
        $this->db->run('UPDATE ' . $table . ' SET value=? WHERE id=?',[Decimal::text($balance,3),$row['id']]);
    }
}
