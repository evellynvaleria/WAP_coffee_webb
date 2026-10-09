<?php

session_start();

require_once "../config/database.php";


// =====================================================
// CEK LOGIN
// =====================================================

if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit;

}


// =====================================================
// CEK USER ROLE
// =====================================================

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'staff'
) {

    header("Location: ../login.php");
    exit;

}


// =====================================================
// DATA STAFF
// =====================================================

$userId = $_SESSION['user_id'];

$userName = $_SESSION['name'] ?? 'Staff';

$userEmail = $_SESSION['email'] ?? '';


// =====================================================
// FUNCTION FORMAT RUPIAH
// =====================================================

function rupiah($number)
{
    return 'Rp ' . number_format(
        (float) $number,
        0,
        ',',
        '.'
    );
}


// =====================================================
// TODAY ORDERS
// =====================================================

$todayOrders = 0;

$sqlTodayOrders = "
    SELECT COUNT(*) AS total_orders
    FROM orders
    WHERE DATE(order_date) = CURDATE()
";

$resultTodayOrders = $conn->query($sqlTodayOrders);

if ($resultTodayOrders) {

    $row = $resultTodayOrders->fetch_assoc();

    $todayOrders = (int) ($row['total_orders'] ?? 0);

}


// =====================================================
// TODAY REVENUE
// =====================================================

$todayRevenue = 0;

$sqlTodayRevenue = "
    SELECT COALESCE(SUM(total_amount), 0) AS total_revenue
    FROM orders
    WHERE DATE(order_date) = CURDATE()
    AND status = 'Success'
";

$resultTodayRevenue = $conn->query($sqlTodayRevenue);

if ($resultTodayRevenue) {

    $row = $resultTodayRevenue->fetch_assoc();

    $todayRevenue = (float) ($row['total_revenue'] ?? 0);

}


// =====================================================
// YESTERDAY ORDERS
// =====================================================

$yesterdayOrders = 0;

$sqlYesterdayOrders = "
    SELECT COUNT(*) AS total_orders
    FROM orders
    WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
";

$resultYesterdayOrders = $conn->query($sqlYesterdayOrders);

if ($resultYesterdayOrders) {

    $row = $resultYesterdayOrders->fetch_assoc();

    $yesterdayOrders = (int) ($row['total_orders'] ?? 0);

}


// =====================================================
// YESTERDAY REVENUE
// =====================================================

$yesterdayRevenue = 0;

$sqlYesterdayRevenue = "
    SELECT COALESCE(SUM(total_amount), 0) AS total_revenue
    FROM orders
    WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    AND status = 'Success'
";

$resultYesterdayRevenue = $conn->query($sqlYesterdayRevenue);

if ($resultYesterdayRevenue) {

    $row = $resultYesterdayRevenue->fetch_assoc();

    $yesterdayRevenue = (float) ($row['total_revenue'] ?? 0);

}


// =====================================================
// PAGINATION
// =====================================================

$ordersPerPage = 8;

$currentPage = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}


// =====================================================
// TOTAL ORDERS HARI INI
// =====================================================

$totalOrders = 0;

$sqlTotalOrders = "
    SELECT COUNT(*) AS total
    FROM orders
    WHERE DATE(order_date) = CURDATE()
";

$resultTotalOrders = $conn->query($sqlTotalOrders);

if ($resultTotalOrders) {

    $row = $resultTotalOrders->fetch_assoc();

    $totalOrders = (int) ($row['total'] ?? 0);

}


$totalPages = max(
    1,
    (int) ceil($totalOrders / $ordersPerPage)
);


if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}


$offset = ($currentPage - 1) * $ordersPerPage;


// =====================================================
// AMBIL ORDER HARI INI
// =====================================================

$orders = [];

$sqlOrders = "
    SELECT
        o.order_id,
        o.user_id,
        o.order_date,
        o.total_amount,
        o.status,
        o.payment_method,
        u.name AS customer_name

    FROM orders o

    LEFT JOIN users u
        ON o.user_id = u.user_id

    WHERE DATE(o.order_date) = CURDATE()

    ORDER BY o.order_date DESC

    LIMIT $ordersPerPage
    OFFSET $offset
