<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if ($user !== null) {
  redirect_to('api/dashboard.php');
}

$error = query_param('error');
$ok = query_param('ok');
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
<div class="theme-toolbar">
  <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">Light mode</button>
</div>
<main class="page page-login">
  <div class="login-shell">
    <section class="login-column login-column-form" aria-label="Login panel">
      <div class="login-brand">
        <h1>Dashboard<br>FurtArt</h1>
      </div>

      <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="alert alert-error">Login Error: <?= h($error) ?></p><?php endif; ?>

      <form class="login-form" action="/api/actions/login.php" method="post">
        <p><label for="email">Username</label></p>
        <p><input id="email" name="email" type="email" required></p>

        <p><label for="password">Password</label></p>
        <div class="password-row login-password-row">
          <input id="password" name="password" type="password" required>
          <button type="button" class="toggle-password" data-target="password" aria-pressed="false">Show</button>
        </div>

        <p class="login-forgot-wrap"><a href="#" class="login-forgot">Forgot Password?</a></p>

        <button type="submit" class="login-submit">
          <span>Login</span>
          <span class="login-submit-arrow" aria-hidden="true">&#8250;</span>
        </button>
      </form>

      <p class="login-register">Don't have an account? <a href="#">Register</a></p>

      <section class="list-panel login-test-users">
        <p>admin@example.com / Pass123!</p>
        <p>staff@example.com / Pass123!</p>
        <p>maintenance@example.com / Pass123!</p>
      </section>
    </section>

    <aside class="login-column login-column-visual" aria-label="Visual panel">
      <div class="login-visual-center">
        <svg viewBox="0 0 96 84" aria-hidden="true" focusable="false">
          <rect x="14" y="18" width="52" height="40" rx="4" fill="none" stroke="currentColor" stroke-width="6" transform="rotate(-6 14 18)"/>
          <rect x="28" y="12" width="52" height="40" rx="4" fill="none" stroke="currentColor" stroke-width="6"/>
          <rect x="36" y="24" width="34" height="22" rx="2" fill="none" stroke="currentColor" stroke-width="4"/>
          <path d="M42 42l8-9 10 13H42z" fill="currentColor"/>
          <circle cx="62" cy="31" r="3.5" fill="currentColor"/>
        </svg>
        <p>Image Here</p>
      </div>
    </aside>
  </div>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
