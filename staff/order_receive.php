<?php
session_start();

/*
|--------------------------------------------------------------------------
| SAMPLE ORDER DATA
|--------------------------------------------------------------------------
| Untuk sementara data masih menggunakan array.
| Nanti bagian ini bisa diganti dengan query database.
|--------------------------------------------------------------------------
*/

$orders = [

    [
        "number" => "#ORD-001",
        "customer" => "Evellyn",
        "time" => "10:15 AM",
        "type" => "Dine In",

        "items" => [
            [
                "name" => "Cappuccino",
                "quantity" => 1,
                "size" => "Medium",
                "temperature" => "Hot",
                "price" => 35000
            ],
            [
                "name" => "Croissant",
                "quantity" => 1,
                "size" => "",
                "temperature" => "",
                "price" => 20000
            ]
        ],

        "total" => 55000,
        "status" => "Pending"
    ],


    [
        "number" => "#ORD-002",
        "customer" => "Amanda",
        "time" => "10:22 AM",
        "type" => "Take Away",

        "items" => [
            [
                "name" => "Iced Latte",
                "quantity" => 1,
                "size" => "Large",
                "temperature" => "Cold",
                "price" => 30000
            ]
        ],

        "total" => 30000,
        "status" => "Pending"
    ],


    [
        "number" => "#ORD-003",
        "customer" => "Rafael",
        "time" => "10:30 AM",
        "type" => "Dine In",

        "items" => [
            [
                "name" => "Americano",
                "quantity" => 1,
                "size" => "Medium",
                "temperature" => "Hot",
                "price" => 25000
            ],
            [
                "name" => "Donut",
                "quantity" => 2,
                "size" => "",
                "temperature" => "",
                "price" => 11000
            ]
        ],

        "total" => 47000,
        "status" => "Processing"
    ],


    [
        "number" => "#ORD-004",
        "customer" => "Nadia",
        "time" => "10:36 AM",
        "type" => "Take Away",

        "items" => [
            [
                "name" => "Matcha Latte",
                "quantity" => 1,
                "size" => "Large",
                "temperature" => "Cold",
                "price" => 32000
            ],
            [
                "name" => "Cookies",
                "quantity" => 1,
                "size" => "",
                "temperature" => "",
                "price" => 16000
            ]
        ],

        "total" => 48000,
        "status" => "Processing"
    ],


    [
        "number" => "#ORD-005",
        "customer" => "Kevin",
        "time" => "10:45 AM",
        "type" => "Dine In",

        "items" => [
            [
                "name" => "Caramel Macchiato",
                "quantity" => 2,
                "size" => "Medium",
                "temperature" => "Hot",
                "price" => 35000
            ]
        ],

        "total" => 70000,
        "status" => "Completed"
    ],


    [
        "number" => "#ORD-006",
        "customer" => "Sarah",
        "time" => "10:52 AM",
        "type" => "Take Away",

        "items" => [
            [
                "name" => "Chocolate Cake",
                "quantity" => 1,
                "size" => "",
                "temperature" => "",
                "price" => 35000
            ]
        ],

        "total" => 35000,
        "status" => "Pending"
    ]

];


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$filter = $_GET['status'] ?? 'All';


$filteredOrders = $orders;


