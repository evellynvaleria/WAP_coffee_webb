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

// Cart & cashier data are kept in the SESSION
$_SESSION['cart']       = $_SESSION['cart'] ?? [];
$_SESSION['order_type'] = $_SESSION['order_type'] ?? 'Dine in';

// Points earned per menu item purchased by a member
const POINTS_PER_MENU = 10;

// HELPER FUNCTIONS
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

// 8.00 -> 8   |   2.50 -> 2.5
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

// Does this menu use an ingredient with the given rule?
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

// Only use an image if the file really exists
function menuImage(string $folder, $image): string
{
    $image = (string)$image;

    return ($image !== '' && is_file(__DIR__ . '/' . $folder . $image)) ? $folder . $image : '';
}

// PAGE STATE (category, moment, search) is carried in the URL
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

function cartQty(array $cart): int
{
    $qty = 0;

    foreach ($cart as $item) {
        $qty += (int)$item['qty'];
    }

    return $qty;
}

// ENUM VALUES IN THE orders TABLE
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
        "Value '$wanted' does not exist in column $table.$column (options: " . implode(', ', $options) . ")."
    );
}

// WALK-IN ACCOUNT
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

// MEMBER LOOKUP BY PHONE NUMBER
// Tolerant of formats: 08xx = 628xx = +628xx, and spaces/dashes stored in the database
function findMemberByPhone(mysqli $conn, string $phone): ?array
{
    $phone = preg_replace('/\D/', '', $phone);

    if ($phone === '') {
        return null;
    }

    $variants = [$phone];

    if (str_starts_with($phone, '0'))  $variants[] = '62' . substr($phone, 1);
    if (str_starts_with($phone, '62')) $variants[] = '0' . substr($phone, 2);

    $variants = array_values(array_unique($variants));
    $in       = implode(',', array_fill(0, count($variants), '?'));

    $stmt = $conn->prepare("
        SELECT u.user_id, u.name
        FROM users u
        INNER JOIN customer c ON c.user_id = u.user_id
        WHERE REPLACE(REPLACE(REPLACE(u.phone, '+', ''), '-', ''), ' ', '') IN ($in)
        LIMIT 1
    ");
    $stmt->bind_param(str_repeat('s', count($variants)), ...$variants);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// Select a member in the session (used by Search and by Payment)
function selectMember(array $member): void
{
    $_SESSION['member_id']   = (int)$member['user_id'];
    $_SESSION['member_name'] = $member['name'];
    $_SESSION['member_msg']  = ['text' => 'Member: ' . $member['name'], 'cls' => 'ok'];
}

// Add points to the rewards table (creates the row if the member has none yet)
function addRewardPoints(mysqli $conn, int $userId, int $points): void
{
    $stmt = $conn->prepare("SELECT 1 FROM rewards WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) {
        $stmt = $conn->prepare("UPDATE rewards SET points = points + ? WHERE user_id = ?");
    } else {
        $stmt = $conn->prepare("INSERT INTO rewards (points, user_id) VALUES (?, ?)");
    }

    $stmt->bind_param("ii", $points, $userId);
    $stmt->execute();
    $stmt->close();
}

// MENU IMAGE FOLDER
$imageFolder = "../assets/menu/";

// INGREDIENT -> MENU RULES
$ingredientRules = [
    'Coffee Beans'  => ['categories' => [1]],
    'Caramel Sauce' => ['keywords' => ['Caramel']],
    'Vanilla Syrup' => ['keywords' => ['Vanilla']],
    'Whipped Cream' => ['keywords' => ['Frappe', 'Cookies & Cream']],
    'Ice Cubes'     => ['keywords' => ['Frappe', 'Smoothie', 'Soda', 'Blue Ocean']],
    'Lemon'         => ['keywords' => ['Lemon', 'Soda']]
];

$rulesByName = array_change_key_case($ingredientRules, CASE_LOWER);

// MENU DESCRIPTIONS
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

// UPDATE INGREDIENT STOCK
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

// ORDER DONE: close the receipt, start a new order
if (isset($_GET['new_order'])) {
    unset($_SESSION['receipt']);
    header('Location: menu.php');
    exit;
}

// CATEGORIES
$categories = [];

$resultCategories = $conn->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_id ASC
");

while ($row = $resultCategories->fetch_assoc()) {
    $categories[] = $row;
}

// INGREDIENTS
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

// MENUS
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

    // Out-of-stock ingredients used by this menu
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

    // Cannot be ordered if manually set to Unavailable OR an ingredient is out
    $manualAvailable = strtolower((string)$row['status']) === 'available';
    $hasMissing      = !empty($row['missing_ingredients']);

    $row['is_available']     = $manualAvailable && !$hasMissing;
    $row['unavailable_note'] = $hasMissing ? $row['missing_ingredients'] : 'Marked unavailable';

    $menus[] = $row;
}

// NUMBER OF MENUS USING EACH INGREDIENT
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

// SIZES (Small / Medium / Large) from table menu_details
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

// Data for menu cards, detail popup, and order validation
$menuModalData = [];

foreach ($menus as $menu) {

    $menuId = (int)$menu['menu_id'];
    $sizes  = $menuSizes[$menuId] ?? [];

    // Drinks always have Small / Medium / Large.
    // Price comes from menu_details; falls back to the menu's base price.
    // Pastries have no size.
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

// MOMENTS + RECOMMENDATIONS
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
// FORM HANDLING (plain POST, then redirect back)
// ==================================================
$panelError = '';
$panel      = (string)($_GET['panel'] ?? ($_GET['open'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $form      = (string)($_POST['form'] ?? '');
    $backState = readState($_POST);

    // ---- ORDER CARD: type, name, member, qty, go to payment ----
    if ($form === 'order') {

        $_SESSION['customer_name'] = mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 100);

        $phone = preg_replace('/\D/', '', (string)($_POST['member_phone'] ?? ''));

        // Phone number changed -> unselect the chosen member
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
                $member = findMemberByPhone($conn, $phone);

                if ($member) {
                    selectMember($member);
                } else {
                    unset($_SESSION['member_id'], $_SESSION['member_name']);
                    $_SESSION['member_msg'] = ['text' => 'Member not found', 'cls' => 'error'];
                }
            }

        } elseif ($act === 'pay' && $_SESSION['cart']) {

            // The cashier typed a phone number but did not press Search:
            // find the member automatically so the order and points go to the right account
            if (empty($_SESSION['member_id']) && $phone !== '') {
                $member = findMemberByPhone($conn, $phone);

                if ($member) {
                    selectMember($member);
                } else {
                    $_SESSION['member_msg'] = ['text' => 'Member not found, order saved as walk-in', 'cls' => 'error'];
                }
            }

            $extra = ['panel' => 'pay'];
        }

        header('Location: ' . buildUrl($backState, $extra, $extra ? '' : 'order-card'));
        exit;
    }

    // ---- ADD ITEM TO ORDER (from the menu detail popup) ----
    if ($form === 'add') {

        $menuId = (int)($_POST['menu_id'] ?? 0);
        $qty    = (int)($_POST['qty'] ?? 1);
        $size   = (string)($_POST['size'] ?? '');

        try {
            if (!isset($menuModalData[$menuId])) {
                throw new Exception('Menu not found.');
            }

            $m = $menuModalData[$menuId];

            if (!$m['available']) {
                throw new Exception($m['name'] . ' is currently unavailable.');
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
                    throw new Exception('Please select a size first.');
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

    // ---- CHECKOUT PREVIEW: Exact / quick amount / Enter only recalculates the change ----
    if ($form === 'checkout' && ($_POST['pay_act'] ?? 'confirm') !== 'confirm') {
        $panel = 'pay';
    }

    // ---- CHECKOUT: SAVE ORDER (only when Confirm is pressed) ----
    if ($form === 'checkout' && ($_POST['pay_act'] ?? 'confirm') === 'confirm') {

        $started = false;
        $panel   = 'pay';

        try {
            $cart      = $_SESSION['cart'];
            $orderType = (string)$_SESSION['order_type'];
            $payment   = (string)($_POST['payment_method'] ?? '');
            $memberId  = $_SESSION['member_id'] ?? null;

            if (!$cart) {
                throw new Exception('The order is empty.');
            }

            if (!in_array($orderType, ['Dine in', 'Take away'], true)) {
                throw new Exception('Invalid order type.');
            }

            if (!in_array($payment, ['QRIS', 'Cash', 'Debit'], true)) {
                throw new Exception('Please select a payment method.');
            }

            // Safety net: a phone number is filled in but no member was selected
            if ($memberId === null && ($_SESSION['member_phone'] ?? '') !== '') {
                $found    = findMemberByPhone($conn, (string)$_SESSION['member_phone']);
                $memberId = $found ? (int)$found['user_id'] : null;
            }

            $lines    = [];
            $snapshot = [];
            $total    = 0.0;
            $totalQty = 0;

            // Prices are always recalculated from the database, not from the cart
            foreach ($cart as $item) {

                $menuId = (int)($item['menu_id'] ?? 0);
                $qty    = (int)($item['qty'] ?? 0);
                $size   = (string)($item['size'] ?? '');

                if (!isset($menuModalData[$menuId]) || $qty < 1 || $qty > 99) {
                    throw new Exception('There is an invalid item.');
                }

                $m = $menuModalData[$menuId];

                if (!$m['available']) {
                    throw new Exception($m['name'] . ' is currently unavailable. Remove it from the order first.');
                }

                $price = (float)$m['price'];

                foreach ($m['sizes'] as $s) {
                    if (strcasecmp($s['size'], $size) === 0) {
                        $price = (float)$s['price'];
                    }
                }

                $subtotal   = $price * $qty;
                $total     += $subtotal;
                $totalQty  += $qty;
                $lines[]    = [$menuId, $qty, $price, $subtotal];
                $snapshot[] = ['name' => $m['name'], 'size' => $size, 'price' => $price, 'qty' => $qty];
            }

            // Cash: the amount received must be enough
            $change   = 0.0;
            $received = 0.0;

            if ($payment === 'Cash') {

                $received = (float)preg_replace('/\D/', '', (string)($_POST['cash_received'] ?? ''));

                if ($received < $total) {
                    throw new Exception('Cash received is less than the total (' . rupiah($total) . ').');
                }

                $change = $received - $total;
            }

            // orders.user_id is required: the selected member, or the Walk-in account
            if ($memberId !== null) {
                $stmt = $conn->prepare("SELECT user_id FROM customer WHERE user_id = ? LIMIT 1");
                $stmt->bind_param("i", $memberId);
                $stmt->execute();
                $isMember = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$isMember) {
                    throw new Exception('Invalid member, please search for the member again.');
                }
            }

            $orderUserId = $memberId ?? guestUserId($conn);

            // Cashier orders are already paid -> payment status 'Success'
            // order_status starts at 'Pending' so it shows up in Order Receive and in the customer's My Orders
            $dbStatus      = enumValue($conn, 'orders', 'status', 'Success');
            $dbOrderStatus = enumValue($conn, 'orders', 'order_status', 'Pending');
            $dbType        = enumValue($conn, 'orders', 'order_type', $orderType === 'Take away' ? 'Take Away' : 'Dine In');
            $dbPayment     = enumValue($conn, 'orders', 'payment_method', $payment);

            $conn->begin_transaction();
            $started = true;

            $stmt = $conn->prepare("
                INSERT INTO orders (user_id, order_date, total_amount, status, order_status, order_type, payment_method)
                VALUES (?, NOW(), ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("idssss", $orderUserId, $total, $dbStatus, $dbOrderStatus, $dbType, $dbPayment);
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

            // Members earn points for every menu item purchased
            $earnedPoints = 0;

            if ($memberId !== null) {
                $earnedPoints = $totalQty * POINTS_PER_MENU;
                addRewardPoints($conn, (int)$memberId, $earnedPoints);
            }

            $conn->commit();

            // Member name for the receipt (the member may have been found by the safety net)
            $memberName = $_SESSION['member_name'] ?? '';

            if ($memberId !== null && $memberName === '') {
                $stmt = $conn->prepare("SELECT name FROM users WHERE user_id = ? LIMIT 1");
                $stmt->bind_param("i", $memberId);
                $stmt->execute();
                $nameRow    = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $memberName = $nameRow['name'] ?? '';
            }

            // Receipt data is kept in the session, then the cart is emptied
            $_SESSION['receipt'] = [
                'id'       => $orderId,
                'type'     => $orderType,
                'customer' => ($_SESSION['customer_name'] ?? '') !== ''
                    ? $_SESSION['customer_name']
                    : ($memberName !== '' ? $memberName : '-'),
                'items'    => $snapshot,
                'total'    => $total,
                'method'   => $payment,
                'received' => $received,
                'change'   => $change,
                'points'   => $earnedPoints,
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
// DATA FOR THE VIEW
// ==================================================
$state  = ($_SERVER['REQUEST_METHOD'] === 'POST') ? readState($_POST) : readState($_GET);
$itemId = filter_var($_GET['item'] ?? null, FILTER_VALIDATE_INT) ?: 0;

$cart      = $_SESSION['cart'];
$cartSum   = cartTotal($cart);
$cartItems = cartQty($cart);
$orderType = $_SESSION['order_type'];
$receipt   = $_SESSION['receipt'] ?? null;
$memberMsg = $_SESSION['member_msg'] ?? null;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!in_array($panel, ['ingredients', 'pay', 'receipt'], true)) {
    $panel = '';
}

// CASH PREVIEW (payment popup): quick amounts, exact amount, live change
$quickAmounts = [50000, 100000];
$payAct       = (string)($_POST['pay_act'] ?? '');
$cashInput    = preg_replace('/\D/', '', (string)($_POST['cash_received'] ?? ''));

if ($payAct === 'exact') {
    $cashInput = (string)(int)round($cartSum);
} elseif (preg_match('/^quick:(\d+)$/', $payAct, $qm) && in_array((int)$qm[1], $quickAmounts, true)) {
    $cashInput = $qm[1];
}

$changePreview = $cashInput !== '' ? (float)$cashInput - $cartSum : null;

if ($panel === 'pay' && !$cart)        $panel = '';
if ($panel === 'receipt' && !$receipt) $panel = '';

// The menu detail popup only shows when no other popup is open
$modalMenu = ($panel === '' && $itemId && isset($menuModalData[$itemId])) ? $menuModalData[$itemId] : null;

// POPULAR MENU
$popularMenus = array_filter($menus, function ($menu) {
    return (int)$menu['total_sold'] > 0;
});

usort($popularMenus, function ($a, $b) {
    return (int)$b['total_sold'] <=> (int)$a['total_sold'];
});

$popularMenus = array_slice($popularMenus, 0, 4);

// ALL MENU: category / moment filter + search
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

// MENU CARD (a link to the detail popup)
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
        a.menu-card { display: block; color: inherit; text-decoration: none; }
        a.filter-btn { display: inline-flex; align-items: center; text-decoration: none; }
        a.moment-card { color: inherit; text-decoration: none; }
        a.close-btn { display: inline-block; text-align: center; text-decoration: none; }
        a.ingredient-btn, a.see-all { text-decoration: none; }

        /* Size / payment method choice without JavaScript (radio) */
        .size-pick { position: relative; display: inline-block; }
        .size-pick input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .size-pick input:checked + .size-btn { outline: 2px solid #6f4e37; outline-offset: -2px; font-weight: 700; }
        .size-pick input:disabled + .size-btn { opacity: .5; }

        /* CSS-only popups (Bootstrap modal without Bootstrap JS) */
        .modal.show.d-block { overflow-y: auto; }
        /* Order card: the <form> wraps everything, so it must carry the layout itself */
        .order-card form { margin: 0; display: flex; flex-direction: column; gap: 14px; }
        .order-card .order-type { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 0; }
        .order-card .order-type-btn { width: 100%; }
        .order-card .order-input { width: 100%; margin: 0; }
        .order-card .order-items { display: flex; flex-direction: column; gap: 10px; margin: 0; }
        .order-card .order-empty { margin: 0; }
        .order-card .member-row { display: flex; gap: 10px; margin: 0; }
        .order-card .member-row .order-input { flex: 1; min-width: 0; }
        .order-card .member-result { display: block; margin: -6px 0 0; min-height: 0; }
        .order-card .member-result:empty { display: none; }
        .order-card .order-total { display: flex; justify-content: space-between; align-items: center; margin: 4px 0 0; }
        .order-card .pay-btn { width: 100%; }
        .hidden-submit { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
        .order-item-qty button { cursor: pointer; }
        .search-box button { border: 0; background: none; padding: 0; color: inherit; cursor: pointer; }

        /* Cash: quick amounts + live change (no JavaScript) */
        .quick-cash { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0; }
        .quick-cash button { flex: 1; padding: 9px 12px; border: 1px solid #d9c9b8; border-radius: 10px; background: #fff; color: #6f4e37; font-weight: 600; cursor: pointer; }
        .quick-cash button:hover:not(:disabled) { background: #f6eee6; }
        .quick-cash button:disabled { opacity: .45; cursor: not-allowed; }
        .cash-result { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 10px 14px; border-radius: 10px; }
        .cash-result.enough { background: #eef6ee; color: #2f5d34; }
        .cash-result.short { background: #fdeeee; color: #a33a3a; }
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

            <!-- LEFT COLUMN: POPULAR + ALL MENU -->
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

                        <!-- CATEGORY FILTER -->
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

                            <!-- SEARCH -->
                            <form method="get" action="menu.php#all-menu" class="search-box">
                                <?php if ($state['cat'] !== 'all'): ?><input type="hidden" name="cat" value="<?= e($state['cat']) ?>"><?php endif; ?>
                                <?php if ($state['moment']): ?><input type="hidden" name="moment" value="<?= (int)$state['moment'] ?>"><?php endif; ?>
                                <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
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

                    <!-- LUMA MOMENTS (alternative filter) -->
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
                                // Click again = turn the moment off, back to All
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

            <!-- RIGHT COLUMN: ORDER CARD -->
            <aside class="order-card" id="order-card">

                <h3 class="order-title">Order</h3>

                <form method="post" action="menu.php">

                    <input type="hidden" name="form" value="order">
                    <?= stateInputs($state) ?>

                    <!-- Default button when pressing Enter in a text field -->
                    <button type="submit" name="act" value="save" class="hidden-submit" tabindex="-1" aria-hidden="true">Save</button>

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
                        placeholder="Customer name (optional)"
                    >

                    <div class="order-items">

                        <?php foreach ($cart as $index => $item): ?>

                            <div class="order-item">

                                <div class="order-item-info">
                                    <strong><?= e($item['name']) ?></strong>
                                    <small><?= e(($item['size'] !== '' ? $item['size'] . ' · ' : '') . rupiah($item['price'])) ?></small>
                                </div>

                                <div class="order-item-qty">
                                    <button type="submit" name="act" value="cart:dec:<?= (int)$index ?>" aria-label="Decrease">−</button>
                                    <span><?= (int)$item['qty'] ?></span>
                                    <button type="submit" name="act" value="cart:inc:<?= (int)$index ?>" aria-label="Increase">+</button>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <p class="order-empty<?= $cart ? ' hidden' : '' ?>">No items yet</p>

                    <div class="member-row">
                        <input
                            type="text"
                            name="member_phone"
                            class="order-input"
                            inputmode="numeric"
                            value="<?= e($_SESSION['member_phone'] ?? '') ?>"
                            placeholder="Member phone number"
                        >
                        <button type="submit" name="act" value="lookup" class="member-btn">Search</button>
                    </div>

                    <small class="member-result <?= e($memberMsg['cls'] ?? '') ?>"><?= e($memberMsg['text'] ?? '') ?></small>

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
// POPUPS (Bootstrap modals, opened via URL parameters, no JavaScript)
// ==================================================
$closeUrl = buildUrl($state);
?>

<?php if ($panel === 'ingredients'): ?>

<!-- INGREDIENT STOCK MODAL -->
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
                                    Used in <?= (int)$ingredient['used_in'] ?> <?= (int)$ingredient['used_in'] === 1 ? 'menu' : 'menus' ?>
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

<!-- MENU DETAIL MODAL -->
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
                    <button type="submit" class="close-btn" <?= $modalMenu['available'] ? '' : 'disabled' ?>>Add to Order</button>
                </div>

            </form>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($panel === 'pay'): ?>

<!-- PAYMENT MODAL -->
<?php $chosenMethod = (string)($_POST['payment_method'] ?? 'Cash'); ?>

<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content detail-modal">

            <form method="post" action="menu.php">

                <input type="hidden" name="form" value="checkout">
                <?= stateInputs($state) ?>

                <!-- Default button: pressing Enter in the cash field only recalculates the change -->
                <button type="submit" name="pay_act" value="calc" class="hidden-submit" tabindex="-1" aria-hidden="true">Calculate</button>

                <div class="modal-header detail-modal-header">
                    <div>
                        <h5 class="modal-title">Payment</h5>
                        <small><?= e($orderType) ?> · <?= count($cart) ?> <?= count($cart) === 1 ? 'item' : 'items' ?></small>
                    </div>

                    <a href="<?= e($closeUrl) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
                </div>

                <div class="modal-body menu-modal-body">

                    <div class="menu-modal-total pay-total">
                        <span>Total</span>
                        <strong><?= e(rupiah($cartSum)) ?></strong>
                    </div>

                    <?php if (!empty($_SESSION['member_id']) && !empty($_SESSION['member_name'])): ?>
                        <small class="member-result ok">
                            Member: <?= e($_SESSION['member_name']) ?>
                            · +<?= (int)($cartItems * POINTS_PER_MENU) ?> points
                        </small>
                    <?php endif; ?>

                    <span class="menu-modal-label">Payment method</span>

                    <div class="size-options">
                        <?php foreach (['Cash', 'QRIS', 'Debit'] as $method): ?>
                            <label class="size-pick">
                                <input type="radio" name="payment_method" value="<?= e($method) ?>" <?= $chosenMethod === $method ? 'checked' : '' ?>>
                                <span class="size-btn"><strong><?= e($method) ?></strong></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- CASH ONLY -->
                    <div class="cash-section">

                        <div class="quick-cash">
                            <button type="submit" name="pay_act" value="exact" formnovalidate>Exact amount</button>

                            <?php foreach ($quickAmounts as $amount): ?>
                                <button
                                    type="submit"
                                    name="pay_act"
                                    value="quick:<?= (int)$amount ?>"
                                    formnovalidate
                                    <?= $amount < $cartSum ? 'disabled' : '' ?>
                                ><?= e(rupiah($amount)) ?></button>
                            <?php endforeach; ?>
                        </div>

                        <input
                            type="number"
                            name="cash_received"
                            class="order-input"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            autocomplete="off"
                            value="<?= e($cashInput) ?>"
                            placeholder="Cash received (Cash only), press Enter to see the change"
                        >

                        <?php if ($changePreview !== null): ?>
                            <div class="cash-change cash-result <?= $changePreview >= 0 ? 'enough' : 'short' ?>">
                                <?php if ($changePreview >= 0): ?>
                                    <span>Change</span>
                                    <strong><?= e(rupiah($changePreview)) ?></strong>
                                <?php else: ?>
                                    <span>Still short</span>
                                    <strong><?= e(rupiah(abs($changePreview))) ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <?php if ($panelError !== ''): ?>
                        <div class="menu-modal-alert pay-error"><?= e($panelError) ?></div>
                    <?php endif; ?>

                </div>

                <div class="modal-footer detail-modal-footer">
                    <a href="<?= e($closeUrl) ?>" class="close-btn close-btn-light">Cancel</a>
                    <button type="submit" name="pay_act" value="confirm" class="close-btn">Confirm</button>
                </div>

            </form>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>


<?php if ($panel === 'receipt'): ?>

<!-- RECEIPT MODAL -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Receipt</h5>
                    <small>Payment successful</small>
                </div>
            </div>

            <div class="modal-body menu-modal-body receipt-body">

                <div class="receipt-paper">

                    <div class="receipt-brand">LÜMA</div>
                    <div class="receipt-line">Order #<?= (int)$receipt['id'] ?> · <?= e($receipt['type']) ?></div>
                    <div class="receipt-line"><?= e($receipt['date']) ?></div>
                    <div class="receipt-line">Customer: <?= e($receipt['customer']) ?></div>

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
                        <div class="receipt-row"><span>Change</span><span><?= e(rupiah($receipt['change'])) ?></span></div>
                    <?php else: ?>
                        <div class="receipt-row"><span>Paid by</span><span><?= e($receipt['method']) ?></span></div>
                    <?php endif; ?>

                    <?php if (!empty($receipt['points'])): ?>
                        <div class="receipt-row"><span>Points earned</span><span>+<?= (int)$receipt['points'] ?></span></div>
                    <?php endif; ?>

                    <div class="receipt-dash"></div>

                    <div class="receipt-thanks">Thank you ☕</div>

                </div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <!-- Print the receipt with Ctrl+P / the browser's Print menu -->
                <a href="menu.php?new_order=1" class="close-btn">New Order</a>
            </div>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>

</body>
</html>