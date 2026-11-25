<?php
declare(strict_types=1);

// Copy this file to database.local.php and enter values for your local install.
// The local file is ignored by Git and the config directory is denied by Apache.
// This XAMPP example uses MariaDB root with no password. Keep it local only;
// use a dedicated account for any publicly exposed or production deployment.
// If your local root account has a password, enter that password below.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'adoptapet_demo',
    'username' => 'root',
    'password' => '',
];
