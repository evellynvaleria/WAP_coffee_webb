<?php
session_start();

require_once "../config/database.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// LOGIN & ROLE
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}

$userName = $_SESSION['name'] ?? 'Staff';

// SEARCH MEMBER
if (isset($_GET['lookup_member'])) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $phone = preg_replace('/\D/', '', (string)$_GET['lookup_member']);

        $stmt = $conn->prepare("
            SELECT u.user_id, u.name
            FROM users u
            INNER JOIN customer c ON c.user_id = u.user_id
            WHERE u.phone = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        echo json_encode(
            $member
                ? ['found' => true, 'user_id' => (int)$member['user_id'], 'name' => $member['name']]
                : ['found' => false]
        );
    } catch (Throwable $err) {
        echo json_encode(['found' => false, 'error' => $err->getMessage()]);
    }

    exit;
}

// NILAI ENUM DI TABEL orders
function enumValue(mysqli $conn, string $table, string $column, string $wanted): string
{
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    $row    = $result ? $result->fetch_assoc() : null;

    if (!$row || stripos((string)$row['Type'], 'enum(') !== 0) {
        return $wanted;
    }

    preg_match_all("/'((?:[^']|'')*)'/", $row['Type'], $matches);

    $normalize = fn($v) => strtolower(preg_replace('/\s+/', '', (string)$v));
    $options   = array_map(fn($v) => str_replace("''", "'", $v), $matches[1]);

    foreach ($options as $option) {
        if ($normalize($option) === $normalize($wanted)) {
            return $option;
        }
    }

    throw new Exception(
        "Nilai '$wanted' tidak ada di kolom $table.$column (pilihan: " . implode(', ', $options) . ")."
    );
}

// AKUN WALK-IN
function guestUserId(mysqli $conn): int
{
    $email = 'walkin@luma.local';

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['user_id'];
    }

    $name     = 'Walk-in';
    $password = bin2hex(random_bytes(8));
    $phone    = '-';

    $stmt = $conn->prepare("
        INSERT INTO users (name, email, password, phone, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("ssss", $name, $email, $password, $phone);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    return (int)$id;
}

// DAFTARKAN MEMBER BARU 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_member'])) {

    header('Content-Type: application/json; charset=utf-8');

    $started = false;

    try {
        $name    = trim((string)($_POST['name'] ?? ''));
        $phone   = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        $gender  = (string)($_POST['gender'] ?? '');
        $dob     = trim((string)($_POST['date_of_birth'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $email   = strtolower(trim((string)($_POST['email'] ?? '')));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new Exception('Nama wajib diisi.');
        }

        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw new Exception('No. HP tidak valid.');
        }

        if (!in_array($gender, ['L', 'P'], true)) {
            throw new Exception('Pilih jenis kelamin.');
        }

        $dobDate = DateTime::createFromFormat('Y-m-d', $dob);

        if (!$dobDate || $dobDate->format('Y-m-d') !== $dob || $dobDate > new DateTime('today')) {
            throw new Exception('Tanggal lahir tidak valid.');
        }

        if ($address === '') {
            $address = '-';
        }

        if ($email === '') {
            $email = 'member-' . $phone . '@luma.local';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Format email tidak valid.');
        }

        // No. HP / email tidak boleh sudah terdaftar
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE phone = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $phone, $email);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            throw new Exception('No. HP atau email sudah terdaftar. Coba klik Cari.');
        }

        // Password acak: member dari kasir belum perlu login
        $password = bin2hex(random_bytes(8));

        $conn->begin_transaction();
        $started = true;

        $stmt = $conn->prepare("
            INSERT INTO users (name, email, password, phone, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("ssss", $name, $email, $password, $phone);
        $stmt->execute();
        $newUserId = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO customer (user_id, gender, address, date_of_birth)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $newUserId, $gender, $address, $dob);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        echo json_encode(['ok' => true, 'user_id' => (int)$newUserId, 'name' => $name]);

    } catch (Throwable $err) {

        if ($started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }
        }

        echo json_encode(['ok' => false, 'message' => $err->getMessage()]);
    }

    exit;
}

