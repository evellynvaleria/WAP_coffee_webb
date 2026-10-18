
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
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$name = $_SESSION['name'] ?? 'Customer';
$moments = [];
$menus = [];
$error = '';

$selectedId = filter_input(INPUT_GET, 'moment_id', FILTER_VALIDATE_INT);
$selectedId = $selectedId ?: 0;

/* Ambil empat LŪMA Moments */
try {
    $result = $conn->query("
        SELECT moment_id, moment_name, icon
        FROM moments
        ORDER BY moment_id
        LIMIT 4
    ");

    while ($row = $result->fetch_assoc()) {
        $moments[] = $row;
    }
} catch (Throwable $err) {
    $error = 'LŪMA Moments belum dapat dimuat.';
}

/* Ambil rekomendasi menu sesuai momen */
if ($selectedId > 0) {
    try {
        $stmt = $conn->prepare("
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
            FROM moment_recommendation mr
            JOIN menus m
                ON m.menu_id = mr.menu_id
            LEFT JOIN categories c
                ON c.category_id = m.category_id
            WHERE mr.moment_id = ?
              AND m.status = 'Available'
            ORDER BY mr.recommendation_id
        ");

        $stmt->bind_param('i', $selectedId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $menus[] = $row;
        }

        $stmt->close();
    } catch (Throwable $err) {
        $error = 'Rekomendasi menu belum dapat dimuat.';
    }
}

/* Validasi momen yang dipilih */
$selectedMoment = null;

foreach ($moments as $moment) {
    if ((int)$moment['moment_id'] === $selectedId) {
        $selectedMoment = $moment;
        break;
    }
}

if ($selectedId > 0 && !$selectedMoment) {
    $selectedId = 0;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LŪMA Moments | LŪMA Café</title>

    <link
        rel="stylesheet"
        href="luma_moments.css?v=<?= filemtime(__DIR__ . '/luma_moments.css') ?>"
    >
</head>

<body>

<!-- NAVBAR -->
<header class="navbar">
    <div class="container nav-content">

        <a href="../dashboard_main.php" class="logo">
            LŪMA<span>.</span>
        </a>

        <nav class="nav-links">
    <a href="dashboard.php">Home</a>
    <a href="menu.php">Menu</a>
    <a href="luma_moments.php">LŪMA Moments</a>
    <a href="dashboard.php#rewards">Rewards</a>
    <a href="my_orders.php">My orders</a>
    <a href="dashboard.php#profile">Profile</a>
        </nav>

        <div class="nav-actions">
            <span>Haloo, <?= e($name) ?></span>
            <a href="../logout.php" class="logout-link">Logout</a>
        </div>

    </div>
</header>

<main class="container">

    <!-- HERO -->
    <section class="hero">
        <span class="eyebrow">YOUR DAY, YOUR MOMENT</span>

        <h1>
            Every Moment Deserves<br>
            a Little LŪMA.
        </h1>

        <p>
            Apa pun suasana hatimu hari ini, temukan minuman
            yang tepat untuk menemani setiap momenmu.
        </p>

        <a href="#choose-moment" class="btn-primary">
            Find Your Moment <span>→</span>
        </a>
    </section>

    <?php if ($error !== ''): ?>
        <div class="notice">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- PILIHAN MOMEN -->
    <section class="section" id="choose-moment">

        <div class="section-heading">
            <div>
                <span class="eyebrow">MADE FOR YOUR MOOD</span>
                <h2>What's Your Moment?</h2>
                <p>
                    Pilih momenmu dan temukan rekomendasi dari LŪMA.
                </p>
            </div>
        </div>

        <?php if (!empty($moments)): ?>

            <div class="moments-grid">

                <?php foreach ($moments as $moment): ?>

                    <?php
                    $isActive =
                        (int)$moment['moment_id'] === $selectedId;
                    ?>

                    <a
                        class="moment-card <?= $isActive ? 'selected' : '' ?>"
                        href="luma_moments.php?moment_id=<?= (int)$moment['moment_id'] ?>#recommendations"
                    >

                        <span class="moment-icon">
                            <?= e($moment['icon'] ?: '☕') ?>
                        </span>

                        <span class="moment-label">YOUR MOMENT</span>

                        <h3>
                            <?= e($moment['moment_name']) ?>
                        </h3>

                        <span class="moment-action">
                            <?= $isActive ? 'Selected ✓' : 'Discover →' ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <h3>Moments are coming soon.</h3>
                <p>Belum ada momen yang tersedia.</p>
            </div>

        <?php endif; ?>

    </section>

    <!-- REKOMENDASI MENU -->
    <section
        class="section recommendations"
        id="recommendations"
    >

        <div class="section-heading">

            <div>
                <span class="eyebrow">CURATED FOR YOU</span>

                <h2>
                    <?php if ($selectedMoment): ?>
                        <?= e($selectedMoment['moment_name']) ?> Selection
                    <?php else: ?>
                        A Taste for Every Moment
                    <?php endif; ?>
                </h2>

                <p>
                    <?php if ($selectedMoment): ?>
                        Pilihan menu yang tersedia untuk momen ini.
                    <?php else: ?>
                        Pilih salah satu momen di atas untuk melihat
                        rekomendasi menu yang sesuai.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($selectedMoment): ?>
                <a
                    href="luma_moments.php#choose-moment"
                    class="text-link"
                >
                    Change moment ↑
                </a>
            <?php endif; ?>

        </div>

        <?php if ($selectedMoment && !empty($menus)): ?>

            <div class="menu-grid">

                <?php foreach ($menus as $menu): ?>

                    <?php
                    $filename = basename(
                        (string)($menu['image'] ?? '')
                    );

                    $imagePath =
                        __DIR__ . '/../assets/menu/' . $filename;

                    $isPastry =
                        strtolower(trim(
                            $menu['category_name'] ?? ''
                        )) === 'pastry';
                    ?>

                    <article class="menu-card">

                        <!-- FOTO MENU -->
                        <div class="menu-image">

                            <?php if (
                                $filename !== '' &&
                                is_file($imagePath)
                            ): ?>

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

                        <!-- INFORMASI MENU -->
                        <div class="menu-info">

                            <span class="category">
                                <?= e(
                                    $menu['category_name']
                                    ?: 'LŪMA Selection'
                                ) ?>
                            </span>

                            <h3>
                                <?= e($menu['menu_name']) ?>
                            </h3>

                            <div class="menu-bottom">
                                <span class="price">
                                    <?= e(rupiah($menu['display_price'])) ?>
                                </span>
                            </div>

                        </div>

                        <!-- ADD TO CART: SATU FORM -->
                        <form
                            action="add_to_cart.php"
                            method="POST"
                            class="add-cart-form"
                        >

                            <input
                                type="hidden"
                                name="menu_id"
                                value="<?= (int)$menu['menu_id'] ?>"
                            >

                            <?php if ($isPastry): ?>

                                <input
                                    type="hidden"
                                    name="size"
                                    value="regular"
                                >

                            <?php else: ?>

                                <label for="size-<?= (int)$menu['menu_id'] ?>">
                                    Size
                                </label>

                                <select
                                    id="size-<?= (int)$menu['menu_id'] ?>"
                                    name="size"
                                    required
                                >
                                    <option value="small">Small</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="large">Large</option>
                                </select>

                            <?php endif; ?>

                            <label for="qty-<?= (int)$menu['menu_id'] ?>">
                                Quantity
                            </label>

                            <input
                                id="qty-<?= (int)$menu['menu_id'] ?>"
                                type="number"
                                name="quantity"
                                value="1"
                                min="1"
                                max="50"
                                required
                            >

                            <button
                                type="submit"
                                class="add-cart-button"
                            >
                                Add to Cart +
                            </button>

                        </form>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php elseif ($selectedMoment): ?>

            <div class="empty-state">
                <h3>Coming up with your next favorite.</h3>

                <p>
                    Belum ada menu tersedia untuk momen ini.
                    Coba pilih momen lainnya.
                </p>

                <a
                    href="#choose-moment"
                    class="text-link"
                >
                    Choose another moment →
                </a>
            </div>

        <?php else: ?>

            <div class="recommendation-placeholder">
                <span>☕</span>

                <h3>Your moment, your perfect sip.</h3>

                <p>
                    Pilih salah satu momen di atas untuk melihat
                    menu rekomendasi khusus untukmu.
                </p>
            </div>

        <?php endif; ?>

    </section>

</main>

<!-- FOOTER -->
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