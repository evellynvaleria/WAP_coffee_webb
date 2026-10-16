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

// Keranjang & data kasir disimpan di SESSION (dulu di variabel JavaScript)
$_SESSION['cart']       = $_SESSION['cart'] ?? [];
$_SESSION['order_type'] = $_SESSION['order_type'] ?? 'Dine in';

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

// Gambar hanya dipakai kalau filenya benar-benar ada
// (pengganti onerror="this.remove()" yang dulu memakai JavaScript)
function menuImage(string $folder, $image): string
{
    $image = (string)$image;

    return ($image !== '' && is_file(__DIR__ . '/' . $folder . $image)) ? $folder . $image : '';
}

// STATE HALAMAN (kategori, moment, pencarian) dibawa lewat URL
function readState(array $src): array
{
    $cat    = (string)($src['cat'] ?? 'all');
    $moment = filter_var($src['moment'] ?? null, FILTER_VALIDATE_INT);

    return [
        'cat'    => ($cat !== 'all' && ctype_digit($cat)) ? $cat : 'all',
        'moment' => ($moment && $moment > 0) ? $moment : 0,
        'q'      => mb_substr(trim((string)($src['q'] ?? '')), 0, 100)
    ];
}

function buildUrl(array $state, array $extra = [], string $fragment = ''): string
{
    $params = $state;

    if ($params['cat'] === 'all') unset($params['cat']);
    if (empty($params['moment'])) unset($params['moment']);
    if ($params['q'] === '')      unset($params['q']);

    foreach ($extra as $key => $value) {
        if ($value !== null && $value !== '' && $value !== 0) {
            $params[$key] = $value;
        }
    }

    return 'menu.php' . ($params ? '?' . http_build_query($params) : '') . ($fragment !== '' ? '#' . $fragment : '');
}

function stateInputs(array $state): string
{
    $html = '';

    foreach (['cat', 'moment', 'q'] as $key) {
        if (!empty($state[$key]) && $state[$key] !== 'all') {
            $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($state[$key]) . '">';
        }
    }

    return $html;
}

function cartTotal(array $cart): float
{
    $total = 0.0;

    foreach ($cart as $item) {
        $total += (float)$item['price'] * (int)$item['qty'];
    }

    return $total;
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

    header('Location: menu.php?panel=ingredients');
    exit;
}

// SELESAI PESANAN: tutup struk, mulai pesanan baru
if (isset($_GET['new_order'])) {
    unset($_SESSION['receipt']);
    header('Location: menu.php');
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
        'image'       => menuImage($imageFolder, $menu['image']),
        'icon'        => categoryIcon($menu['category_name']),
        'price'       => (float)$menu['price'],
        'available'   => $menu['is_available'],
        'note'        => $menu['is_available'] ? '' : $menu['unavailable_note'],
        'sizes'       => $sizes
    ];
}

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

