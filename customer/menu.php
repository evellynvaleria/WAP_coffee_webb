<?php
session_start();

// Cek login customer
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

// Koneksi database
require_once __DIR__ . '/../config/database.php';

// Escape output agar aman ditampilkan di HTML
function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Format harga Rupiah
function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

// Cek kategori minuman
function isDrinkCategory($name): bool {
    $name = strtolower(trim((string)$name));
    $name = str_replace('-', ' ', $name);

    return in_array(
        $name,
        ['coffee', 'non coffee', 'tea'],
        true
    );
}

// Cek kategori pastry
function isPastryCategory($name): bool {
    return strtolower(trim((string)$name)) === 'pastry';
}

// ======================================
// AMBIL DATA KATEGORI
// ======================================

$categoryQuery = "
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_id ASC
";

$categoryResult = $conn->query($categoryQuery);

$categories = [];

if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ======================================
// FILTER KATEGORI
// ======================================

$selectedCategory = isset($_GET['category_id'])
    ? (int) $_GET['category_id']
    : 0;

// ======================================
// AMBIL DATA MENU
// Kolom description sudah dihapus
// ======================================

$sql = "
    SELECT
        m.menu_id,
        m.menu_name,
        m.price AS base_price,
        m.image,
        m.status,
        c.category_id,
        c.category_name,
        MIN(md.price) AS min_size_price
    FROM menus m
    INNER JOIN categories c
        ON m.category_id = c.category_id
    LEFT JOIN menu_details md
        ON m.menu_id = md.menu_id
    WHERE m.status = 'Available'
";

if ($selectedCategory > 0) {
    $sql .= " AND c.category_id = ?";
}

$sql .= "
    GROUP BY
        m.menu_id,
        m.menu_name,
        m.price,
        m.image,
        m.status,
        c.category_id,
        c.category_name
    ORDER BY m.menu_name ASC
";

$stmt = $conn->prepare($sql);

if ($selectedCategory > 0) {
    $stmt->bind_param('i', $selectedCategory);
}

$stmt->execute();

$menus = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Menu | LŪMA Café</title>

    <link rel="stylesheet" href="luma_moments.css">
</head>

<body>

<!-- ==============================
     NAVBAR
============================== -->

<header class="menu-navbar">

    <a href="dashboard.php" class="menu-logo">
        LŪMA<span>.</span>
    </a>

    <nav class="nav-links">
    <a href="dashboard.php">Home</a>
    <a href="menu.php">Menu</a>
    <a href="luma_moments.php">LŪMA Moments</a>
    <a href="dashboard.php#rewards">Rewards</a>
    <a href="my_orders.php">My orders</a>
</nav>

    <a href="cart.php" class="menu-cart-link">
        My Cart
    </a>

</header>


