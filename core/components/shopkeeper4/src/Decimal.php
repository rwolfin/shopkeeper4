<?php
namespace Shopkeeper4;

final class Decimal
{
    public static function scaled($value, int $digits = 2): int
    {
        $text = str_replace(',', '.', trim((string)$value));
        if (!preg_match('/^\d{1,9}(?:\.\d{1,' . $digits . '})?$/D', $text)) {
            throw new \InvalidArgumentException('Некорректное число: ' . $text);
        }
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        return (int)$integer * (10 ** $digits) + (int)str_pad($fraction, $digits, '0');
    }
    public static function text(int $value, int $digits = 2): string
    {
        $scale = 10 ** $digits;
        return intdiv($value, $scale) . '.' . str_pad((string)($value % $scale), $digits, '0', STR_PAD_LEFT);
    }
    public static function line(int $price, int $quantity): int
    {
        if ($price < 0 || $quantity < 1 || $quantity > 100000000 || $price > 99999999999) throw new \InvalidArgumentException('Цена или количество за пределами допустимого диапазона.');
        if ($price > intdiv(PHP_INT_MAX - 500, $quantity)) throw new \InvalidArgumentException('Сумма позиции слишком велика.');
        return intdiv($price * $quantity + 500, 1000);
    }
    public static function convert(int $price, int $baseRate, int $targetRate): int
    {
        if ($baseRate < 1 || $targetRate < 1 || $price > 99999999999 || $baseRate > 10000000 || $targetRate > 10000000) throw new \InvalidArgumentException('Неверный курс валюты.');
        return intdiv($price * $baseRate + intdiv($targetRate, 2), $targetRate);
    }
}
