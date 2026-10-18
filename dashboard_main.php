<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Escape output so it can be displayed safely
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Format price in Rupiah
function rupiah($value) {
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

// Login status
$loggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['name'] ?? '';
$userRole = $_SESSION['role'] ?? '';

// Specify the destination for the account button
if (!$loggedIn) {
    $accountUrl = 'login.php';
} elseif ($userRole === 'staff') {
    $accountUrl = 'staff/dashboard.php';
} else {
    $accountUrl = 'dashboard_main.php';
}

// Prepare menu and Moments data
$menus = [];
$moments = [];
$dbError = false;

try {
    // Selected Menu
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

   // Retrieve LUMA Moments data
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
<html lang="en">
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
            Hello, <?= e($userName) ?>
        <?php else: ?>
            Log in to continue
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
                Find the cup of coffee that accompanies your story.
                Enjoy every moment with LŪMA.
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
                     alt="Featured menu at LŪMA Café">
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

            <p>Find a new favorite to accompany your day.</p>
        </div>

        <?php if ($dbError): ?>
            <p class="empty-message">
                The menu could not be loaded. Please check the database connection.
            </p>
        <?php elseif (empty($menus)): ?>
            <p class="empty-message">
                No menu items are available right now.
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

            <p>Every mood has the perfect companion.</p>
        </div>

        <?php if (empty($moments)): ?>
            <p class="empty-message">
                Moments recommendations are not available yet.
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
                            <p>Find a companion for your moment.</p>
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
                Enjoy the journey with LŪMA and discover
                a special experience with every visit.
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