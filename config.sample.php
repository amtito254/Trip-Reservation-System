<?php
// =============================================
// config.sample.php
// Copy this file to  demo_coaches_config.php  and place it OUTSIDE your web root
// (for XAMPP: C:\xampp\demo_coaches_config.php — two folders above htdocs\demo_coaches\).
// Shared hosting (cannot place files above the web root)? Put demo_coaches_config.php in the SAME folder as db.php;
// the included .htaccess blocks it from being downloaded, and .gitignore keeps it out of Git.
// Do NOT commit the real file to Git.
// =============================================
return [
    'host'     => 'localhost',
    'user'     => 'democoaches_app',                 // least-privilege user, NOT root (see migrate_security.sql step 5)
    'password' => 'CHANGE_ME_TO_A_LONG_RANDOM_PASSWORD',
    'database' => 'demo_coaches',
];