// FOLDER GAMBAR MENU
$imageFolder = "../assets/menu/";

// ATURAN INGREDIENT -> MENU
$ingredientRules = [
    'Coffee Beans'  => ['categories' => [1]],
    'Caramel Sauce' => ['keywords' => ['Caramel']],
    'Vanilla Syrup' => ['keywords' => ['Vanilla']],
    'Whipped Cream' => ['keywords' => ['Frappe', 'Cookies & Cream']],
    'Ice Cubes'     => ['keywords' => ['Frappe', 'Smoothie', 'Soda', 'Blue Ocean']],
    'Lemon'         => ['keywords' => ['Lemon', 'Soda']]
];

$rulesByName = array_change_key_case($ingredientRules, CASE_LOWER);

// HELPER FUNCTIONS
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

// DESKRIPSI MENU
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

// UPDATE STOCK INGREDIENT
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

// KATEGORI
$categories = [];

$resultCategories = $conn->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_id ASC
");

while ($row = $resultCategories->fetch_assoc()) {
    $categories[] = $row;
}

// INGREDIENT
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

// MENU
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

// JUMLAH MENU YANG MEMAKAI TIAP INGREDIENT
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

// SIZE (Small / Medium / Large) dari tabel menu_details
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

// Data untuk kartu menu, popup detail, dan validasi pesanan
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

// SIMPAN PESANAN 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {

    header('Content-Type: application/json; charset=utf-8');

    $started = false;

    try {
        $cart      = json_decode($_POST['cart'] ?? '[]', true);
        $orderType = $_POST['order_type'] ?? '';
        $payment   = $_POST['payment_method'] ?? '';
        $memberId  = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT) ?: null;
        $cashReceived = filter_input(INPUT_POST, 'cash_received', FILTER_VALIDATE_FLOAT);

        if (!is_array($cart) || !$cart) {
            throw new Exception('Pesanan masih kosong.');
        }

        if (!in_array($orderType, ['Dine in', 'Take away'], true)) {
            throw new Exception('Tipe pesanan tidak valid.');
        }

        if (!in_array($payment, ['QRIS', 'Cash', 'Debit'], true)) {
            throw new Exception('Pilih metode pembayaran.');
        }

        $lines = [];
        $total = 0.0;

        foreach ($cart as $item) {

            $menuId = (int)($item['menu_id'] ?? 0);
            $qty    = (int)($item['qty'] ?? 0);
            $size   = (string)($item['size'] ?? '');

            if (!isset($menuModalData[$menuId]) || $qty < 1 || $qty > 99) {
                throw new Exception('Ada item yang tidak valid.');
            }

            $m = $menuModalData[$menuId];

            if (!$m['available']) {
                throw new Exception($m['name'] . ' sedang tidak tersedia.');
            }

            $price = (float)$m['price'];

            foreach ($m['sizes'] as $s) {
                if (strcasecmp($s['size'], $size) === 0) {
                    $price = (float)$s['price'];
                }
            }

            $subtotal = $price * $qty;
            $total   += $subtotal;
            $lines[]  = [$menuId, $qty, $price, $subtotal];
        }

        // Cash: uang diterima harus cukup
        $change = 0.0;

        if ($payment === 'Cash') {
            if ($cashReceived === false || $cashReceived === null || $cashReceived < $total) {
                throw new Exception('Uang diterima kurang dari total.');
            }

            $change = $cashReceived - $total;
        }

        // orders.user_id wajib: member terpilih, atau akun Walk-in
        if ($memberId !== null) {
            $stmt = $conn->prepare("SELECT user_id FROM customer WHERE user_id = ? LIMIT 1");
            $stmt->bind_param("i", $memberId);
            $stmt->execute();
            $isMember = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$isMember) {
                throw new Exception('Member tidak valid, cari ulang member.');
            }
        }

        $orderUserId = $memberId ?? guestUserId($conn);

        // Pesanan dari kasir sudah dibayar -> status 'Success'
        $dbStatus  = enumValue($conn, 'orders', 'status', 'Success');
        $dbType    = enumValue($conn, 'orders', 'order_type', $orderType === 'Take away' ? 'Take Away' : 'Dine In');
        $dbPayment = enumValue($conn, 'orders', 'payment_method', $payment);

        $conn->begin_transaction();
        $started = true;

        $stmt = $conn->prepare("
            INSERT INTO orders (user_id, order_date, total_amount, status, order_type, payment_method)
            VALUES (?, NOW(), ?, ?, ?, ?)
        ");
        $stmt->bind_param("idsss", $orderUserId, $total, $dbStatus, $dbType, $dbPayment);
        $stmt->execute();
        $orderId = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO order_details (order_id, menu_id, quantity, price, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($lines as [$menuId, $qty, $price, $subtotal]) {
            $stmt->bind_param("iiidd", $orderId, $menuId, $qty, $price, $subtotal);
            $stmt->execute();
        }

        $stmt->close();
        $conn->commit();

        echo json_encode(['ok' => true, 'order_id' => $orderId, 'total' => $total, 'change' => $change]);

    } catch (Throwable $err) {

        if ($started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }
        }

        echo json_encode(['ok' => false, 'message' => $err->getMessage()]);
    }

    exit;
}

