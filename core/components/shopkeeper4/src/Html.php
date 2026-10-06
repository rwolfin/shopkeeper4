<?php
namespace Shopkeeper4;
final class Html
{
    public static function escape($value): string { return str_replace(['[',']'],['&#91;','&#93;'],htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')); }
    public static function money($cents): string { return number_format((int)$cents/100,2,'.',' '); }
}
