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

// HELPER FUNCTIONS
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getColumnNames(mysqli $conn, string $table): array
{
    // Nama tabel selalu konstanta di file ini.
    $columns = [];
    $result  = $conn->query("SHOW COLUMNS FROM `$table`");

    while ($column = $result->fetch_assoc()) {
        $columns[] = $column['Field'];
    }

    return $columns;
}

function firstExisting(array $candidates, array $available): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $available, true)) {
            return $candidate;
        }
    }

    return null;
}

// Nilai enum asli di database (sama seperti di menu.php)
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

/*
 * ALUR STATUS
 * Pending -> Processing -> Completed
 * Pesanan dari kasir (menu.php) disimpan dengan status 'Success' (sudah dibayar,
 * belum dibuat), jadi di sini dianggap Pending. Ubah di sini kalau berbeda.
 */
function normalizeStatus($raw): string
{
    $key = strtolower(trim((string)$raw));

    if (in_array($key, ['processing', 'process', 'preparing'], true)) {
        return 'Processing';
    }

    if (in_array($key, ['completed', 'complete', 'done', 'selesai'], true)) {
        return 'Completed';
    }

    if (str_contains($key, 'cancel')) {
        return 'Cancelled';
    }

    return 'Pending'; // pending, success, paid, dll.
}

// DETEKSI KOLOM
try {
    $orderColumns  = getColumnNames($conn, 'orders');
    $detailColumns = getColumnNames($conn, 'order_details');
} catch (Throwable $err) {
    die('Table structure error: ' . e($err->getMessage()));
}

$cfg = [
    'status'      => firstExisting(['order_status', 'status', 'payment_status'], $orderColumns),
    'amount'      => firstExisting(['total_amount', 'total', 'grand_total', 'total_price'], $orderColumns),
    'type'        => firstExisting(['order_type', 'type', 'service_type'], $orderColumns),
    'payment'     => firstExisting(['payment_method', 'payment'], $orderColumns),
    'size'        => firstExisting(['size', 'menu_size', 'drink_size', 'variant', 'item_size'], $detailColumns),
    'detailOrder' => in_array('order_detail_id', $detailColumns, true) ? 'ORDER BY od.order_detail_id ASC' : ''
];

if ($cfg['status'] === null || $cfg['amount'] === null) {
    die('Kolom status/total tidak ditemukan di tabel orders. Kolom yang ada: ' . e(implode(', ', $orderColumns)));
}

