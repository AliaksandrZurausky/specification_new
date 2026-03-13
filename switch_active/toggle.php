<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Crm\Service\Container;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
	die();
}

define('BX_SESSION_ID_CHANGE', false);

$format = mb_strtolower((string)($_REQUEST['format'] ?? 'json'));
$needRedirect = in_array($format, ['redirect', 'html'], true);

if (!$needRedirect) {
	header('Content-Type: application/json; charset=utf-8');
}

function jsonOut(array $data, int $status = 200): void
{
	http_response_code($status);
	echo Json::encode($data, JSON_UNESCAPED_UNICODE);
	die();
}

function plainOut(string $text, int $status = 200): void
{
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	echo $text;
	die();
}

function normalizeActive($v): int
{
	if (is_array($v)) {
		$v = $v[0] ?? null;
	}

	if ($v === true) {
		return 1;
	}

	$v = (string)$v;
	if ($v === 'Y' || $v === '1' || mb_strtolower($v) === 'true') {
		return 1;
	}

	return 0;
}

if (!check_bitrix_sessid()) {
	if ($needRedirect) {
		plainOut('sessid', 403);
	}
	jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm')) {
	if ($needRedirect) {
		plainOut('crm', 500);
	}
	jsonOut(['ok' => false, 'error' => 'crm'], 500);
}

$payloadJson = trim((string)($_REQUEST['payload'] ?? ''));
$payload = null;
if ($payloadJson !== '') {
	try {
		$decoded = Json::decode($payloadJson);
		if (is_array($decoded)) {
			$payload = $decoded;
		}
	} catch (\Throwable $e) {
		$payload = null;
	}
}

$headId = (int)($_REQUEST['headId'] ?? ($_REQUEST['id'] ?? 0));
if (is_array($payload)) {
	$headId = (int)($payload['id'] ?? $headId);
}

if ($headId <= 0) {
	if ($needRedirect) {
		plainOut('validate', 400);
	}
	jsonOut(['ok' => false, 'error' => 'validate'], 400);
}

// CRM_27 (смарт-процесс). На всякий случай оставляем fallback на прежнее значение.
$entityTypeId = (int)\CCrmOwnerType::ResolveID('CRM_27');
if ($entityTypeId <= 0) {
	$entityTypeId = 1142;
}

$factoryHead = Container::getInstance()->getFactory($entityTypeId);
if (!$factoryHead) {
	if ($needRedirect) {
		plainOut('factory', 500);
	}
	jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

$head = $factoryHead->getItem($headId);
if (!$head) {
	if ($needRedirect) {
		plainOut('head_not_found', 404);
	}
	jsonOut(['ok' => false, 'error' => 'head_not_found', 'headId' => $headId], 404);
}

$current = normalizeActive($head->get('UF_CRM_27_1767865292'));
$requestActivePassed = array_key_exists('active', $_REQUEST) || (is_array($payload) && array_key_exists('active', $payload));
$requestActive = $requestActivePassed ? normalizeActive($_REQUEST['active'] ?? ($payload['active'] ?? null)) : null;

// Всегда считаем next от фактического значения в элементе (без доверия клиенту)
$next = $current === 1 ? 0 : 1;

$head->set('UF_CRM_27_1767865292', $next);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => $next], JSON_UNESCAPED_UNICODE));

$res = $factoryHead->getUpdateOperation($head)->launch();
if (!$res->isSuccess()) {
	$errors = $res->getErrorMessages();
	if ($needRedirect) {
		plainOut('update: ' . implode('; ', $errors), 400);
	}
	jsonOut([
		'ok' => false,
		'error' => 'update',
		'headId' => $headId,
		'errors' => $errors,
	], 400);
}

if ($needRedirect) {
	$backUrl = (string)($_REQUEST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '/'));
	// Минимальная защита от внешних редиректов
	if ($backUrl === '' || $backUrl[0] !== '/') {
		$backUrl = '/';
	}
	LocalRedirect($backUrl);
	die();
}

jsonOut([
	'ok' => true,
	'headId' => $headId,
	'prevActive' => $current,
	'active' => $next,
	'requestActivePassed' => $requestActivePassed,
	'requestActive' => $requestActive,
]);