// POPULAR MENU
$popularMenus = array_filter($menus, function ($menu) {
    return (int)$menu['total_sold'] > 0;
});

usort($popularMenus, function ($a, $b) {
    return (int)$b['total_sold'] <=> (int)$a['total_sold'];
});

$popularMenus = array_slice($popularMenus, 0, 4);

// MOMENTS + REKOMENDASI
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

// KARTU MENU (menampilkan chip size S / M / L)
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

        <div class="menu-columns">

            <!-- KOLOM KIRI: POPULAR + ALL MENU -->
            <div class="menu-left">

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

                <!-- ALL MENU -->
                <section class="menu-section">

                    <div class="section-heading">
                        <h2>All Menu</h2>
                        <p>Browse by category or pick a LÜMA Moment</p>
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

                    <!-- LUMA MOMENTS (filter alternatif) -->
                    <?php if ($moments): ?>

                        <div class="moment-label">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <strong>LÜMA Moments</strong>
                            <span>Recommend the right drink based on what the customer needs</span>
                        </div>

                        <div class="moment-grid">

                            <?php foreach ($moments as $moment): ?>

                                <?php [$momentIcon, $momentDesc] = momentInfo($moment['moment_name']); ?>

                                <button
                                    type="button"
                                    class="moment-card"
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

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <div class="menu-grid" id="allMenuGrid">
                        <?php foreach ($menus as $menu): ?>
                            <?= renderMenuCard($menu, $imageFolder) ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="empty-state hidden" id="noResult">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No menu found</strong>
                        <span>Try another keyword, category, or moment.</span>
                    </div>

                </section>

            </div>

            <!-- KOLOM KANAN: CARD PESANAN -->
            <aside class="order-card">

                <h3 class="order-title">Pesanan</h3>

                <div class="order-type">
                    <button type="button" class="order-type-btn active" data-type="Dine in">Dine in</button>
                    <button type="button" class="order-type-btn" data-type="Take away">Take away</button>
                </div>

                <input type="text" id="customerName" class="order-input" placeholder="Nama pelanggan (opsional)">

                <div class="order-items" id="orderItems"></div>
                <p class="order-empty" id="orderEmpty">Belum ada item</p>

                <div class="member-row">
                    <input type="text" id="memberPhone" class="order-input" placeholder="No. HP member">
                    <button type="button" class="member-btn" id="memberSearch">Cari</button>
                </div>
                <small class="member-result" id="memberResult"></small>
                <button type="button" class="member-new-btn" id="newMemberBtn">
                    <i class="fa-solid fa-user-plus"></i> Daftarkan member baru
                </button>

                <div class="order-total">
                    <span>Total</span>
                    <strong id="orderTotal">Rp 0</strong>
                </div>

                <button type="button" class="pay-btn" id="payBtn" disabled>Payment</button>

            </aside>

        </div>

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
                <button type="button" class="close-btn close-btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="close-btn" id="addToOrder">Tambah ke Pesanan</button>
            </div>

        </div>
    </div>
