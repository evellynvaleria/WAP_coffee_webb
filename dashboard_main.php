<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Escape output agar aman ditampilkan
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Format harga Rupiah
function rupiah($value) {
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

// Status login
$loggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['name'] ?? '';
$userRole = $_SESSION['role'] ?? '';

// Tentukan tujuan tombol akun
if (!$loggedIn) {
    $accountUrl = 'login.php';
} elseif ($userRole === 'staff') {
    $accountUrl = 'staff/dashboard.php';
} else {
    $accountUrl = 'dashboard_main.php';
}

// Siapkan data menu dan Moments
$menus = [];
$moments = [];
$dbError = false;

try {
    // Ambil menu yang tersedia
    $sqlMenus = "
        SELECT
            m.menu_id,
            m.menu_name,
            m.image,
            m.category_id,
            c.category_name,
            COALESCE(
                (SELECT MIN(md.price)
                 FROM menu_details md
                 WHERE md.menu_id = m.menu_id),
                m.price
            ) AS display_price
        FROM menus m
        LEFT JOIN categories c
            ON c.category_id = m.category_id
        WHERE m.status = 'Available'
        ORDER BY m.menu_id DESC
        LIMIT 6
    ";

    $resultMenus = $conn->query($sqlMenus);

    if ($resultMenus) {
        while ($row = $resultMenus->fetch_assoc()) {
            $menus[] = $row;
        }
    }

    // Ambil data LUMA Moments
    $sqlMoments = "
        SELECT
            mo.moment_id,
            mo.moment_name,
            mo.icon,
            m.menu_name,
            m.image
        FROM moments mo
        LEFT JOIN moment_recommendation mr
            ON mr.moment_id = mo.moment_id
        LEFT JOIN menus m
            ON m.menu_id = mr.menu_id
            AND m.status = 'Available'
        ORDER BY mo.moment_id, m.menu_id
    ";

    $resultMoments = $conn->query($sqlMoments);

    if ($resultMoments) {
        $seenMoments = [];

        while ($row = $resultMoments->fetch_assoc()) {
            $id = $row['moment_id'];

            if (!isset($seenMoments[$id])) {
                $seenMoments[$id] = true;
                $moments[] = $row;
            } elseif (empty($moments[array_key_last($moments)]['menu_name'])
                && !empty($row['menu_name'])) {
                $moments[array_key_last($moments)] = $row;
            }
        }
    }
} catch (Throwable $error) {
    $dbError = true;
}
?>

<?php if ($loggedIn && $userRole === 'customer'): ?>
    <a href="logout.php" class="account-button">Logout</a>
<?php endif; ?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LŪMA Café | Discover Your Moment</title>

    <link rel="stylesheet" href="css/dashboard_main.css">
</head>

<body>

<header class="navbar">
    <a href="dashboard_main.php" class="brand">LŪMA<span>.</span></a>

    <nav class="nav-links">
        <a href="#discover">Discover</a>
        <a href="#menu">Our Menu</a>
        <a href="#moments">LŪMA Moments</a>
        <a href="#rewards">Rewards</a>
    </nav>

    <a class="account-button" href="<?= e($accountUrl) ?>">
        <?php if ($loggedIn): ?>
            Haloo, <?= e($userName) ?>
        <?php else: ?>
            Login untuk melanjutkan
        <?php endif; ?>
    </a>
</header>

<main>

    <section class="hero" id="discover">
        <div class="hero-content">
            <p class="eyebrow">YOUR DAILY COFFEE RITUAL</p>

            <h1>Find your moment.<br>
                <span>Savor the feeling.</span>
            </h1>

            <p class="hero-description">
                Temukan secangkir kopi yang menemani ceritamu.
                Nikmati setiap momen bersama LŪMA.
            </p>

            <a href="#menu" class="primary-button">
                Discover Our Menu
            </a>
        </div>

        <div class="hero-image">
            <?php
            $heroImage = !empty($menus[0]['image'])
                ? 'assets/menu/' . basename($menus[0]['image'])
                : '';
            ?>

            <?php if ($heroImage !== ''): ?>
                <img src="<?= e($heroImage) ?>"
                     alt="Menu pilihan LUMA Café">
            <?php else: ?>
                <div class="image-placeholder">
                    <span>LŪMA Café</span>
                    <p>A little moment of calm.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section menu-section" id="menu">
        <div class="section-heading">
            <div>
                <p class="eyebrow">A LITTLE SOMETHING FOR YOU</p>
                <h2>Our Favorites</h2>
            </div>

            <p>Temukan favorit baru untuk menemani harimu.</p>
        </div>

        <?php if ($dbError): ?>
            <p class="empty-message">
                Menu belum dapat dimuat. Periksa koneksi database.
            </p>
        <?php elseif (empty($menus)): ?>
            <p class="empty-message">
                Belum ada menu tersedia saat ini.
            </p>
        <?php else: ?>
            <div class="menu-grid">
                <?php foreach ($menus as $menu): ?>
                    <article class="menu-card">
                        <?php if (!empty($menu['image'])): ?>
                            <img
                                class="menu-image"
                                src="<?= e('assets/menu/' . basename($menu['image'])) ?>"
                                alt="<?= e($menu['menu_name']) ?>"
                                loading="lazy">
                        <?php else: ?>
                            <div class="menu-placeholder">
                                LŪMA Café
                            </div>
                        <?php endif; ?>

                        <div class="menu-info">
                            <p class="menu-category">
                                <?= e($menu['category_name'] ?? 'LŪMA Selection') ?>
                            </p>

                            <h3><?= e($menu['menu_name']) ?></h3>

                            <p class="menu-price">
                                <?= rupiah($menu['display_price']) ?>
                            </p>

                            <a href="#moments" class="text-link">
                                Explore more →
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section moments-section" id="moments">
        <div class="section-heading">
            <div>
                <p class="eyebrow">MADE FOR YOUR EVERYDAY</p>
                <h2>LŪMA Moments</h2>
            </div>

            <p>Setiap suasana punya teman yang tepat.</p>
        </div>

        <?php if (empty($moments)): ?>
            <p class="empty-message">
                Rekomendasi Moments belum tersedia.
            </p>
        <?php else: ?>
            <div class="moments-grid">
                <?php foreach ($moments as $moment): ?>
                    <article class="moment-card">
                        <div class="moment-icon">
                            <?= e($moment['icon'] ?: '☕') ?>
                        </div>

                        <h3><?= e($moment['moment_name']) ?></h3>

                        <?php if (!empty($moment['menu_name'])): ?>
                            <p>Perfect with</p>
                            <strong><?= e($moment['menu_name']) ?></strong>
                        <?php else: ?>
                            <p>Temukan teman untuk momenmu.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="rewards-section" id="rewards">
        <div>
            <p class="eyebrow">A LITTLE MORE LOVE</p>
            <h2>Every cup brings you closer.</h2>
            <p>
                Nikmati perjalanan bersama LŪMA dan temukan
                pengalaman spesial di setiap kunjungan.
            </p>
        </div>

        <?php if ($loggedIn): ?>
            <a href="#menu" class="light-button">Explore the Menu</a>
        <?php else: ?>
            <a href="login.php" class="light-button">Join LŪMA</a>
        <?php endif; ?>
    </section>

</main>

<footer class="footer">
    <a href="dashboard_main.php" class="brand footer-brand">
        LŪMA<span>.</span>
    </a>

    <p>Slow down. Sip slowly. Stay awhile.</p>
    <p class="copyright">© <?= date('Y') ?> LŪMA Café</p>
</footer>

</body>
</html>