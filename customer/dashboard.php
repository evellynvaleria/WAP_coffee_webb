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

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function statusClass($status): string
{
    $status = strtolower(trim((string)$status));

    if ($status === 'success' || $status === 'completed') {
        return 'status-success';
    }

    if ($status === 'failed' || $status === 'cancelled') {
        return 'status-failed';
    }

    return 'status-pending';
}

$userId = (int) $_SESSION['user_id'];
$name = $_SESSION['name'] ?? 'Customer';

$rewards = [
    'points' => 0,
    'level'  => 'Bronze'
];

$recommendedMenus = [];
$moments = [];
$recentOrders = [];

/* ========================================
   CUSTOMER PROFILE
   ======================================== */

try {
    $stmt = $conn->prepare(
        "SELECT name
         FROM users
         WHERE user_id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $name = $user['name'];
        $_SESSION['name'] = $name;
    }

    $stmt->close();
} catch (Throwable $err) {
    // Gunakan nama dari session jika profil tidak dapat diambil.
}

$initial = function_exists('mb_substr')
    ? mb_substr($name, 0, 1, 'UTF-8')
    : substr($name, 0, 1);

/* ========================================
   REWARDS
   Level (ENUM): Bronze, Silver, Gold
   Poin bertambah +10 setiap 1 menu dibeli
   (diproses saat pembayaran di menu.php).
   ======================================== */

try {
    $stmt = $conn->prepare(
        "SELECT points, level
         FROM rewards
         WHERE user_id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $rewards['points'] = (int)($row['points'] ?? 0);
        $rewards['level']  = $row['level'] ?? 'Bronze';
    }

    $stmt->close();
} catch (Throwable $err) {
    // Tampilkan nilai default jika rewards belum tersedia.
}

/* ========================================
   RECOMMENDED MENU
   Harga: menus.price dulu, jika kosong/0 pakai harga
   termurah dari menu_details.
   ======================================== */

try {
    $result = $conn->query(
        "SELECT
            m.menu_id,
            m.menu_name,
            m.image,
            COALESCE(
                NULLIF(m.price, 0),
                NULLIF(MIN(d.price), 0),
                0
            ) AS price
         FROM menus m
         LEFT JOIN menu_details d ON d.menu_id = m.menu_id
         WHERE m.status = 'Available'
         GROUP BY m.menu_id, m.menu_name, m.image, m.price
         ORDER BY m.menu_id DESC
         LIMIT 4"
    );

    while ($row = $result->fetch_assoc()) {
        $recommendedMenus[] = $row;
    }
} catch (Throwable $err) {
    $recommendedMenus = [];
}

/* ========================================
   LŪMA MOMENTS
   ======================================== */

try {
    $result = $conn->query(
        "SELECT moment_id, moment_name, icon
         FROM moments
         ORDER BY moment_id
         LIMIT 4"
    );

    while ($row = $result->fetch_assoc()) {
        $moments[] = $row;
    }
} catch (Throwable $err) {
    $moments = [];
}

/* ========================================
   RECENT ORDERS
   ======================================== */