</div>


<!-- =====================================================
     MODAL MEMBER BARU
====================================================== -->
<div class="modal fade" id="memberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Member Baru</h5>
                    <small>Data disimpan ke database pelanggan</small>
                </div>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body menu-modal-body">

                <div class="member-form">

                    <div>
                        <label class="menu-modal-label" for="newMemberName">Nama *</label>
                        <input type="text" class="order-input" id="newMemberName" maxlength="100" autocomplete="off">
                    </div>

                    <div>
                        <label class="menu-modal-label" for="newMemberPhone">No. HP *</label>
                        <input type="text" inputmode="numeric" class="order-input" id="newMemberPhone" autocomplete="off">
                    </div>

                    <div class="member-form-row">

                        <div>
                            <label class="menu-modal-label" for="newMemberGender">Jenis kelamin *</label>
                            <select class="order-input" id="newMemberGender">
                                <option value="">Pilih</option>
                                <option value="P">Perempuan</option>
                                <option value="L">Laki-laki</option>
                            </select>
                        </div>

                        <div>
                            <label class="menu-modal-label" for="newMemberDob">Tanggal lahir *</label>
                            <input type="date" class="order-input" id="newMemberDob">
                        </div>

                    </div>

                    <div>
                        <label class="menu-modal-label" for="newMemberAddress">Alamat (opsional)</label>
                        <input type="text" class="order-input" id="newMemberAddress" autocomplete="off">
                    </div>

                    <div>
                        <label class="menu-modal-label" for="newMemberEmail">Email (opsional)</label>
                        <input type="email" class="order-input" id="newMemberEmail" autocomplete="off">
                    </div>

                </div>

                <div class="menu-modal-alert pay-error hidden" id="memberError"></div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn close-btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="close-btn" id="memberSave">Simpan Member</button>
            </div>

        </div>
    </div>
</div>


<!-- MODAL PAYMENT-->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Payment</h5>
                    <small id="payModalSummary"></small>
                </div>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body menu-modal-body">

                <div class="menu-modal-total pay-total">
                    <span>Total</span>
                    <strong id="payModalTotal"></strong>
                </div>

                <span class="menu-modal-label">Metode pembayaran</span>

                <div class="size-options" id="payMethods">
                    <button type="button" class="size-btn" data-method="Cash"><strong>Cash</strong></button>
                    <button type="button" class="size-btn" data-method="QRIS"><strong>QRIS</strong></button>
                    <button type="button" class="size-btn" data-method="Debit"><strong>Debit</strong></button>
                </div>

                <!-- KHUSUS CASH -->
                <div class="cash-section hidden" id="cashSection">

                    <input
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        class="order-input"
                        id="cashReceived"
                        placeholder="Uang diterima"
                    >

                    <div class="cash-quick">
                        <button type="button" class="cash-quick-btn" data-cash="exact">Pas</button>
                        <button type="button" class="cash-quick-btn" data-cash="50000">50rb</button>
                        <button type="button" class="cash-quick-btn" data-cash="100000">100rb</button>
                    </div>

                    <div class="cash-change">
                        <span>Kembalian</span>
                        <strong id="cashChange">-</strong>
                    </div>

                </div>

                <div class="menu-modal-alert pay-error hidden" id="payError"></div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn close-btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="close-btn" id="payConfirm" disabled>Confirm</button>
            </div>

        </div>
    </div>
