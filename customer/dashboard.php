<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$userId = (int)$_SESSION['user_id'];
$name = $_SESSION['name'] ?? 'Customer';
$email = $_SESSION['email'] ?? '';
$phone = '';
$address = '';
$points = 0;
$level = 'Member';

$menus = [];
$moments = [];
$orders = [];
$errors = [];

// 1. Mengambil profil customer
try {
    $stmt = $conn->prepare("
        SELECT u.name, u.email, u.phone, c.address
        FROM users u
        LEFT JOIN customer c ON c.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($profile) {
            $name = $profile['name'] ?: $name;
            $email = $profile['email'] ?: $email;
            $phone = $profile['phone'] ?? '';
            $address = $profile['address'] ?? '';
        }
    }
} catch (Throwable $err) {
    $errors[] = 'Profil belum dapat dimuat.';
}

// 2. Mengambil data rewards customer
try {
    $stmt = $conn->prepare("
        SELECT points, level
        FROM rewards
        WHERE user_id = ?
        ORDER BY updated_at DESC, reward_id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $reward = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($reward) {
            $points = (int)$reward['points'];
            $level = $reward['level'] ?: 'Member';
        }
    }
} catch (Throwable $err) {
    $errors[] = 'Data rewards belum dapat dimuat.';
}

// 3. Mengambil rekomendasi menu
try {
    $sql = "
        SELECT
            m.menu_id,
            m.menu_name,
            m.image,
            c.category_name,
            COALESCE(
                (
                    SELECT MIN(md.price)
                    FROM menu_details md
                    WHERE md.menu_id = m.menu_id
                ),
                CAST(
                    REPLACE(
                        REPLACE(
                            REPLACE(m.price, 'Rp', ''),
                            '.', ''
                        ),
                        ',', '.'
                    ) AS DECIMAL(12,2)
                ),
                0
            ) AS display_price
        FROM menus m
        LEFT JOIN categories c
            ON c.category_id = m.category_id
        WHERE m.status = 'Available'
        ORDER BY m.menu_id
        LIMIT 4
    ";

    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $menus[] = $row;
    }
} catch (Throwable $err) {
    $errors[] = 'Rekomendasi menu belum dapat dimuat.';
}

// 4. Mengambil LŪMA Moments dan rekomendasi menunya
try {
    $sql = "
        SELECT
            mo.moment_id,
            mo.moment_name,
            mo.icon,
            m.menu_name
        FROM moments mo
        LEFT JOIN moment_recommendation mr
            ON mr.recommendation_id = (
                SELECT mr2.recommendation_id
                FROM moment_recommendation mr2
                JOIN menus m2
                    ON m2.menu_id = mr2.menu_id
                WHERE mr2.moment_id = mo.moment_id
                    AND m2.status = 'Available'
                ORDER BY mr2.recommendation_id
                LIMIT 1
            )
        LEFT JOIN menus m
            ON m.menu_id = mr.menu_id
        ORDER BY mo.moment_id
        LIMIT 4
    ";

    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $moments[] = $row;
    }
} catch (Throwable $err) {
    $errors[] = 'LŪMA Moments belum dapat dimuat.';
}