";


$resultOrders = $conn->query($sqlOrders);


// =====================================================
// PROSES DATA ORDER
// =====================================================

if ($resultOrders) {

    while ($order = $resultOrders->fetch_assoc()) {


        // =============================================
        // DATA MENU
        // =============================================

        $menuItems = [];

        $orderId = (int) $order['order_id'];


        $sqlDetails = "
            SELECT
                menu_name,
                quantity,
                size,
                temperature,
                note

            FROM order_details

            WHERE order_id = ?

            ORDER BY order_detail_id ASC
        ";


        $detailStmt = $conn->prepare($sqlDetails);


        if ($detailStmt) {

            $detailStmt->bind_param(
                "i",
                $orderId
            );

            $detailStmt->execute();

            $detailResult = $detailStmt->get_result();


            while ($detail = $detailResult->fetch_assoc()) {

                $menuItems[] = [

                    'menu_name' =>
                        $detail['menu_name'] ?? '',

                    'quantity' =>
                        $detail['quantity'] ?? 0,

                    'size' =>
                        $detail['size'] ?? '',

                    'temperature' =>
                        $detail['temperature'] ?? '',

                    'note' =>
                        $detail['note'] ?? ''

                ];

            }


            $detailStmt->close();

        }


        // =============================================
        // SIMPAN DATA ORDER
        // =============================================

        $orders[] = [

            'order_id' =>
                $orderId,

            'order_number' =>
                'ORD-' . str_pad(
                    $orderId,
                    4,
                    '0',
                    STR_PAD_LEFT
                ),

            'time' =>
                date(
                    'H:i',
                    strtotime($order['order_date'])
                ),

            'customer' =>
                $order['customer_name'] ?? 'Customer',

            'type' =>
                '-',

            'payment_method' =>
                $order['payment_method'] ?? '-',

            'status' =>
                $order['status'] ?? '-',

            'total_amount' =>
                $order['total_amount'] ?? 0,

            'menu' =>
                $menuItems

        ];

    }

}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Staff Dashboard - LÜMA</title>

    <!-- ICON -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <!-- DASHBOARD CSS -->
    <link
        rel="stylesheet"
        href="dashboard.css"
    >

</head>


<body>

