<?php
/* =====================================================
   LÜMA REWARDS (membership)
   - Setiap order yang SELESAI (Completed) = +10 poin
   - Level ditentukan dari total poin (batas ada di sini)
   - Kolom rewards.level = ENUM('BRONZE','SILVER','GOLD')
===================================================== */

const POINTS_PER_ORDER = 10;

// Akun walk-in dipakai bersama oleh banyak orang, jadi tidak dapat poin.
// Dikenali lewat email, bukan user_id.
const WALKIN_EMAIL = 'walkin@luma.local';

const LEVEL_THRESHOLDS = [   // urut dari tertinggi ke terendah
    'GOLD'   => 500,
    'SILVER' => 200,
    'BRONZE' => 0,
];

function getLevel(int $points): string
{
    foreach (LEVEL_THRESHOLDS as $level => $min) {
        if ($points >= $min) {
            return $level;
        }
    }

    return 'BRONZE';
}

// Untuk tampilan: 'GOLD' -> 'Gold'
function levelLabel(string $level): string
{
    return ucfirst(strtolower($level));
}

/**
 * Info progress menuju level berikutnya.
 * Return null kalau sudah di level tertinggi.
 * ['level' => 'GOLD', 'need' => 50, 'percent' => 83]
 */
function nextLevelInfo(int $points): ?array
{
    $levels  = array_reverse(LEVEL_THRESHOLDS, true);   // BRONZE, SILVER, GOLD
    $current = 0;

    foreach ($levels as $name => $min) {
        if ($points < $min) {
            $range   = max(1, $min - $current);
            $percent = (int)floor((($points - $current) / $range) * 100);

            return [
                'level'   => $name,
                'need'    => $min - $points,
                'percent' => max(0, min(100, $percent))
            ];
        }

        $current = $min;
    }

    return null;
}

// Rapikan nomor HP: 62812... / +62812... / 812... -> 0812...
function normalizePhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);

    if ($digits === '') {
        return '';
    }

    if (strncmp($digits, '62', 2) === 0) {
        $digits = '0' . substr($digits, 2);
    }

    if ($digits[0] !== '0') {
        $digits = '0' . $digits;
    }

    return $digits;
}

// Pastikan user punya row di tabel rewards
function ensureRewardRow(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare("SELECT 1 FROM rewards WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$exists) {
        $stmt = $conn->prepare("
            INSERT INTO rewards (user_id, points, level, updated_at)
            VALUES (?, 0, 'BRONZE', NOW())
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Beri poin untuk satu order yang baru selesai.
 * Dipanggil DI DALAM transaksi milik order_receive.php.
 * Return jumlah poin yang diberikan (0 kalau tidak berhak).
 */
function awardOrderPoints(mysqli $conn, int $userId): int
{
    if ($userId < 1) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT u.email, (c.user_id IS NOT NULL) AS is_member
        FROM users u
        LEFT JOIN customer c ON c.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$user ||
        strtolower((string)$user['email']) === WALKIN_EMAIL ||
        (int)$user['is_member'] !== 1
    ) {
        return 0;
    }

    ensureRewardRow($conn, $userId);

    $earned = POINTS_PER_ORDER;

    $stmt = $conn->prepare("
        UPDATE rewards
        SET points = points + ?, updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->bind_param('ii', $earned, $userId);
    $stmt->execute();
    $stmt->close();

    // Baca total terbaru, lalu set level sesuai batas di atas
    $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['points'];
    $stmt->close();

    $level = getLevel($total);

    $stmt = $conn->prepare("UPDATE rewards SET level = ? WHERE user_id = ?");
    $stmt->bind_param('si', $level, $userId);
    $stmt->execute();
    $stmt->close();

    return $earned;
}