// AMBIL PESANAN + ITEM
function loadOrders(mysqli $conn, array $cfg, string $scope): array
{
    $where = $scope === 'today' ? 'WHERE DATE(o.order_date) = CURDATE()' : '';

    $typeSel = $cfg['type']    ? ", o.`{$cfg['type']}` AS order_type"       : ", NULL AS order_type";
    $paySel  = $cfg['payment'] ? ", o.`{$cfg['payment']}` AS payment_method" : ", NULL AS payment_method";

    $result = $conn->query("
        SELECT
            o.order_id,
            o.`{$cfg['amount']}` AS total_amount,
            o.`{$cfg['status']}` AS order_status,
            DATE_FORMAT(o.order_date, '%H:%i') AS time_label,
            DATE_FORMAT(o.order_date, '%d/%m') AS date_label,
            TIMESTAMPDIFF(MINUTE, o.order_date, NOW()) AS age_min,
            u.name  AS customer_name,
            u.email AS customer_email,
            (c.user_id IS NOT NULL) AS is_member
            $typeSel
            $paySel
        FROM orders o
        LEFT JOIN users u    ON u.user_id = o.user_id
        LEFT JOIN customer c ON c.user_id = o.user_id
        $where
        ORDER BY o.order_id DESC
        LIMIT 300
    ");

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    // Semua item sekaligus (satu query)
    $itemsByOrder = [];

    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'order_id')));

        $sizeSel = $cfg['size'] ? ", od.`{$cfg['size']}` AS item_size" : ", NULL AS item_size";

        $details = $conn->query("
            SELECT od.order_id, m.menu_name, od.quantity, od.price, od.subtotal $sizeSel
            FROM order_details od
            LEFT JOIN menus m ON m.menu_id = od.menu_id
            WHERE od.order_id IN ($ids)
            {$cfg['detailOrder']}
        ");

        while ($d = $details->fetch_assoc()) {
            $size = trim((string)($d['item_size'] ?? ''));

            $itemsByOrder[(int)$d['order_id']][] = [
                'name'     => $d['menu_name'] ?? 'Menu',
                'qty'      => (int)$d['quantity'],
                'price'    => (float)$d['price'],
                'subtotal' => (float)$d['subtotal'],
                'size'     => $size !== '' ? ucfirst(strtolower($size)) : ''
            ];
        }
    }

    $orders = [];

    foreach ($rows as $row) {

        $id     = (int)$row['order_id'];
        $walkin = strtolower((string)$row['customer_email']) === 'walkin@luma.local';

        $orders[] = [
            'id'        => $id,
            'number'    => '#ORD-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'customer'  => $walkin ? 'Walk-in' : ($row['customer_name'] ?: 'Guest'),
            'is_member' => !$walkin && (int)$row['is_member'] === 1,
            'time'      => $row['time_label'] ?? '-',
            'date'      => $row['date_label'] ?? '',
            'age'       => max(0, (int)$row['age_min']),
            'type'      => $row['order_type'] ?: '-',
            'payment'   => $row['payment_method'] ?: '',
            'total'     => (float)$row['total_amount'],
            'status'    => normalizeStatus($row['order_status']),
            'items'     => $itemsByOrder[$id] ?? []
        ];
    }

    return $orders;
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

// REFRESH DATA (dipanggil JS)
if (isset($_GET['fetch_orders'])) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $scope = ($_GET['scope'] ?? 'today') === 'all' ? 'all' : 'today';

        echo json_encode(['ok' => true, 'orders' => loadOrders($conn, $cfg, $scope)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $err) {
        echo json_encode(['ok' => false, 'message' => $err->getMessage()]);
    }

    exit;
}

// UPDATE STATUS (satu langkah maju saja)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $target  = (string)($_POST['status'] ?? '');

        if (!$orderId || $orderId < 1) {
            throw new Exception('Pesanan tidak valid.');
        }

        if (!in_array($target, ['Processing', 'Completed'], true)) {
            throw new Exception('Status tidak valid.');
        }

        $stmt = $conn->prepare("SELECT `{$cfg['status']}` AS s FROM orders WHERE order_id = ? LIMIT 1");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current) {
            throw new Exception('Pesanan tidak ditemukan.');
        }

        $next = ['Pending' => 'Processing', 'Processing' => 'Completed'];

        if (($next[normalizeStatus($current['s'])] ?? null) !== $target) {
            throw new Exception('Status pesanan sudah berubah, data diperbarui.');
        }

        $dbStatus = enumValue($conn, 'orders', $cfg['status'], $target);

        $stmt = $conn->prepare("UPDATE orders SET `{$cfg['status']}` = ? WHERE order_id = ?");
        $stmt->bind_param("si", $dbStatus, $orderId);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['ok' => true, 'status' => $target]);

    } catch (Throwable $err) {
        echo json_encode(['ok' => false, 'message' => $err->getMessage()]);
    }

    exit;
}