<div class="dashboard">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">

        <!-- LOGO -->
        <div class="logo">

            <div class="brand-name">
                LÜMA
            </div>

        </div>


        <!-- NAVIGATION -->
        <nav class="sidebar-menu">

            <!-- DASHBOARD -->
            <a
                href="dashboard.php"
                class="menu-item active"
            >

                <i class="fa-solid fa-table-cells-large"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <!-- ORDER RECEIVE -->
            <a
                href="order_receive.php"
                class="menu-item"
            >

                <i class="fa-solid fa-receipt"></i>

                <span>
                    Order Receive
                </span>

            </a>


            <!-- MENU -->
            <a
                href="menu.php"
                class="menu-item"
            >

                <i class="fa-solid fa-mug-hot"></i>

                <span>
                    Menu
                </span>

            </a>


            <!-- LOGOUT -->
            <a
                href="../logout.php"
                class="logout"
            >

                <i class="fa-solid fa-arrow-right-from-bracket"></i>

                <span>
                    Logout
                </span>

            </a>

        </nav>

    </aside>



    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="topbar">


            <!-- TITLE -->
            <div class="topbar-title">

                <h2>
                    Dashboard
                </h2>

            </div>


            <!-- PROFILE -->
            <div class="cashier-profile">


                <!-- NOTIFICATION -->
                <button
                    type="button"
                    class="notification"
                    aria-label="Notifications"
                >

                    <i class="fa-regular fa-bell"></i>

                </button>


                <!-- PROFILE INFO -->
                <div class="profile-info">

                    <strong>
                        <?= htmlspecialchars($userName) ?>
                    </strong>

                    <small>
                        Staff
                    </small>

                </div>

            </div>

        </header>



        <!-- =================================================
             CONTENT
        ================================================== -->

        <section class="content">


            <!-- =================================================
                 WELCOME
            ================================================== -->

            <div class="welcome">

                <h1>
                    Welcome back,
                    <?= htmlspecialchars($userName) ?>!
                    👋
                </h1>

                <p>
                    Here's today's summary.
                </p>

            </div>



            <!-- =================================================
                 SUMMARY CARDS
            ================================================== -->

            <div class="summary-cards">


                <!-- TODAY ORDERS -->
                <div class="summary-card">


                    <div class="card-icon">

                        <i class="fa-solid fa-cube"></i>

                    </div>


                    <div class="card-info">

                        <span class="card-title">
                            Today's Orders
                        </span>

                        <strong class="card-value">
                            <?= $todayOrders ?>
                        </strong>

                        <span class="card-condition">

                            <?php

                            $orderDifference =
                                $todayOrders - $yesterdayOrders;

                            if ($orderDifference >= 0):

                            ?>

                                + <?= $orderDifference ?>
                                from yesterday

                            <?php else: ?>

                                <?= $orderDifference ?>
                                from yesterday

                            <?php endif; ?>

                        </span>

                    </div>

                </div>



                <!-- TODAY REVENUE -->
                <div class="summary-card">


                    <div class="card-icon">

                        <i class="fa-solid fa-money-bill"></i>

                    </div>


                    <div class="card-info">

                        <span class="card-title">
                            Today's Revenue
                        </span>

                        <strong class="card-value">
                            <?= rupiah($todayRevenue) ?>
                        </strong>

                        <span class="card-condition">

                            <?php

                            $revenueDifference =
                                $todayRevenue - $yesterdayRevenue;

                            if ($revenueDifference >= 0):

                            ?>

                                + <?= rupiah($revenueDifference) ?>
                                from yesterday

                            <?php else: ?>

                                <?= rupiah($revenueDifference) ?>
                                from yesterday

                            <?php endif; ?>

                        </span>

                    </div>

                </div>

            </div>



            <!-- =================================================
                 RECENT ORDERS
            ================================================== -->

            <section class="orders-section">


                <!-- SECTION HEADER -->
                <div class="section-header">


                    <div class="section-title">

                        <h1>
                            Recent Orders
                        </h1>

                        <p>
                            Today's transactions
                        </p>

                    </div>


                    <!-- NEW ORDER -->
                    <a
                        href="receive_order.php"
                        class="new-order-btn"
                    >

                        <i class="fa-solid fa-plus"></i>

                        New Order

                    </a>

                </div>



                <!-- =================================================
                     ORDERS CARD
                ================================================== -->

                <div class="orders-card">


                    <div class="orders-table">


                        <!-- TABLE HEADER -->
                        <div class="order-row order-header">


                            <div>
                                Order Number
                            </div>


                            <div>
                                Time
                            </div>


                            <div>
                                Menu
                            </div>


                            <div>
                                Customer Name
                            </div>


                            <div>
                                Order Type
                            </div>


                            <div>
                                Payment Method
                            </div>


                            <div>
                                Status
                            </div>


                            <div>
                                Action
                            </div>


                        </div>



                        <!-- =================================================
                             EMPTY ORDERS
                        ================================================== -->

                        <?php if (empty($orders)): ?>


                            <div class="no-orders">

                                <i class="fa-solid fa-mug-hot"></i>

                                <span>
                                    No orders today
                                </span>

                            </div>


                        <?php else: ?>


                            <!-- =================================================
                                 ORDER LOOP
                            ================================================== -->

                            <?php foreach ($orders as $order): ?>


                                <div class="order-row">


                                    <!-- ORDER NUMBER -->
                                    <div class="order-number">

                                        <?= htmlspecialchars(
                                            $order['order_number']
                                        ) ?>

                                    </div>



                                    <!-- TIME -->
                                    <div>

                                        <?= htmlspecialchars(
                                            $order['time']
                                        ) ?>

                                    </div>



                                    <!-- MENU -->
                                    <div class="menu-column">


                                        <?php if (!empty($order['menu'])): ?>


                                            <?php foreach (
                                                $order['menu'] as $menu
                                            ): ?>


                                                <div class="menu-item-row">


                                                    <strong>

                                                        <?= htmlspecialchars(
                                                            $menu['menu_name']
                                                        ) ?>

                                                    </strong>


                                                    <small>

                                                        <?= (int) $menu['quantity'] ?>x


                                                        <?php if (
                                                            !empty($menu['size'])
                                                        ): ?>

                                                            ·
                                                            <?= htmlspecialchars(
                                                                $menu['size']
                                                            ) ?>

                                                        <?php endif; ?>


                                                        <?php if (
                                                            !empty($menu['temperature'])
                                                        ): ?>

                                                            ·
                                                            <?= htmlspecialchars(
                                                                $menu['temperature']
                                                            ) ?>

                                                        <?php endif; ?>

                                                    </small>


                                                </div>


                                            <?php endforeach; ?>


                                        <?php else: ?>

                                            <span class="no-menu">
                                                -
                                            </span>

                                        <?php endif; ?>


                                    </div>



                                    <!-- CUSTOMER -->
                                    <div>

                                        <?= htmlspecialchars(
                                            $order['customer']
                                        ) ?>

                                    </div>



                                    <!-- ORDER TYPE -->
                                    <div>

                                        <?= htmlspecialchars(
                                            $order['type']
                                        ) ?>

                                    </div>



                                    <!-- PAYMENT METHOD -->
                                    <div class="payment-method">

                                        <?= htmlspecialchars(
                                            $order['payment_method']
                                        ) ?>

                                    </div>



                                    <!-- STATUS -->
                                    <div>


                                        <?php

                                        $statusClass =
                                            strtolower(
                                                $order['status']
                                            ) === 'success'
                                                ? 'complete'
                                                : 'cancelled';

                                        ?>


                                        <span
                                            class="status <?= $statusClass ?>"
                                        >

                                            <?= htmlspecialchars(
                                                $order['status']
                                            ) ?>

                                        </span>


                                    </div>



                                    <!-- ACTION -->
                                    <div>

                                        <a
                                            href="order_detail.php?id=<?= (int) $order['order_id'] ?>"
                                            class="details-btn"
                                        >

                                            Details

                                        </a>

                                    </div>


                                </div>


                            <?php endforeach; ?>


                        <?php endif; ?>


                    </div>



                    <!-- =================================================
                         TABLE FOOTER / PAGINATION
                    ================================================== -->

                    <?php if ($totalOrders > 0): ?>


                        <div class="table-footer">


                            <!-- SHOWING -->
                            <div>

                                Showing

                                <?= (($currentPage - 1) * $ordersPerPage) + 1 ?>

                                -

                                <?= min(
                                    $currentPage * $ordersPerPage,
                                    $totalOrders
                                ) ?>

                                of

                                <?= $totalOrders ?>

                                orders today

                            </div>



                            <!-- PAGINATION -->
                            <div class="pagination">


                                <!-- PREVIOUS -->
                                <?php if ($currentPage > 1): ?>

                                    <a
                                        href="?page=<?= $currentPage - 1 ?>"
                                        class="page-btn"
                                    >

                                        <i class="fa-solid fa-chevron-left"></i>

                                    </a>

                                <?php else: ?>

                                    <span
                                        class="page-btn disabled"
                                    >

                                        <i class="fa-solid fa-chevron-left"></i>

                                    </span>

                                <?php endif; ?>



                                <!-- PAGE NUMBERS -->
                                <?php for (
                                    $page = 1;
                                    $page <= $totalPages;
                                    $page++
                                ): ?>

                                    <a
                                        href="?page=<?= $page ?>"
                                        class="page-btn <?= $page === $currentPage ? 'active' : '' ?>"
                                    >

                                        <?= $page ?>

                                    </a>

                                <?php endfor; ?>



                                <!-- NEXT -->
                                <?php if ($currentPage < $totalPages): ?>

                                    <a
                                        href="?page=<?= $currentPage + 1 ?>"
                                        class="page-btn"
                                    >

                                        <i class="fa-solid fa-chevron-right"></i>

                                    </a>

                                <?php else: ?>

                                    <span
                                        class="page-btn disabled"
                                    >

                                        <i class="fa-solid fa-chevron-right"></i>

                                    </span>

                                <?php endif; ?>


                            </div>


                        </div>


                    <?php endif; ?>


                </div>


            </section>


        </section>


    </main>


</div>


</body>

</html>