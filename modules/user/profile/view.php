<?php
require BASE_PATH . '/core/Auth.php';
require BASE_PATH . '/modules/user/UserService.php';

Auth::requireAuth();

$userId = Auth::id();

$user = UserService::getById($userId);
$stats = UserService::getStats($userId);
$connections = UserService::getConnections($userId);