// 5. Mengambil lima riwayat pesanan customer
try {
    $stmt = $conn->prepare("
        SELECT
            o.order_id,
            o.order_date,
            o.total_amount,
            o.status,
            o.payment_method,
            COUNT(od.order_detail_id) AS item_count,
            GROUP_CONCAT(
                CONCAT(m.menu_name, ' × ', od.quantity)
                ORDER BY od.order_detail_id
                SEPARATOR ', '
            ) AS item_names
        FROM orders o
        LEFT JOIN order_details od
            ON od.order_id = o.order_id
        LEFT JOIN menus m
            ON m.menu_id = od.menu_id
        WHERE o.user_id = ?
        GROUP BY
            o.order_id,
            o.order_date,
            o.total_amount,
            o.status,
            o.payment_method
        ORDER BY o.order_date DESC, o.order_id DESC
        LIMIT 5
    ");

    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }

        $stmt->close();
    }
} catch (Throwable $err) {
    $errors[] = 'Riwayat pesanan belum dapat dimuat.';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LŪMA Café | Customer Dashboard</title>

    <link rel="stylesheet" href="dashboard.css">
</head>

<body>

<header class="navbar">
    <div class="container nav-content">

        <a href="../dashboard_main.php" class="logo">
            LŪMA<span>.</span>
        </a>

        <nav class="nav-links">
            <a href="../dashboard_main.php">Home</a>
            <a href="../dashboard_main.php#menu">Menu</a>
            <a href="#moments">LŪMA Moments</a>
            <a href="#rewards">Rewards</a>
        </nav>

        <div class="nav-actions">
            <span class="hello-name">
                Haloo, <?= e($name) ?>
            </span>

            <a class="logout-link" href="../logout.php">
                Logout
            </a>
        </div>

    </div>
</header>

<main class="container page-content">

    <!-- WELCOME SECTION -->
    <section class="welcome-panel">
        <div>
            <span class="eyebrow">YOUR LŪMA SPACE</span>

            <h1>Welcome back, <?= e($name) ?>!</h1>

            <p>
                Senang melihatmu kembali. Nikmati momen kecil
                yang berarti bersama LŪMA.
            </p>

            <a href="../dashboard_main.php#menu" class="btn-primary">
                Explore Our Menu <span>→</span>
            </a>
        </div>

        <div class="welcome-art" aria-hidden="true">
            <span>☕</span>
            <small>YOUR EVERYDAY<br>COFFEE MOMENT</small>
        </div>
    </section>

    <?php if ($errors): ?>
        <div class="notice">
            Beberapa informasi belum dapat ditampilkan.
            Pastikan struktur tabel di database sesuai dengan project LŪMA.
        </div>
    <?php endif; ?>

    <!-- CUSTOMER PROFILE -->
    <section class="section profile-section" id="profile">

        <div class="section-heading">
            <div>
                <span class="eyebrow">A LITTLE ABOUT YOU</span>
                <h2>Your Profile</h2>
            </div>
        </div>

        <div class="profile-card">

            <div class="profile-avatar">
                <?= e(strtoupper(substr(trim($name), 0, 1) ?: 'C')) ?>
            </div>

            <div class="profile-info">
                <h3><?= e($name) ?></h3>
                <p><?= e($email) ?></p>

                <div class="profile-meta">
                    <span>
                        <?= e($phone !== '' ? $phone : 'Nomor telepon belum diisi') ?>
                    </span>

                    <span>
                        <?= e($address !== '' ? $address : 'Alamat belum diisi') ?>
                    </span>
                </div>
            </div>

            <span class="member-pill">
                <?= e($level) ?> Member
            </span>

        </div>
    </section>

    <!-- REWARDS -->
    <section class="section" id="rewards">

        <div class="section-heading">
            <div>
                <span class="eyebrow">YOUR LŪMA JOURNEY</span>
                <h2>Every Visit Becomes a Memory.</h2>
                <p>Kumpulkan poin dari perjalananmu bersama LŪMA.</p>
            </div>
        </div>

        <div class="rewards-card">

            <div>
                <span class="reward-label">YOUR POINTS</span>

                <div class="points-number">
                    <?= number_format($points, 0, ',', '.') ?>
                    <small>pts</small>
                </div>

                <p>Poin LŪMA kamu saat ini</p>
            </div>

            <div class="reward-level">
                <span class="reward-label">MEMBERSHIP LEVEL</span>

                <strong><?= e($level) ?></strong>

                <span class="level-note">
                    Terima kasih sudah menjadi bagian dari LŪMA.
                </span>
            </div>

        </div>
    </section>

    <!-- RECOMMENDED MENU -->
    <section class="section" id="menu">

        <div class="section-heading">
            <div>
                <span class="eyebrow">A TASTE OF LŪMA</span>
                <h2>Recommended for You</h2>
                <p>Pilihan yang bisa menemani harimu.</p>
            </div>

            <a class="text-link" href="../dashboard_main.php#menu">
                Explore all menu →
            </a>
        </div>

        <?php if ($menus): ?>

            <div class="menu-grid">

                <?php foreach ($menus as $menu): ?>
                    <?php
                    $filename = basename((string)($menu['image'] ?? ''));
                    $imagePath = __DIR__ . '/../assets/menu/' . $filename;
                    ?>

                    <article class="menu-card">

                        <div class="menu-image">

                            <?php if ($filename !== '' && is_file($imagePath)): ?>

                                <img
                                    src="../assets/menu/<?= rawurlencode($filename) ?>"
                                    alt="<?= e($menu['menu_name']) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="image-placeholder">
                                    LŪMA Café
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="menu-info">

                            <span class="category">
                                <?= e($menu['category_name'] ?: 'LŪMA Selection') ?>
                            </span>

                            <h3><?= e($menu['menu_name']) ?></h3>

                            <div class="menu-bottom">

                                <span class="price">
                                    <?= e(rupiah($menu['display_price'])) ?>
                                </span>

                                <a class="text-link" href="../dashboard_main.php#menu">
                                    View menu →
                                </a>

                            </div>
                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="empty-state">
                Belum ada menu tersedia untuk ditampilkan.
            </p>

        <?php endif; ?>

    </section>

    <!-- LŪMA MOMENTS -->
    <section class="section moments-section" id="moments">

        <div class="section-heading">
            <div>
                <span class="eyebrow">FIND YOUR MOMENT</span>
                <h2>A Moment for Everyone</h2>
                <p>
                    Setiap suasana punya cerita dan pilihan
                    minuman tersendiri.
                </p>
            </div>
        </div>

        <?php if ($moments): ?>

            <div class="moments-grid">

                <?php foreach ($moments as $moment): ?>

                    <article class="moment-card">

                        <div class="moment-icon">
                            <?= e($moment['icon'] ?: '☕') ?>
                        </div>

                        <h3><?= e($moment['moment_name']) ?></h3>

                        <p>
                            <?php if (!empty($moment['menu_name'])): ?>
                                Pilihan menu: <?= e($moment['menu_name']) ?>
                            <?php else: ?>
                                Temukan pilihan favoritmu di LŪMA.
                            <?php endif; ?>
                        </p>

                        <a class="text-link" href="../dashboard_main.php#menu">
                            Discover →
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="empty-state">
                Rekomendasi LŪMA Moments belum tersedia.
            </p>

        <?php endif; ?>

    </section>

    <!-- ORDER HISTORY -->
    <section class="section orders-section" id="orders">

        <div class="section-heading">
            <div>
                <span class="eyebrow">YOUR RECENT ACTIVITY</span>
                <h2>Recent Orders</h2>
                <p>Lihat kembali pesanan terakhir kamu di LŪMA.</p>
            </div>
        </div>

        <?php if ($orders): ?>

            <div class="orders-list">

                <?php foreach ($orders as $order): ?>
                    <?php
                    $status = strtolower((string)$order['status']);
                    $statusClass = $status === 'success'
                        ? 'status-success'
                        : 'status-failed';
                    ?>

                    <article class="order-card">

                        <div class="order-top">

                            <div>
                                <span class="order-number">
                                    Order #<?= (int)$order['order_id'] ?>
                                </span>

                                <span class="order-date">
                                    <?= e(date('d M Y · H:i', strtotime($order['order_date']))) ?>
                                </span>
                            </div>

                            <span class="status <?= e($statusClass) ?>">
                                <?= e($order['status']) ?>
                            </span>

                        </div>

                        <p class="order-items">
                            <?= e($order['item_names'] ?: 'Detail item tidak tersedia') ?>
                        </p>

                        <div class="order-bottom">

                            <span>
                                <?= e($order['payment_method']) ?>
                                ·
                                <?= (int)$order['item_count'] ?> item
                            </span>

                            <strong>
                                <?= e(rupiah($order['total_amount'])) ?>
                            </strong>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <strong>Belum ada pesanan.</strong>

                <p>
                    Pesanan kamu berikutnya bisa dimulai
                    dari menu favoritmu.
                </p>

                <a class="text-link" href="../dashboard_main.php#menu">
                    Explore menu →
                </a>
            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="footer">
    <div class="container footer-content">

        <div>
            <a href="../dashboard_main.php" class="logo">
                LŪMA<span>.</span>
            </a>

            <p>Your everyday coffee, your everyday moment.</p>
        </div>

        <p>&copy; <?= date('Y') ?> LŪMA Café</p>

    </div>
</footer>

</body>
</html>
