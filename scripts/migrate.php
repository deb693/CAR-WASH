<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$db = database();
migrate_database($db);
initialize_application($db);
initialize_content($db);
echo "Database schema is ready.\n";

