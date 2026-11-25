<?php
// Removes every trace of the app from the database of the test server.
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

$db = \OC::$server->get(\OCP\IDBConnection::class);
foreach (['opds_books', 'opds_terms'] as $table) {
    if ($db->tableExists($table)) {
        $db->dropTable($table);
    }
}
foreach (['migrations' => 'app', 'appconfig' => 'appid', 'preferences' => 'appid'] as $table => $column) {
    $qb = $db->getQueryBuilder();
    $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter('opds')))->executeStatement();
}