$initialOrders = loadOrders($conn, $cfg, 'today');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Receive - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- menu.css = dasar tampilan (sidebar, topbar, kartu, filter); order_receive.css = tambahan khusus halaman ini -->
    <link rel="stylesheet" href="menu.css">
    <link rel="stylesheet" href="order_receive.css">
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

            <a href="order_receive.php" class="menu-item active">
                <i class="fa-solid fa-receipt"></i>
                <span>Order Receive</span>
            </a>

            <a href="menu.php" class="menu-item">
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

        <header class="topbar">

            <h2>Order Receive</h2>

            <div class="profile">
                <i class="fa-regular fa-bell notification-icon"></i>

                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>

        </header>

        <div class="menu-columns">

            <!-- KOLOM KIRI: RINGKASAN + DAFTAR PESANAN -->
            <div class="menu-left">

                <section class="summary-grid">

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div>
                            <span class="summary-label">Pending</span>
                            <strong class="summary-number" id="sumPending">0</strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-fire-burner"></i></div>
                        <div>
                            <span class="summary-label">Processing</span>
                            <strong class="summary-number" id="sumProcessing">0</strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-regular fa-circle-check"></i></div>
                        <div>
                            <span class="summary-label">Completed</span>
                            <strong class="summary-number" id="sumCompleted">0</strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div>
                            <span class="summary-label">Total Orders</span>
                            <strong class="summary-number" id="sumTotal">0</strong>
                        </div>
                    </article>

                </section>

                <section class="menu-section">

                    <div class="section-heading">
                        <h2>Incoming Orders</h2>
                        <p>Pesanan yang belum selesai ditampilkan paling atas, yang paling lama menunggu di depan</p>
                    </div>

                    <div class="menu-toolbar">

                        <div class="filters" id="statusFilters">
                            <button type="button" class="filter-btn active" data-status="All">All <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Pending">Pending <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Processing">Processing <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Completed">Completed <span class="chip-count">0</span></button>
                        </div>

                        <div class="toolbar-right">

                            <div class="scope-toggle" id="scopeToggle">
                                <button type="button" class="scope-btn active" data-scope="today">Hari ini</button>
                                <button type="button" class="scope-btn" data-scope="all">Semua</button>
                            </div>

                            <div class="search-box">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="searchInput" placeholder="Cari no. order / nama / menu...">
                            </div>

                            <button type="button" class="ingredient-btn" id="refreshBtn">
                                <i class="fa-solid fa-rotate"></i>
                                Refresh
                            </button>

                        </div>

                    </div>

                    <small class="live-status" id="lastUpdate"></small>

                    <div class="ord-grid" id="orderGrid"></div>

                    <div class="empty-state hidden" id="noResult">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No orders found</strong>
                        <span>Belum ada pesanan dengan filter ini.</span>
                    </div>

                </section>

            </div>

            <!-- KOLOM KANAN: DETAIL PESANAN -->
            <aside class="order-card ord-detail" id="detailPanel">

                <div class="detail-head">
                    <h3 class="order-title">Detail Pesanan</h3>
                    <button type="button" class="detail-close" id="detailClose" aria-label="Tutup">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <p class="order-empty" id="detailEmpty">Pilih pesanan untuk melihat detailnya.</p>

                <div class="detail-body hidden" id="detailBody">

                    <div class="detail-top">
                        <strong class="detail-number" id="dNumber"></strong>
                        <span class="status-badge" id="dStatus"></span>
                    </div>

                    <div class="stepper" id="dStepper"></div>

                    <dl class="detail-meta" id="dMeta"></dl>

                    <div class="order-items" id="dItems"></div>

                    <div class="order-total">
                        <span>Total</span>
                        <strong id="dTotal">Rp 0</strong>
                    </div>

                    <button type="button" class="pay-btn" id="dAction"></button>

                </div>

            </aside>

        </div>

    </main>

</div>

<div class="ord-toast" id="ordToast" role="status"></div>

<script>

var orders = <?= json_encode($initialOrders, $jsonFlags) ?>;

var state = {
    filter: 'All',
    scope: 'today',
    keyword: '',
    selectedId: null,
    busy: false
};

var STATUS_RANK = { Pending: 0, Processing: 1, Completed: 2, Cancelled: 3 };


// ==================================================
// HELPER
// ==================================================
function el(tag, className, text) {

    var node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined && text !== null) {
        node.textContent = text;
    }

    return node;

}

function formatRupiah(number) {

    return 'Rp ' + Math.round(number)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

}

function statusClass(status) {
    return 'status-' + status.toLowerCase();
}

