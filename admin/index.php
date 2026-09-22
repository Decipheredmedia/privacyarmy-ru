<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/api/lib/orders.php';

$hash = (string) getenv('ADMIN_PASSWORD_HASH');
if ($hash === '') {
    http_response_code(500);
    echo 'ADMIN_PASSWORD_HASH is not configured.';
    exit;
}

if (isset($_POST['logout'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: /admin/index.php');
    exit;
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify((string) $_POST['password'], $hash)) {
            $_SESSION['admin_authenticated'] = true;
            header('Location: /admin/index.php');
            exit;
        }
        $loginError = 'Invalid password.';
    }
    ?>
    <!doctype html>
    <html lang="en">
    <meta charset="utf-8">
    <title>PrivacyArmy Admin Login</title>
    <body>
      <h1>PrivacyArmy Admin</h1>
      <?php if (isset($loginError)) : ?><p><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
      <form method="post">
        <label>Password <input type="password" name="password" required></label>
        <button type="submit">Log in</button>
      </form>
    </body>
    </html>
    <?php
    exit;
}

$orders = load_orders();
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['admin_notes'])) {
    $index = find_order_index($orders, (string) $_POST['order_id']);
    if ($index !== null) {
        $orders[$index]['admin_notes'] = trim((string) $_POST['admin_notes']);
        if (isset($_POST['status']) && $_POST['status'] !== '') {
            $orders[$index]['status'] = trim((string) $_POST['status']);
        }
        $orders[$index]['updated_at'] = gmdate(DATE_ATOM);
        save_orders($orders);
        $message = 'Order updated.';
        $orders = load_orders();
    }
}
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>PrivacyArmy Admin Dashboard</title>
<body>
  <h1>PrivacyArmy Orders</h1>
  <form method="post"><button type="submit" name="logout" value="1">Log out</button></form>
  <?php if ($message) : ?><p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
  <table border="1" cellpadding="8" cellspacing="0">
    <thead>
      <tr>
        <th>Order</th>
        <th>Product</th>
        <th>Customer</th>
        <th>Status</th>
        <th>Amount (USD)</th>
        <th>Notes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (array_reverse($orders) as $order) : ?>
      <tr>
        <td><?php echo htmlspecialchars((string) $order['order_id'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) $order['product_title'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string) $order['customer_email'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars((string) $order['order_id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="status" value="<?php echo htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8'); ?>">
        </td>
        <td><?php echo htmlspecialchars((string) $order['amount_usd'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td>
            <textarea name="admin_notes" rows="3" cols="40"><?php echo htmlspecialchars((string) ($order['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div><button type="submit">Save</button></div>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
