<?php
require __DIR__ . '/../../../config/config.php';
require BASE_PATH . '/core/Auth.php';      // ensure Auth exists
require BASE_PATH . '/core/Guard.php';     // keep if you need Guard side-effects

Auth::requireAuth();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$userId   = (int)($_SESSION['user_id'] ?? 0);
$animeId  = (string)($data['anime_id'] ?? '');
$episode  = (int)($data['episode'] ?? 0);
$progress = (int)($data['progress'] ?? 0);
$duration = (int)($data['duration'] ?? 0);

if ($userId <= 0 || $animeId === '' || $episode <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$pdo = db();

/** --------- helpers ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

$hasUpdatedAt = col_exists($pdo, 'user_watch_progress', 'updated_at');
$hasBuffer    = col_exists($pdo, 'user_stats', 'watch_seconds_buffer');

// your old schema may have typo columns; detect them safely
$hasCompletedTypo = col_exists($pdo, 'user_stats', 'complited_anime');
$hasCompletedOk   = col_exists($pdo, 'user_stats', 'completed_anime');

// Auto-complete at 90%
$completed = ($duration > 0 && $progress >= (int)floor($duration * 0.9)) ? 1 : 0;

// Cap delta to avoid seek abuse (tune to your save frequency)
$MAX_DELTA_SECONDS = 30;

try {
    $pdo->beginTransaction();

    // Lock existing progress row
    $stmt = $pdo->prepare("
        SELECT id, progress_seconds, completed
        FROM user_watch_progress
        WHERE user_id = ? AND anime_id = ? AND episode = ?
        FOR UPDATE
    ");
    $stmt->execute([$userId, $animeId, $episode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $deltaSeconds = 0;

    if (!$row) {
        // Build INSERT depending on updated_at existence
        if ($hasUpdatedAt) {
            $stmt = $pdo->prepare("
                INSERT INTO user_watch_progress
                    (user_id, anime_id, episode, progress_seconds, duration_seconds, completed, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $animeId, $episode, $progress, $duration, $completed]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO user_watch_progress
                    (user_id, anime_id, episode, progress_seconds, duration_seconds, completed)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $animeId, $episode, $progress, $duration, $completed]);
        }
    } else {
        $prevPos = (int)$row['progress_seconds'];
        $wasCompleted = (int)$row['completed'];

        $rawDelta = $progress - $prevPos;
        $deltaSeconds = max(0, min($rawDelta, $MAX_DELTA_SECONDS));

        $newCompleted = ($wasCompleted === 1) ? 1 : $completed;

        if ($hasUpdatedAt) {
            $stmt = $pdo->prepare("
                UPDATE user_watch_progress
                SET progress_seconds = ?,
                    duration_seconds = GREATEST(duration_seconds, ?),
                    completed = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$progress, $duration, $newCompleted, (int)$row['id']]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE user_watch_progress
                SET progress_seconds = ?,
                    duration_seconds = GREATEST(duration_seconds, ?),
                    completed = ?
                WHERE id = ?
            ");
            $stmt->execute([$progress, $duration, $newCompleted, (int)$row['id']]);
        }
    }

    /**
     * Minutes logic:
     * - If user_stats.watch_seconds_buffer exists, use it (best).
     * - If not, degrade: add minutes only when delta >= 60 (rare), otherwise do nothing.
     */
    $stmt = $pdo->prepare("SELECT user_id" . ($hasBuffer ? ", watch_seconds_buffer" : "") . " FROM user_stats WHERE user_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stats) {
        // Build insert based on existing columns
        $cols = ['user_id','watched_anime','total_watch_minutes','last_updated'];
        $vals = ['?', '0', '0', 'NOW()'];
        $params = [$userId];

        if ($hasBuffer) {
            $cols[] = 'watch_seconds_buffer';
            $vals[] = '0';
        }
        if ($hasCompletedTypo) {
            $cols[] = 'complited_anime';
            $vals[] = '0';
        } elseif ($hasCompletedOk) {
            $cols[] = 'completed_anime';
            $vals[] = '0';
        }

        $sql = "INSERT INTO user_stats (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $buffer = 0;
    } else {
        $buffer = $hasBuffer ? (int)($stats['watch_seconds_buffer'] ?? 0) : 0;
    }

    $addMinutes = 0;

    if ($hasBuffer) {
        $buffer += $deltaSeconds;
        $addMinutes = intdiv($buffer, 60);
        $buffer = $buffer % 60;

        $stmt = $pdo->prepare("
            UPDATE user_stats
            SET total_watch_minutes = total_watch_minutes + ?,
                watch_seconds_buffer = ?,
                last_updated = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([$addMinutes, $buffer, $userId]);
    } else {
        // Degraded mode without buffer column
        if ($deltaSeconds >= 60) {
            $addMinutes = intdiv($deltaSeconds, 60);
            $stmt = $pdo->prepare("
                UPDATE user_stats
                SET total_watch_minutes = total_watch_minutes + ?,
                    last_updated = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$addMinutes, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE user_stats SET last_updated = NOW() WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'delta_seconds' => $deltaSeconds,
        'added_minutes' => $addMinutes,
        'buffer_enabled' => $hasBuffer,
        'completed' => $completed
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // log real error server-side
    error_log('[watch/progress/save] ' . $e->getMessage());

    http_response_code(500);

    // show error only in debug
    $debug = (bool)env('APP_DEBUG');
    echo json_encode([
        'ok' => false,
        'error' => $debug ? $e->getMessage() : 'Server error'
    ]);
}
