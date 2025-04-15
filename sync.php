<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/vendor/autoload.php';

use Mihod\MarylineService\CategorySyncer;
use Mihod\MarylineService\ProductSyncer;

$keyCrmBaseUrl = 'https://openapi.keycrm.app/v1';
$keyCrmToken = 'ZjgxMmZhZWJhODkxOGJiZTAxNWFhYzhkODIzOWU1NjI0ZDMyNTM0MA';
$strapiBaseUrl = 'http://localhost:1337';
$strapiToken = 'ae123512941e4b88c5b1b4e2e1f9487bc86359cd63680018c3ae6512ef94a23e2f26d405a50e3ff3bfd2ede268f9b2a49d9715151200339b0e4b2043dad1779cfc910ba2f361580548e6b250e0309af6c2c36767368c47e979bb9c638ae10b6f9d6758d90cb6f35572cc979548343bda3030a53d82f1f8e4899472727221d81f';

$syncer = new CategorySyncer($keyCrmBaseUrl, $keyCrmToken, $strapiBaseUrl, $strapiToken);
$syncer->syncCategories();

//$productSyncer = new ProductSyncer($keyCrmBaseUrl, $keyCrmToken, $strapiBaseUrl, $strapiToken);
//$productSyncer->syncProducts();

?>