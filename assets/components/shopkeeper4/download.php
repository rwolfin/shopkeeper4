<?php
require_once dirname(__DIR__,3).'/config.core.php';
require_once MODX_CORE_PATH.'vendor/autoload.php';
$modx=new \MODX\Revolution\modX();$modx->initialize('mgr');
if(!$modx->user->hasSessionContext('mgr') || !$modx->hasPermission('settings')) {http_response_code(403);exit;}
$token=$_GET['token'] ?? '';
if(!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D',$token)) {http_response_code(404);exit;}
$entry=$_SESSION['shopkeeper4_exports'][$token] ?? null;
if(!$entry || $entry['expires']<time()) {http_response_code(404);exit;}
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="shopkeeper4-orders-'.gmdate('Ymd-His').'.csv"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
echo $entry['csv'];unset($_SESSION['shopkeeper4_exports'][$token]);
