<?php
// Backward-compat endpoint (create form used it initially)
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);

require($_SERVER['DOCUMENT_ROOT'] . '/local/specifications/api/get_product_measure.php');