if ($filter !== 'All') {

    $filteredOrders = array_filter(
        $orders,
        function ($order) use ($filter) {

            return strtolower($order['status']) === strtolower($filter);

        }
    );
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$pendingCount = 0;
$processingCount = 0;
$completedCount = 0;

foreach ($orders as $order) {

    if ($order['status'] === 'Pending') {
        $pendingCount++;
    }

    if ($order['status'] === 'Processing') {
        $processingCount++;
    }

    if ($order['status'] === 'Completed') {
        $completedCount++;
    }

}

$totalOrders = count($orders);


/*
|--------------------------------------------------------------------------
| FORMAT RUPIAH
|--------------------------------------------------------------------------
*/

function rupiah($number)
{
    return 'Rp ' . number_format(
        $number,
        0,
        ',',
        '.'
    );
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

    <title>
        Order Receive - LÜMA
    </title>


    <!-- =====================================================
         FONT AWESOME
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <!-- =====================================================
         BOOTSTRAP
    ====================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =====================================================
         CASHIER CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="order_receive.css"
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


        <!-- MENU -->

        <nav class="sidebar-menu">


            <!-- DASHBOARD -->

            <a
                href="dashboard.php"
                class="menu-item"
            >

                <i class="fa-solid fa-table-cells-large"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <!-- ORDER RECEIVE -->

            <a
                href="order_receive.php"
                class="menu-item active"
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


        </nav>


        <!-- LOGOUT -->

        <a
            href="../login.php"
            class="logout"
        >

            <i class="fa-solid fa-arrow-right-from-bracket"></i>

            <span>
                Logout
            </span>

        </a>


    </aside>



    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="topbar">


            <div class="topbar-title">

                <h2>
                    Order Receive
                </h2>

            </div>


            <div class="cashier-profile">


                <!-- NOTIFICATION -->

                <button
                    type="button"
                    class="notification"
                >

                    <i class="fa-regular fa-bell"></i>

                </button>


                <!-- PROFILE -->

                <div class="profile-info">

                    <strong>
                        Raka Pratama
                    </strong>

                    <small>
                        Staff
                    </small>

                </div>


            </div>


        </header>



        <!-- =================================================
             WELCOME
        ================================================== -->

        <section class="welcome">


            <h1>
                Good Morning, Cashier! 👋
            </h1>


            <p>
                Here's today's order.
            </p>


        </section>



        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <section class="summary-cards">


            <!-- PENDING -->

            <div class="summary-card">

                <div class="card-icon">

                    <i class="fa-solid fa-hourglass-half"></i>

                </div>


                <div class="card-info">

                    <span class="card-title">
                        Pending
                    </span>

                    <strong class="card-value">
                        <?= $pendingCount ?>
                    </strong>

                </div>

            </div>



            <!-- PROCESSING -->

            <div class="summary-card">

                <div class="card-icon">

                    <i class="fa-solid fa-rotate"></i>

                </div>


                <div class="card-info">

                    <span class="card-title">
                        Processing
                    </span>

                    <strong class="card-value">
                        <?= $processingCount ?>
                    </strong>

                </div>

            </div>



            <!-- COMPLETED -->

            <div class="summary-card">

                <div class="card-icon">

                    <i class="fa-regular fa-circle-check"></i>

                </div>


                <div class="card-info">

                    <span class="card-title">
                        Completed
                    </span>

                    <strong class="card-value">
                        <?= $completedCount ?>
                    </strong>

                </div>

            </div>



            <!-- TOTAL -->

            <div class="summary-card">

                <div class="card-icon">

                    <i class="fa-solid fa-receipt"></i>

                </div>


                <div class="card-info">

                    <span class="card-title">
                        Total Orders
                    </span>

                    <strong class="card-value">
                        <?= $totalOrders ?>
                    </strong>

                </div>

            </div>


        </section>



        <!-- =================================================
             ORDER SECTION
        ================================================== -->

        <section class="orders-section">


            <!-- SECTION HEADER -->

            <div class="section-header">


                <div class="section-title">

                    <h1>
                        Order Receive
                    </h1>

                    <p>
                        Manage today's customer orders
                    </p>

                </div>


            </div>



            <!-- =================================================
                 FILTER
            ================================================== -->

            <div class="order-filter">


                <a
                    href="order_receive.php?status=All"
                    class="<?= $filter === 'All' ? 'active' : '' ?>"
                >
                    All
                </a>


                <a
                    href="order_receive.php?status=Pending"
                    class="<?= $filter === 'Pending' ? 'active' : '' ?>"
                >
                    Pending
                </a>


                <a
                    href="order_receive.php?status=Processing"
                    class="<?= $filter === 'Processing' ? 'active' : '' ?>"
                >
                    Processing
                </a>


                <a
                    href="order_receive.php?status=Completed"
                    class="<?= $filter === 'Completed' ? 'active' : '' ?>"
                >
                    Completed
                </a>


            </div>



            <!-- =================================================
                 TABLE CARD
            ================================================== -->

            <div class="orders-card">


                <div class="orders-table">


                    <!-- TABLE HEADER -->

                    <div class="order-row order-header">


                        <div>
                            ORDER NUMBER
                        </div>


                        <div>
                            CUSTOMER
                        </div>


                        <div>
                            TIME
                        </div>


                        <div>
                            ORDER TYPE
                        </div>


                        <div>
                            ITEMS
                        </div>


                        <div>
                            TOTAL
                        </div>


                        <div>
                            STATUS
                        </div>


                        <div>
                            ACTION
                        </div>


                    </div>



                    <!-- =================================================
                         ORDER DATA
                    ================================================== -->

                    <?php if (count($filteredOrders) > 0): ?>


                        <?php foreach ($filteredOrders as $order): ?>


                            <div class="order-row">


                                <!-- ORDER NUMBER -->

                                <div class="order-number">

                                    <?= htmlspecialchars($order['number']) ?>

                                </div>



                                <!-- CUSTOMER -->

                                <div>

                                    <?= htmlspecialchars($order['customer']) ?>

                                </div>



                                <!-- TIME -->

                                <div>

                                    <?= htmlspecialchars($order['time']) ?>

                                </div>



                                <!-- ORDER TYPE -->

                                <div>

                                    <span class="order-type">

                                        <?= htmlspecialchars($order['type']) ?>

                                    </span>

                                </div>



                                <!-- ITEMS -->

                                <div class="menu-column">


                                    <?php foreach ($order['items'] as $item): ?>


                                        <div class="menu-item-row">


                                            <strong>

                                                <?= htmlspecialchars($item['name']) ?>

                                                ×<?= $item['quantity'] ?>

                                            </strong>


                                            <?php if (
                                                !empty($item['size']) ||
                                                !empty($item['temperature'])
                                            ): ?>

                                                <small>

                                                    <?= htmlspecialchars($item['size']) ?>


                                                    <?php if (
                                                        !empty($item['size']) &&
                                                        !empty($item['temperature'])
                                                    ): ?>

                                                        ·

                                                    <?php endif; ?>


                                                    <?= htmlspecialchars($item['temperature']) ?>

                                                </small>

                                            <?php endif; ?>


                                        </div>


                                    <?php endforeach; ?>


                                </div>



                                <!-- TOTAL -->

                                <div>

                                    <strong>

                                        <?= rupiah($order['total']) ?>

                                    </strong>

                                </div>



                                <!-- STATUS -->

                                <div>

                                    <span
                                        class="status <?= strtolower($order['status']) ?>"
                                    >

                                        <?= htmlspecialchars($order['status']) ?>

                                    </span>

                                </div>



                                <!-- ACTION -->

                                <div class="order-actions">


                                    <!-- DETAIL -->

                                    <button
                                        type="button"
                                        class="action-btn detail-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#detailModal<?= substr($order['number'], 5) ?>"
                                        title="View Order Detail"
                                    >

                                        <i class="fa-regular fa-eye"></i>

                                    </button>


                                    <!-- COMPLETE -->

                                    <?php if ($order['status'] === 'Completed'): ?>

                                        <button
                                            type="button"
                                            class="action-btn complete-btn completed-btn"
                                            disabled
                                            title="Order Completed"
                                        >

                                            <i class="fa-solid fa-check"></i>

                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="action-btn complete-btn"
                                            onclick="completeOrder(this)"
                                            title="Mark as Completed"
                                        >

                                            <i class="fa-solid fa-check"></i>

                                        </button>

                                    <?php endif; ?>


                                </div>


                            </div>



                        <?php endforeach; ?>


                    <?php else: ?>


                        <!-- NO ORDER -->

                        <div class="no-orders">


                            <i class="fa-solid fa-mug-hot"></i>


                            <strong>
                                No orders found
                            </strong>


                            <span>
                                There are no orders with this status.
                            </span>


                        </div>


                    <?php endif; ?>


                </div>


            </div>


        </section>


    </main>


</div>



<!-- =========================================================
     DETAIL MODALS
========================================================= -->


<?php foreach ($filteredOrders as $order): ?>


    <div
        class="modal fade"
        id="detailModal<?= substr($order['number'], 5) ?>"
        tabindex="-1"
        aria-hidden="true"
    >


        <div class="modal-dialog modal-dialog-centered">


            <div class="modal-content">


                <!-- MODAL HEADER -->

                <div class="modal-header">


                    <div>

                        <h5 class="modal-title">
                            Order Detail
                        </h5>

                        <small>

                            <?= htmlspecialchars($order['number']) ?>

                        </small>

                    </div>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>


                </div>



                <!-- MODAL BODY -->

                <div class="modal-body">


                    <!-- CUSTOMER -->

                    <div class="detail-info">


                        <div>

                            <span>
                                Customer
                            </span>

                            <strong>
                                <?= htmlspecialchars($order['customer']) ?>
                            </strong>

                        </div>


                        <div>

                            <span>
                                Order Type
                            </span>

                            <strong>
                                <?= htmlspecialchars($order['type']) ?>
                            </strong>

                        </div>


                        <div>

                            <span>
                                Time
                            </span>

                            <strong>
                                <?= htmlspecialchars($order['time']) ?>
                            </strong>

                        </div>


                    </div>



                    <hr>



                    <!-- ITEM DETAIL -->

                    <h6>
                        Order Items
                    </h6>


                    <div class="detail-items">


                        <?php foreach ($order['items'] as $item): ?>


                            <div class="detail-item">


                                <div class="detail-item-name">


                                    <strong>

                                        <?= htmlspecialchars($item['name']) ?>

                                        ×<?= $item['quantity'] ?>

                                    </strong>


                                    <?php if (
                                        !empty($item['size']) ||
                                        !empty($item['temperature'])
                                    ): ?>

                                        <small>

                                            <?= htmlspecialchars($item['size']) ?>


                                            <?php if (
                                                !empty($item['size']) &&
                                                !empty($item['temperature'])
                                            ): ?>

                                                ·

                                            <?php endif; ?>


                                            <?= htmlspecialchars($item['temperature']) ?>

                                        </small>

                                    <?php endif; ?>


                                </div>


                                <strong>

                                    <?= rupiah($item['price'] * $item['quantity']) ?>

                                </strong>


                            </div>


                        <?php endforeach; ?>


                    </div>



                    <hr>



                    <!-- TOTAL -->

                    <div class="detail-total">


                        <span>
                            Total
                        </span>


                        <strong>
                            <?= rupiah($order['total']) ?>
                        </strong>


                    </div>


                </div>



                <!-- MODAL FOOTER -->

                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>


                </div>


            </div>


        </div>


    </div>


<?php endforeach; ?>



<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
></script>



<!-- =========================================================
     COMPLETE ORDER
========================================================= -->

<script>

function completeOrder(button) {

    const row = button.closest('.order-row');

    const status = row.querySelector('.status');


    /* Change status */

    status.textContent = 'Completed';

    status.classList.remove(
        'pending',
        'processing'
    );

    status.classList.add(
        'completed'
    );


    /* Change button */

    button.classList.add(
        'completed-btn'
    );

    button.disabled = true;


    button.title = 'Order Completed';

}

</script>


</body>

</html>