</div>


<!-- MODAL STRUK -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Struk</h5>
                    <small>Pembayaran berhasil</small>
                </div>
            </div>

            <div class="modal-body menu-modal-body receipt-body">
                <div class="receipt-paper" id="receiptPaper"></div>
            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn close-btn-light" id="printReceipt">
                    <i class="fa-solid fa-print"></i> Print
                </button>
                <button type="button" class="close-btn" data-bs-dismiss="modal">New Order</button>
            </div>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>

// FILTER: KATEGORI ATAU LUMA MOMENT (+ SEARCH)
var momentMenus = <?= json_encode($momentMenuIds) ?>;

var activeCategory = 'all';
var activeMoment = null;

function syncFilterUI() {

    document.querySelectorAll('.filter-btn').forEach(function (item) {
        item.classList.toggle(
            'active',
            activeMoment === null && item.dataset.category === String(activeCategory)
        );
    });

    document.querySelectorAll('.moment-card').forEach(function (item) {
        item.classList.toggle('active', item.dataset.moment === activeMoment);
    });

}

function applyMenuFilter() {

    var keyword = document.getElementById('searchInput').value.trim().toLowerCase();
    var momentIds = activeMoment !== null ? (momentMenus[activeMoment] || []) : null;
    var shown = 0;

    document.querySelectorAll('#allMenuGrid .menu-card').forEach(function (card) {

        var matchGroup;

        if (momentIds !== null) {
            matchGroup = momentIds.indexOf(parseInt(card.dataset.menuId, 10)) !== -1;
        } else {
            matchGroup = activeCategory === 'all' || card.dataset.category === String(activeCategory);
        }

        var matchName = card.dataset.name.indexOf(keyword) !== -1;
        var visible = matchGroup && matchName;

        card.classList.toggle('hidden', !visible);

        if (visible) {
            shown++;
        }

    });

    document.getElementById('noResult').classList.toggle('hidden', shown > 0);

}

document.querySelectorAll('.filter-btn').forEach(function (button) {

    button.addEventListener('click', function () {

        activeMoment = null;
        activeCategory = button.dataset.category;

        syncFilterUI();
        applyMenuFilter();

    });

});