function ageText(minutes) {

    if (minutes < 1) {
        return 'Baru saja';
    }

    if (minutes < 60) {
        return minutes + ' mnt lalu';
    }

    var hours = Math.floor(minutes / 60);

    return hours < 24 ? hours + ' jam lalu' : Math.floor(hours / 24) + ' hari lalu';

}

function timeText(order) {
    return (state.scope === 'all' && order.date ? order.date + ' ' : '') + order.time;
}

function isLate(order) {
    return order.status === 'Pending' && order.age >= 10;
}

function findOrder(id) {

    for (var i = 0; i < orders.length; i++) {
        if (orders[i].id === id) {
            return orders[i];
        }
    }

    return null;

}

// Langkah berikutnya untuk sebuah pesanan
function nextAction(order) {

    if (order.status === 'Pending') {
        return { target: 'Processing', label: 'Proses', icon: 'fa-play' };
    }

    if (order.status === 'Processing') {
        return { target: 'Completed', label: 'Selesai', icon: 'fa-check' };
    }

    return null;

}

var toastTimer;

function toast(message, isError) {

    var box = document.getElementById('ordToast');

    box.textContent = message;
    box.className = 'ord-toast show' + (isError ? ' error' : '');

    clearTimeout(toastTimer);

    toastTimer = setTimeout(function () {
        box.className = 'ord-toast';
    }, 3500);

}


// ==================================================
// FILTER + SORT
// ==================================================
function matches(order) {

    if (state.filter !== 'All' && order.status !== state.filter) {
        return false;
    }

    if (!state.keyword) {
        return true;
    }

    var text = order.number + ' ' + order.customer + ' ' + order.items.map(function (item) {
        return item.name;
    }).join(' ');

    return text.toLowerCase().indexOf(state.keyword) !== -1;

}

// Antrian: Pending -> Processing (paling lama dulu), lalu yang sudah selesai (terbaru dulu)
function sortOrders(list) {

    return list.slice().sort(function (a, b) {

        var rank = STATUS_RANK[a.status] - STATUS_RANK[b.status];

        if (rank !== 0) {
            return rank;
        }

        return a.status === 'Completed' || a.status === 'Cancelled' ? b.id - a.id : a.id - b.id;

    });

}


// ==================================================
// RENDER: RINGKASAN + CHIP
// ==================================================
function renderCounts() {

    var count = { All: orders.length, Pending: 0, Processing: 0, Completed: 0 };

    orders.forEach(function (order) {
        if (count[order.status] !== undefined) {
            count[order.status]++;
        }
    });

    document.getElementById('sumPending').textContent = count.Pending;
    document.getElementById('sumProcessing').textContent = count.Processing;
    document.getElementById('sumCompleted').textContent = count.Completed;
    document.getElementById('sumTotal').textContent = count.All;

    document.querySelectorAll('#statusFilters .filter-btn').forEach(function (button) {
        button.querySelector('.chip-count').textContent = count[button.dataset.status];
        button.classList.toggle('active', button.dataset.status === state.filter);
    });

}


