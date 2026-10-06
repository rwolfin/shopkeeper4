<?php
namespace Shopkeeper4;

final class Installer
{
    public static function install(Database $db): void
    {
        $id = $db->driver === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $db->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $schemas = [
            'config' => 'id INTEGER PRIMARY KEY, version INTEGER NOT NULL, data TEXT NOT NULL',
            'orders' => "id $id, checkout_key VARCHAR(64) NOT NULL UNIQUE, context_key VARCHAR(100) NOT NULL, customer_id INTEGER NOT NULL DEFAULT 0, status_id INTEGER NOT NULL, currency VARCHAR(12) NOT NULL, subtotal BIGINT NOT NULL, delivery_fee BIGINT NOT NULL, total BIGINT NOT NULL, delivery_id INTEGER NOT NULL, delivery_name VARCHAR(255) NOT NULL, payment_id INTEGER NOT NULL, payment_name VARCHAR(255) NOT NULL, contacts TEXT NOT NULL, email VARCHAR(254) NOT NULL, note TEXT NOT NULL, tracking VARCHAR(255) NOT NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL, version INTEGER NOT NULL DEFAULT 1, deleted_at VARCHAR(19) NULL, legacy_id INTEGER NULL UNIQUE",
            'items' => "id $id, order_id BIGINT NOT NULL, product_id BIGINT NOT NULL, provider VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, price BIGINT NOT NULL, quantity BIGINT NOT NULL, total BIGINT NOT NULL, options TEXT NOT NULL, stock_key VARCHAR(255) NOT NULL, reserved BIGINT NOT NULL DEFAULT 0",
            'history' => "id $id, order_id BIGINT NOT NULL, actor_id INTEGER NOT NULL, action VARCHAR(50) NOT NULL, message TEXT NOT NULL, created_at VARCHAR(19) NOT NULL",
            'outbox' => "id $id, order_id BIGINT NOT NULL, recipient VARCHAR(254) NOT NULL, template VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, sent_at VARCHAR(19) NULL, error TEXT NOT NULL, created_at VARCHAR(19) NOT NULL",
        ];
        foreach ($schemas as $name => $schema) {
            if($db->driver==='mysql') $schema=str_replace(' TEXT',' MEDIUMTEXT',$schema);
            $db->run('CREATE TABLE IF NOT EXISTS ' . $db->table($name) . ' (' . $schema . ')' . $suffix);
        }
        // IF NOT EXISTS for indexes differs across MySQL versions; inspect metadata first.
        foreach (['items' => ['order_id'], 'orders' => ['created_at','status_id'], 'history' => ['order_id'], 'outbox' => ['order_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $index = 'sk4_' . $table . '_' . $column;
                $exists = $db->driver === 'mysql' ? $db->one('SHOW INDEX FROM ' . $db->table($table) . ' WHERE Key_name = ?', [$index]) : $db->one('SELECT name FROM sqlite_master WHERE type=? AND name=?', ['index',$index]);
                if (!$exists) $db->run('CREATE INDEX `' . $index . '` ON ' . $db->table($table) . ' (`' . $column . '`)');
            }
        }
        if (!$db->one('SELECT id FROM ' . $db->table('config') . ' WHERE id=1')) $db->insert('config', ['id' => 1, 'version' => 1, 'data' => Database::json(Settings::defaults())]);
    }
}