try {
    $stmt = $conn->prepare(
        "SELECT
            order_id,
            order_date,
            total_amount,
            status
         FROM orders
         WHERE user_id = ?
         ORDER BY order_date DESC
         LIMIT 3"
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recentOrders[] = $row;
    }

    $stmt->close();
} catch (Throwable $err) {
    $recentOrders = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Home | LŪMA Café</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
          rel="stylesheet">

    <link rel="stylesheet" href="dashboard.css?v=6">
</head>

<body>

<!-- ========================================
     NAVBAR
     ======================================== -->

<header class="navbar">
    <div class="container nav-content">

        <a href="dashboard.php" class="logo">
            LŪMA<span>.</span>
        </a>

        <nav class="nav-links">
            <a href="dashboard.php" class="active">Home</a>
            <a href="menu.php">Menu</a>
            <a href="luma_moments.php">LŪMA Moments</a>
            <a href="#rewards">Rewards</a>
            <a href="my_orders.php">My Orders</a>
            <a href="#profile">Profile</a>
        </nav>

        <div class="nav-actions">
            <span class="hello-name">
                Hello, <?= e($name) ?>
            </span>

            <a href="../logout.php" class="logout-link">
                Logout
            </a>
        </div>

    </div>
</header>


<main class="container dashboard-main">

    <!-- ========================================
         WELCOME SECTION
         ======================================== -->

    <section class="welcome-section">

        <div class="welcome-copy">
            <span class="eyebrow">
                YOUR DAILY COFFEE MOMENT
            </span>

            <h1>
                A little moment,<br>
                just for <span>you.</span>
            </h1>

            <p>
                Welcome back, <?= e($name) ?>.
                Discover your favorite drinks and enjoy
                a special moment with LŪMA.
            </p>

            <div class="welcome-actions">
                <a href="menu.php" class="btn-primary">
                    Explore Our Menu <span>→</span>
                </a>

                <a href="luma_moments.php" class="btn-text">
                    Discover Moments
                </a>
            </div>
        </div>

        <div class="welcome-decoration">
            <div class="decor-circle decor-circle-one"></div>
            <div class="decor-circle decor-circle-two"></div>

            <div class="welcome-brand">
                <span class="brand-small">
                    SLOW DOWN &amp; SAVOR
                </span>

                <span class="brand-large">LŪMA.</span>

                <span class="brand-caption">
                    Your everyday coffee ritual
                </span>
            </div>
        </div>

    </section>


    <!-- ========================================
         REWARDS
         ======================================== -->

    <section class="rewards-banner" id="rewards">

        <div class="rewards-icon">✦</div>

        <div class="rewards-copy">
            <span class="section-kicker">LŪMA REWARDS</span>

            <h2>Every sip brings you closer.</h2>

            <p>
                Earn 10 points for every item you order
                and enjoy the journey with LŪMA.
            </p>
        </div>

        <div class="rewards-stat">
            <span class="stat-label">YOUR POINTS</span>

            <strong>
                <?= number_format($rewards['points'], 0, ',', '.') ?>
            </strong>

            <span class="level-pill">
                <?= e($rewards['level']) ?>
            </span>
        </div>

    </section>


    <!-- ========================================
         RECOMMENDED MENU
         ======================================== -->

    <section class="content-section" id="menu">

        <div class="section-heading">
            <div>
                <span class="section-kicker">
                    MADE FOR YOUR DAY
                </span>

                <h2>Recommended for you</h2>

                <p>
                    Find a pick that might become
                    your new favorite.
                </p>
            </div>

            <a href="menu.php" class="text-link">
                View all menu <span>→</span>
            </a>
        </div>

        <?php if (!empty($recommendedMenus)): ?>

            <div class="menu-grid">

                <?php foreach ($recommendedMenus as $menu): ?>

                    <?php
                    /*
                     * Kolom image berisi nama file saja (mis. Affogato.jpg).
                     * Semua gambar menu berada di assets/menu/.
                     * Pengecekan file dilakukan di server (PHP),
                     * bukan lagi dengan JavaScript onerror.
                     */
                    $imageName = basename(
                        trim((string)($menu['image'] ?? ''))
                    );

                    $imagePath = __DIR__ . '/../assets/menu/' . $imageName;

                    $imageSrc = ($imageName !== '' && is_file($imagePath))
                        ? '../assets/menu/' . rawurlencode($imageName)
                        : '';
                    ?>

                    <article class="menu-card">

                        <a href="menu.php"
                           class="menu-image-wrap">

                            <?php if ($imageSrc !== ''): ?>

                                <img
                                    src="<?= e($imageSrc) ?>"
                                    alt="<?= e($menu['menu_name']) ?>"
                                    class="menu-image"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="image-fallback">
                                    LŪMA.
                                </div>

                            <?php endif; ?>

                            <span class="menu-image-label">
                                LŪMA PICK
                            </span>

                        </a>

                        <div class="menu-card-body">

                            <h3>
                                <?= e($menu['menu_name']) ?>
                            </h3>

                            <div class="menu-card-bottom">

                                <span class="menu-price">
                                    <?= rupiah($menu['price']) ?>
                                </span>

                                <a href="menu.php"
                                   class="round-arrow"
                                   aria-label="View menu">
                                    ↗
                                </a>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <span class="empty-icon">☕</span>

                <h3>Our menu is being prepared</h3>

                <p>
                    Please visit the Menu page
                    to see more options.
                </p>

                <a href="menu.php" class="btn-outline">
                    Explore Menu
                </a>
            </div>

        <?php endif; ?>

    </section>


    <!-- ========================================
         LŪMA MOMENTS
         ======================================== -->

    <section class="content-section moments-section">

        <div class="section-heading">
            <div>
                <span class="section-kicker">
                    A MOMENT FOR EVERY MOOD
                </span>

                <h2>Find your LŪMA Moment</h2>

                <p>
                    Choose the mood that fits
                    your moment today.
                </p>
            </div>

            <a href="luma_moments.php" class="text-link">
                Discover all <span>→</span>
            </a>
        </div>

        <?php if (!empty($moments)): ?>

            <div class="moments-grid">

                <?php foreach ($moments as $moment): ?>

                    <a
                        class="moment-card"
                        href="luma_moments.php?moment_id=<?= (int)$moment['moment_id'] ?>"
                    >

                        <span class="moment-icon">
                            <?= e($moment['icon'] ?? '✦') ?>
                        </span>

                        <span class="moment-name">
                            <?= e($moment['moment_name']) ?>
                        </span>

                        <span class="moment-arrow">↗</span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state compact-empty">
                <p>LŪMA Moments will be available soon.</p>

                <a href="luma_moments.php" class="text-link">
                    Visit LŪMA Moments →
                </a>
            </div>

        <?php endif; ?>

    </section>


    <!-- ========================================
         RECENT ORDERS
         ======================================== -->

    <section class="content-section orders-section">

        <div class="section-heading">
            <div>
                <span class="section-kicker">
                    YOUR LŪMA JOURNEY
                </span>

                <h2>Recent orders</h2>

                <p>
                    Take another look at your latest orders.
                </p>
            </div>

            <a href="my_orders.php" class="text-link">
                View all orders <span>→</span>
            </a>
        </div>

        <?php if (!empty($recentOrders)): ?>

            <div class="orders-list">

                <?php foreach ($recentOrders as $order): ?>

                    <article class="order-card">

                        <div class="order-info">

                            <span class="order-number">
                                ORDER #<?= (int)$order['order_id'] ?>
                            </span>

                            <strong>
                                <?php
                                $timestamp = strtotime(
                                    (string)$order['order_date']
                                );

                                echo $timestamp
                                    ? e(date('d M Y, H:i', $timestamp))
                                    : e($order['order_date']);
                                ?>
                            </strong>

                            <span class="order-total">
                                <?= rupiah($order['total_amount']) ?>
                            </span>

                        </div>

                        <div class="order-statuses">

                            <span class="status-label">Status</span>

                            <span class="status-pill <?= e(statusClass($order['status'] ?? 'Pending')) ?>">
                                <?= e($order['status'] ?? 'Pending') ?>
                            </span>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <span class="empty-icon">♡</span>

                <h3>Your journey starts here</h3>

                <p>
                    You have no orders yet. Let's find
                    a drink that suits you.
                </p>

                <a href="menu.php" class="btn-outline">
                    Order your first coffee
                </a>

            </div>

        <?php endif; ?>

    </section>


    <!-- ========================================
         CLOSING BANNER
         ======================================== -->

    <section class="closing-banner">

        <span class="section-kicker">
            TAKE A BREATH. TAKE A SIP.
        </span>

        <h2>Make today a little more meaningful.</h2>

        <p>
            Accompany your day with a cup of happiness from LŪMA.
        </p>

        <a href="menu.php" class="btn-light">
            Find your favorite <span>→</span>
        </a>

    </section>


    <!-- ========================================
         CUSTOMER PROFILE
         ======================================== -->

    <section class="profile-section" id="profile">

        <div class="section-heading">
            <div>
                <span class="section-kicker">
                    YOUR PERSONAL SPACE
                </span>

                <h2>Your Profile</h2>

                <p>
                    Get to know and manage your LŪMA account information.
                </p>
            </div>
        </div>

        <div class="profile-card">

            <div class="profile-avatar">
                <?= e(function_exists('mb_strtoupper')
                    ? mb_strtoupper($initial, 'UTF-8')
                    : strtoupper($initial)) ?>
            </div>

            <div class="profile-info">

                <span class="section-kicker">
                    LŪMA MEMBER
                </span>

                <h3><?= e($name) ?></h3>

                <p>
                    Welcome to your personal LŪMA space.
                </p>

            </div>

        </div>

    </section>

</main>


<!-- ========================================
     FOOTER
     ======================================== -->

<footer class="site-footer">

    <a href="dashboard.php" class="footer-logo">
        LŪMA<span>.</span>
    </a>

    <p>Your everyday coffee ritual.</p>

    <span class="copyright">
        © <?= date('Y') ?> LŪMA Café. Made with care.
    </span>

</footer>

</body>
</html>