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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;800&family=Rajdhani:wght@300;400;500&display=swap" rel="stylesheet">
  <title>Equipment Management System</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --cyan:        #00e5ff;
      --cyan-dim:    #00b8cc;
      --cyan-glow:   rgba(0, 229, 255, 0.18);
      --cyan-border: rgba(0, 229, 255, 0.28);
      --bg-deep:     #050b12;
      --bg-mid:      #0a1520;
      --bg-panel:    rgba(10, 22, 35, 0.75);
      --text-primary: #e8f4f8;
      --text-muted:   #5a7a8a;
      --text-label:   #7ab8c8;
      --error-bg:    rgba(255, 60, 80, 0.12);
      --error-border: rgba(255, 60, 80, 0.4);
      --error-text:  #ff8090;
      --success-bg:  rgba(0, 229, 180, 0.1);
      --success-border: rgba(0, 229, 180, 0.35);
      --success-text: #00e5b4;
    }

    html, body {
      height: 100%;
      font-family: 'Rajdhani', sans-serif;
      background-color: var(--bg-deep);
      color: var(--text-primary);
      overflow-x: hidden;
    }

    /* ── Grid background ── */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(0,229,255,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,229,255,0.04) 1px, transparent 1px);
      background-size: 48px 48px;
      pointer-events: none;
      z-index: 0;
    }

    /* ── Ambient glows ── */
    body::after {
      content: '';
      position: fixed;
      top: -20%;
      left: -10%;
      width: 60%;
      height: 60%;
      background: radial-gradient(ellipse, rgba(0,120,160,0.18) 0%, transparent 70%);
      pointer-events: none;
      z-index: 0;
    }

    .glow-br {
      position: fixed;
      bottom: -15%;
      right: -5%;
      width: 50%;
      height: 50%;
      background: radial-gradient(ellipse, rgba(0,60,100,0.2) 0%, transparent 70%);
      pointer-events: none;
      z-index: 0;
    }

    /* ── Layout ── */
    main {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 2rem 1rem;
    }

    .login-wrapper {
      width: 100%;
      max-width: 420px;
      animation: fadeUp 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Brand header ── */
    .brand {
      text-align: center;
      margin-bottom: 2.4rem;
    }

    .brand-icon {
      width: 52px;
      height: 52px;
      margin: 0 auto 1rem;
      border: 1.5px solid var(--cyan-border);
      border-radius: 12px;
      background: var(--cyan-glow);
      display: grid;
      place-items: center;
      box-shadow: 0 0 20px var(--cyan-glow), inset 0 0 12px var(--cyan-glow);
    }

    .brand-icon svg {
      width: 26px;
      height: 26px;
      fill: none;
      stroke: var(--cyan);
      stroke-width: 1.5;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .brand h1 {
      font-family: 'Orbitron', sans-serif;
      font-size: 1rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--text-primary);
      line-height: 1.4;
    }

    .brand h1 span {
      display: block;
      color: var(--cyan);
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.22em;
      margin-top: 0.25rem;
      opacity: 0.85;
    }

    .brand p {
      font-size: 0.9rem;
      color: var(--text-muted);
      margin-top: 0.6rem;
      font-weight: 300;
      letter-spacing: 0.04em;
    }

    /* ── Alerts ── */
    .alert {
      border-radius: 8px;
      padding: 0.7rem 1rem;
      font-size: 0.875rem;
      font-weight: 500;
      letter-spacing: 0.02em;
      margin-bottom: 1rem;
      border-left: 3px solid;
    }

    .alert-success {
      background: var(--success-bg);
      border-color: var(--success-text);
      color: var(--success-text);
    }

    .alert-error {
      background: var(--error-bg);
      border-color: var(--error-text);
      color: var(--error-text);
    }

    /* ── Glass panel ── */
    .panel {
      background: var(--bg-panel);
      border: 1px solid var(--cyan-border);
      border-radius: 16px;
      padding: 2rem;
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow:
        0 0 0 1px rgba(0,229,255,0.04) inset,
        0 24px 64px rgba(0,0,0,0.45),
        0 0 40px rgba(0,80,120,0.12);
      position: relative;
      overflow: hidden;
    }

    /* top-edge cyan shimmer */
    .panel::before {
      content: '';
      position: absolute;
      top: 0; left: 10%; right: 10%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--cyan), transparent);
      opacity: 0.5;
    }

    /* ── Form elements ── */
    .field { margin-bottom: 1.35rem; }

    label {
      display: block;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--text-label);
      margin-bottom: 0.5rem;
    }

    input[type="email"],
    input[type="password"],
    input[type="text"] {
      width: 100%;
      background: rgba(0, 229, 255, 0.04);
      border: 1px solid rgba(0, 229, 255, 0.18);
      border-radius: 8px;
      padding: 0.7rem 1rem;
      color: var(--text-primary);
      font-family: 'Rajdhani', sans-serif;
      font-size: 1rem;
      font-weight: 400;
      letter-spacing: 0.03em;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }

    input:focus {
      border-color: var(--cyan);
      background: rgba(0, 229, 255, 0.08);
      box-shadow: 0 0 0 3px rgba(0,229,255,0.1);
    }

    input::placeholder { color: var(--text-muted); }

    /* ── Password row ── */
    .password-row {
      position: relative;
    }

    .password-row input {
      padding-right: 4.5rem;
    }

    .toggle-password {
      position: absolute;
      right: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--text-muted);
      font-family: 'Rajdhani', sans-serif;
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      cursor: pointer;
      padding: 0.25rem 0.4rem;
      transition: color 0.2s;
    }

    .toggle-password:hover { color: var(--cyan); }

    /* ── Submit button ── */
    .btn-submit {
      width: 100%;
      margin-top: 0.5rem;
      background: linear-gradient(135deg, rgba(0,180,210,0.2), rgba(0,80,140,0.25));
      border: 1px solid var(--cyan-border);
      border-radius: 8px;
      color: var(--cyan);
      font-family: 'Orbitron', sans-serif;
      font-size: 0.8rem;
      font-weight: 600;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      padding: 0.85rem 1rem;
      cursor: pointer;
      position: relative;
      overflow: hidden;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .btn-submit::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(0,229,255,0.12), transparent);
      opacity: 0;
      transition: opacity 0.2s;
    }

    .btn-submit:hover {
      border-color: var(--cyan);
      box-shadow: 0 0 20px rgba(0,229,255,0.2), 0 0 6px rgba(0,229,255,0.15) inset;
    }

    .btn-submit:hover::before { opacity: 1; }
    .btn-submit:active { transform: scale(0.99); }

    /* ── Divider ── */
    .divider {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin: 1.5rem 0;
      color: var(--text-muted);
      font-size: 0.7rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }

    .divider::before, .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: rgba(0,229,255,0.12);
    }

    /* ── Test users section ── */
    .test-users {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(0,229,255,0.1);
      border-radius: 10px;
      overflow: hidden;
    }

    .test-users-header {
      padding: 0.55rem 1rem;
      font-size: 0.65rem;
      font-family: 'Orbitron', sans-serif;
      font-weight: 600;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--text-muted);
      background: rgba(0,229,255,0.04);
      border-bottom: 1px solid rgba(0,229,255,0.08);
    }

    .test-user-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.55rem 1rem;
      border-bottom: 1px solid rgba(0,229,255,0.06);
      cursor: pointer;
      transition: background 0.15s;
    }

    .test-user-row:last-child { border-bottom: none; }
    .test-user-row:hover { background: rgba(0,229,255,0.05); }

    .test-user-role {
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--cyan);
      opacity: 0.75;
      width: 90px;
      flex-shrink: 0;
    }

    .test-user-email {
      font-size: 0.82rem;
      color: var(--text-primary);
      opacity: 0.65;
      flex: 1;
      text-align: center;
    }

    .fill-btn {
      font-size: 0.65rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--text-muted);
      background: none;
      border: 1px solid rgba(0,229,255,0.12);
      border-radius: 4px;
      padding: 0.2rem 0.5rem;
      cursor: pointer;
      transition: color 0.15s, border-color 0.15s;
      font-family: 'Rajdhani', sans-serif;
    }

    .fill-btn:hover { color: var(--cyan); border-color: var(--cyan-border); }

    /* ── Footer line ── */
    .footer-line {
      text-align: center;
      font-size: 0.68rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-top: 1.8rem;
      opacity: 0.5;
    }

    .footer-line span { color: var(--cyan); opacity: 0.7; }
  </style>
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
        <p><label for="email">Email address</label></p>
        <p><input id="email" name="email" type="email" required></p>

        <p><label for="password">Password</label></p>
        <div class="password-row login-password-row password-field-wrap">
          <input id="password" name="password" type="password" required>
          <!-- Icon attribution: https://www.flaticon.com/free-icons/eyes and https://www.flaticon.com/free-icons/password -->
          <button type="button" class="toggle-password toggle-password-inside" data-target="password" aria-pressed="false" aria-label="Show password"></button>
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
        <h2 class="login-visual-title">Equipment Management System</h2>
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

<script>
  /* ── Toggle password visibility ── */
  document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show);
    });
  });

  /* ── Auto-fill test credentials ── */
  document.querySelectorAll('.test-user-row').forEach(row => {
    row.querySelector('.fill-btn').addEventListener('click', e => {
      e.stopPropagation();
      document.getElementById('email').value    = row.dataset.email;
      document.getElementById('password').value = row.dataset.pass;
      document.getElementById('password').type  = 'password';
      document.querySelector('.toggle-password').textContent = 'Show';
    });

    row.addEventListener('click', () => {
      row.querySelector('.fill-btn').click();
    });
  });
</script>
</body>
</html>
