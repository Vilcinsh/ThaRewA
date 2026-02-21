<?php
declare(strict_types=1);

class UserService
{
    public static function getById(int $userId): ?array
    {
        $stmt = db()->prepare("
            SELECT u.*, p.display_name, p.bio, p.country, p.timezone
            FROM users u
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function getStats(int $userId): array
    {
        $stmt = db()->prepare("SELECT * FROM user_stats WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: [];
    }

    public static function getSettings(int $userId): array
    {
        $stmt = db()->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: [];
    }

    public static function getConnections(int $userId): array
    {
        $stmt = db()->prepare("SELECT provider, connected_at FROM user_connections WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
