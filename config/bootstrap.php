<?php if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) { ob_start("ob_gzhandler"); } else { ob_start(); } ?>
<?php
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once 'config.php';
require_once 'client_config.php';

