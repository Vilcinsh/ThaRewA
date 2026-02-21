<?php
require '../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::logout();
header('Location: /login.php');
exit;