// ==================================================
// PROSES FORM (POST biasa, lalu redirect balik)
// ==================================================
$panelError = '';
$memberOld  = [];
$panel      = (string)($_GET['panel'] ?? ($_GET['open'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $form      = (string)($_POST['form'] ?? '');
    $backState = readState($_POST);

    // ---- KARTU PESANAN: tipe, nama, member, qty, ke payment ----
    if ($form === 'order') {

        $_SESSION['customer_name'] = mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 100);

        $phone = preg_replace('/\D/', '', (string)($_POST['member_phone'] ?? ''));

        // No. HP berubah -> member yang terpilih dilepas
        if ($phone !== ($_SESSION['member_phone'] ?? '')) {
            unset($_SESSION['member_id'], $_SESSION['member_name'], $_SESSION['member_msg']);
        }

        $_SESSION['member_phone'] = $phone;

        $act   = (string)($_POST['act'] ?? 'save');
        $extra = [];

        if ($act === 'type_dine') {
            $_SESSION['order_type'] = 'Dine in';

        } elseif ($act === 'type_take') {
            $_SESSION['order_type'] = 'Take away';

        } elseif (preg_match('/^cart:(inc|dec):(\d+)$/', $act, $m)) {

            $i = (int)$m[2];

            if (isset($_SESSION['cart'][$i])) {
                if ($m[1] === 'inc') {
                    if ($_SESSION['cart'][$i]['qty'] < 99) {
                        $_SESSION['cart'][$i]['qty']++;
                    }
                } elseif ($_SESSION['cart'][$i]['qty'] > 1) {
                    $_SESSION['cart'][$i]['qty']--;
                } else {
                    array_splice($_SESSION['cart'], $i, 1);
                }
            }

            $_SESSION['cart'] = array_values($_SESSION['cart']);

        } elseif ($act === 'lookup') {

            if ($phone !== '') {
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

                if ($member) {
                    $_SESSION['member_id']   = (int)$member['user_id'];
                    $_SESSION['member_name'] = $member['name'];
                    $_SESSION['member_msg']  = ['text' => 'Member: ' . $member['name'], 'cls' => 'ok'];
                } else {
                    unset($_SESSION['member_id'], $_SESSION['member_name']);
                    $_SESSION['member_msg'] = ['text' => 'Member tidak ditemukan', 'cls' => 'error'];
                }
            }

        } elseif ($act === 'new_member') {
            $extra = ['panel' => 'member'];

        } elseif ($act === 'pay' && $_SESSION['cart']) {
            $extra = ['panel' => 'pay'];
        }

        header('Location: ' . buildUrl($backState, $extra, $extra ? '' : 'order-card'));
        exit;
    }

    // ---- TAMBAH ITEM KE PESANAN (dari popup detail menu) ----
    if ($form === 'add') {

        $menuId = (int)($_POST['menu_id'] ?? 0);
        $qty    = (int)($_POST['qty'] ?? 1);
        $size   = (string)($_POST['size'] ?? '');

        try {
            if (!isset($menuModalData[$menuId])) {
                throw new Exception('Menu tidak ditemukan.');
            }

            $m = $menuModalData[$menuId];

            if (!$m['available']) {
                throw new Exception($m['name'] . ' sedang tidak tersedia.');
            }

            $qty   = max(1, min(99, $qty));
            $price = (float)$m['price'];

            if ($m['sizes']) {
                $found = false;

                foreach ($m['sizes'] as $s) {
                    if (strcasecmp($s['size'], $size) === 0) {
                        $price = (float)$s['price'];
                        $size  = $s['size'];
                        $found = true;
                    }
                }

                if (!$found) {
                    throw new Exception('Pilih size dulu.');
                }
            } else {
                $size = '';
            }

            $merged = false;

            foreach ($_SESSION['cart'] as $i => $line) {
                if ($line['menu_id'] === $menuId && $line['size'] === $size) {
                    $_SESSION['cart'][$i]['qty'] = min($line['qty'] + $qty, 99);
                    $merged = true;
                    break;
                }
            }

            if (!$merged) {
                $_SESSION['cart'][] = [
                    'menu_id' => $menuId,
                    'name'    => $m['name'],
                    'size'    => $size,
                    'price'   => $price,
                    'qty'     => $qty
                ];
            }

            header('Location: ' . buildUrl($backState, [], 'order-card'));
            exit;

        } catch (Throwable $err) {
            $_SESSION['flash'] = $err->getMessage();
            header('Location: ' . buildUrl($backState, ['item' => $menuId]));
            exit;
        }
    }

    // ---- DAFTARKAN MEMBER BARU ----
    if ($form === 'member') {

        $started   = false;
        $memberOld = $_POST;
        $panel     = 'member';

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

            $_SESSION['member_id']    = (int)$newUserId;
            $_SESSION['member_name']  = $name;
            $_SESSION['member_phone'] = $phone;
            $_SESSION['member_msg']   = ['text' => 'Member baru: ' . $name, 'cls' => 'ok'];

            header('Location: ' . buildUrl($backState, [], 'order-card'));
            exit;

        } catch (Throwable $err) {

            if ($started) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignore) {
                }
            }

            $panelError = $err->getMessage();
        }
    }

    // ---- CHECKOUT: SIMPAN PESANAN ----
    if ($form === 'checkout') {

        $started = false;
        $panel   = 'pay';

        try {
            $cart      = $_SESSION['cart'];
            $orderType = (string)$_SESSION['order_type'];
            $payment   = (string)($_POST['payment_method'] ?? '');
            $memberId  = $_SESSION['member_id'] ?? null;

            if (!$cart) {
                throw new Exception('Pesanan masih kosong.');
            }

            if (!in_array($orderType, ['Dine in', 'Take away'], true)) {
                throw new Exception('Tipe pesanan tidak valid.');
            }

            if (!in_array($payment, ['QRIS', 'Cash', 'Debit'], true)) {
                throw new Exception('Pilih metode pembayaran.');
            }

            $lines    = [];
            $snapshot = [];
            $total    = 0.0;

            // Harga selalu dihitung ulang dari database, bukan dari keranjang
            foreach ($cart as $item) {

                $menuId = (int)($item['menu_id'] ?? 0);
                $qty    = (int)($item['qty'] ?? 0);
                $size   = (string)($item['size'] ?? '');

                if (!isset($menuModalData[$menuId]) || $qty < 1 || $qty > 99) {
                    throw new Exception('Ada item yang tidak valid.');
                }

                $m = $menuModalData[$menuId];

                if (!$m['available']) {
                    throw new Exception($m['name'] . ' sedang tidak tersedia. Hapus dari pesanan dulu.');
                }

                $price = (float)$m['price'];

                foreach ($m['sizes'] as $s) {
                    if (strcasecmp($s['size'], $size) === 0) {
                        $price = (float)$s['price'];
                    }
                }

                $subtotal   = $price * $qty;
                $total     += $subtotal;
                $lines[]    = [$menuId, $qty, $price, $subtotal];
                $snapshot[] = ['name' => $m['name'], 'size' => $size, 'price' => $price, 'qty' => $qty];
            }

            // Cash: uang diterima harus cukup
            $change   = 0.0;
            $received = 0.0;

            if ($payment === 'Cash') {

                $received = isset($_POST['exact'])
                    ? $total
                    : (float)preg_replace('/\D/', '', (string)($_POST['cash_received'] ?? ''));

                if ($received < $total) {
                    throw new Exception('Uang diterima kurang dari total (' . rupiah($total) . ').');
                }

                $change = $received - $total;
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

            // Data struk disimpan di session, lalu keranjang dikosongkan
            $_SESSION['receipt'] = [
                'id'       => $orderId,
                'type'     => $orderType,
                'customer' => ($_SESSION['customer_name'] ?? '') !== ''
                    ? $_SESSION['customer_name']
                    : ($_SESSION['member_name'] ?? '-'),
                'items'    => $snapshot,
                'total'    => $total,
                'method'   => $payment,
                'received' => $received,
                'change'   => $change,
                'date'     => date('d/m/Y H:i:s')
            ];

            $_SESSION['cart'] = [];
            unset(
                $_SESSION['member_id'],
                $_SESSION['member_name'],
                $_SESSION['member_phone'],
                $_SESSION['member_msg'],
                $_SESSION['customer_name']
            );

            header('Location: ' . buildUrl($backState, ['panel' => 'receipt']));
            exit;

        } catch (Throwable $err) {

            if ($started) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignore) {
                }
            }

            $panelError = $err->getMessage();
        }
    }
}