<main class="menu-page">

    <!-- ==============================
         HERO SECTION
    ============================== -->

    <section class="menu-hero">

        <p class="menu-eyebrow">
            YOUR DAILY RITUAL
        </p>

        <h1>Explore Our Menu</h1>

        <p>
            Find your favorite drinks and treats,
            made for your everyday moments.
        </p>

    </section>


    <!-- ==============================
         MENU CATALOG
    ============================== -->

    <section class="menu-catalog">

        <div class="menu-section-heading">

            <div>
                <p class="menu-eyebrow">
                    FRESHLY MADE FOR YOU
                </p>

                <h2>Our Menu</h2>
            </div>

        </div>


        <!-- FILTER KATEGORI -->

        <div class="menu-category-filter">

            <a
                href="menu.php"
                class="<?= $selectedCategory === 0 ? 'selected' : '' ?>"
            >
                All Menu
            </a>

            <?php foreach ($categories as $category): ?>

                <?php
                $categoryName = strtolower(
                    trim($category['category_name'])
                );

                $categoryName = str_replace(
                    '-',
                    ' ',
                    $categoryName
                );

                // Tampilkan hanya empat kategori
                if (!in_array(
                    $categoryName,
                    ['coffee', 'non coffee', 'tea', 'pastry'],
                    true
                )) {
                    continue;
                }

                $categoryId = (int) $category['category_id'];
                ?>

                <a
                    href="menu.php?category_id=<?= $categoryId ?>"
                    class="<?= $selectedCategory === $categoryId ? 'selected' : '' ?>"
                >
                    <?= e($category['category_name']) ?>
                </a>

            <?php endforeach; ?>

        </div>


        <!-- DAFTAR PRODUK -->

        <div class="menu-product-grid">

            <?php if ($menus->num_rows > 0): ?>

                <?php while ($menu = $menus->fetch_assoc()): ?>

                    <?php
                    $menuId = (int) $menu['menu_id'];

                    $imageName = basename(
                        (string) ($menu['image'] ?? '')
                    );

                    $imagePath = __DIR__
                        . '/../assets/menu/'
                        . $imageName;

                    $imageUrl = '../assets/menu/'
                        . rawurlencode($imageName);

                    $isDrink = isDrinkCategory(
                        $menu['category_name']
                    );

                    $isPastry = isPastryCategory(
                        $menu['category_name']
                    );

                    $displayPrice = (
                        $isDrink &&
                        $menu['min_size_price'] !== null
                    )
                        ? $menu['min_size_price']
                        : $menu['base_price'];
                    ?>


                    <article class="menu-product-card">

                        <!-- FOTO PRODUK -->

                        <div class="menu-product-image">

                            <?php if (
                                $imageName !== '' &&
                                is_file($imagePath)
                            ): ?>

                                <img
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($menu['menu_name']) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="menu-no-image">
                                    Image unavailable
                                </div>

                            <?php endif; ?>

                        </div>


                        <!-- INFORMASI PRODUK -->

                        <div class="menu-product-info">

                            <span class="menu-product-category">
                                <?= e($menu['category_name']) ?>
                            </span>

                            <h3>
                                <?= e($menu['menu_name']) ?>
                            </h3>

                            <p class="menu-product-price">

                                <?php if ($isDrink): ?>

                                    <span>Starting from </span>

                                <?php endif; ?>

                                <?= rupiah($displayPrice) ?>

                            </p>


                            <!-- FORM ADD TO CART -->

                            <form
                                action="add_to_cart.php"
                                method="POST"
                                class="menu-add-form"
                            >

                                <input
                                    type="hidden"
                                    name="menu_id"
                                    value="<?= $menuId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="quantity"
                                    value="1"
                                >


                                <?php if ($isDrink): ?>

                                    <!-- UKURAN MINUMAN -->

                                    <label for="size-<?= $menuId ?>">
                                        Choose Size
                                    </label>

                                    <select
                                        name="size"
                                        id="size-<?= $menuId ?>"
                                        required
                                    >
                                        <option value="small">
                                            Small
                                        </option>

                                        <option
                                            value="medium"
                                            selected
                                        >
                                            Medium
                                        </option>

                                        <option value="large">
                                            Large
                                        </option>
                                    </select>


                                <?php elseif ($isPastry): ?>

                                    <!-- PASTRY TIDAK MEMILIKI UKURAN -->

                                    <input
                                        type="hidden"
                                        name="size"
                                        value="regular"
                                    >

                                    <p class="menu-size-note">
                                        Regular serving
                                    </p>


                                <?php else: ?>

                                    <!-- KATEGORI LAIN -->

                                    <input
                                        type="hidden"
                                        name="size"
                                        value="regular"
                                    >

                                <?php endif; ?>


                                <button
                                    type="submit"
                                    class="menu-add-button"
                                >
                                    Add to Cart +
                                </button>

                            </form>

                        </div>

                    </article>

                <?php endwhile; ?>


            <?php else: ?>

                <div class="menu-empty-state">

                    <h3>No Menu Available</h3>

                    <p>
                        There are currently no available products
                        in this category.
                    </p>

                    <a href="menu.php">
                        View All Menu
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>


<!-- ==============================
     FOOTER
============================== -->

<footer class="menu-footer">

    <p>
        &copy; <?= date('Y') ?> LŪMA Café.
        Made for your everyday moments.
    </p>

</footer>

</body>
</html>