document.querySelectorAll('.moment-card').forEach(function (card) {

    card.addEventListener('click', function () {

        if (activeMoment === card.dataset.moment) {
            // klik lagi = matikan moment, kembali ke All
            activeMoment = null;
            activeCategory = 'all';
        } else {
            activeMoment = card.dataset.moment;
        }

        syncFilterUI();
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

    ['qtyMinus', 'qtyPlus', 'qtyInput', 'addToOrder'].forEach(function (id) {
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

// CARD PESANAN
var cart = [];
var orderType = 'Dine in';
var memberId = null;
var memberName = '';
var payMethod = '';

var payModal = new bootstrap.Modal(document.getElementById('payModal'));

function cartTotal() {

    return cart.reduce(function (sum, item) {
        return sum + item.price * item.qty;
    }, 0);

}

function makeQtyButton(text, label, handler) {

    var button = document.createElement('button');

    button.type = 'button';
    button.textContent = text;
    button.setAttribute('aria-label', label);
    button.addEventListener('click', handler);

    return button;

}

function renderCart() {

    var list = document.getElementById('orderItems');

    list.innerHTML = '';

    cart.forEach(function (item, index) {

        var row = document.createElement('div');
        var info = document.createElement('div');
        var name = document.createElement('strong');
        var meta = document.createElement('small');
        var control = document.createElement('div');
        var qty = document.createElement('span');

        row.className = 'order-item';
        info.className = 'order-item-info';
        control.className = 'order-item-qty';

        name.textContent = item.name;
        meta.textContent = (item.size ? item.size + ' · ' : '') + formatRupiah(item.price);
        qty.textContent = item.qty;

        info.appendChild(name);
        info.appendChild(meta);

        control.appendChild(makeQtyButton('−', 'Kurangi', function () {
            if (item.qty > 1) {
                item.qty--;
            } else {
                cart.splice(index, 1);
            }
            renderCart();
        }));

        control.appendChild(qty);

        control.appendChild(makeQtyButton('+', 'Tambah', function () {
            if (item.qty < 99) {
                item.qty++;
                renderCart();
            }
        }));

        row.appendChild(info);
        row.appendChild(control);
        list.appendChild(row);

    });

    document.getElementById('orderEmpty').classList.toggle('hidden', cart.length > 0);
    document.getElementById('orderTotal').textContent = formatRupiah(cartTotal());
    document.getElementById('payBtn').disabled = cart.length === 0;

}

// Tambah item dari popup menu
document.getElementById('addToOrder').addEventListener('click', function () {

    if (!currentMenu || !currentMenu.available) {
        return;
    }

    var size = currentMenu.sizes.length > 0 ? currentMenu.sizes[selectedSize].size : '';

    var existing = cart.find(function (item) {
        return item.menu_id === currentMenu.id && item.size === size;
    });

    if (existing) {
        existing.qty = Math.min(existing.qty + quantity, 99);
    } else {
        cart.push({
            menu_id: currentMenu.id,
            name: currentMenu.name,
            size: size,
            price: currentPrice(),
            qty: quantity
        });
    }

    renderCart();
    menuModal.hide();

});

// Dine in / Take away
document.querySelectorAll('.order-type-btn').forEach(function (button) {

    button.addEventListener('click', function () {

        orderType = button.dataset.type;

        document.querySelectorAll('.order-type-btn').forEach(function (item) {
            item.classList.toggle('active', item === button);
        });

    });

});

// Cari member
function setMemberResult(text, cls) {

    var box = document.getElementById('memberResult');

    box.textContent = text;
    box.className = 'member-result ' + (cls || '');

}

document.getElementById('memberPhone').addEventListener('input', function () {
    memberId = null;
    memberName = '';
    setMemberResult('');
});

document.getElementById('memberSearch').addEventListener('click', function () {

    var phone = document.getElementById('memberPhone').value.trim();

    if (!phone) {
        return;
    }

    fetch('menu.php?lookup_member=' + encodeURIComponent(phone))
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (data.found) {
                memberId = data.user_id;
                memberName = data.name;
                setMemberResult('Member: ' + data.name, 'ok');
            } else {
                memberId = null;
                setMemberResult('Member tidak ditemukan', 'error');
            }

        })
        .catch(function () {
            setMemberResult('Gagal mencari member', 'error');
        });

});

// Member baru
var memberModal = new bootstrap.Modal(document.getElementById('memberModal'));

document.getElementById('newMemberBtn').addEventListener('click', function () {

    ['newMemberName', 'newMemberGender', 'newMemberDob', 'newMemberAddress', 'newMemberEmail'].forEach(function (id) {
        document.getElementById(id).value = '';
    });

    document.getElementById('newMemberPhone').value = document.getElementById('memberPhone').value.replace(/\D/g, '');
    document.getElementById('memberError').classList.add('hidden');

    memberModal.show();

});

document.getElementById('memberSave').addEventListener('click', function () {

    var saveBtn = this;
    var errorBox = document.getElementById('memberError');
    var phone = document.getElementById('newMemberPhone').value.replace(/\D/g, '');
    var form = new FormData();

    form.append('register_member', '1');
    form.append('name', document.getElementById('newMemberName').value);
    form.append('phone', phone);
    form.append('gender', document.getElementById('newMemberGender').value);
    form.append('date_of_birth', document.getElementById('newMemberDob').value);
    form.append('address', document.getElementById('newMemberAddress').value);
    form.append('email', document.getElementById('newMemberEmail').value);

    saveBtn.disabled = true;
    errorBox.classList.add('hidden');

    fetch('menu.php', { method: 'POST', body: form })
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (!data.ok) {
                errorBox.textContent = data.message || 'Member gagal disimpan.';
                errorBox.classList.remove('hidden');
                return;
            }

            memberId = data.user_id;
            memberName = data.name;

            document.getElementById('memberPhone').value = phone;
            setMemberResult('Member baru: ' + data.name, 'ok');

            memberModal.hide();

        })
        .catch(function () {
            errorBox.textContent = 'Koneksi gagal, coba lagi.';
            errorBox.classList.remove('hidden');
        })
        .finally(function () {
            saveBtn.disabled = false;
        });

});

// Payment
var payModalEl = document.getElementById('payModal');
var receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));

