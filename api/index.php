<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$error = query_param('error');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Equipment Management System</title>
</head>
<body>
<main class="page page-login">
  <h1>Equipment Management System</h1>
  <p>Login workflow (session handling intentionally omitted in workflow mode).</p>
  <?php if ($error !== ''): ?><p class="alert alert-error">Login Error: <?= h($error) ?></p><?php endif; ?>
  <form class="panel" action="/api/actions/login.php" method="post">
    <p><label for="email">Email</label></p>
    <p><input id="email" name="email" type="email" required></p>
    <p><label for="password">Password</label></p>
    <p><input id="password" name="password" type="password" required></p>
    <p><button type="submit">Login</button></p>
  </form>
  <hr>
  <h2>Seeded Test Users</h2>
  <section class="list-panel">
    <p>admin@example.com / Pass123!</p>
    <p>staff@example.com / Pass123!</p>
    <p>maintenance@example.com / Pass123!</p>
  </section>
</main>
</body>
</html>
