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

function rupiah($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

// 8,00 -> 8   |   2,50 -> 2.5
function formatQty($number): string
{
    return rtrim(rtrim(number_format((float)$number, 2, '.', ''), '0'), '.');
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

// KOLOM orders (sama seperti order_receive.php)
$orderColumns = getColumnNames($conn, 'orders');
$statusCol    = firstExisting(['order_status', 'status', 'payment_status'], $orderColumns);
$amountCol    = firstExisting(['total_amount', 'total', 'grand_total', 'total_price'], $orderColumns);

if ($statusCol === null || $amountCol === null) {
    die('Kolom status/total tidak ditemukan di tabel orders. Kolom yang ada: ' . e(implode(', ', $orderColumns)));
}

// KEMARIN (untuk perbandingan "from yesterday")
$yesterday = $conn->query("
    SELECT COUNT(*) AS total_orders, COALESCE(SUM(`$amountCol`), 0) AS revenue
    FROM orders
    WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      AND LOWER(`$statusCol`) NOT LIKE '%cancel%'
")->fetch_assoc();

$yesterdayData = [
    'orders'  => (int)$yesterday['total_orders'],
    'revenue' => (float)$yesterday['revenue']
];

// POPULAR MENU (sama dengan logika halaman Menu)
$imageFolder  = "../assets/menu/";
$popularMenus = [];

$resultPopular = $conn->query("
    SELECT
        m.menu_id,
        m.menu_name,
        m.price,
        m.image,
        c.category_name,
        SUM(od.quantity) AS total_sold
    FROM order_details od
    INNER JOIN menus m ON m.menu_id = od.menu_id
    LEFT JOIN categories c ON c.category_id = m.category_id
    GROUP BY m.menu_id, m.menu_name, m.price, m.image, c.category_name
    ORDER BY total_sold DESC, m.menu_name ASC
    LIMIT 4
");

while ($row = $resultPopular->fetch_assoc()) {
    $popularMenus[] = $row;
}

// INGREDIENT YANG PERLU PERHATIAN (stok <= minimum)
$lowStock = [];
$outCount = 0;

$resultStock = $conn->query("
    SELECT ingredient_name, unit, stock, minimum_stock
    FROM ingredients
    WHERE stock <= minimum_stock
    ORDER BY stock ASC, ingredient_name ASC
");

while ($row = $resultStock->fetch_assoc()) {
    if ((float)$row['stock'] <= 0) {
        $outCount++;
    }
    $lowStock[] = $row;
}

$lowCount = count($lowStock);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- menu.css = dasar; order_receive.css = kartu pesanan, ringkasan, stepper; dashboard.css = khusus dashboard -->
    <link rel="stylesheet" href="menu.css">
    <link rel="stylesheet" href="order_receive.css">
    <link rel="stylesheet" href="dashboard.css">
</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <img src="../assets/Group 194.png" alt="LÜMA Logo">
        </div>

        <nav class="sidebar-menu">

            <a href="dashboard.php" class="menu-item active">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>

            <a href="order_receive.php" class="menu-item">
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

            <h2>Dashboard</h2>

            <div class="profile">
                <i class="fa-regular fa-bell notification-icon"></i>

                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>

        </header>

        <section class="welcome">
            <h1>Welcome back, <?= e($userName) ?>! 👋</h1>
            <p>Here's today's summary.</p>
        </section>

        <!-- RINGKASAN -->
        <section class="summary-grid">

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-box"></i></div>
                <div>
                    <span class="summary-label">Today's Orders</span>
                    <strong class="summary-number" id="sumOrders">-</strong>
                    <small class="summary-sub" id="sumOrdersSub">&nbsp;</small>
                </div>
            </article>

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                <div>
                    <span class="summary-label">Today's Revenue</span>
                    <strong class="summary-number" id="sumRevenue">-</strong>
                    <small class="summary-sub" id="sumRevenueSub">&nbsp;</small>
                </div>
            </article>

            <a href="order_receive.php" class="summary-card summary-link">
                <div class="summary-icon"><i class="fa-solid fa-fire-burner"></i></div>
                <div>
                    <span class="summary-label">In Queue</span>
                    <strong class="summary-number" id="sumQueue">-</strong>
                    <small class="summary-sub" id="sumQueueSub">&nbsp;</small>
                </div>
            </a>

            <a href="menu.php?open=ingredients" class="summary-card summary-link">
                <div class="summary-icon"><i class="fa-solid fa-box-open"></i></div>
                <div>
                    <span class="summary-label">Low Stock</span>
                    <strong class="summary-number"><?= $lowCount ?></strong>
                    <small class="summary-sub <?= $outCount > 0 ? 'bad' : '' ?>">
                        <?= $outCount > 0 ? $outCount . ' out of stock' : ($lowCount > 0 ? 'Needs restock' : 'All ingredients OK') ?>
                    </small>
                </div>
            </a>

        </section>

        <div class="menu-columns">

            <!-- KOLOM KIRI: RECENT ORDERS -->
            <div class="menu-left">

                <section class="menu-section">

                    <div class="section-heading section-heading-row">

                        <div>
                            <h2>Recent Orders</h2>
                            <p>Today's transactions, newest first</p>
                        </div>

                        <a href="menu.php" class="ingredient-btn">
                            <i class="fa-solid fa-plus"></i>
                            New Order
                        </a>

                    </div>

                    <div class="menu-toolbar">

                        <div class="filters" id="statusFilters">
                            <button type="button" class="filter-btn active" data-status="All">All <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Pending">Pending <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Processing">Processing <span class="chip-count">0</span></button>
                            <button type="button" class="filter-btn" data-status="Completed">Completed <span class="chip-count">0</span></button>
                        </div>

                        <a href="order_receive.php" class="see-all">
                            Lihat semua di Order Receive <i class="fa-solid fa-arrow-right"></i>
                        </a>

                    </div>

                    <small class="live-status" id="lastUpdate">Memuat pesanan...</small>

                    <div class="ord-grid" id="orderGrid"></div>

                    <div class="empty-state hidden" id="noResult">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No orders found</strong>
                        <span>Belum ada pesanan dengan filter ini.</span>
                    </div>

                </section>

            </div>

            <!-- KOLOM KANAN: POPULAR + STOCK -->
            <aside class="side-stack">

                <section class="side-card">

                    <div class="side-head">
                        <h3>Popular Menu</h3>
                        <a href="menu.php">Menu</a>
                    </div>

                    <?php if ($popularMenus): ?>

                        <?php foreach ($popularMenus as $menu): ?>

                            <a href="menu.php" class="pop-item">

                                <div class="pop-thumb">
                                    <i class="fa-solid fa-mug-hot"></i>

                                    <?php if (!empty($menu['image'])): ?>
                                        <img
                                            src="<?= e($imageFolder . $menu['image']) ?>"
                                            alt="<?= e($menu['menu_name']) ?>"
                                            loading="lazy"
                                            onerror="this.remove()"
                                        >
                                    <?php endif; ?>
                                </div>

                                <div class="pop-info">
                                    <strong><?= e($menu['menu_name']) ?></strong>
                                    <small><?= e($menu['category_name']) ?> · <?= rupiah($menu['price']) ?></small>
                                </div>

                                <span class="pop-sold"><?= (int)$menu['total_sold'] ?> sold</span>

                            </a>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <p class="side-empty">Popular menu appears after orders come in.</p>

                    <?php endif; ?>

                </section>

                <section class="side-card">

                    <div class="side-head">
                        <h3>Ingredient Stock</h3>
                        <a href="menu.php?open=ingredients">Kelola</a>
                    </div>

                    <?php if ($lowStock): ?>

                        <?php foreach (array_slice($lowStock, 0, 5) as $ingredient): ?>

                            <?php $isOut = (float)$ingredient['stock'] <= 0; ?>

                            <div class="stock-row">

                                <div class="stock-info">
                                    <strong><?= e($ingredient['ingredient_name']) ?></strong>
                                    <small>
                                        <?= e(formatQty($ingredient['stock'])) ?> / min <?= e(formatQty($ingredient['minimum_stock'])) ?>
                                        <?= e($ingredient['unit']) ?>
                                    </small>
                                </div>

                                <span class="status-badge <?= $isOut ? 'status-out' : 'status-low' ?>">
                                    <?= $isOut ? 'Out of Stock' : 'Low' ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                        <?php if ($lowCount > 5): ?>
                            <p class="side-empty">+<?= $lowCount - 5 ?> ingredient lainnya</p>
                        <?php endif; ?>

                    <?php else: ?>

                        <p class="side-empty">Semua ingredient masih aman.</p>

                    <?php endif; ?>

                </section>

            </aside>

        </div>

    </main>

</div>


<!-- =====================================================
     MODAL DETAIL PESANAN
====================================================== -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Order Detail</h5>
                    <small id="mNumber"></small>
                </div>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body menu-modal-body dash-modal-body">

                <div class="detail-top">
                    <strong class="detail-number" id="mCustomer"></strong>
                    <span class="status-badge" id="mStatus"></span>
                </div>

                <div class="stepper" id="mStepper"></div>

                <dl class="detail-meta" id="mMeta"></dl>

                <div class="order-items" id="mItems"></div>

                <div class="order-total">
                    <span>Total</span>
                    <strong id="mTotal">Rp 0</strong>
                </div>

            </div>

            <div class="modal-footer detail-modal-footer">
                <button type="button" class="close-btn close-btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="close-btn hidden" id="mAction"></button>
            </div>

        </div>
    </div>
</div>

<div class="ord-toast" id="ordToast" role="status"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>

var yesterday = <?= json_encode($yesterdayData, $jsonFlags) ?>;

var orders = [];

var state = {
    filter: 'All',
    selectedId: null,
    busy: false
};

var RECENT_LIMIT = 8;

var orderModal = new bootstrap.Modal(document.getElementById('orderModal'));


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

// Langkah berikutnya untuk sebuah pesanan (alur sama dengan Order Receive)
function nextAction(order) {

    if (order.status === 'Pending') {
        return { target: 'Processing', label: 'Proses', icon: 'fa-play' };
    }

    if (order.status === 'Processing') {
        return { target: 'Completed', label: 'Selesai', icon: 'fa-check' };
    }

    return null;

}

// "+3 from yesterday" / "-2 from yesterday" / "Same as yesterday"
function compareText(diff, formatter) {

    if (diff === 0) {
        return 'Same as yesterday';
    }

    return (diff > 0 ? '+ ' : '- ') + formatter(Math.abs(diff)) + ' from yesterday';

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
// RENDER: RINGKASAN + CHIP
// ==================================================
function renderSummary() {

    var counted = orders.filter(function (order) { return order.status !== 'Cancelled'; });
    var revenue = counted.reduce(function (sum, order) { return sum + order.total; }, 0);
    var pending = 0;
    var processing = 0;
    var chip = { All: orders.length, Pending: 0, Processing: 0, Completed: 0 };

    orders.forEach(function (order) {

        if (order.status === 'Pending') pending++;
        if (order.status === 'Processing') processing++;

        if (chip[order.status] !== undefined) {
            chip[order.status]++;
        }

    });

    document.getElementById('sumOrders').textContent = counted.length;
    document.getElementById('sumOrdersSub').textContent = compareText(counted.length - yesterday.orders, String);

    document.getElementById('sumRevenue').textContent = formatRupiah(revenue);
    document.getElementById('sumRevenueSub').textContent = compareText(revenue - yesterday.revenue, formatRupiah);

    document.getElementById('sumQueue').textContent = pending + processing;
    document.getElementById('sumQueueSub').textContent = pending + ' pending · ' + processing + ' processing';

    document.querySelectorAll('#statusFilters .filter-btn').forEach(function (button) {
        button.querySelector('.chip-count').textContent = chip[button.dataset.status];
        button.classList.toggle('active', button.dataset.status === state.filter);
    });

}


// ==================================================
// RENDER: KARTU PESANAN
// ==================================================
function buildCard(order) {

    var card = el('article', 'ord-card ' + statusClass(order.status));

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

    meta.appendChild(el('span', 'ord-time' + (isLate(order) ? ' late' : ''), order.time + ' · ' + ageText(order.age)));

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

    // Terbaru di atas
    var filtered = orders
        .filter(function (order) { return state.filter === 'All' || order.status === state.filter; })
        .sort(function (a, b) { return b.id - a.id; });

    var visible = filtered.slice(0, RECENT_LIMIT);

    grid.innerHTML = '';

    visible.forEach(function (order) {
        grid.appendChild(buildCard(order));
    });

    document.getElementById('noResult').classList.toggle('hidden', visible.length > 0);

    document.getElementById('lastUpdate').textContent =
        'Menampilkan ' + visible.length + ' dari ' + filtered.length + ' pesanan hari ini · diperbarui ' +
        new Date().toLocaleTimeString('id-ID');

}


// ==================================================
// RENDER: MODAL DETAIL
// ==================================================
function renderModal() {

    var order = findOrder(state.selectedId);

    if (!order) {
        return;
    }

    document.getElementById('mNumber').textContent = order.number;
    document.getElementById('mCustomer').textContent = order.customer + (order.is_member ? ' (Member)' : '');

    var badge = document.getElementById('mStatus');
    badge.className = 'status-badge ' + statusClass(order.status);
    badge.textContent = order.status;

    // Stepper
    var steps = ['Pending', 'Processing', 'Completed'];
    var current = steps.indexOf(order.status);
    var stepper = document.getElementById('mStepper');

    stepper.innerHTML = '';
    stepper.classList.toggle('hidden', current === -1);

    steps.forEach(function (name, index) {

        var step = el('div', 'step' + (index < current ? ' done' : '') + (index === current ? ' active' : ''));

        step.appendChild(el('span', 'step-dot'));
        step.appendChild(el('small', '', name));
        stepper.appendChild(step);

    });

    // Info
    var meta = document.getElementById('mMeta');
    var rows = [
        ['Tipe', order.type],
        ['Waktu', order.time + ' · ' + ageText(order.age)],
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
    var items = document.getElementById('mItems');

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

    document.getElementById('mTotal').textContent = formatRupiah(order.total);

    // Tombol aksi
    var button = document.getElementById('mAction');
    var action = nextAction(order);

    button.classList.toggle('hidden', !action);

    if (action) {
        button.textContent = action.target === 'Processing' ? 'Proses Pesanan' : 'Tandai Selesai';
    }

}

function renderAll() {

    renderSummary();
    renderGrid();

    if (state.selectedId !== null) {

        if (findOrder(state.selectedId)) {
            renderModal();
        } else {
            state.selectedId = null;
            orderModal.hide();
        }

    }

}


// ==================================================
// DATA: REFRESH + UPDATE STATUS (memakai endpoint order_receive.php)
// ==================================================
function loadOrders() {

    return fetch('order_receive.php?fetch_orders=1&scope=today')
        .then(function (res) { return res.json(); })
        .then(function (data) {

            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat pesanan.');
            }

            orders = data.orders;
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
function openOrder(id) {

    state.selectedId = id;
    renderModal();
    orderModal.show();

}

document.getElementById('orderGrid').addEventListener('click', function (event) {

    var card = event.target.closest('.ord-card');

    if (card) {
        openOrder(Number(card.dataset.id));
    }

});

document.getElementById('orderGrid').addEventListener('keydown', function (event) {

    if ((event.key === 'Enter' || event.key === ' ') && event.target.classList.contains('ord-card')) {
        event.preventDefault();
        openOrder(Number(event.target.dataset.id));
    }

});

document.querySelectorAll('#statusFilters .filter-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        state.filter = button.dataset.status;
        renderSummary();
        renderGrid();
    });
});

document.getElementById('mAction').addEventListener('click', function () {

    var order = findOrder(state.selectedId);

    if (order) {
        advance(order);
    }

});

document.getElementById('orderModal').addEventListener('hidden.bs.modal', function () {
    state.selectedId = null;
});

// Pesanan baru dari kasir muncul otomatis
setInterval(function () {

    if (!document.hidden && !state.busy) {
        loadOrders().catch(function () {});
    }

}, 15000);

loadOrders().catch(function (err) {

    document.getElementById('lastUpdate').textContent = 'Gagal memuat pesanan.';
    toast(err.message, true);

});

</script>

</body>
</html>