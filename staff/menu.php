<?php
session_start();

require_once "../config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================================================
// CEK LOGIN & ROLE
// =====================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}

$userName = $_SESSION['name'] ?? 'Staff';

// =====================================================
// FOLDER GAMBAR MENU
// Kolom menus.image hanya berisi nama file (americano.jpg).
// =====================================================
$imageFolder = "../assets/menu/";

// =====================================================
// ATURAN INGREDIENT -> MENU
// Kunci = nama ingredient (sama persis dengan tabel ingredients).
//   'categories' => id kategori (categories.category_id)
//   'keywords'   => kata yang ada di nama menu
//   'menus'      => nama menu persis
// Ingredient habis (stock 0) = semua menu yang cocok jadi Unavailable.
// =====================================================
$ingredientRules = [
    'Coffee Beans'  => ['categories' => [1]],
    'Caramel Sauce' => ['keywords' => ['Caramel']],
    'Vanilla Syrup' => ['keywords' => ['Vanilla']],
    'Whipped Cream' => ['keywords' => ['Frappe', 'Cookies & Cream']],
    'Ice Cubes'     => ['keywords' => ['Frappe', 'Smoothie', 'Soda', 'Blue Ocean']],
    'Lemon'         => ['keywords' => ['Lemon', 'Soda']]
];

$rulesByName = array_change_key_case($ingredientRules, CASE_LOWER);

// =====================================================
// HELPER FUNCTIONS
// =====================================================
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

// 8,00 -> 8   |   2,50 -> 2.5
function formatQty($number): string
{
    return rtrim(rtrim(number_format((float)$number, 2, '.', ''), '0'), '.');
}

function categoryIcon($name): string
{
    $name = strtolower(str_replace(['-', '_'], ' ', (string)$name));

    if (str_contains($name, 'non'))    return 'fa-glass-water';
    if (str_contains($name, 'coffee')) return 'fa-mug-hot';
    if (str_contains($name, 'tea'))    return 'fa-leaf';
    if (str_contains($name, 'pastry')) return 'fa-bread-slice';

    return 'fa-mug-hot';
}

function momentInfo($name): array
{
    $name = strtolower(trim((string)$name));

    $list = [
        'study time'   => ['fa-book-open', 'Calm and steady, easy to sip while you focus.'],
        'work mode'    => ['fa-laptop', 'A little boost to keep productivity going.'],
        'need a break' => ['fa-face-smile', 'Comforting treats to slow things down.'],
        'refreshment'  => ['fa-droplet', 'Light and cool to freshen up the day.']
    ];

    return $list[$name] ?? ['fa-star', ''];
}

// Apakah menu ini memakai ingredient dengan aturan tersebut?
function menuUsesIngredient(array $menu, array $rule): bool
{
    if (in_array((int)$menu['category_id'], $rule['categories'] ?? [], true)) {
        return true;
    }

    foreach ($rule['keywords'] ?? [] as $keyword) {
        if (stripos($menu['menu_name'], $keyword) !== false) {
            return true;
        }
    }

    foreach ($rule['menus'] ?? [] as $name) {
        if (strcasecmp($menu['menu_name'], $name) === 0) {
            return true;
        }
    }

    return false;
}

