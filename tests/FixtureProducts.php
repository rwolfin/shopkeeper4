<?php
use Shopkeeper4\{Database,ProductProvider};
final class FixtureProducts implements ProductProvider
{
    public function __construct(private Database $db) {}
    public function quote(int $id,array $selected,string $context,array $settings): array
    {
        if(!in_array($id,[1,2],true) || $context!=='web') throw new RuntimeException('Product unavailable');
        if(array_diff(array_keys($selected),['size'])) throw new RuntimeException('Invalid option');
        if(isset($selected['size']) && $selected['size']!=='large') throw new RuntimeException('Invalid variant');
        return ['product_id'=>$id,'provider'=>'fixture','name'=>$id===1?'Товар <один>':'Товар два','price'=>$id===1?10025:20000,'options'=>isset($selected['size'])?[['name'=>'size','label'=>'Размер','id'=>'large','value'=>'Большой','price'=>500]]:[],'stock_key'=>$settings['general']['track_inventory']?'inventory':''];
    }
    public function adjustStock(int $id,string $key,int $delta): void
    {
        $row=$this->db->one('SELECT quantity FROM fixture_stock WHERE id=?',[$id]);
        if(!$row || $row['quantity']<$delta) throw new RuntimeException('Insufficient stock');
        $this->db->run('UPDATE fixture_stock SET quantity=quantity-? WHERE id=?',[$delta,$id]);
    }
}