var payTotal = 0;
var cashReceived = 0;
var pendingReceipt = null;

function updatePayState() {

    var isCash = payMethod === 'Cash';
    var diff = cashReceived - payTotal;
    var changeBox = document.getElementById('cashChange');

    document.getElementById('cashSection').classList.toggle('hidden', !isCash);

    if (isCash) {

        if (cashReceived === 0) {
            changeBox.textContent = '-';
        } else if (diff < 0) {
            changeBox.textContent = 'Kurang ' + formatRupiah(-diff);
        } else {
            changeBox.textContent = formatRupiah(diff);
        }

        changeBox.classList.toggle('short', cashReceived > 0 && diff < 0);

    }

    document.getElementById('payConfirm').disabled =
        !(payMethod !== '' && (!isCash || cashReceived >= payTotal));

}

function setCashReceived(value) {

    cashReceived = value;

    document.getElementById('cashReceived').value =
        value > 0 ? String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';

    updatePayState();

}

function selectPayMethod(method) {

    payMethod = method;

    document.querySelectorAll('#payMethods .size-btn').forEach(function (item) {
        item.classList.toggle('active', item.dataset.method === method);
    });

    if (method !== 'Cash') {
        setCashReceived(0);
    }

    updatePayState();

}

document.getElementById('payBtn').addEventListener('click', function () {

    payTotal = cartTotal();

    document.getElementById('payError').classList.add('hidden');
    document.getElementById('payModalSummary').textContent = orderType + ' · ' + cart.length + ' item';
    document.getElementById('payModalTotal').textContent = formatRupiah(payTotal);

    selectPayMethod('Cash');
    setCashReceived(0);

    payModal.show();

});

document.querySelectorAll('#payMethods .size-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        selectPayMethod(button.dataset.method);
    });
});

document.getElementById('cashReceived').addEventListener('input', function () {
    setCashReceived(parseInt(this.value.replace(/\D/g, ''), 10) || 0);
});

document.querySelectorAll('.cash-quick-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        setCashReceived(button.dataset.cash === 'exact' ? payTotal : parseInt(button.dataset.cash, 10));
    });
});

