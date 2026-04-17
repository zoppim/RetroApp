<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

require_admin_login();
csrf_verify();

admin_logout();
redirect(BASE_URL . '/admin/login.php');