// =====================================================
// DESKRIPSI MENU
// Kunci = nama menu huruf kecil tanpa spasi/simbol.
// =====================================================
$menuDescriptions = [
    'americano'                => 'Espresso topped with hot water for a smooth, bold cup.',
    'caramelmatcchiato'        => 'Espresso with steamed milk and a sweet caramel drizzle.',
    'caramelmacchiato'         => 'Espresso with steamed milk and a sweet caramel drizzle.',
    'cappuccino'               => 'Equal parts espresso, steamed milk and velvety foam.',
    'mochalatte'               => 'Espresso blended with chocolate and steamed milk.',
    'affogato'                 => 'A scoop of ice cream topped with a hot shot of espresso.',
    'espressoconpanna'         => 'A shot of espresso topped with a swirl of whipped cream.',
    'pumpkinspicelatte'        => 'Latte with pumpkin and warm spices.',
    'pistachiolatte'           => 'Creamy latte with a nutty pistachio flavor.',
    'lavenderlatte'            => 'Latte lightly infused with floral lavender.',
    'saltedcaramellatte'       => 'Latte with sweet caramel and a pinch of sea salt.',
    'brownsugarlatte'          => 'Latte sweetened with rich brown sugar.',
    'spanishlatte'             => 'Sweet and creamy latte made with condensed milk.',
    'javachipfrappe'           => 'Blended iced coffee with chocolate chips and whipped cream.',
    'cookiescream'             => 'Creamy blended drink with crushed cookies.',
    'strawberrymatcha'         => 'Earthy matcha layered with sweet strawberry.',
    'cookiesdough'             => 'Blended cookie dough flavor, sweet and creamy.',
    'matchaube'                => 'Matcha paired with sweet, creamy ube.',
    'mintchoco'                => 'Cool mint and rich chocolate together.',
    'hazelnutchocolate'        => 'Rich chocolate with a toasty hazelnut finish.',
    'matchalatte'              => 'Smooth matcha with creamy milk.',
    'blueocean'                => 'A cool, refreshing blue drink.',
    'sunsetsoda'               => 'Fruity sparkling soda with a sunset gradient.',
    'mixedberrysmoothie'       => 'Smoothie blended from a mix of berries.',
    'mixedpinapplesmoothie'    => 'Tropical smoothie blended with sweet pineapple.',
    'mixedpineapplesmoothie'   => 'Tropical smoothie blended with sweet pineapple.',
    'mixeddragonfruitsmoothie' => 'Smoothie blended with refreshing dragon fruit.',
    'jasminetea'               => 'Fragrant tea scented with jasmine blossoms.',
    'oolongtea'                => 'Semi-oxidized tea with a smooth, toasty aroma.',
    'earlgreytea'              => 'Black tea scented with bergamot.',
    'rosetea'                  => 'Delicate tea infused with rose.',
    'pepperminttea'            => 'Cooling peppermint tea.',
    'blacktea'                 => 'Classic black tea, bold and clean.'
];

function menuDescription($menuName, $categoryName): string
{
    global $menuDescriptions;

    $key = preg_replace('/[^a-z0-9]/', '', strtolower((string)$menuName));

    if (isset($menuDescriptions[$key])) {
        return $menuDescriptions[$key];
    }

    $category = strtolower(str_replace(['-', '_'], ' ', (string)$categoryName));

    if (str_contains($category, 'non'))    return 'A refreshing coffee-free drink made to order.';
    if (str_contains($category, 'coffee')) return 'Freshly made coffee from our baristas.';
    if (str_contains($category, 'tea'))    return 'A soothing cup of tea, brewed fresh.';
    if (str_contains($category, 'pastry')) return 'Baked fresh and served at its best.';

    return 'Made fresh at LÜMA.';
}

// =====================================================
// UPDATE STOCK INGREDIENT
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {

    $ingredientId = filter_input(INPUT_POST, 'ingredient_id', FILTER_VALIDATE_INT);
    $stock        = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_FLOAT);

    if (
        $ingredientId && $ingredientId > 0 &&
        $stock !== false && $stock !== null && $stock >= 0
    ) {
        $stmt = $conn->prepare(
            "UPDATE ingredients SET stock = ?, updated_at = NOW() WHERE ingredient_id = ?"
        );
        $stmt->bind_param("di", $stock, $ingredientId);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: menu.php?open=ingredients');
    exit;
}

// =====================================================
// KATEGORI
// =====================================================
$categories = [];

$resultCategories = $conn->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_id ASC
");

while ($row = $resultCategories->fetch_assoc()) {
    $categories[] = $row;
}

// =====================================================
// INGREDIENT
// =====================================================
$ingredients = [];
$outCount = 0;

