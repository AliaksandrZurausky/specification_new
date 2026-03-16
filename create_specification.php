<?php
use Bitrix\Main\Page\Asset;
$targetUrl = "/crm/type/1142/list/category/0/";
if (strpos($_SERVER['REQUEST_URI'], $targetUrl) !== false) {
    Asset::getInstance()->addJs("/local/specifications/create/create_specification.js"); // - меняем интерфейс у карточки смарт
}