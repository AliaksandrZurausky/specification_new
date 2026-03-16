<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Catalog\Model\Product;

// Allow direct access and inclusion from other scripts
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
  require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
}

define('BX_SESSION_ID_CHANGE', false);

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $status = 200): void
{
  http_response_code($status);
  echo Json::encode($data, JSON_UNESCAPED_UNICODE);
  die();
}

if (!check_bitrix_sessid()) {
  jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('catalog')) {
  jsonOut(['ok' => false, 'error' => 'catalog'], 500);
}

$productId = (int)($_POST['productId'] ?? 0);
if ($productId <= 0) {
  jsonOut(['ok' => false, 'error' => 'productId'], 400);
}

$row = Product::getList([
  'filter' => ['=ID' => $productId],
  'select' => ['ID', 'MEASURE'],
  'limit' => 1,
])->fetch();

$measureId = 0;
if (is_array($row) && isset($row['MEASURE'])) {
  $measureId = (int)$row['MEASURE'];
}

jsonOut([
  'ok' => true,
  'productId' => $productId,
  'measureId' => $measureId,
]);
