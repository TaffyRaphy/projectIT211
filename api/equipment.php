<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$role = require_role(['admin']);
$userId = (int) $user['id'];
$ok = query_param('ok');
$error = query_param('error');
$dashboardTitle = 'Equipment Management';

$rows = db()->query(
    'SELECT id, code, name, category, status, quantity_total, quantity_available, location, description, next_maintenance_date FROM equipment ORDER BY id DESC'
)->fetchAll();

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Equipment Management – Equipment Management System</title>
  <style>
    .eq-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }
    .eq-card {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 12px;
      padding: 1.2rem 1.4rem;
      position: relative;
    }
    .eq-card-title { font-weight: 700; font-size: 1rem; margin: 0 0 .3rem; }
    .eq-card-code  { font-size: .75rem; color: var(--text-muted); font-family: monospace; }
    .eq-card-meta  { font-size: .82rem; color: var(--text-muted); margin: .5rem 0; display: flex; flex-wrap: wrap; gap: .5rem; }
    .eq-card-meta span { background: var(--bg-alt, #111); border: 1px solid var(--border-color, #2a2a2a); border-radius: 5px; padding: .1rem .4rem; }
    .eq-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .8rem; border-top: 1px solid var(--border-color, #2a2a2a); padding-top: .8rem; }
    .edit-form-wrap { display: none; margin-top: 1rem; border-top: 1px solid var(--border-color, #2a2a2a); padding-top: 1rem; }
    .edit-form-wrap.open { display: block; }
    .edit-form-wrap .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 480px) { .edit-form-wrap .form-grid { grid-template-columns: 1fr; } }
    .add-panel {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }
    .add-panel h2 { margin-top: 0; }
    .add-panel-toggle { cursor: pointer; }
    .add-panel-body { display: none; }
    .add-panel-body.open { display: block; }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title" style="text-decoration:none; color:inherit;">
      🏠 Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <a class="bell-btn" href="/api/my_notifications.php" aria-label="Notifications">
        🔔
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <a class="profile-link" href="/api/profile.php">🪪 Profile</a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>
<main class="page page-equipment">
  <?php if ($ok !== ''): ?><p class="alert alert-success">Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <!-- Add Equipment Panel -->
  <div class="add-panel">
    <div class="add-panel-toggle" onclick="toggleAddPanel()" id="add-panel-header">
      <h2 style="display:flex; gap:.75rem; align-items:center; margin:0;">
        ➕ Add New Equipment
        <span id="add-panel-chevron" style="font-size:1rem; transition: transform .2s;">▼</span>
      </h2>
    </div>
    <div class="add-panel-body" id="add-panel-body">
      <br>
      <form class="form" action="/api/actions/equipment_create.php" method="post">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="form-group">
            <label for="name">Equipment Name *</label>
            <input id="name" name="name" required placeholder="e.g. Projector XZ2000">
          </div>
          <div class="form-group">
            <label for="category">Category *</label>
            <input id="category" name="category" required placeholder="e.g. Electronics, Furniture">
          </div>
          <div class="form-group">
            <label for="quantity_total">Total Quantity *</label>
            <input id="quantity_total" name="quantity_total" type="number" min="1" required placeholder="e.g. 10">
          </div>
          <div class="form-group">
            <label for="location">Location *</label>
            <input id="location" name="location" required placeholder="e.g. Room 101, Storage A">
          </div>
          <div class="form-group" style="grid-column: 1/-1;">
            <label for="description">Description</label>
            <textarea id="description" name="description" placeholder="Optional description..."></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">➕ Add Equipment</button>
          <button type="button" class="btn btn-secondary" onclick="toggleAddPanel()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <h2>Current Inventory (<?= count($rows) ?> items)</h2>
  <div class="eq-grid">
  <?php foreach ($rows as $item): ?>
    <div class="eq-card" id="eq-card-<?= (int) $item['id'] ?>">
      <div class="eq-card-code"><?= h((string) $item['code']) ?></div>
      <p class="eq-card-title"><?= h((string) $item['name']) ?></p>
      <div class="eq-card-meta">
        <span><?= h((string) $item['category']) ?></span>
        <span class="chip chip-status chip-<?= h((string) $item['status']) ?>"><?= h((string) $item['status']) ?></span>
        <span>📦 Total: <?= (int) $item['quantity_total'] ?></span>
        <span>✅ Available: <?= (int) $item['quantity_available'] ?></span>
        <span>📍 <?= h((string) $item['location']) ?></span>
        <?php if (!empty($item['next_maintenance_date'])): ?>
          <span>🔧 Next: <?= h($item['next_maintenance_date']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ((string) $item['description'] !== ''): ?>
        <p style="margin:.6rem 0 0; color: var(--text-muted); font-size:.86rem;">
          <?= h((string) $item['description']) ?>
        </p>
      <?php endif; ?>

      <div class="eq-actions">
        <button type="button" class="btn btn-secondary" style="font-size:.8rem;"
          onclick="toggleEditForm(<?= (int) $item['id'] ?>)">
          ✏️ Edit
        </button>
        <form class="inline-form" action="/api/actions/equipment_update.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post"
              onsubmit="return confirm('Retire this equipment? This cannot be undone.')">
          <input type="hidden" name="name" value="<?= h((string) $item['name']) ?>">
          <input type="hidden" name="category" value="<?= h((string) $item['category']) ?>">
          <input type="hidden" name="status" value="<?= h((string) $item['status']) ?>">
          <input type="hidden" name="quantity_total" value="<?= (int) $item['quantity_total'] ?>">
          <input type="hidden" name="quantity_available" value="<?= (int) $item['quantity_available'] ?>">
          <input type="hidden" name="location" value="<?= h((string) $item['location']) ?>">
          <?php if ((string) $item['status'] !== 'retired'): ?>
          <button type="submit" name="action" value="retire" class="btn btn-danger" style="font-size:.8rem;">
            🗑️ Retire
          </button>
          <?php else: ?>
          <span class="badge badge-error">Retired</span>
          <?php endif; ?>
        </form>
      </div>

      <!-- Edit Form (hidden by default) -->
      <div class="edit-form-wrap" id="edit-form-<?= (int) $item['id'] ?>">
        <form action="/api/actions/equipment_update.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post" class="form">
          <input type="hidden" name="action" value="update">
          <div class="form-grid">
            <div class="form-group">
              <label>Name *</label>
              <input type="text" name="name" required value="<?= h((string) $item['name']) ?>">
            </div>
            <div class="form-group">
              <label>Category *</label>
              <input type="text" name="category" required value="<?= h((string) $item['category']) ?>">
            </div>
            <div class="form-group">
              <label>Status *</label>
              <select name="status" required>
                <?php foreach (['available', 'allocated', 'maintenance', 'retired'] as $s): ?>
                  <option value="<?= $s ?>" <?= $item['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Location *</label>
              <input type="text" name="location" required value="<?= h((string) $item['location']) ?>">
            </div>
            <div class="form-group">
              <label>Total Qty *</label>
              <input type="number" name="quantity_total" min="0" required value="<?= (int) $item['quantity_total'] ?>">
            </div>
            <div class="form-group">
              <label>Available Qty *</label>
              <input type="number" name="quantity_available" min="0" required value="<?= (int) $item['quantity_available'] ?>">
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
              <label>Description</label>
              <textarea name="description" rows="3" placeholder="Optional description..."><?= h((string) ($item['description'] ?? '')) ?></textarea>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" style="font-size:.85rem;">💾 Save Changes</button>
            <button type="button" class="btn btn-secondary" style="font-size:.85rem;" onclick="toggleEditForm(<?= (int) $item['id'] ?>)">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <p class="back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>
<script>
function toggleAddPanel() {
  const body    = document.getElementById('add-panel-body');
  const chevron = document.getElementById('add-panel-chevron');
  const isOpen  = body.classList.toggle('open');
  chevron.style.transform = isOpen ? 'rotate(180deg)' : '';
}
function toggleEditForm(id) {
  const wrap = document.getElementById('edit-form-' + id);
  wrap.classList.toggle('open');
}
</script>
<script src="/assets/app.js"></script>
</body>
</html>