// ==================================================
// RENDER: KARTU PESANAN
// ==================================================
function buildCard(order) {

    var card = el('article', 'ord-card ' + statusClass(order.status) + (order.id === state.selectedId ? ' selected' : ''));

    card.dataset.id = order.id;
    card.tabIndex = 0;
    card.setAttribute('role', 'button');

    var head = el('div', 'ord-head');
    head.appendChild(el('strong', 'ord-number', order.number));
    head.appendChild(el('span', 'status-badge ' + statusClass(order.status), order.status));

    var who = el('div', 'ord-who');
    who.appendChild(el('span', 'ord-customer', order.customer));

    if (order.is_member) {
        who.appendChild(el('span', 'member-chip', 'Member'));
    }

    var meta = el('div', 'ord-meta');
    meta.appendChild(el('span', 'type-badge', order.type));

    if (order.payment) {
        meta.appendChild(el('span', 'type-badge', order.payment));
    }

    meta.appendChild(el('span', 'ord-time' + (isLate(order) ? ' late' : ''), timeText(order) + ' · ' + ageText(order.age)));

    var list = el('ul', 'ord-items');

    if (order.items.length === 0) {
        list.appendChild(el('li', 'more', 'Tidak ada item'));
    }

    order.items.slice(0, 3).forEach(function (item) {
        list.appendChild(el('li', '', item.qty + '× ' + item.name + (item.size ? ' (' + item.size + ')' : '')));
    });

    if (order.items.length > 3) {
        list.appendChild(el('li', 'more', '+' + (order.items.length - 3) + ' item lainnya'));
    }

    var foot = el('div', 'ord-foot');
    foot.appendChild(el('strong', 'ord-total', formatRupiah(order.total)));

    var action = nextAction(order);

    if (action) {

        var button = el('button', 'ord-action');
        button.type = 'button';
        button.appendChild(el('i', 'fa-solid ' + action.icon));
        button.appendChild(document.createTextNode(' ' + action.label));

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            advance(order);
        });

        foot.appendChild(button);

    } else {
        foot.appendChild(el('span', 'ord-done', order.status === 'Completed' ? 'Selesai' : order.status));
    }

    card.appendChild(head);
    card.appendChild(who);
    card.appendChild(meta);
    card.appendChild(list);
    card.appendChild(foot);

    return card;

}

function renderGrid() {

    var grid = document.getElementById('orderGrid');
    var visible = sortOrders(orders.filter(matches));

    grid.innerHTML = '';

    visible.forEach(function (order) {
        grid.appendChild(buildCard(order));
    });

    document.getElementById('noResult').classList.toggle('hidden', visible.length > 0);

}


// ==================================================
// RENDER: PANEL DETAIL
// ==================================================
function renderDetail() {

    var order = findOrder(state.selectedId);

    document.getElementById('detailEmpty').classList.toggle('hidden', !!order);
    document.getElementById('detailBody').classList.toggle('hidden', !order);

    if (!order) {
        return;
    }

    document.getElementById('dNumber').textContent = order.number;

    var badge = document.getElementById('dStatus');
    badge.className = 'status-badge ' + statusClass(order.status);
    badge.textContent = order.status;

    // Stepper
    var steps = ['Pending', 'Processing', 'Completed'];
    var current = steps.indexOf(order.status);
    var stepper = document.getElementById('dStepper');

    stepper.innerHTML = '';
    stepper.classList.toggle('hidden', current === -1);

    steps.forEach(function (name, index) {

        var step = el('div', 'step' + (index < current ? ' done' : '') + (index === current ? ' active' : ''));

        step.appendChild(el('span', 'step-dot'));
        step.appendChild(el('small', '', name));
        stepper.appendChild(step);

    });

    // Info
    var meta = document.getElementById('dMeta');
    var rows = [
        ['Pelanggan', order.customer + (order.is_member ? ' (Member)' : '')],
        ['Tipe', order.type],
        ['Waktu', timeText(order) + ' · ' + ageText(order.age)],
        ['Pembayaran', order.payment || '-']
    ];

    meta.innerHTML = '';

    rows.forEach(function (row) {

        var box = el('div');

        box.appendChild(el('dt', '', row[0]));
        box.appendChild(el('dd', '', row[1]));
        meta.appendChild(box);

    });

    // Item
    var items = document.getElementById('dItems');

    items.innerHTML = '';

    if (order.items.length === 0) {
        items.appendChild(el('p', 'order-empty', 'Tidak ada item pada pesanan ini.'));
    }

    order.items.forEach(function (item) {

        var row = el('div', 'order-item');
        var info = el('div', 'order-item-info');

        info.appendChild(el('strong', '', item.qty + '× ' + item.name));
        info.appendChild(el('small', '', (item.size ? item.size + ' · ' : '') + formatRupiah(item.price)));

        row.appendChild(info);
        row.appendChild(el('strong', 'order-item-sub', formatRupiah(item.subtotal)));
        items.appendChild(row);

    });

    document.getElementById('dTotal').textContent = formatRupiah(order.total);

    // Tombol aksi
    var button = document.getElementById('dAction');
    var action = nextAction(order);

    button.classList.toggle('hidden', !action);

    if (action) {
        button.textContent = action.target === 'Processing' ? 'Proses Pesanan' : 'Tandai Selesai';
    }

}

