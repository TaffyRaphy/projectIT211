<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if ($user !== null) {
  redirect_to('api/dashboard.php');
}

$error = query_param('error');
?>
<!doctype html>
<html lang="en" class="login-page">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Equipment Management System</title>
</head>
<body class="login-page">
<div class="theme-toolbar">
  <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
</div>
<main class="page page-login">
  <div class="login-shell">
    <section class="login-column login-column-form" aria-label="Login panel">
      <p class="login-auth-kicker">Access Portal</p>
      <h1 class="login-auth-title">Welcome back</h1>
      <p class="login-auth-copy">Sign in to manage equipment inventory, approvals, maintenance schedules, and reports.</p>

      <?php if ($error !== ''): ?><p class="alert alert-error">Login Error: <?= h($error) ?></p><?php endif; ?>

      <form class="login-form" action="/api/actions/login.php" method="post">
        <p><label for="email">Username</label></p>
        <p><input id="email" name="email" type="email" required></p>

        <p><label for="password">Password</label></p>
        <div class="password-row login-password-row password-field-wrap">
          <input id="password" name="password" type="password" required>
          <button type="button" class="toggle-password toggle-password-inside" data-target="password" aria-pressed="false" aria-label="Show password">👁</button>
        </div>

        <p class="login-forgot-wrap"><a href="#" class="login-forgot">Forgot Password?</a></p>

        <button type="submit" class="login-submit">
          <span>Sign in</span>
          <span class="login-submit-arrow" aria-hidden="true">&#8250;</span>
        </button>
      </form>

      <section class="list-panel login-test-users">
        <button type="button" class="login-seed" data-login-seed data-email="admin@example.com" data-password="Pass123!">admin@example.com / Pass123!</button>
        <button type="button" class="login-seed" data-login-seed data-email="staff@example.com" data-password="Pass123!">staff@example.com / Pass123!</button>
        <button type="button" class="login-seed" data-login-seed data-email="maintenance@example.com" data-password="Pass123!">maintenance@example.com / Pass123!</button>
      </section>
    </section>

    <aside class="login-column login-column-visual" aria-label="System title and branding panel">
      <div class="login-visual-center">
        <p class="login-visual-eyebrow">Web Systems and Technology</p>
        <h2 class="login-visual-title">Equipement Management System</h2>
        <p class="login-visual-subtitle">A role-based platform for stock control, request workflows, maintenance planning, and historical reporting.</p>
        <div class="login-accent-lines" aria-hidden="true">
          <span></span>
          <span></span>
          <span></span>
        </div>
      </div>
    </aside>
  </div>
</main>
<script src="/assets/app.js"></script>
</body>
</html>