// ==================================================
// DATA UNTUK TAMPILAN
// ==================================================
$state  = ($_SERVER['REQUEST_METHOD'] === 'POST') ? readState($_POST) : readState($_GET);
$itemId = filter_var($_GET['item'] ?? null, FILTER_VALIDATE_INT) ?: 0;

$cart      = $_SESSION['cart'];
$cartSum   = cartTotal($cart);
$orderType = $_SESSION['order_type'];
$receipt   = $_SESSION['receipt'] ?? null;
$memberMsg = $_SESSION['member_msg'] ?? null;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!in_array($panel, ['ingredients', 'member', 'pay', 'receipt'], true)) {
    $panel = '';
}

if ($panel === 'pay' && !$cart)       $panel = '';
if ($panel === 'receipt' && !$receipt) $panel = '';

// Popup detail menu hanya tampil kalau tidak ada popup lain
$modalMenu = ($panel === '' && $itemId && isset($menuModalData[$itemId])) ? $menuModalData[$itemId] : null;

// POPULAR MENU
$popularMenus = array_filter($menus, function ($menu) {
    return (int)$menu['total_sold'] > 0;
});

usort($popularMenus, function ($a, $b) {
    return (int)$b['total_sold'] <=> (int)$a['total_sold'];
});

$popularMenus = array_slice($popularMenus, 0, 4);