$resultIngredients = $conn->query("
    SELECT ingredient_id, ingredient_name, unit, stock, minimum_stock
    FROM ingredients
    ORDER BY (stock <= 0) DESC, ingredient_name ASC
");

while ($row = $resultIngredients->fetch_assoc()) {
    if ((float)$row['stock'] <= 0) {
        $outCount++;
    }
    $ingredients[] = $row;
}

// =====================================================
// MENU
// total_sold = total terjual (untuk Popular Menu)
// =====================================================
$menus = [];

$resultMenus = $conn->query("
    SELECT
        m.menu_id,
        m.category_id,
        m.menu_name,
        m.price,
        m.image,
        m.status,
        c.category_name,
        COALESCE((
            SELECT SUM(od.quantity)
            FROM order_details od
            WHERE od.menu_id = m.menu_id
        ), 0) AS total_sold
    FROM menus m
    LEFT JOIN categories c ON c.category_id = m.category_id
    ORDER BY m.category_id ASC, m.menu_name ASC
");

while ($row = $resultMenus->fetch_assoc()) {

    // Ingredient yang habis dan dipakai menu ini
    $missing = [];

    foreach ($ingredients as $ingredient) {
        if ((float)$ingredient['stock'] > 0) {
            continue;
        }

        $rule = $rulesByName[strtolower($ingredient['ingredient_name'])] ?? null;

        if ($rule && menuUsesIngredient($row, $rule)) {
            $missing[] = $ingredient['ingredient_name'];
        }
    }

    $row['missing_ingredients'] = implode(', ', $missing);

    // Tidak bisa dipesan kalau status manual Unavailable ATAU ingredient habis
    $manualAvailable = strtolower((string)$row['status']) === 'available';
    $hasMissing      = !empty($row['missing_ingredients']);

    $row['is_available']     = $manualAvailable && !$hasMissing;
    $row['unavailable_note'] = $hasMissing ? $row['missing_ingredients'] : 'Marked unavailable';

    $menus[] = $row;
}

// =====================================================
// JUMLAH MENU YANG MEMAKAI TIAP INGREDIENT
// =====================================================
foreach ($ingredients as $key => $ingredient) {

    $rule  = $rulesByName[strtolower($ingredient['ingredient_name'])] ?? null;
    $count = 0;

    if ($rule) {
        foreach ($menus as $menu) {
            if (menuUsesIngredient($menu, $rule)) {
                $count++;
            }
        }
    }

    $ingredients[$key]['used_in'] = $count;
}

// =====================================================
// SIZE (Small / Medium / Large) dari tabel menu_details
// =====================================================
$menuSizes = [];

$resultSizes = $conn->query("
    SELECT menu_id, size, price
    FROM menu_details
    ORDER BY menu_id ASC, FIELD(size, 'Small', 'Medium', 'Large')
");

while ($row = $resultSizes->fetch_assoc()) {
    $menuSizes[(int)$row['menu_id']][] = [
        'size'  => $row['size'],
        'price' => (float)$row['price']
    ];
}

// Data untuk kartu menu dan popup detail
$menuModalData = [];

foreach ($menus as $menu) {

    $menuId = (int)$menu['menu_id'];
    $sizes  = $menuSizes[$menuId] ?? [];

    // Minuman selalu punya Small / Medium / Large.
    // Harga dari menu_details; kalau belum ada, memakai harga dasar menu.
    // Pastry tidak punya size.
    if (!str_contains(strtolower((string)$menu['category_name']), 'pastry')) {

        $sizePrices = [];

        foreach ($sizes as $item) {
            $sizePrices[strtolower($item['size'])] = $item['price'];
        }

        $sizes = [];

        foreach (['Small', 'Medium', 'Large'] as $sizeName) {
            $sizes[] = [
                'size'  => $sizeName,
                'price' => $sizePrices[strtolower($sizeName)] ?? (float)$menu['price']
            ];
        }
    }

    $menuModalData[$menuId] = [
        'id'          => $menuId,
        'name'        => $menu['menu_name'],
        'category'    => $menu['category_name'],
        'description' => menuDescription($menu['menu_name'], $menu['category_name']),
        'image'       => !empty($menu['image']) ? $imageFolder . $menu['image'] : '',
        'icon'        => categoryIcon($menu['category_name']),
        'price'       => (float)$menu['price'],
        'available'   => $menu['is_available'],
        'note'        => $menu['is_available'] ? '' : $menu['unavailable_note'],
        'sizes'       => $sizes
    ];
}

// =====================================================
// POPULAR MENU
// =====================================================
$popularMenus = array_filter($menus, function ($menu) {
    return (int)$menu['total_sold'] > 0;
});

usort($popularMenus, function ($a, $b) {
    return (int)$b['total_sold'] <=> (int)$a['total_sold'];
});

$popularMenus = array_slice($popularMenus, 0, 4);

// =====================================================
// MOMENTS + REKOMENDASI
// =====================================================
$moments = [];

$resultMoments = $conn->query("
    SELECT moment_id, moment_name
    FROM moments
    ORDER BY moment_id ASC
");

while ($row = $resultMoments->fetch_assoc()) {
    $moments[] = $row;
}

$momentMenuIds = [];

$resultRecommendations = $conn->query("
    SELECT moment_id, menu_id
    FROM moment_recommendation
");

while ($row = $resultRecommendations->fetch_assoc()) {
    $momentMenuIds[(int)$row['moment_id']][] = (int)$row['menu_id'];
}

// =====================================================
// KARTU MENU (menampilkan chip size S / M / L)
// =====================================================
function renderMenuCard(array $menu, string $imageFolder): string
{
    global $menuModalData;

    $icon  = categoryIcon($menu['category_name']);
    $sizes = $menuModalData[(int)$menu['menu_id']]['sizes'] ?? [];

    ob_start();
    ?>
    <article
        class="menu-card <?= $menu['is_available'] ? '' : 'unavailable' ?>"
        data-category="<?= (int)$menu['category_id'] ?>"
        data-menu-id="<?= (int)$menu['menu_id'] ?>"
        data-name="<?= e(strtolower($menu['menu_name'])) ?>"
        role="button"
        tabindex="0"
    >

        <div class="menu-image">

            <i class="fa-solid <?= e($icon) ?>"></i>

            <?php if (!empty($menu['image'])): ?>
                <img
                    src="<?= e($imageFolder . $menu['image']) ?>"
                    alt="<?= e($menu['menu_name']) ?>"
                    loading="lazy"
                    onerror="this.remove()"
                >
            <?php endif; ?>

            <?php if ($menu['is_available']): ?>
                <span class="status-badge status-completed menu-badge">Available</span>
            <?php else: ?>
                <span class="status-badge status-out menu-badge">Unavailable</span>
            <?php endif; ?>

        </div>

        <div class="menu-body">

            <h3 class="menu-title"><?= e($menu['menu_name']) ?></h3>

            <span class="menu-category"><?= e($menu['category_name']) ?></span>

            <?php if ($sizes): ?>
                <div class="menu-sizes">
                    <?php foreach ($sizes as $size): ?>
                        <span
                            class="size-chip"
                            title="<?= e($size['size'] . ' - ' . rupiah($size['price'])) ?>"
                        ><?= e(strtoupper(substr($size['size'], 0, 1))) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="menu-card-bottom">

                <strong class="menu-price"><?= rupiah($menu['price']) ?></strong>

                <?php if (!$menu['is_available']): ?>
                    <span class="menu-missing">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= e($menu['unavailable_note']) ?>
                    </span>
                <?php endif; ?>

            </div>

        </div>

    </article>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="menu.css">

    <!-- Style chip size di kartu menu (boleh dipindah ke menu.css) -->
    <style>
        .menu-sizes {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 6px 0;
        }

        .size-chip {
            min-width: 26px;
            padding: 2px 8px;
            border: 1px solid rgba(0, 0, 0, .15);
            border-radius: 999px;
            background: rgba(0, 0, 0, .04);
            font-size: 11px;
            font-weight: 600;
            line-height: 1.5;
            text-align: center;
            cursor: default;
        }
    </style>
</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <img src="../assets/Group 194.png" alt="LÜMA Logo">
        </div>

        <nav class="sidebar-menu">

            <a href="dashboard.php" class="menu-item">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>

            <a href="order_receive.php" class="menu-item">
                <i class="fa-solid fa-receipt"></i>
                <span>Order Receive</span>
            </a>

            <a href="menu.php" class="menu-item active">
                <i class="fa-solid fa-mug-hot"></i>
                <span>Menu</span>
            </a>

        </nav>

        <a href="../logout.php" class="logout">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">

            <h2>Menu</h2>

            <div class="profile">
                <i class="fa-regular fa-bell notification-icon"></i>

                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>

        </header>

        <!-- ALL MENU -->
        <section class="menu-section">

            <div class="section-heading">
                <h2>All Menu</h2>
                <p>Browse by category</p>
            </div>

            <div class="menu-toolbar">

                <div class="filters">

                    <button type="button" class="filter-btn active" data-category="all">All</button>

                    <?php foreach ($categories as $category): ?>
                        <button
                            type="button"
                            class="filter-btn"
                            data-category="<?= (int)$category['category_id'] ?>"
                        ><?= e($category['category_name']) ?></button>
                    <?php endforeach; ?>

                </div>

                <div class="toolbar-right">

                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search menu...">
                    </div>

                    <button
                        type="button"
                        class="ingredient-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#ingredientModal"
                    >
                        <i class="fa-solid fa-box-open"></i>
                        Ingredient Stock

                        <?php if ($outCount > 0): ?>
                            <span class="out-badge"><?= $outCount ?></span>
                        <?php endif; ?>
                    </button>

                </div>

            </div>

            <div class="menu-grid" id="allMenuGrid">
                <?php foreach ($menus as $menu): ?>
                    <?= renderMenuCard($menu, $imageFolder) ?>
                <?php endforeach; ?>
            </div>

            <div class="empty-state hidden" id="noResult">
                <i class="fa-solid fa-mug-hot"></i>
                <strong>No menu found</strong>
                <span>Try another keyword or category.</span>
            </div>

        </section>

        <!-- POPULAR MENU -->
        <section class="menu-section">

            <div class="section-heading">
                <h2>Popular Menu</h2>
                <p>Customers' favorites based on total orders</p>
            </div>

            <div class="menu-grid">

                <?php if ($popularMenus): ?>

                    <?php foreach ($popularMenus as $menu): ?>
                        <?= renderMenuCard($menu, $imageFolder) ?>
                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state wide">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No popular menu yet</strong>
                        <span>Popular menu appears after orders come in.</span>
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- LUMA MOMENTS -->
        <section class="menu-section">

            <div class="section-heading">
                <h2>LÜMA Moments</h2>
                <p>Recommend the right drink based on what the customer needs</p>
            </div>

            <?php if ($moments): ?>

                <div class="moment-grid">

                    <?php $first = true; ?>

                    <?php foreach ($moments as $moment): ?>

                        <?php [$momentIcon, $momentDesc] = momentInfo($moment['moment_name']); ?>

                        <button
                            type="button"
                            class="moment-card <?= $first ? 'active' : '' ?>"
                            data-moment="<?= (int)$moment['moment_id'] ?>"
                        >
                            <div class="menu-icon">
                                <i class="fa-solid <?= e($momentIcon) ?>"></i>
                            </div>

                            <div>
                                <strong><?= e($moment['moment_name']) ?></strong>
                                <small><?= e($momentDesc) ?></small>
                            </div>
                        </button>

                        <?php $first = false; ?>

                    <?php endforeach; ?>

                </div>

                <?php $first = true; ?>

                <?php foreach ($moments as $moment): ?>

                    <?php
                    $ids = $momentMenuIds[(int)$moment['moment_id']] ?? [];

                    $momentMenus = array_filter(
                        $menus,
                        fn($menu) => in_array((int)$menu['menu_id'], $ids, true)
                    );
                    ?>

                    <div
                        class="moment-panel <?= $first ? '' : 'hidden' ?>"
                        data-moment="<?= (int)$moment['moment_id'] ?>"
                    >
                        <div class="menu-grid">

                            <?php if ($momentMenus): ?>

                                <?php foreach ($momentMenus as $menu): ?>
                                    <?= renderMenuCard($menu, $imageFolder) ?>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="empty-state wide">
                                    <i class="fa-solid fa-mug-hot"></i>
                                    <strong>No recommendations yet</strong>
                                    <span>No menu is recommended for this moment.</span>
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>

                    <?php $first = false; ?>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-state">
                    <i class="fa-solid fa-mug-hot"></i>
                    <strong>No moments yet</strong>
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>


<!-- =====================================================
     MODAL INGREDIENT STOCK
====================================================== -->
<div class="modal fade" id="ingredientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Ingredient Stock</h5>
                    <small>Stock 0 makes every menu using it unavailable</small>
                </div>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body detail-modal-body">

                <?php if ($ingredients): ?>

                    <?php foreach ($ingredients as $ingredient): ?>

                        <?php
                        $stock   = (float)$ingredient['stock'];
                        $minimum = (float)$ingredient['minimum_stock'];

                        if ($stock <= 0) {
                            $stockClass = 'status-out';
                            $stockLabel = 'Out of Stock';
                        } elseif ($stock <= $minimum) {
                            $stockClass = 'status-low';
                            $stockLabel = 'Low';
                        } else {
                            $stockClass = 'status-completed';
                            $stockLabel = 'In Stock';
                        }
                        ?>

                        <div class="stock-item">

                            <div class="stock-info">
                                <strong><?= e($ingredient['ingredient_name']) ?></strong>

                                <small>
                                    Min <?= e(formatQty($minimum)) ?> <?= e($ingredient['unit']) ?>
                                    ·
                                    Used in <?= (int)$ingredient['used_in'] ?> menu
                                </small>
                            </div>

                            <span class="status-badge <?= $stockClass ?>"><?= $stockLabel ?></span>

                            <form method="POST" action="menu.php" class="stock-form">

                                <input type="hidden" name="ingredient_id" value="<?= (int)$ingredient['ingredient_id'] ?>">

                                <input
                                    type="number"
                                    name="stock"
                                    class="stock-input"
                                    value="<?= e(formatQty($stock)) ?>"
                                    min="0"
                                    step="0.01"
                                    required
                                    aria-label="Stock <?= e($ingredient['ingredient_name']) ?>"
                                >

                                <span class="stock-unit"><?= e($ingredient['unit']) ?></span>

                                <button type="submit" name="update_stock" value="1" class="stock-btn">Save</button>

                            </form>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p class="muted">No ingredients recorded yet.</p>

                <?php endif; ?>

            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>


<!-- =====================================================
     MODAL DETAIL MENU
====================================================== -->
<div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title" id="menuModalTitle"></h5>
                    <small id="menuModalCategory"></small>
                </div>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body detail-modal-body menu-modal-body">

                <div class="menu-image menu-modal-image" id="menuModalImage"></div>

                <p class="menu-modal-desc" id="menuModalDesc"></p>

                <div class="menu-modal-alert hidden" id="menuModalAlert"></div>

                <!-- SIZE -->
                <div class="menu-modal-group" id="menuSizeGroup">
                    <span class="menu-modal-label">Size</span>
                    <div class="size-options" id="menuSizeOptions"></div>
                </div>

                <!-- QUANTITY -->
                <div class="menu-modal-group">

                    <span class="menu-modal-label">Quantity</span>

                    <div class="qty-stepper">

                        <button type="button" class="qty-btn" id="qtyMinus" aria-label="Decrease quantity">
                            <i class="fa-solid fa-minus"></i>
                        </button>

                        <input
                            type="number"
                            class="qty-input"
                            id="qtyInput"
                            value="1"
                            min="1"
                            max="99"
                            aria-label="Quantity"
                        >

                        <button type="button" class="qty-btn" id="qtyPlus" aria-label="Increase quantity">
                            <i class="fa-solid fa-plus"></i>
                        </button>

                    </div>

                </div>

                <!-- TOTAL (menampilkan size + jumlah yang dipilih) -->
                <div class="menu-modal-total">
                    <span id="menuModalTotalLabel">Total</span>
                    <strong id="menuModalTotal"></strong>
                </div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>

// ==================================================
// LUMA MOMENTS
// ==================================================
document.querySelectorAll('.moment-card').forEach(function (card) {

    card.addEventListener('click', function () {

        document.querySelectorAll('.moment-card').forEach(function (item) {
            item.classList.toggle('active', item === card);
        });

        document.querySelectorAll('.moment-panel').forEach(function (panel) {
            panel.classList.toggle('hidden', panel.dataset.moment !== card.dataset.moment);
        });

    });

});


// ==================================================
// FILTER KATEGORI + SEARCH
// ==================================================
var activeCategory = 'all';

function applyMenuFilter() {

    var keyword = document.getElementById('searchInput').value.trim().toLowerCase();
    var shown = 0;

    document.querySelectorAll('#allMenuGrid .menu-card').forEach(function (card) {

        var matchCategory = activeCategory === 'all' || card.dataset.category === activeCategory;
        var matchName = card.dataset.name.indexOf(keyword) !== -1;
        var visible = matchCategory && matchName;

        card.classList.toggle('hidden', !visible);

        if (visible) {
            shown++;
        }

    });

    document.getElementById('noResult').classList.toggle('hidden', shown > 0);

}

document.querySelectorAll('.filter-btn').forEach(function (button) {

    button.addEventListener('click', function () {

        document.querySelectorAll('.filter-btn').forEach(function (item) {
            item.classList.toggle('active', item === button);
        });

        activeCategory = button.dataset.category;
        applyMenuFilter();

    });

});

document.getElementById('searchInput').addEventListener('input', applyMenuFilter);


// ==================================================
// POPUP DETAIL MENU
// ==================================================
var menuData = <?= json_encode(
    $menuModalData,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
) ?>;

var menuModal = new bootstrap.Modal(document.getElementById('menuModal'));

var currentMenu = null;
var selectedSize = 0;
var quantity = 1;

function formatRupiah(number) {

    return 'Rp ' + Math.round(number)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

}

function currentPrice() {

    if (currentMenu.sizes.length > 0) {
        return currentMenu.sizes[selectedSize].price;
    }

    return currentMenu.price;

}

function renderTotal() {

    var label = 'Total';

    if (currentMenu.sizes.length > 0) {
        label += ' · ' + currentMenu.sizes[selectedSize].size;
    }

    label += ' × ' + quantity;

    document.getElementById('qtyInput').value = quantity;
    document.getElementById('menuModalTotalLabel').textContent = label;
    document.getElementById('menuModalTotal').textContent = formatRupiah(currentPrice() * quantity);

}

function renderSizes() {

    var group = document.getElementById('menuSizeGroup');
    var options = document.getElementById('menuSizeOptions');

    options.innerHTML = '';

    if (currentMenu.sizes.length === 0) {
        group.classList.add('hidden');
        return;
    }

    group.classList.remove('hidden');

    currentMenu.sizes.forEach(function (item, index) {

        var button = document.createElement('button');
        var name = document.createElement('strong');
        var price = document.createElement('small');

        button.type = 'button';
        button.className = 'size-btn' + (index === selectedSize ? ' active' : '');
        button.disabled = !currentMenu.available;

        name.textContent = item.size;
        price.textContent = formatRupiah(item.price);

        button.appendChild(name);
        button.appendChild(price);

        button.addEventListener('click', function () {
            selectedSize = index;
            renderSizes();
            renderTotal();
        });

        options.appendChild(button);

    });

}

function openMenuModal(menu) {

    currentMenu = menu;
    quantity = 1;
    selectedSize = 0;

    // Default size = Medium kalau ada
    menu.sizes.forEach(function (item, index) {
        if (String(item.size).toLowerCase() === 'medium') {
            selectedSize = index;
        }
    });

    document.getElementById('menuModalTitle').textContent = menu.name;
    document.getElementById('menuModalCategory').textContent = menu.category;
    document.getElementById('menuModalDesc').textContent = menu.description;

    // Gambar (fallback ke icon kategori)
    var imageBox = document.getElementById('menuModalImage');
    var icon = document.createElement('i');

    imageBox.innerHTML = '';

    icon.className = 'fa-solid ' + menu.icon;
    imageBox.appendChild(icon);

    if (menu.image) {

        var image = document.createElement('img');

        image.src = menu.image;
        image.alt = menu.name;
        image.onerror = function () { image.remove(); };

        imageBox.appendChild(image);

    }

    // Menu tidak tersedia
    var alertBox = document.getElementById('menuModalAlert');

    if (menu.available) {
        alertBox.classList.add('hidden');
    } else {
        alertBox.textContent = 'Unavailable: ' + menu.note;
        alertBox.classList.remove('hidden');
    }

    ['qtyMinus', 'qtyPlus', 'qtyInput'].forEach(function (id) {
        document.getElementById(id).disabled = !menu.available;
    });

    renderSizes();
    renderTotal();

    menuModal.show();

}

// Klik card menu (card ada di beberapa section)
document.addEventListener('click', function (event) {

    var card = event.target.closest('.menu-card');

    if (card && menuData[card.dataset.menuId]) {
        openMenuModal(menuData[card.dataset.menuId]);
    }

});

document.addEventListener('keydown', function (event) {

    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    var card = event.target.closest ? event.target.closest('.menu-card') : null;

    if (card && menuData[card.dataset.menuId]) {
        event.preventDefault();
        openMenuModal(menuData[card.dataset.menuId]);
    }

});

// Quantity
document.getElementById('qtyMinus').addEventListener('click', function () {
    if (quantity > 1) {
        quantity--;
        renderTotal();
    }
});

document.getElementById('qtyPlus').addEventListener('click', function () {
    if (quantity < 99) {
        quantity++;
        renderTotal();
    }
});

document.getElementById('qtyInput').addEventListener('change', function () {

    var value = parseInt(this.value, 10);

    quantity = isNaN(value) || value < 1 ? 1 : Math.min(value, 99);

    renderTotal();

});


// ==================================================
// BUKA LAGI MODAL SETELAH UPDATE STOCK
// ==================================================
if (new URLSearchParams(window.location.search).get('open') === 'ingredients') {

    new bootstrap.Modal(document.getElementById('ingredientModal')).show();

    history.replaceState(null, '', 'menu.php');

}

</script>

</body>
</html>