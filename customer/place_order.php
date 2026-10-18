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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Pengaturan Rewards
|--------------------------------------------------------------------------
| +10 poin untuk setiap 1 menu yang dibeli (dihitung dari quantity).
| Level (ENUM): Bronze, Silver, Gold.
*/
const POINTS_PER_MENU = 10;
const SILVER_MIN_POINTS = 100;
const GOLD_MIN_POINTS = 300;

function rewardLevel(int $points): string
{
    if ($points >= GOLD_MIN_POINTS) {
        return 'Gold';
    }

    if ($points >= SILVER_MIN_POINTS) {
        return 'Silver';
    }

    return 'Bronze';
}

/**
 * Tambah poin reward customer dan perbarui level.
 * Dipanggil di dalam transaksi pesanan (sebelum commit).
 */
function addRewardPoints(mysqli $conn, int $userId, int $totalQty): void
{
    $earned = $totalQty * POINTS_PER_MENU;

    if ($earned <= 0) {
        return;
    }

    // Kunci baris rewards milik user (jika sudah ada)
    $stmt = $conn->prepare(
        "SELECT points
         FROM rewards
         WHERE user_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception('Gagal menyiapkan data rewards.');
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $newPoints = (int) $row['points'] + $earned;
        $newLevel = rewardLevel($newPoints);

        $stmt = $conn->prepare(
            "UPDATE rewards
             SET points = ?, level = ?
             WHERE user_id = ?"
        );

        if (!$stmt) {
            throw new Exception('Gagal memperbarui rewards.');
        }

        $stmt->bind_param('isi', $newPoints, $newLevel, $userId);
    } else {
        $newPoints = $earned;
        $newLevel = rewardLevel($newPoints);

        $stmt = $conn->prepare(
            "INSERT INTO rewards (user_id, points, level)
             VALUES (?, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception('Gagal membuat data rewards.');
        }

        $stmt->bind_param('iis', $userId, $newPoints, $newLevel);
    }

    if (!$stmt->execute()) {
        throw new Exception('Gagal menyimpan poin rewards.');
    }

    $stmt->close();
}

$userId = (int) $_SESSION['user_id'];
$cart = $_SESSION['cart'] ?? [];
$paymentMethod = $_POST['payment_method'] ?? '';
$orderType = $_POST['order_type'] ?? '';

$allowedOrderTypes = ['Dine In', 'Take Away'];

if (!in_array($orderType, $allowedOrderTypes, true)) {
    header('Location: checkout.php');
    exit;
}

$allowedMethods = ['QRIS', 'Cash', 'Debit'];

if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

if (!in_array($paymentMethod, $allowedMethods, true)) {
    header('Location: checkout.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validasi cart dan hitung ulang total dari session
|--------------------------------------------------------------------------
*/
$totalAmount = 0;
$totalQty = 0;
$items = [];

foreach ($cart as $item) {
    $menuId = (int) ($item['menu_id'] ?? 0);
    $quantity = (int) ($item['quantity'] ?? 0);
    $price = (float) ($item['price'] ?? 0);

    if ($menuId <= 0 || $quantity <= 0 || $price < 0) {
        header('Location: cart.php');
        exit;
    }

    $subtotal = $price * $quantity;
    $totalAmount += $subtotal;
    $totalQty += $quantity;

    $items[] = [
        'menu_id' => $menuId,
        'quantity' => $quantity,
        'price' => $price,
        'subtotal' => $subtotal
    ];
}

if (empty($items) || $totalAmount <= 0) {
    header('Location: cart.php');
    exit;
}

try {
    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Simpan data utama pesanan
    |--------------------------------------------------------------------------
    */
    $sqlOrder = "
        INSERT INTO orders
        (
            user_id,
            order_date,
            total_amount,
            status,
            order_status,
            order_type,
            payment_method
        )
        VALUES (?, NOW(), ?, 'Success', 'Pending', ?, ?)
    ";

    $stmtOrder = $conn->prepare($sqlOrder);

    if (!$stmtOrder) {
        throw new Exception('Gagal menyiapkan data pesanan.');
    }

    $stmtOrder->bind_param(
        'idss',
        $userId,
        $totalAmount,
        $orderType,
        $paymentMethod
    );

    if (!$stmtOrder->execute()) {
        throw new Exception('Gagal menyimpan pesanan.');
    }

    $orderId = (int) $conn->insert_id;
    $stmtOrder->close();

    /*
    |--------------------------------------------------------------------------
    | Simpan setiap item ke order_details
    |--------------------------------------------------------------------------
    */
    $sqlDetail = "
        INSERT INTO order_details
        (
            order_id,
            menu_id,
            quantity,
            price,
            subtotal
        )
        VALUES (?, ?, ?, ?, ?)
    ";

    $stmtDetail = $conn->prepare($sqlDetail);

    if (!$stmtDetail) {
        throw new Exception('Gagal menyiapkan detail pesanan.');
    }

    foreach ($items as $item) {
        $menuId = $item['menu_id'];
        $quantity = $item['quantity'];
        $price = $item['price'];
        $subtotal = $item['subtotal'];

        $stmtDetail->bind_param(
            'iiidd',
            $orderId,
            $menuId,
            $quantity,
            $price,
            $subtotal
        );

        if (!$stmtDetail->execute()) {
            throw new Exception('Gagal menyimpan item pesanan.');
        }
    }

    $stmtDetail->close();

    /*
    |--------------------------------------------------------------------------
    | Tambah poin rewards: +10 untuk setiap 1 menu
    | (status pesanan langsung 'Success', jadi poin diberikan sekarang)
    |--------------------------------------------------------------------------
    */
    addRewardPoints($conn, $userId, $totalQty);

    // Simpan semua data jika seluruh proses berhasil.
    $conn->commit();

    // Kosongkan cart hanya setelah pesanan tersimpan.
    unset($_SESSION['cart']);

    // Tampilkan pesanan yang baru dibuat.
    header('Location: my_orders.php?success=1');
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    error_log('Place Order Error: ' . $e->getMessage());

    $_SESSION['order_error'] =
        'Pesanan belum berhasil disimpan. Silakan coba kembali.';

    header('Location: checkout.php');
    exit;
}