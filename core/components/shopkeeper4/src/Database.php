<?php
namespace Shopkeeper4;

final class Database
{
    public function __construct(public $connection, public string $prefix, public string $driver = 'mysql')
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new \InvalidArgumentException('Недопустимый префикс таблиц.');
    }
    public function table(string $name): string { return '`' . $this->prefix . 'shopkeeper4_' . $name . '`'; }
    public function run(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt || !$stmt->execute($args)) throw new \RuntimeException('Ошибка базы данных Shopkeeper 4.');
        return $stmt;
    }
    public function one(string $sql, array $args = []): ?array { return $this->run($sql, $args)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    public function all(string $sql, array $args = []): array { return $this->run($sql, $args)->fetchAll(\PDO::FETCH_ASSOC); }
    public function lock(): string { return $this->driver === 'mysql' ? ' FOR UPDATE' : ''; }
    public function transaction(callable $callback)
    {
        if (!$this->connection->beginTransaction()) throw new \RuntimeException('Не удалось начать транзакцию. Требуется InnoDB.');
        try { $result = $callback(); if (!$this->connection->commit()) throw new \RuntimeException('Не удалось сохранить транзакцию.'); return $result; }
        catch (\Throwable $e) { $this->connection->rollBack(); throw $e; }
    }
    public function insert(string $table, array $values): int
    {
        $columns = implode(',', array_map(static fn($v) => '`' . $v . '`', array_keys($values)));
        $this->run('INSERT INTO ' . $this->table($table) . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
        return (int)$this->connection->lastInsertId();
    }
    public static function json($data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    public static function decode($data): array { return json_decode($data ?: '[]', true, 512, JSON_THROW_ON_ERROR); }
}
