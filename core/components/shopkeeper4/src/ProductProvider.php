<?php
namespace Shopkeeper4;

interface ProductProvider
{
    /** Return trusted price, name, option surcharges and inventory key for a product. */
    public function quote(int $id, array $selected, string $context, array $settings): array;
    /** Positive delta reserves stock, negative delta releases it. Must use the order DB transaction. */
    public function adjustStock(int $id, string $key, int $delta): void;
}