function renderAll() {

    if (state.selectedId !== null && !findOrder(state.selectedId)) {
        state.selectedId = null;
        document.getElementById('detailPanel').classList.remove('open');
    }

    renderCounts();
    renderGrid();
    renderDetail();

}

function selectOrder(id) {

    state.selectedId = id;

    document.querySelectorAll('.ord-card').forEach(function (card) {
        card.classList.toggle('selected', Number(card.dataset.id) === id);
    });

    renderDetail();
    document.getElementById('detailPanel').classList.add('open');

}


// ==================================================
// DATA: REFRESH + UPDATE STATUS
// ==================================================
function loadOrders() {

    return fetch('order_receive.php?fetch_orders=1&scope=' + state.scope)
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat pesanan.');
            }

            orders = data.orders;

            document.getElementById('lastUpdate').textContent =
                'Diperbarui ' + new Date().toLocaleTimeString('id-ID') + ' · otomatis tiap 15 detik';

            renderAll();

        });

}

function advance(order) {

    var action = nextAction(order);

    if (!action || state.busy) {
        return;
    }

    if (action.target === 'Completed' && !confirm('Tandai ' + order.number + ' sebagai selesai?')) {
        return;
    }

    var form = new FormData();

    form.append('update_status', '1');
    form.append('order_id', order.id);
    form.append('status', action.target);

    state.busy = true;

    fetch('order_receive.php', { method: 'POST', body: form })
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (!data.ok) {
                toast(data.message || 'Status gagal diperbarui.', true);
            } else {
                toast(order.number + ' → ' + data.status);
            }

            return loadOrders();

        })
        .catch(function () {
            toast('Koneksi gagal, coba lagi.', true);
        })
        .finally(function () {
            state.busy = false;
        });

}


// ==================================================
// EVENT
// ==================================================
document.getElementById('orderGrid').addEventListener('click', function (event) {

    var card = event.target.closest('.ord-card');

    if (card) {
        selectOrder(Number(card.dataset.id));
    }

});

document.getElementById('orderGrid').addEventListener('keydown', function (event) {

    if ((event.key === 'Enter' || event.key === ' ') && event.target.classList.contains('ord-card')) {
        event.preventDefault();
        selectOrder(Number(event.target.dataset.id));
    }

});

document.querySelectorAll('#statusFilters .filter-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        state.filter = button.dataset.status;
        renderCounts();
        renderGrid();
    });
});

document.querySelectorAll('#scopeToggle .scope-btn').forEach(function (button) {

    button.addEventListener('click', function () {

        state.scope = button.dataset.scope;

        document.querySelectorAll('#scopeToggle .scope-btn').forEach(function (item) {
            item.classList.toggle('active', item === button);
        });

        loadOrders().catch(function (err) { toast(err.message, true); });

    });

});

document.getElementById('searchInput').addEventListener('input', function () {
    state.keyword = this.value.trim().toLowerCase();
    renderGrid();
});

document.getElementById('refreshBtn').addEventListener('click', function () {
    loadOrders().catch(function (err) { toast(err.message, true); });
});

document.getElementById('dAction').addEventListener('click', function () {

    var order = findOrder(state.selectedId);

    if (order) {
        advance(order);
    }

});

document.getElementById('detailClose').addEventListener('click', function () {
    document.getElementById('detailPanel').classList.remove('open');
});

// Pesanan baru dari kasir muncul otomatis
setInterval(function () {

    if (!document.hidden && !state.busy) {
        loadOrders().catch(function () {});
    }

}, 15000);

renderAll();

</script>

</body>
</html>