// ALL MENU: filter kategori / moment + pencarian (dulu dikerjakan JavaScript)
$momentIds = $state['moment'] ? ($momentMenuIds[$state['moment']] ?? []) : null;
$keyword   = $state['q'];

$filteredMenus = array_filter($menus, function ($menu) use ($state, $momentIds, $keyword) {

    if ($momentIds !== null) {
        $matchGroup = in_array((int)$menu['menu_id'], $momentIds, true);
    } else {
        $matchGroup = $state['cat'] === 'all' || (string)$menu['category_id'] === $state['cat'];
    }

    $matchName = $keyword === '' || mb_stripos($menu['menu_name'], $keyword) !== false;

    return $matchGroup && $matchName;
});

// KARTU MENU (sekarang berupa link ke popup detail)
function renderMenuCard(array $menu, array $state, array $menuModalData): string
{
    $data  = $menuModalData[(int)$menu['menu_id']] ?? [];
    $icon  = categoryIcon($menu['category_name']);
    $sizes = $data['sizes'] ?? [];
    $image = $data['image'] ?? '';

    ob_start();
    ?>
    <a
        href="<?= e(buildUrl($state, ['item' => (int)$menu['menu_id']])) ?>"
        class="menu-card <?= $menu['is_available'] ? '' : 'unavailable' ?>"
    >

        <div class="menu-image">

            <i class="fa-solid <?= e($icon) ?>"></i>

            <?php if ($image !== ''): ?>
                <img src="<?= e($image) ?>" alt="<?= e($menu['menu_name']) ?>" loading="lazy">
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

    </a>
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

    <style>
        /* Tombol/kartu sekarang berupa link atau label, bukan button */
        a.menu-card { display: block; color: inherit; text-decoration: none; }
        a.filter-btn { display: inline-flex; align-items: center; text-decoration: none; }
        a.moment-card { color: inherit; text-decoration: none; }
        a.close-btn { display: inline-block; text-align: center; text-decoration: none; }
        a.ingredient-btn, a.see-all { text-decoration: none; }

        /* Pilihan size / metode bayar tanpa JavaScript (radio) */
        .size-pick { position: relative; display: inline-block; }
        .size-pick input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .size-pick input:checked + .size-btn { outline: 2px solid #6f4e37; outline-offset: -2px; font-weight: 700; }
        .size-pick input:disabled + .size-btn { opacity: .5; }

        /* Popup berbasis CSS saja (Bootstrap modal tanpa Bootstrap JS) */
        .modal.show.d-block { overflow-y: auto; }
        .order-card form { margin: 0; }
        .hidden-submit { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
        .order-item-qty button { cursor: pointer; }
        .search-box button { border: 0; background: none; padding: 0; color: inherit; cursor: pointer; }
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

        <?php if ($flash): ?>
            <div class="alert alert-danger m-3" role="alert"><?= e($flash) ?></div>
        <?php endif; ?>

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
                                <?= renderMenuCard($menu, $state, $menuModalData) ?>
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
                <section class="menu-section" id="all-menu">

                    <div class="section-heading">
                        <h2>All Menu</h2>
                        <p>Browse by category or pick a LÜMA Moment</p>
                    </div>

                    <div class="menu-toolbar">

                        <!-- FILTER KATEGORI -->
                        <div class="filters">

                            <a
                                href="<?= e(buildUrl(array_merge($state, ['cat' => 'all', 'moment' => 0]), [], 'all-menu')) ?>"
                                class="filter-btn <?= ($state['moment'] === 0 && $state['cat'] === 'all') ? 'active' : '' ?>"
                            >All</a>

                            <?php foreach ($categories as $category): ?>
                                <a
                                    href="<?= e(buildUrl(array_merge($state, ['cat' => (string)$category['category_id'], 'moment' => 0]), [], 'all-menu')) ?>"
                                    class="filter-btn <?= ($state['moment'] === 0 && $state['cat'] === (string)$category['category_id']) ? 'active' : '' ?>"
                                ><?= e($category['category_name']) ?></a>
                            <?php endforeach; ?>

                        </div>

                        <div class="toolbar-right">

                            <!-- PENCARIAN -->
                            <form method="get" action="menu.php#all-menu" class="search-box">
                                <?php if ($state['cat'] !== 'all'): ?><input type="hidden" name="cat" value="<?= e($state['cat']) ?>"><?php endif; ?>
                                <?php if ($state['moment']): ?><input type="hidden" name="moment" value="<?= (int)$state['moment'] ?>"><?php endif; ?>
                                <button type="submit" aria-label="Cari"><i class="fa-solid fa-magnifying-glass"></i></button>
                                <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Search menu...">
                            </form>

                            <a href="<?= e(buildUrl($state, ['panel' => 'ingredients'])) ?>" class="ingredient-btn">
                                <i class="fa-solid fa-box-open"></i>
                                Ingredient Stock

                                <?php if ($outCount > 0): ?>
                                    <span class="out-badge"><?= $outCount ?></span>
                                <?php endif; ?>
                            </a>

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

                                <?php
                                [$momentIcon, $momentDesc] = momentInfo($moment['moment_name']);
                                $isActiveMoment = $state['moment'] === (int)$moment['moment_id'];
                                // Klik lagi = matikan moment, kembali ke All
                                $momentLink = $isActiveMoment
                                    ? buildUrl(array_merge($state, ['cat' => 'all', 'moment' => 0]), [], 'all-menu')
                                    : buildUrl(array_merge($state, ['moment' => (int)$moment['moment_id']]), [], 'all-menu');
                                ?>

                                <a href="<?= e($momentLink) ?>" class="moment-card <?= $isActiveMoment ? 'active' : '' ?>">
                                    <div class="menu-icon">
                                        <i class="fa-solid <?= e($momentIcon) ?>"></i>
                                    </div>

                                    <div>
                                        <strong><?= e($moment['moment_name']) ?></strong>
                                        <small><?= e($momentDesc) ?></small>
                                    </div>
                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <div class="menu-grid" id="allMenuGrid">
                        <?php foreach ($filteredMenus as $menu): ?>
                            <?= renderMenuCard($menu, $state, $menuModalData) ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="empty-state<?= $filteredMenus ? ' hidden' : '' ?>">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No menu found</strong>
                        <span>Try another keyword, category, or moment.</span>
                    </div>

                </section>

            </div>

            <!-- KOLOM KANAN: CARD PESANAN -->
            <aside class="order-card" id="order-card">

                <h3 class="order-title">Pesanan</h3>

                <form method="post" action="menu.php">

                    <input type="hidden" name="form" value="order">
                    <?= stateInputs($state) ?>

                    <!-- Tombol default saat menekan Enter di kolom teks -->
                    <button type="submit" name="act" value="save" class="hidden-submit" tabindex="-1" aria-hidden="true">Simpan</button>

                    <div class="order-type">
                        <button type="submit" name="act" value="type_dine" class="order-type-btn <?= $orderType === 'Dine in' ? 'active' : '' ?>">Dine in</button>
                        <button type="submit" name="act" value="type_take" class="order-type-btn <?= $orderType === 'Take away' ? 'active' : '' ?>">Take away</button>
                    </div>

                    <input
                        type="text"
                        name="customer_name"
                        class="order-input"
                        maxlength="100"
                        value="<?= e($_SESSION['customer_name'] ?? '') ?>"
                        placeholder="Nama pelanggan (opsional)"
                    >

                    <div class="order-items">

                        <?php foreach ($cart as $index => $item): ?>

                            <div class="order-item">

                                <div class="order-item-info">
                                    <strong><?= e($item['name']) ?></strong>
                                    <small><?= e(($item['size'] !== '' ? $item['size'] . ' · ' : '') . rupiah($item['price'])) ?></small>
                                </div>

                                <div class="order-item-qty">
                                    <button type="submit" name="act" value="cart:dec:<?= (int)$index ?>" aria-label="Kurangi">−</button>
                                    <span><?= (int)$item['qty'] ?></span>
                                    <button type="submit" name="act" value="cart:inc:<?= (int)$index ?>" aria-label="Tambah">+</button>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <p class="order-empty<?= $cart ? ' hidden' : '' ?>">Belum ada item</p>

                    <div class="member-row">
                        <input
                            type="text"
                            name="member_phone"
                            class="order-input"
                            inputmode="numeric"
                            value="<?= e($_SESSION['member_phone'] ?? '') ?>"
                            placeholder="No. HP member"
                        >
                        <button type="submit" name="act" value="lookup" class="member-btn">Cari</button>
                    </div>

                    <small class="member-result <?= e($memberMsg['cls'] ?? '') ?>"><?= e($memberMsg['text'] ?? '') ?></small>

                    <button type="submit" name="act" value="new_member" class="member-new-btn">
                        <i class="fa-solid fa-user-plus"></i> Daftarkan member baru
                    </button>

                    <div class="order-total">
                        <span>Total</span>
                        <strong><?= rupiah($cartSum) ?></strong>
                    </div>

                    <button type="submit" name="act" value="pay" class="pay-btn" <?= $cart ? '' : 'disabled' ?>>Payment</button>

                </form>

            </aside>

        </div>

    </main>

</div>


<?php
// ==================================================
// POPUP (modal Bootstrap, dibuka lewat parameter URL, tanpa JavaScript)
// ==================================================
$closeUrl = buildUrl($state);
?>

<?php if ($panel === 'ingredients'): ?>

<!-- MODAL INGREDIENT STOCK -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Ingredient Stock</h5>
                    <small>Stock 0 makes every menu using it unavailable</small>
                </div>

                <a href="<?= e($closeUrl) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
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
                <a href="<?= e($closeUrl) ?>" class="close-btn">Close</a>
            </div>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($modalMenu): ?>

<!-- MODAL DETAIL MENU -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <form method="post" action="menu.php">

                <input type="hidden" name="form" value="add">
                <input type="hidden" name="menu_id" value="<?= (int)$modalMenu['id'] ?>">
                <?= stateInputs($state) ?>

                <div class="modal-header detail-modal-header">
                    <div>
                        <h5 class="modal-title"><?= e($modalMenu['name']) ?></h5>
                        <small><?= e($modalMenu['category']) ?></small>
                    </div>

                    <a href="<?= e($closeUrl) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
                </div>

                <div class="modal-body detail-modal-body menu-modal-body">

                    <div class="menu-image menu-modal-image">
                        <i class="fa-solid <?= e($modalMenu['icon']) ?>"></i>

                        <?php if ($modalMenu['image'] !== ''): ?>
                            <img src="<?= e($modalMenu['image']) ?>" alt="<?= e($modalMenu['name']) ?>">
                        <?php endif; ?>
                    </div>

                    <p class="menu-modal-desc"><?= e($modalMenu['description']) ?></p>

                    <?php if (!$modalMenu['available']): ?>
                        <div class="menu-modal-alert">Unavailable: <?= e($modalMenu['note']) ?></div>
                    <?php endif; ?>

                    <!-- SIZE -->
                    <?php if ($modalMenu['sizes']): ?>
                        <div class="menu-modal-group">
                            <span class="menu-modal-label">Size</span>

                            <div class="size-options">
                                <?php foreach ($modalMenu['sizes'] as $sizeItem): ?>
                                    <label class="size-pick">
                                        <input
                                            type="radio"
                                            name="size"
                                            value="<?= e($sizeItem['size']) ?>"
                                            <?= strtolower($sizeItem['size']) === 'medium' ? 'checked' : '' ?>
                                            <?= $modalMenu['available'] ? '' : 'disabled' ?>
                                        >
                                        <span class="size-btn">
                                            <strong><?= e($sizeItem['size']) ?></strong>
                                            <small><?= e(rupiah($sizeItem['price'])) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- QUANTITY -->
                    <div class="menu-modal-group">

                        <span class="menu-modal-label">Quantity</span>

                        <div class="qty-stepper">
                            <input
                                type="number"
                                name="qty"
                                class="qty-input"
                                value="1"
                                min="1"
                                max="99"
                                aria-label="Quantity"
                                <?= $modalMenu['available'] ? '' : 'disabled' ?>
                            >
                        </div>

                    </div>

                </div>

                <div class="modal-footer detail-modal-footer">
                    <a href="<?= e($closeUrl) ?>" class="close-btn close-btn-light">Close</a>
                    <button type="submit" class="close-btn" <?= $modalMenu['available'] ? '' : 'disabled' ?>>Tambah ke Pesanan</button>
                </div>

            </form>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($panel === 'member'): ?>

<!-- MODAL MEMBER BARU -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <form method="post" action="menu.php">

                <input type="hidden" name="form" value="member">
                <?= stateInputs($state) ?>

                <div class="modal-header detail-modal-header">
                    <div>
                        <h5 class="modal-title">Member Baru</h5>
                        <small>Data disimpan ke database pelanggan</small>
                    </div>

                    <a href="<?= e($closeUrl) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
                </div>

                <div class="modal-body menu-modal-body">

                    <div class="member-form">

                        <div>
                            <label class="menu-modal-label" for="newMemberName">Nama *</label>
                            <input type="text" name="name" class="order-input" id="newMemberName" maxlength="100" autocomplete="off" value="<?= e($memberOld['name'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="menu-modal-label" for="newMemberPhone">No. HP *</label>
                            <input type="text" name="phone" inputmode="numeric" class="order-input" id="newMemberPhone" autocomplete="off" value="<?= e($memberOld['phone'] ?? ($_SESSION['member_phone'] ?? '')) ?>">
                        </div>

                        <div class="member-form-row">

                            <div>
                                <label class="menu-modal-label" for="newMemberGender">Jenis kelamin *</label>
                                <select name="gender" class="order-input" id="newMemberGender">
                                    <option value="">Pilih</option>
                                    <option value="P" <?= ($memberOld['gender'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                                    <option value="L" <?= ($memberOld['gender'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                </select>
                            </div>

                            <div>
                                <label class="menu-modal-label" for="newMemberDob">Tanggal lahir *</label>
                                <input type="date" name="date_of_birth" class="order-input" id="newMemberDob" value="<?= e($memberOld['date_of_birth'] ?? '') ?>">
                            </div>

                        </div>

                        <div>
                            <label class="menu-modal-label" for="newMemberAddress">Alamat (opsional)</label>
                            <input type="text" name="address" class="order-input" id="newMemberAddress" autocomplete="off" value="<?= e($memberOld['address'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="menu-modal-label" for="newMemberEmail">Email (opsional)</label>
                            <input type="email" name="email" class="order-input" id="newMemberEmail" autocomplete="off" value="<?= e($memberOld['email'] ?? '') ?>">
                        </div>

                    </div>

                    <?php if ($panelError !== ''): ?>
                        <div class="menu-modal-alert pay-error"><?= e($panelError) ?></div>
                    <?php endif; ?>

                </div>

                <div class="modal-footer detail-modal-footer">
                    <a href="<?= e($closeUrl) ?>" class="close-btn close-btn-light">Batal</a>
                    <button type="submit" class="close-btn">Simpan Member</button>
                </div>

            </form>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($panel === 'pay'): ?>

<!-- MODAL PAYMENT -->
<?php $chosenMethod = (string)($_POST['payment_method'] ?? 'Cash'); ?>

<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <form method="post" action="menu.php">

                <input type="hidden" name="form" value="checkout">
                <?= stateInputs($state) ?>

                <div class="modal-header detail-modal-header">
                    <div>
                        <h5 class="modal-title">Payment</h5>
                        <small><?= e($orderType) ?> · <?= count($cart) ?> item</small>
                    </div>

                    <a href="<?= e($closeUrl) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
                </div>

                <div class="modal-body menu-modal-body">

                    <div class="menu-modal-total pay-total">
                        <span>Total</span>
                        <strong><?= e(rupiah($cartSum)) ?></strong>
                    </div>

                    <span class="menu-modal-label">Metode pembayaran</span>

                    <div class="size-options">
                        <?php foreach (['Cash', 'QRIS', 'Debit'] as $method): ?>
                            <label class="size-pick">
                                <input type="radio" name="payment_method" value="<?= e($method) ?>" <?= $chosenMethod === $method ? 'checked' : '' ?>>
                                <span class="size-btn"><strong><?= e($method) ?></strong></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- KHUSUS CASH -->
                    <div class="cash-section">

                        <input
                            type="number"
                            name="cash_received"
                            class="order-input"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            autocomplete="off"
                            value="<?= e($_POST['cash_received'] ?? '') ?>"
                            placeholder="Uang diterima (khusus Cash)"
                        >

                        <label class="cash-change" style="cursor:pointer">
                            <span>Uang pas</span>
                            <input type="checkbox" name="exact" value="1" <?= isset($_POST['exact']) ? 'checked' : '' ?>>
                        </label>

                    </div>

                    <?php if ($panelError !== ''): ?>
                        <div class="menu-modal-alert pay-error"><?= e($panelError) ?></div>
                    <?php endif; ?>

                </div>

                <div class="modal-footer detail-modal-footer">
                    <a href="<?= e($closeUrl) ?>" class="close-btn close-btn-light">Batal</a>
                    <button type="submit" class="close-btn">Confirm</button>
                </div>

            </form>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($panel === 'receipt'): ?>

<!-- MODAL STRUK -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Struk</h5>
                    <small>Pembayaran berhasil</small>
                </div>
            </div>

            <div class="modal-body menu-modal-body receipt-body">

                <div class="receipt-paper">

                    <div class="receipt-brand">LÜMA</div>
                    <div class="receipt-line">Order #<?= (int)$receipt['id'] ?> · <?= e($receipt['type']) ?></div>
                    <div class="receipt-line"><?= e($receipt['date']) ?></div>
                    <div class="receipt-line">Pelanggan: <?= e($receipt['customer']) ?></div>

                    <div class="receipt-dash"></div>

                    <?php foreach ($receipt['items'] as $item): ?>
                        <div class="receipt-row">
                            <span><?= e($item['name'] . ($item['size'] !== '' ? ' ' . strtoupper(substr($item['size'], 0, 1)) : '') . ' ×' . $item['qty']) ?></span>
                            <span><?= e(rupiah($item['price'] * $item['qty'])) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <div class="receipt-dash"></div>

                    <div class="receipt-row bold">
                        <span>Total</span>
                        <span><?= e(rupiah($receipt['total'])) ?></span>
                    </div>

                    <?php if ($receipt['method'] === 'Cash'): ?>
                        <div class="receipt-row"><span>Cash</span><span><?= e(rupiah($receipt['received'])) ?></span></div>
                        <div class="receipt-row"><span>Kembali</span><span><?= e(rupiah($receipt['change'])) ?></span></div>
                    <?php else: ?>
                        <div class="receipt-row"><span>Bayar</span><span><?= e($receipt['method']) ?></span></div>
                    <?php endif; ?>

                    <div class="receipt-dash"></div>

                    <div class="receipt-thanks">Terima kasih ☕</div>

                </div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <!-- Cetak struk: gunakan Ctrl+P / menu Print di browser -->
                <a href="menu.php?new_order=1" class="close-btn">New Order</a>
            </div>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>

</body>
</html>