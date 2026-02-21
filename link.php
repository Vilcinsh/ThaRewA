<?php
Auth::requireAuth();

$code = $_POST['code'] ?? null;

$stmt = db()->prepare("
    UPDATE device_auth_requests
    SET user_id = ?, approved_at = NOW()
    WHERE device_code = ?
      AND approved_at IS NULL
      AND expires_at > NOW()
");

$stmt->execute([Auth::id(), $code]);
