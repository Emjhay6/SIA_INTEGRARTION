<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

// TODO: Display the appropriate checkout result.

$gatewayUrl = 'http://localhost/payment-gateway/api/payments.php';
$idempotencyKey = 'shopping-cart-order-' . $orderId;

$payload = [
    'order_id'       => $orderId,
    'amount'         => round($total, 2),
    'customer_name'  => $customer,
    'payment_method' => $method,
    'idempotency_key'=> $idempotencyKey,
];

$jsonPayload = json_encode($payload);
if ($jsonPayload === false) {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute(['PENDING_PAYMENT', $orderId]);
    finish('Payment pending', 'The payment request could not be prepared. Your order remains pending. Check Order History before trying again.');
}

$ch = curl_init($gatewayUrl);
if ($ch === false) {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute(['PENDING_PAYMENT', $orderId]);
    finish('Payment pending', 'The payment service could not be reached. Your order remains pending. Check Order History before trying again.');
}

curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => $jsonPayload,
    CURLOPT_HTTPHEADER      => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Length: ' . strlen($jsonPayload),
    ],
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_CONNECTTIMEOUT  => 5,
    CURLOPT_TIMEOUT         => 15,
]);

$responseBody = curl_exec($ch);
$curlErrorNo = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $curlErrorNo !== 0) {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute(['PENDING_PAYMENT', $orderId]);
    finish('Payment status unconfirmed', 'The payment gateway could not be contacted reliably. Your order remains pending. Check Order History before attempting payment again.');
}

$gatewayData = json_decode($responseBody, true);
if ($httpCode < 200 || $httpCode >= 300 || !is_array($gatewayData)) {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute(['PENDING_PAYMENT', $orderId]);
    finish('Payment status unconfirmed', 'The gateway returned an error or an invalid response. Your order remains pending. Check Order History before attempting payment again.');
}

$gatewayOrderId = filter_var($gatewayData['order_id'] ?? null, FILTER_VALIDATE_INT);
$gatewayAmount = filter_var($gatewayData['amount'] ?? null, FILTER_VALIDATE_FLOAT);
$gatewayStatus = strtoupper((string)($gatewayData['status'] ?? ''));
$transactionId = trim((string)($gatewayData['transaction_id'] ?? ''));
$gatewaySuccess = ($gatewayData['success'] ?? false) === true;

if (!$gatewaySuccess ||
    $gatewayOrderId !== $orderId ||
    $gatewayAmount === false ||
    abs((float)$gatewayAmount - round($total, 2)) > 0.009 ||
    $transactionId === '' ||
    !in_array($gatewayStatus, ['SUCCESS', 'FAILED', 'PENDING'], true)) {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute(['PENDING_PAYMENT', $orderId]);
    finish('Payment status unconfirmed', 'The gateway response could not be safely verified. Your order remains pending. Check Order History before attempting payment again.');
}

switch ($gatewayStatus) {
    case 'SUCCESS':
        $orderStatus = 'PAID';
        $message = 'Payment successful. Your order has been marked as paid.';
        $title = 'Payment successful';
        break;
    case 'FAILED':
        $orderStatus = 'PAYMENT_FAILED';
        $message = 'The gateway confirmed that the payment failed. You may review your order history.';
        $title = 'Payment failed';
        break;
    default:
        $orderStatus = 'PENDING_PAYMENT';
        $message = 'Your payment is awaiting confirmation. Check Order History before trying again.';
        $title = 'Payment pending';
        break;
}

try {
    $stmt = $pdo->prepare('UPDATE orders SET status = ?, gateway_transaction_id = ? WHERE id = ?');
    $stmt->execute([$orderStatus, $transactionId, $orderId]);
    if ($stmt->rowCount() === 0) {
    
        $check = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $check->execute([$orderId]);
        if (!$check->fetch()) {
            finish('Order update error', 'The payment response was received, but the order record could not be found. Contact your instructor before retrying payment.');
        }
    }
} catch (Throwable $e) {
    finish('Payment status unconfirmed', 'The gateway responded, but the shopping cart could not save the payment result. Check Order History before attempting payment again.');
}

if ($gatewayStatus === 'SUCCESS') {
    unset($_SESSION['cart']);
}
finish($title, $message);