document.getElementById('payConfirm').addEventListener('click', function () {

    var confirmBtn = this;
    var errorBox = document.getElementById('payError');

    if (!payMethod) {
        errorBox.textContent = 'Pilih metode pembayaran dulu.';
        errorBox.classList.remove('hidden');
        return;
    }

    if (payMethod === 'Cash' && cashReceived < payTotal) {
        errorBox.textContent = 'Uang diterima kurang dari total.';
        errorBox.classList.remove('hidden');
        return;
    }

    // Salinan pesanan untuk struk (cart dikosongkan setelah sukses)
    var snapshot = cart.map(function (item) {
        return { name: item.name, size: item.size, price: item.price, qty: item.qty };
    });

    var customer = document.getElementById('customerName').value.trim() || memberName || '-';
    var method = payMethod;
    var received = cashReceived;
    var form = new FormData();

    form.append('checkout', '1');
    form.append('order_type', orderType);
    form.append('payment_method', method);
    form.append('member_id', memberId || '');
    form.append('cash_received', method === 'Cash' ? received : '');
    form.append('cart', JSON.stringify(cart.map(function (item) {
        return { menu_id: item.menu_id, size: item.size, qty: item.qty };
    })));

    confirmBtn.disabled = true;
    errorBox.classList.add('hidden');

    fetch('menu.php', { method: 'POST', body: form })
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (!data.ok) {
                errorBox.textContent = data.message || 'Pesanan gagal disimpan.';
                errorBox.classList.remove('hidden');
                return;
            }

            pendingReceipt = {
                id: data.order_id,
                type: orderType,
                customer: customer,
                items: snapshot,
                total: data.total,
                method: method,
                received: received,
                change: data.change,
                date: new Date()
            };

            cart = [];
            memberId = null;
            memberName = '';

            document.getElementById('customerName').value = '';
            document.getElementById('memberPhone').value = '';
            setMemberResult('');
            renderCart();

            payModal.hide();

        })
        .catch(function () {
            errorBox.textContent = 'Koneksi gagal, coba lagi.';
            errorBox.classList.remove('hidden');
        })
        .finally(function () {
            updatePayState();
        });

});

// Struk muncul setelah modal payment tertutup
payModalEl.addEventListener('hidden.bs.modal', function () {

    if (pendingReceipt) {
        var receipt = pendingReceipt;
        pendingReceipt = null;
        showReceipt(receipt);
    }

});

function receiptRow(left, right, bold) {

    var row = document.createElement('div');
    var a = document.createElement('span');
    var b = document.createElement('span');

    row.className = 'receipt-row' + (bold ? ' bold' : '');
    a.textContent = left;
    b.textContent = right;

    row.appendChild(a);
    row.appendChild(b);

    return row;

}

function receiptLine(text, className) {

    var line = document.createElement('div');

    line.className = className || 'receipt-line';
    line.textContent = text;

    return line;

}

function receiptDash() {

    var dash = document.createElement('div');

    dash.className = 'receipt-dash';

    return dash;

}

function showReceipt(r) {

    var paper = document.getElementById('receiptPaper');

    paper.innerHTML = '';

    paper.appendChild(receiptLine('LÜMA', 'receipt-brand'));
    paper.appendChild(receiptLine('Order #' + r.id + ' · ' + r.type));
    paper.appendChild(receiptLine(r.date.toLocaleString('id-ID')));
    paper.appendChild(receiptLine('Pelanggan: ' + r.customer));
    paper.appendChild(receiptDash());

    r.items.forEach(function (item) {

        var label = item.name;

        if (item.size) {
            label += ' ' + item.size.charAt(0).toUpperCase();
        }

        label += ' ×' + item.qty;

        paper.appendChild(receiptRow(label, formatRupiah(item.price * item.qty)));

    });

    paper.appendChild(receiptDash());
    paper.appendChild(receiptRow('Total', formatRupiah(r.total), true));

    if (r.method === 'Cash') {
        paper.appendChild(receiptRow('Cash', formatRupiah(r.received)));
        paper.appendChild(receiptRow('Kembali', formatRupiah(r.change)));
    } else {
        paper.appendChild(receiptRow('Bayar', r.method));
    }

    paper.appendChild(receiptDash());
    paper.appendChild(receiptLine('Terima kasih ☕', 'receipt-thanks'));

    receiptModal.show();

}

document.getElementById('printReceipt').addEventListener('click', function () {
    window.print();
});

renderCart();

// BUKA LAGI MODAL SETELAH UPDATE STOCK
if (new URLSearchParams(window.location.search).get('open') === 'ingredients') {

    new bootstrap.Modal(document.getElementById('ingredientModal')).show();

    history.replaceState(null, '', 'menu.php');

}

</script>

</body>
</html>