<?php
require __DIR__ . '/bootstrap.php';

try {
  $pdo = db();
 } catch (Throwable $e) {
  http_response_code(503);
  error_log('NEXA startup: ' . $e->getMessage());
  ?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>NEXA — подключение</title><link rel="stylesheet" href="assets/css/style.css?v=9"><main class="empty"><h1>Не удалось открыть каталог</h1><p>Убедитесь, что MySQL запущен в XAMPP и подключение в config.php настроено правильно.</p><p>Если база уже установлена, проверьте права на обновление таблиц. Подробности записаны в журнал ошибок PHP.</p><a class="btn btn-primary" href="install.php">Настроить базу</a> <a class="btn btn-secondary" href="index.php">Повторить</a></main></html><?php
  exit;
}
$page = $_GET['page'] ?? 'home';
if (in_array($page, ['account','admin'], true) && !user()) { header('Location: ?page=login'); exit; }
if ($page === 'admin' && !is_admin()) { header('Location: ?page=account'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'add_cart') {
    $id = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    if ($id > 0) {
      $st = $pdo->prepare('SELECT stock, is_active FROM products WHERE id=?');
      $st->execute([$id]);
      $product = $st->fetch();
      if (!$product || !(int)$product['is_active']) {
        flash('Товар недоступен.');
      } else {
        $stock = max(0, (int)$product['stock']);
        $current = (int)($_SESSION['cart'][$id] ?? 0);
        $newQty = min($stock, $current + $qty);
        if ($newQty <= 0) {
          flash('Товара временно нет в наличии.');
        } else {
          $_SESSION['cart'][$id] = $newQty;
          flash($newQty < $current + $qty ? 'Количество ограничено остатком на складе.' : 'Товар добавлен в корзину.');
        }
      }
    }
    $returnTo = trim((string)($_POST['return_to'] ?? '?page=cart'));
    if ($returnTo === '' || !str_starts_with($returnTo, '?')) $returnTo = '?page=cart';
    header('Location: ' . $returnTo);
    exit;
  }

  if ($action === 'remove_cart') {
    unset($_SESSION['cart'][(int)($_POST['product_id'] ?? 0)]);
    header('Location: ?page=cart');
    exit;
  }

  if ($action === 'update_cart') {
    $id = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $st = $pdo->prepare('SELECT stock FROM products WHERE id=? AND is_active=1');
    $st->execute([$id]);
    $stock = (int)($st->fetchColumn() ?: 0);
    if ($stock <= 0) {
      unset($_SESSION['cart'][$id]);
      flash('Товар удалён из корзины: его больше нет в наличии.');
    } else {
      $_SESSION['cart'][$id] = min($qty, $stock);
      if ($qty > $stock) flash('Количество уменьшено до доступного остатка.');
    }
    header('Location: ?page=cart');
    exit;
  }

  if ($action === 'register') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
      $st = $pdo->prepare('INSERT INTO users(name,email,phone,password_hash) VALUES(?,?,?,?)');
      $st->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
      flash('Регистрация завершена. Войдите в аккаунт.');
    } catch (Throwable $e) {
      flash('Пользователь с таким e-mail уже существует.');
    }
    $next = (string)($_POST['next'] ?? 'account');
    if (!in_array($next, ['account','cart','service'], true)) $next = 'account';
    header('Location: ?page=login&next=' . $next);
    exit;
  }

  if ($action === 'login') {
    $st = $pdo->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();

    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
      unset($u['password_hash']);
      $_SESSION['user'] = $u;
      flash('Добро пожаловать, ' . $u['name'] . '!');
      $next = (string)($_POST['next'] ?? 'account');
      if (!in_array($next, ['account','cart','service'], true)) $next = 'account';
      header('Location: ?page=' . $next);
      exit;
    }

    flash('Неверный e-mail или пароль.');
    header('Location: ?page=login');
    exit;
  }

  if ($action === 'logout') {
    session_destroy();
    session_start();
    flash('Вы вышли из аккаунта.');
    header('Location: ?');
    exit;
  }

  if ($action === 'checkout') {
    if (!user()) {
      flash('Для оформления заказа необходимо войти.');
      header('Location: ?page=login');
      exit;
    }

    $items = cart();
    if (!$items) {
      header('Location: ?page=cart');
      exit;
    }

    $ids = array_keys($items);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM products WHERE id IN ($ph)");
    $st->execute($ids);
    $products = $st->fetchAll();

    $total = 0;
    foreach ($products as $p) {
      $total += $p['price'] * $items[$p['id']];
    }

    foreach ($products as $p) {
      if ((int)$p['stock'] < (int)$items[$p['id']]) {
        flash('Для товара «' . $p['name'] . '» изменился остаток. Проверьте корзину.');
        header('Location: ?page=cart');
        exit;
      }
    }

    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare('INSERT INTO orders(user_id,status,total_amount,delivery_method,address,phone,comment) VALUES(?,?,?,?,?,?,?)');
      $st->execute([
        user()['id'],
        'new',
        $total,
        trim($_POST['delivery_method'] ?? 'Курьер'),
        trim($_POST['address'] ?? ''),
        trim($_POST['phone'] ?? ''),
        trim($_POST['comment'] ?? ''),
      ]);
      $orderId = (int)$pdo->lastInsertId();

      $itemSt = $pdo->prepare('INSERT INTO order_items(order_id,product_id,quantity,price) VALUES(?,?,?,?)');
      $stockSt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id=? AND stock >= ?');
      foreach ($products as $p) {
        $quantity = (int)$items[$p['id']];
        $itemSt->execute([$orderId, $p['id'], $quantity, $p['price']]);
        $stockSt->execute([$quantity, $p['id'], $quantity]);
        if ($stockSt->rowCount() !== 1) {
          throw new RuntimeException('Недостаточно товара на складе: ' . $p['name']);
        }
      }
      $pdo->commit();
      $_SESSION['cart'] = [];
      flash('Заказ №' . $orderId . ' успешно создан.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash('Не удалось оформить заказ: ' . $e->getMessage());
      header('Location: ?page=cart');
      exit;
    }
    header('Location: ?page=account');
    exit;
  }

  if ($action === 'service') {
    if (!user()) {
      flash('Войдите в аккаунт для отправки сервисной заявки.');
      header('Location: ?page=login');
      exit;
    }

    $st = $pdo->prepare('INSERT INTO service_requests(user_id,service_type_id,device_type,model,serial_number,problem,phone) VALUES(?,?,?,?,?,?,?)');
    $st->execute([
      user()['id'],
      (int)($_POST['service_type_id'] ?? 0),
      trim($_POST['device_type'] ?? ''),
      trim($_POST['model'] ?? ''),
      trim($_POST['serial_number'] ?? ''),
      trim($_POST['problem'] ?? ''),
      trim($_POST['phone'] ?? ''),
    ]);
    flash('Сервисная заявка отправлена.');
    header('Location: ?page=account');
    exit;
  }

  if (is_admin() && $action === 'admin_order_status') {
    $st = $pdo->prepare('UPDATE orders SET status=? WHERE id=?');
    $st->execute([$_POST['status'] ?? 'new', (int)($_POST['id'] ?? 0)]);
    header('Location: ?page=admin');
    exit;
  }

  if (is_admin() && $action === 'admin_service_status') {
    $st = $pdo->prepare('UPDATE service_requests SET status=?, cost=? WHERE id=?');
    $st->execute([$_POST['status'] ?? 'new', (float)($_POST['cost'] ?? 0), (int)($_POST['id'] ?? 0)]);
    header('Location: ?page=admin');
    exit;
  }

  if (is_admin() && $action === 'admin_product_add') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $image = trim($_POST['image'] ?? '');
    if ($image === '') {
      $catSt = $pdo->prepare('SELECT slug FROM categories WHERE id=?');
      $catSt->execute([$categoryId]);
      $slug = (string)($catSt->fetchColumn() ?: 'printers');
      $image = category_image($slug);
    }
    $st = $pdo->prepare('INSERT INTO products(category_id,name,brand,model,description,price,stock,warranty_months,image,is_active) VALUES(?,?,?,?,?,?,?,?,?,1)');
    $st->execute([
      $categoryId,
      trim($_POST['name'] ?? ''),
      trim($_POST['brand'] ?? ''),
      trim($_POST['model'] ?? ''),
      trim($_POST['description'] ?? ''),
      (float)($_POST['price'] ?? 0),
      (int)($_POST['stock'] ?? 0),
      (int)($_POST['warranty_months'] ?? 24),
      $image,
    ]);
    flash('Товар добавлен.');
    header('Location: ?page=admin');
    exit;
  }

  if (is_admin() && $action === 'admin_product_update') {
    $st = $pdo->prepare('UPDATE products SET price=?, stock=?, warranty_months=? WHERE id=?');
    $st->execute([
      max(0, (float)($_POST['price'] ?? 0)),
      max(0, (int)($_POST['stock'] ?? 0)),
      max(0, (int)($_POST['warranty_months'] ?? 0)),
      (int)($_POST['id'] ?? 0),
    ]);
    flash('Данные товара обновлены.');
    header('Location: ?page=admin');
    exit;
  }

  if (is_admin() && $action === 'admin_product_toggle') {
    $st = $pdo->prepare('UPDATE products SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    flash('Видимость товара изменена.');
    header('Location: ?page=admin');
    exit;
  }
}

function category_image(string $slug): string {
  $map = [
    'printers' => 'assets/img/products/printer-01.jpg',
    'mfu' => 'assets/img/products/mfu-01.jpg',
    'scanners' => 'assets/img/products/scanner-01.jpg',
    'projectors' => 'assets/img/products/projector-01.jpg',
    'monitors' => 'assets/img/products/monitor-01.jpg',
    'shredders' => 'assets/img/products/shredder-01.jpg',
    'laminators' => 'assets/img/products/laminator-01.jpg',
    'label-printers' => 'assets/img/products/label-01.jpg',
  ];
  return $map[$slug] ?? 'assets/img/products/printer-01.jpg';
}


require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/images.php';

function nav_active(string $target): string {
  global $page;
  return $page === $target ? 'active' : '';
}

function query_url(array $changes = []): string {
  $params = $_GET;
  foreach ($changes as $key => $value) {
    if ($value === null || $value === '') unset($params[$key]);
    else $params[$key] = $value;
  }
  return '?' . http_build_query($params);
}


function order_status_label(string $status): string {
  return [
    'new' => 'Новый',
    'processing' => 'В обработке',
    'shipped' => 'Передан в доставку',
    'done' => 'Выполнен',
    'cancelled' => 'Отменён',
  ][$status] ?? $status;
}

function service_status_label(string $status): string {
  return [
    'new' => 'Новая',
    'diagnostics' => 'Диагностика',
    'repair' => 'В ремонте',
    'done' => 'Готово',
    'cancelled' => 'Отменена',
  ][$status] ?? $status;
}

function render_breadcrumbs(string $current, ?string $label = null): void {
  $labels = [
    'catalog' => 'Каталог',
    'product' => 'Товар',
    'favorites' => 'Избранное',
    'cart' => 'Корзина',
    'service' => 'Сервис',
    'login' => 'Личный кабинет',
    'account' => 'Личный кабинет',
    'admin' => 'Админ-панель',
    'about' => 'О компании',
    'contacts' => 'Контакты',
  ];
  $title = $label ?: ($labels[$current] ?? ucfirst($current));
  ?>
  <nav class="breadcrumbs" aria-label="Хлебные крошки">
    <a href="?">Главная</a>
    <?php if ($current === 'product'): ?><a href="?page=catalog">Каталог</a><?php endif; ?>
    <span><?= h($title) ?></span>
  </nav>
  <?php
}

function render_header(string $title = ''): void {
  global $pdo, $page;
  $flash = get_flash();
  $headerCategories = $pdo->query('SELECT id,name,slug FROM categories ORDER BY id ASC')->fetchAll();
  $currentCategory = (int)($_GET['category'] ?? 0);
  if ($page === 'product' && !empty($_GET['id'])) {
    $catSt = $pdo->prepare('SELECT category_id FROM products WHERE id=?');
    $catSt->execute([(int)$_GET['id']]);
    $currentCategory = (int)($catSt->fetchColumn() ?: 0);
  }
  ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <meta name="theme-color" content="#37494d">
  <title><?= h($title ? $title . ' — ' . APP_NAME : APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/css/style.css?v=9">
  <link rel="stylesheet" href="assets/css/design.css?v=9">
  <link rel="stylesheet" href="assets/css/atelier.css?v=9">
  <link rel="stylesheet" href="assets/css/photos.css?v=9">
</head>
<body class="view-<?= h($page) ?>">
  <a class="skip-link" href="#main-content">Перейти к содержимому</a>
  <div id="transition" class="transition" aria-hidden="true"></div>

  <div class="topbar">
    <div class="topbar-inner">
      <span>Комплексные поставки оргтехники для бизнеса</span>
      <div>
        <a href="?page=service">Сервисный центр</a>
        <a href="?page=about">О компании</a>
        <a href="?page=contacts">Контакты</a>
        <strong>+7 (495) 120-44-20</strong>
      </div>
    </div>
  </div>

  <header class="header">
    <div class="header-inner">
      <a class="logo" href="?" aria-label="NEXA OFFICE — главная">
        <span class="brand-mark"><?= svg_icon('brand') ?></span>
        <div><b><?= APP_NAME ?></b><small>техника и сервис для офиса</small></div>
      </a>

      <form class="header-search" action="" method="get">
        <input type="hidden" name="page" value="catalog">
        <input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Поиск по товарам, моделям и брендам" aria-label="Поиск">
        <button type="submit" aria-label="Найти"><?= svg_icon('search') ?><span>Найти</span></button>
      </form>

      <div class="header-actions">
        <a class="header-action favorite-link <?= nav_active('favorites') ?>" href="?page=favorites" aria-label="Избранное"><span class="icon-wrap"><?= svg_icon('heart') ?></span><small>Избранное</small><b class="favorite-count">0</b></a>
        <a class="header-action <?= nav_active('cart') ?>" href="?page=cart" aria-label="Корзина"><span class="icon-wrap"><?= svg_icon('cart') ?></span><small>Корзина</small><b><?= cart_count() ?></b></a>
        <a class="header-action <?= in_array($page, ['login','account','admin'], true) ? 'active' : '' ?>" href="?page=<?= user() ? 'account' : 'login' ?>"><span class="icon-wrap"><?= svg_icon('user') ?></span><small><?= user() ? h(explode(' ', user()['name'])[0]) : 'Войти' ?></small></a>
        <button class="mobile-menu" type="button" aria-label="Меню" aria-controls="siteNav" aria-expanded="false"><?= svg_icon('menu') ?></button>
      </div>
    </div>
  </header>

  <nav id="siteNav" class="main-nav" aria-label="Основная навигация">
    <div class="main-nav-inner">
      <a class="catalog-nav-link <?= $page === 'catalog' ? 'active' : '' ?>" href="?page=catalog"><span class="icon-wrap"><?= svg_icon('grid') ?></span> Каталог</a>
      <div class="category-nav" aria-label="Категории каталога">
        <?php foreach ($headerCategories as $cat): ?>
          <a class="<?= in_array($page, ['catalog','product'], true) && $currentCategory === (int)$cat['id'] ? 'active' : '' ?>" href="?page=catalog&category=<?= (int)$cat['id'] ?>"><?= h($cat['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="main-nav-links">
        <a class="<?= nav_active('service') ?>" href="?page=service">Сервис</a>
        <a class="<?= nav_active('about') ?>" href="?page=about">О компании</a>
        <a class="<?= nav_active('contacts') ?>" href="?page=contacts">Контакты</a>
        <a class="mobile-favorites-link <?= nav_active('favorites') ?>" href="?page=favorites">Избранное</a>
      </div>
    </div>
  </nav>

  <?php if ($flash): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>

  <main id="main-content" class="page">
  <?php
}
function render_footer(): void {
  ?>
  </main>

  <footer class="footer">
    <div>
      <a class="logo footer-brand" href="?" aria-label="NEXA OFFICE"><span class="brand-mark"><?= svg_icon('brand') ?></span><div><b><?= APP_NAME ?></b><small>оргтехника и сервис</small></div></a>
      <p>Продажа, внедрение и сервисное обслуживание оргтехники для современных офисов.</p>
    </div>
    <div>
      <strong>Разделы</strong>
      <a href="?page=catalog">Каталог</a>
      <a href="?page=service">Сервис</a>
      <a href="?page=about">О компании</a>
      <a href="?page=contacts">Контакты</a>
    </div>
    <div>
      <strong>Контакты</strong>
      <a href="tel:+74951204420">+7 (495) 120-44-20</a>
      <a href="mailto:hello@nexa-office.ru">hello@nexa-office.ru</a>
      <span>Пн–Пт · 09:00–19:00</span>
    </div>
    <div>
      <strong>Нужна помощь?</strong>
      <p>Подберём оборудование<br>под ваши рабочие задачи.</p>
      <a class="footer-contact-link" href="?page=contacts">Давайте обсудим <?= svg_icon('arrow-right') ?></a>
    </div>
    <div class="footer-bottom"><span>© <?= date('Y') ?> NEXA OFFICE</span><span>Техника, сервис и внимание к деталям.</span><a href="?page=catalog">Вернуться к выбору <?= svg_icon('arrow-right') ?></a></div>
  </footer>

  <button id="backToTop" class="back-to-top" type="button" aria-label="Наверх"><?= svg_icon('arrow-up') ?></button>

  <nav class="mobile-bottom-nav" aria-label="Мобильная навигация">
    <a class="<?= nav_active('home') ?>" href="?"><span class="icon-wrap"><?= svg_icon('home') ?></span><small>Главная</small></a>
    <a class="<?= nav_active('catalog') ?>" href="?page=catalog"><span class="icon-wrap"><?= svg_icon('grid') ?></span><small>Каталог</small></a>
    <a class="<?= nav_active('service') ?>" href="?page=service"><span class="icon-wrap"><?= svg_icon('service') ?></span><small>Сервис</small></a>
    <a class="<?= nav_active('cart') ?>" href="?page=cart"><span class="icon-wrap"><?= svg_icon('cart') ?></span><small>Корзина</small><b><?= cart_count() ?></b></a>
    <a class="<?= in_array(($GLOBALS['page'] ?? ''), ['login','account','admin'], true) ? 'active' : '' ?>" href="?page=<?= user() ? 'account' : 'login' ?>"><span class="icon-wrap"><?= svg_icon('user') ?></span><small>Профиль</small></a>
  </nav>

  <script src="assets/js/app.js?v=9" defer></script>
</body>
</html>
  <?php
}

function empty_state(string $html): void {
  echo '<div class="empty">' . str_replace(['→','←'], [svg_icon('arrow-right'),svg_icon('arrow-left')], $html) . '</div>';
}

try {
  $catalogCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
  $categoryCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
} catch (Throwable $e) {
  header('Location: install.php');
  exit;
}

$pageTitles = [
  'home' => '',
  'catalog' => 'Каталог оргтехники',
  'product' => 'Карточка товара',
  'favorites' => 'Избранное',
  'cart' => 'Корзина',
  'service' => 'Сервисный центр',
  'login' => 'Личный кабинет',
  'account' => 'Личный кабинет',
  'admin' => 'Админ-панель',
  'about' => 'О компании',
  'contacts' => 'Контакты',
];
render_header($pageTitles[$page] ?? 'NEXA OFFICE');
if ($page !== 'home') render_breadcrumbs($page);

if ($page === 'home') {
  $featuredRows = $pdo->query('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 AND p.id=(SELECT p2.id FROM products p2 WHERE p2.category_id=p.category_id AND p2.is_active=1 ORDER BY CASE WHEN p2.image LIKE \'assets/img/products/%\' THEN 0 ELSE 1 END, p2.id LIMIT 1) ORDER BY p.category_id LIMIT 8')->fetchAll();
  $featured = [];
  $usedCategories = [];
  foreach ($featuredRows as $row) {
    if (isset($usedCategories[$row['category_id']])) continue;
    $usedCategories[$row['category_id']] = true;
    $featured[] = $row;
    if (count($featured) >= 8) break;
  }
  $categories = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.is_active=1) product_count FROM categories c ORDER BY c.id ASC')->fetchAll();
  $heroProduct = $featured[1] ?? ($featured[0] ?? null);
  ?>
  <section class="home-shell">
    <section class="hero reveal visible">
      <div class="hero-copy">
        <span class="hero-kicker"><i aria-hidden="true"></i> NEXA — НОВЫЙ УРОВЕНЬ ВАШЕГО ОФИСА</span>
        <h1>Больше возможностей.<br><em>Меньше забот<br>о технике.</em></h1>
        <p>Оргтехника, которая помогает двигаться вперёд.<br>Подберём оборудование, подключим и позаботимся о сервисе.</p>
        <div class="hero-buttons">
          <a class="btn btn-primary" href="?page=catalog">Каталог · <?= $catalogCount ?> товаров <?= svg_icon('arrow-right') ?></a>
          <a class="btn btn-secondary" href="?page=service"><?= svg_icon('service') ?> Сервис и поддержка</a>
        </div>
        <div class="hero-facts">
          <span><b><?= $catalogCount ?></b> товаров</span>
          <span><b><?= $categoryCount ?></b> категорий</span>
          <span><b>01</b> место для всех задач</span>
        </div>
      </div>
      <div class="hero-media orbit-stage" aria-hidden="true">
        <div class="orbit-halo"></div>
        <img class="orbit-art" src="assets/img/blue-orbit.webp" srcset="assets/img/blue-orbit-640.webp 640w, assets/img/blue-orbit.webp 1200w" sizes="(max-width: 620px) 90vw, 52vw" width="1200" height="1200" alt="" fetchpriority="high" decoding="async">
        <div class="orbit-badge badge-top"><?= svg_icon('shield') ?><span>Техника + сервис<br><b>В одном месте</b></span></div>
        <div class="orbit-badge badge-bottom"><strong><?= $catalogCount ?></strong><span>моделей<br>для вашего офиса</span><i></i></div>
        <span class="hero-corner-note">ПРОДУМАНО ДЛЯ ВАШЕЙ РАБОТЫ</span>
      </div>
    </section>
  </section>

  <section class="showcase-panel reveal" aria-label="Знакомство с каталогом">
    <div class="showcase-bar"><div class="window-dots" aria-hidden="true"><i></i><i></i><i></i></div><span>ВАШЕ РАБОЧЕЕ ПРОСТРАНСТВО</span><a href="?page=catalog">Открыть каталог <?= svg_icon('arrow-right') ?></a></div>
    <div class="showcase-products">
      <?php foreach (array_slice($featured, 0, 3) as $showProduct): ?>
        <a class="showcase-product" href="?page=product&id=<?= (int)$showProduct['id'] ?>">
          <div class="showcase-product-top"><small><?= h($showProduct['category_name']) ?></small><?= svg_icon('arrow-right') ?></div>
          <?= product_image($showProduct['image'], $showProduct['name'], false, '(max-width:620px) 70vw, 28vw') ?>
          <div class="showcase-product-caption"><b><?= h($showProduct['name']) ?></b><span><?= number_format($showProduct['price'], 0, ',', ' ') ?> ₽</span></div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="task-navigation" aria-label="Что вы хотите сделать?">
    <a href="?page=catalog" class="task-card reveal"><span class="task-icon"><?= svg_icon('grid') ?></span><div><small>КУПИТЬ</small><h2>Выбрать технику</h2><p><?= $catalogCount ?> товаров с ценами и фотографиями</p></div><?= svg_icon('arrow-right') ?></a>
    <a href="?page=service" class="task-card reveal"><span class="task-icon"><?= svg_icon('service') ?></span><div><small>ОТРЕМОНТИРОВАТЬ</small><h2>Обратиться в сервис</h2><p>Диагностика, настройка и ремонт</p></div><?= svg_icon('arrow-right') ?></a>
    <a href="?page=contacts" class="task-card reveal"><span class="task-icon"><?= svg_icon('user') ?></span><div><small>ПОСОВЕТОВАТЬСЯ</small><h2>Получить помощь</h2><p>Контакты и помощь с подбором</p></div><?= svg_icon('arrow-right') ?></a>
  </section>
  <section class="benefit-strip reveal">
    <div><b class="benefit-icon"><?= svg_icon('shield') ?></b><span><strong>Официальная гарантия</strong><small>на оборудование из каталога</small></span></div>
    <div><b class="benefit-icon"><?= svg_icon('delivery') ?></b><span><strong>Быстрая доставка</strong><small>по городу и регионам</small></span></div>
    <div><b class="benefit-icon"><?= svg_icon('service') ?></b><span><strong>Собственный сервис</strong><small>диагностика и ремонт</small></span></div>
    <div><b class="benefit-icon"><?= svg_icon('briefcase') ?></b><span><strong>Подбор для бизнеса</strong><small>по нагрузке и бюджету</small></span></div>
  </section>

  <section class="section">
    <div class="section-head reveal">
      <div>
        <span class="section-label">Категории</span>
        <h2>Ваш офис. Ваши задачи.</h2>
      </div>
      <a class="text-link" href="?page=catalog">Весь каталог <?= svg_icon('arrow-right') ?></a>
    </div>
    <div class="category-rail category-grid-real">
      <?php foreach ($categories as $i => $cat): ?>
        <a class="category-card reveal" href="?page=catalog&category=<?= $cat['id'] ?>">
          <div class="category-card-copy">
            <small><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></small>
            <h3><?= h($cat['name']) ?></h3><div class="category-quantity"><?= (int)$cat['product_count'] ?> товаров</div>
            <p><?= h($cat['description']) ?></p>
            <span>Смотреть <i class="inline-icon"><?= svg_icon('chevron-right') ?></i></span>
          </div>
          <?= product_image(category_image($cat['slug']), $cat['name'], false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section solutions-section">
    <div class="section-head reveal"><div><span class="section-label">Внимание к каждой детали</span><h2>Всё работает.<br><em>Вы занимаетесь главным.</em></h2></div><p class="section-description">От выбора первого устройства до поддержки всего офиса — нужное решение всегда рядом.</p></div>
    <div class="feature-bento">
      <a class="bento-card bento-main reveal" href="?page=catalog"><span class="bento-index">01 / ОБОРУДОВАНИЕ</span><h3>Ваш офис.<br>Ваши возможности.</h3><p>Печать, сканирование, презентации и удобное рабочее место.</p><div class="bento-stat"><b><?= $catalogCount ?></b><span>товаров<br>в одном каталоге</span></div><img src="assets/img/blue-orbit-640.webp" alt="" width="640" height="640" loading="lazy" decoding="async"><span class="bento-link">Выбрать технику <?= svg_icon('arrow-right') ?></span></a>
      <a class="bento-card bento-service reveal" href="?page=service"><span class="bento-index">02 / СЕРВИС</span><div class="bento-icon"><?= svg_icon('service') ?></div><h3>Поддержка,<br>на которую можно опереться.</h3><p>Диагностика, ремонт и настройка. Стоимость согласуем до начала работ.</p><span class="bento-link">Оставить заявку <?= svg_icon('arrow-right') ?></span></a>
      <a class="bento-card bento-consult reveal" href="?page=contacts"><span class="bento-index">03 / ПОДБОР</span><h3>Не нужно разбираться<br>во всём самостоятельно.</h3><p>Расскажите о задачах — поможем с выбором.</p><span class="bento-link">Обсудить задачу <?= svg_icon('arrow-right') ?></span></a>
    </div>
  </section>
  <section class="section section-tight">
    <div class="section-head reveal">
      <div>
        <span class="section-label">Из нашего каталога</span>
        <h2>Начните с этих моделей</h2><p class="section-description">Показаны <?= count($featured) ?> из <?= $catalogCount ?> товаров. Полный выбор — в каталоге.</p>
      </div>
      <a class="text-link" href="?page=catalog">Смотреть все <?= svg_icon('arrow-right') ?></a>
    </div>
    <div class="product-grid featured-grid">
      <?php foreach ($featured as $n => $p): ?>
        <article class="product-card reveal">
          <button class="fav-btn" type="button" data-product-id="<?= $p['id'] ?>" aria-label="Добавить в избранное"><?= svg_icon('heart') ?></button>
          <a class="product-card-link" href="?page=product&id=<?= $p['id'] ?>">
            <div class="product-media">
              <?= product_image($p['image'], $p['name'], false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?>
              <span class="stock <?= (int)$p['stock'] > 0 ? 'is-available' : 'is-unavailable' ?>"><?= (int)$p['stock'] > 0 ? 'В наличии' : 'Под заказ' ?></span>
            </div>
            <div class="product-info">
              <small><?= h($p['category_name'] . ' · ' . $p['model']) ?></small>
              <h3><?= h($p['name']) ?></h3>
              <p><?= h($p['description']) ?></p>
              <div class="product-card-bottom"><b><?= number_format($p['price'], 0, ',', ' ') ?> ₽</b><span>Подробнее</span></div>
            </div>
          </a>
          <form class="quick-cart" method="post">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_cart">
            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="return_to" value="?">
            <button type="submit" <?= (int)$p['stock'] <= 0 ? 'disabled' : '' ?>><?= (int)$p['stock'] > 0 ? 'В корзину' : 'Нет в наличии' ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="service-banner reveal">
    <div class="service-banner-copy">
      <span class="section-label inverse">Сервисный центр</span>
      <h2>Техника требует<br>внимания?<br><em>Мы рядом.</em></h2>
      <p>Диагностика, профилактика, ремонт и настройка. История заявок и статусы доступны в личном кабинете.</p>
      <ul>
        <li>Диагностика от 1 500 ₽</li>
        <li>Гарантия на выполненные работы</li>
        <li>Согласование стоимости до ремонта</li>
      </ul>
      <a class="btn btn-light" href="?page=service">Обсудить задачу <?= svg_icon('arrow-right') ?></a>
    </div>
    <div class="service-banner-media"><div class="service-media-label"><?= svg_icon('service') ?><span>Диагностика<br><b>Настройка. Ремонт. Поддержка.</b></span></div><?= product_image('assets/img/printer.jpg', 'Сервис оргтехники', false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?></div>
  </section>

  <section class="business-block section reveal">
    <div>
      <span class="section-label">Для компаний</span>
      <h2>Большие задачи<br>начинаются с удобного офиса.</h2>
      <p>Подберём оборудование по количеству сотрудников, объёму печати и бюджету. Поможем с сетью, установкой и дальнейшим обслуживанием.</p>
      <a class="text-link" href="?page=contacts">Связаться с нами <?= svg_icon('arrow-right') ?></a>
    </div>
    <div class="business-points">
      <div><b>01</b><span>Подбор комплекта оборудования</span></div>
      <div><b>02</b><span>Счёт и документы для юрлиц</span></div>
      <div><b>03</b><span>Доставка и настройка на месте</span></div>
      <div><b>04</b><span>Сервис после покупки</span></div>
    </div>
  </section>
  <section class="section journey-section">
    <div class="section-head reveal"><div><span class="section-label">Просто на каждом этапе</span><h2>От задачи <em>к решению.</em></h2></div></div>
    <div class="journey-grid">
      <div class="journey-card reveal"><span>01</span><h3>Выберите технику</h3><p>Посмотрите модели, цены и описания в каталоге.</p></div>
      <div class="journey-card reveal"><span>02</span><h3>Добавьте в корзину</h3><p>Укажите нужное количество и проверьте итоговую сумму.</p></div>
      <div class="journey-card reveal"><span>03</span><h3>Оформите заказ</h3><p>Войдите в аккаунт и оставьте контакты для связи.</p></div>
      <div class="journey-card reveal"><span>04</span><h3>Согласуйте получение</h3><p>Выберите доставку или самовывоз при оформлении.</p></div>
      <div class="journey-card reveal"><span>05</span><h3>Оставайтесь на связи</h3><p>Заказы и обращения доступны в личном кабинете.</p></div>
    </div>
  </section>
  <section class="section faq-section">
    <div class="section-head reveal"><div><span class="section-label">Полезно знать</span><h2>Остались <em>вопросы?</em></h2></div><a class="text-link" href="?page=contacts">Свяжитесь с нами <?= svg_icon('arrow-right') ?></a></div>
    <div class="faq-list">
      <details class="reveal"><summary>Как выбрать подходящую технику?<span aria-hidden="true">+</span></summary><p>Выберите категорию в каталоге, сравните описание, цену и гарантию. Если нужна помощь с подбором под задачи офиса, откройте раздел «Контакты».</p></details>
      <details class="reveal"><summary>Нужно ли регистрироваться для покупки?<span aria-hidden="true">+</span></summary><p>Каталог и корзина доступны без регистрации. Для оформления заказа войдите или создайте аккаунт — так заказ останется в вашем личном кабинете.</p></details>
      <details class="reveal"><summary>Как передать устройство в ремонт?<span aria-hidden="true">+</span></summary><p>В разделе «Сервис» выберите услугу, войдите в аккаунт и опишите неисправность. После отправки обращение появится в личном кабинете.</p></details>
    </div>
  </section>
  <section class="closing-cta reveal"><span class="section-label">Готовы к следующему шагу?</span><h2>Создайте пространство,<br><em>в котором хочется работать.</em></h2><a class="btn btn-primary" href="?page=catalog">Выбрать свою технику <?= svg_icon('arrow-right') ?></a><div class="closing-orbit" aria-hidden="true"></div></section>
  <?php
}
if ($page === 'catalog') {
  $q = trim($_GET['q'] ?? '');
  $cat = (int)($_GET['category'] ?? 0);
  $sort = $_GET['sort'] ?? 'newest';
  $catalogPage = max(1, (int)($_GET['p'] ?? 1));
  $perPage = 60;
  $orderMap = [
    'newest' => 'p.id DESC',
    'price_asc' => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name' => 'p.name ASC',
  ];
  $orderBy = $orderMap[$sort] ?? $orderMap['newest'];

  $where = ' WHERE p.is_active=1';
  $params = [];
  if ($q) {
    $where .= ' AND (p.name LIKE ? OR p.brand LIKE ? OR p.model LIKE ? OR p.description LIKE ?)';
    $params = array_fill(0, 4, '%' . $q . '%');
  }
  if ($cat) {
    $where .= ' AND p.category_id=?';
    $params[] = $cat;
  }

  $countSt = $pdo->prepare('SELECT COUNT(*) FROM products p' . $where);
  $countSt->execute($params);
  $totalProducts = (int)$countSt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalProducts / $perPage));
  $catalogPage = min($catalogPage, $totalPages);
  $offset = ($catalogPage - 1) * $perPage;

  $sql = 'SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id' . $where . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $products = $st->fetchAll();
  $cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.is_active=1) product_count FROM categories c ORDER BY c.id')->fetchAll();
  $selectedCategoryName = '';
  foreach ($cats as $c) {
    if ((int)$c['id'] === $cat) { $selectedCategoryName = $c['name']; break; }
  }
  ?>
  <section class="inner-hero catalog-hero">
    <div class="eyebrow">КАТАЛОГ / <?= $selectedCategoryName ? h($selectedCategoryName) : 'ОРГТЕХНИКА' ?></div>
    <h1><?= $selectedCategoryName ? h($selectedCategoryName) : 'Техника для вашего офиса' ?><span class="catalog-title-count"><?= $totalProducts ?></span></h1>
    <p>Найдите своё решение для печати, презентаций и комфортной работы.</p>
  </section>

  <nav class="catalog-category-tabs" aria-label="Категории товаров">
    <a class="<?= !$cat ? 'active' : '' ?>" href="<?= h(query_url(['category'=>null,'p'=>null])) ?>" <?= !$cat ? 'aria-current="page"' : '' ?>><?= svg_icon('grid') ?> Все товары</a>
    <?php foreach ($cats as $c): ?>
      <a class="<?= $cat === (int)$c['id'] ? 'active' : '' ?>" href="<?= h(query_url(['category'=>$c['id'],'p'=>null])) ?>" <?= $cat === (int)$c['id'] ? 'aria-current="page"' : '' ?>><?= h($c['name']) ?> <span class="category-tab-count"><?= (int)$c['product_count'] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filterbar" method="get">
    <input type="hidden" name="page" value="catalog">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Какую технику ищете?" aria-label="Поиск по модели, бренду или описанию">
    <select name="category" aria-label="Категория">
      <option value="0">Все категории</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" aria-label="Сортировка">
      <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Сначала новые</option>
      <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Сначала дешевле</option>
      <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Сначала дороже</option>
      <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>По названию</option>
    </select>
    <button class="btn btn-primary">Показать</button>
  </form>

  <div class="catalog-meta" role="status">
    <span><b><?= $totalProducts ?> товаров</b> <?= $totalPages === 1 ? '· все на этой странице' : '· страница ' . $catalogPage . ' из ' . $totalPages ?></span>
    <?php if ($q || $cat || $sort !== 'newest'): ?><a href="?page=catalog">Сбросить фильтры</a><?php endif; ?>
  </div>

  <?php if (!$products): ?>
    <?php empty_state('По вашему запросу ничего не найдено.<br><a href="?page=catalog">Показать весь каталог →</a>'); ?>
  <?php else: ?>
    <div class="catalog-grid">
      <?php foreach ($products as $p): ?>
        <article class="catalog-card reveal">
          <button class="fav-btn catalog-fav" type="button" data-product-id="<?= $p['id'] ?>" aria-label="Добавить в избранное"><?= svg_icon('heart') ?></button>
          <a href="?page=product&id=<?= $p['id'] ?>">
            <div class="product-media">
              <?= product_image($p['image'], $p['name'], false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?>
              <span class="category"><?= h($p['category_name']) ?></span>
            </div>
            <div class="catalog-card-body">
              <small><?= h($p['brand'] . ' · ' . $p['model']) ?></small>
              <h3><?= h($p['name']) ?></h3>
              <p><?= h($p['description']) ?></p>
              <div class="price-row"><b><?= number_format($p['price'], 0, ',', ' ') ?> ₽</b><span class="detail-arrow"><?= svg_icon('arrow-right') ?></span></div>
            </div>
          </a>
          <form class="quick-cart catalog-quick-cart" method="post">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_cart">
            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="return_to" value="?<?= h($_SERVER['QUERY_STRING'] ?? 'page=catalog') ?>">
            <button type="submit" <?= (int)$p['stock'] <= 0 ? 'disabled' : '' ?>><?= (int)$p['stock'] > 0 ? 'В корзину' : 'Под заказ' ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="pagination" aria-label="Страницы каталога">
        <a class="pagination-arrow <?= $catalogPage <= 1 ? 'disabled' : '' ?>" href="<?= $catalogPage > 1 ? h(query_url(['p' => $catalogPage - 1])) : '#' ?>" aria-label="Предыдущая страница"><?= svg_icon('arrow-left') ?></a>
        <?php
          $startPage = max(1, $catalogPage - 2);
          $endPage = min($totalPages, $catalogPage + 2);
          if ($startPage > 1) echo '<a href="' . h(query_url(['p' => 1])) . '">1</a>' . ($startPage > 2 ? '<span>…</span>' : '');
          for ($i = $startPage; $i <= $endPage; $i++):
        ?>
          <a class="<?= $i === $catalogPage ? 'active' : '' ?>" href="<?= h(query_url(['p' => $i])) ?>"><?= $i ?></a>
        <?php endfor;
          if ($endPage < $totalPages) echo ($endPage < $totalPages - 1 ? '<span>…</span>' : '') . '<a href="' . h(query_url(['p' => $totalPages])) . '">' . $totalPages . '</a>';
        ?>
        <a class="pagination-arrow <?= $catalogPage >= $totalPages ? 'disabled' : '' ?>" href="<?= $catalogPage < $totalPages ? h(query_url(['p' => $catalogPage + 1])) : '#' ?>" aria-label="Следующая страница"><?= svg_icon('arrow-right') ?></a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
  <?php
}

if ($page === 'favorites') {
  $favoriteProducts = $pdo->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 ORDER BY p.id DESC')->fetchAll();
  ?>
  <section class="inner-hero compact">
    <div class="eyebrow">ИЗБРАННОЕ</div>
    <h1>Избранное</h1>
    <p>Сохранённые товары хранятся в вашем браузере. Нажмите на сердечко в карточке, чтобы добавить или убрать товар.</p>
  </section>
  <div class="catalog-meta favorites-meta"><span>Сохранено: <b class="favorite-count">0</b></span><a href="?page=catalog">Вернуться в каталог</a></div>
  <div id="favoritesEmpty" class="empty favorites-empty" hidden>В избранном пока ничего нет.<br><a href="?page=catalog">Перейти в каталог <?= svg_icon('arrow-right') ?></a></div>
  <div id="favoritesGrid" class="catalog-grid favorites-grid">
    <?php foreach ($favoriteProducts as $p): ?>
      <article class="catalog-card favorite-item" data-product-id="<?= $p['id'] ?>" hidden>
        <button class="fav-btn catalog-fav" type="button" data-product-id="<?= $p['id'] ?>" aria-label="Убрать из избранного"><?= svg_icon('heart') ?></button>
        <a href="?page=product&id=<?= $p['id'] ?>">
          <div class="product-media">
            <?= product_image($p['image'], $p['name'], false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?>
            <span class="category"><?= h($p['category_name']) ?></span>
          </div>
          <div class="catalog-card-body">
            <small><?= h($p['brand'] . ' · ' . $p['model']) ?></small>
            <h3><?= h($p['name']) ?></h3>
            <p><?= h($p['description']) ?></p>
            <div class="price-row"><b><?= number_format($p['price'], 0, ',', ' ') ?> ₽</b><span class="detail-arrow"><?= svg_icon('arrow-right') ?></span></div>
          </div>
        </a>
      </article>
    <?php endforeach; ?>
  </div>
  <?php
}

if ($page === 'product') {
  $st = $pdo->prepare('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.is_active=1');
  $st->execute([(int)($_GET['id'] ?? 0)]);
  $p = $st->fetch();

  if (!$p) {
    empty_state('Товар не найден или временно скрыт.<br><a href="?page=catalog">Вернуться в каталог →</a>');
  } else {
    $prevSt = $pdo->prepare('SELECT id,name FROM products WHERE category_id=? AND is_active=1 AND id < ? ORDER BY id DESC LIMIT 1');
    $prevSt->execute([$p['category_id'], $p['id']]);
    $prevProduct = $prevSt->fetch();
    $nextSt = $pdo->prepare('SELECT id,name FROM products WHERE category_id=? AND is_active=1 AND id > ? ORDER BY id ASC LIMIT 1');
    $nextSt->execute([$p['category_id'], $p['id']]);
    $nextProduct = $nextSt->fetch();
    $relatedSt = $pdo->prepare('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.category_id=? AND p.id<>? AND p.is_active=1 ORDER BY p.id LIMIT 4');
    $relatedSt->execute([$p['category_id'], $p['id']]);
    $related = $relatedSt->fetchAll();
    ?>
    <div class="product-nav">
      <a href="?page=catalog&category=<?= (int)$p['category_id'] ?>"><?= svg_icon('arrow-left') ?> <?= h($p['category_name']) ?></a>
      <div>
        <?php if ($prevProduct): ?><a href="?page=product&id=<?= $prevProduct['id'] ?>" title="<?= h($prevProduct['name']) ?>"><?= svg_icon('arrow-left') ?> Предыдущий</a><?php endif; ?>
        <?php if ($nextProduct): ?><a href="?page=product&id=<?= $nextProduct['id'] ?>" title="<?= h($nextProduct['name']) ?>">Следующий <?= svg_icon('arrow-right') ?></a><?php endif; ?>
      </div>
    </div>

    <section class="product-page">
      <div class="product-stage reveal">
        <?= product_image($p['image'], $p['name'], true, '(max-width: 900px) calc(100vw - 40px), 55vw') ?>
        <div class="stage-label"><?= h($p['brand']) ?> / <?= h($p['model']) ?></div>
      </div>
      <div class="product-detail reveal delay">
        <div class="eyebrow"><?= h($p['category_name']) ?> / <?= h($p['brand']) ?></div>
        <h1><?= h($p['name']) ?></h1>
        <p><?= h($p['description']) ?></p>
        <div class="specs">
          <div><span>Гарантия</span><b><?= (int)$p['warranty_months'] ?> мес.</b></div>
          <div><span>На складе</span><b><?= (int)$p['stock'] ?> шт.</b></div>
          <div><span>Модель</span><b><?= h($p['model']) ?></b></div>
        </div>
        <div class="buy">
          <div><small><?= (int)$p['stock'] > 0 ? 'Есть в наличии' : 'Нет в наличии' ?></small><strong><?= number_format($p['price'], 0, ',', ' ') ?> ₽</strong></div>
          <?php if ((int)$p['stock'] > 0): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="add_cart">
              <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
              <button class="btn btn-primary">Добавить в корзину</button>
            </form>
          <?php else: ?>
            <a class="btn btn-ghost" href="?page=service">Уточнить поставку</a>
          <?php endif; ?>
        </div>
        <div class="product-assurance">
          <span><?= svg_icon('check') ?> Проверка перед выдачей</span>
          <span><?= svg_icon('check') ?> Гарантия <?= (int)$p['warranty_months'] ?> мес.</span>
          <span><?= svg_icon('check') ?> Сервис после покупки</span>
          <span><?= svg_icon('check') ?> Помощь с подключением</span>
        </div>
      </div>
    </section>

    <?php if ($related): ?>
      <section class="section related-section">
        <div class="section-head"><div><div class="eyebrow">RELATED</div><h2>Ещё в категории<br><?= h($p['category_name']) ?></h2></div><a class="text-link" href="?page=catalog&category=<?= (int)$p['category_id'] ?>">Смотреть все <?= svg_icon('arrow-right') ?></a></div>
        <div class="product-grid related-grid">
          <?php foreach ($related as $rp): ?>
            <article class="product-card reveal">
              <div class="product-media"><?= product_image($rp['image'], $rp['name'], false, '(max-width: 620px) calc(100vw - 40px), (max-width: 1200px) 45vw, 340px') ?><span class="stock"><?= h($rp['category_name']) ?></span></div>
              <div class="product-info"><div><small><?= h($rp['brand'] . ' / ' . $rp['model']) ?></small><h3><?= h($rp['name']) ?></h3></div><b><?= number_format($rp['price'],0,',',' ') ?> ₽</b></div>
              <a class="card-link" href="?page=product&id=<?= $rp['id'] ?>" aria-label="Открыть">↗</a>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php
  }
}

if ($page === 'cart') {
  $items = cart();
  $products = [];
  $total = 0;
  if ($items) {
    $ids = array_keys($items);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM products WHERE id IN ($ph)");
    $st->execute($ids);
    $products = $st->fetchAll();
    foreach ($products as $p) {
      $total += $p['price'] * $items[$p['id']];
    }
  }
  ?>
  <section class="inner-hero compact">
    <div class="eyebrow">ORDER / CART</div>
    <h1>Корзина</h1>
  </section>
  <?php if (!$products): ?>
    <?php empty_state('Корзина пока пуста.<br><a href="?page=catalog">Перейти в каталог →</a>'); ?>
  <?php else: ?>
    <div class="cart-layout">
      <div>
        <?php foreach ($products as $p): ?>
          <div class="cart-item">
            <div class="thumb"><?= product_image($p['image'], '', false, '110px') ?></div>
            <div>
              <small><?= h($p['brand'] . ' ' . $p['model']) ?></small>
              <h3><?= h($p['name']) ?></h3>
              <form method="post" class="cart-qty">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="update_cart">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <label>Количество <input type="number" name="qty" value="<?= (int)$items[$p['id']] ?>" min="1" max="<?= max(1,(int)$p['stock']) ?>"></label>
                <button type="submit">Обновить</button>
              </form>
            </div>
            <b><?= number_format($p['price'] * $items[$p['id']], 0, ',', ' ') ?> ₽</b>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="remove_cart">
              <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
              <button class="remove" aria-label="Удалить товар"><?= svg_icon('close') ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <aside class="checkout">
        <h3>Оформление заказа</h3>
        <div class="total"><span>Итого</span><b><?= number_format($total, 0, ',', ' ') ?> ₽</b></div>
        <?php if (user()): ?>
          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="checkout">
            <label class="form-field"><span>Телефон для связи</span><input type="tel" autocomplete="tel" name="phone" required placeholder="Телефон" value="<?= h(user()['phone']) ?>"></label>
            <label class="form-field"><span>Адрес доставки</span><input name="address" required placeholder="Адрес доставки"></label>
            <label class="form-field"><span>Способ получения</span><select name="delivery_method">
              <option>Курьер</option>
              <option>Самовывоз</option>
            </select></label>
            <label class="form-field field-wide"><span>Комментарий (необязательно)</span><textarea name="comment" placeholder="Комментарий"></textarea></label>
            <button class="btn btn-primary">Оформить заказ</button>
          </form>
        <?php else: ?>
          <p>Для оформления заказа необходимо войти.</p>
          <a class="btn btn-primary" href="?page=login&next=cart">Войти и продолжить заказ</a>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif;
}

if ($page === 'service') {
  $types = $pdo->query('SELECT * FROM service_types ORDER BY id ASC')->fetchAll();
  ?>
  <section class="inner-hero">
    <div class="eyebrow">СЕРВИСНЫЙ ЦЕНТР</div>
    <h1>Сервис и ремонт<br><em>офисной техники</em></h1>
    <p>Диагностируем, обслуживаем и ремонтируем принтеры, МФУ, сканеры, проекторы и другую офисную технику. Стоимость согласовываем до начала работ.</p>
  </section>

  <div class="services-grid">
    <?php foreach ($types as $i => $s): ?>
      <div class="service-card reveal">
        <span><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <h3><?= h($s['name']) ?></h3>
        <p><?= h($s['description']) ?></p>
        <div><b>от <?= number_format($s['base_price'], 0, ',', ' ') ?> ₽</b><small>≈ <?= (int)$s['estimated_days'] ?> дн.</small><a class="service-select" href="#service-request" data-service-id="<?= (int)$s['id'] ?>">Выбрать услугу <?= svg_icon('arrow-right') ?></a></div>
      </div>
    <?php endforeach; ?>
  </div>

  <section id="service-request" class="request-panel reveal">
    <div>
      <div class="eyebrow">НОВАЯ ЗАЯВКА</div>
      <h2>Опишите неисправность</h2>
      <p>После отправки заявка появится в личном кабинете. Менеджер свяжется с вами и согласует стоимость и сроки.</p>
    </div>

    <?php if (user()): ?>
      <form method="post" class="form form-grid">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="service">
        <label class="form-field"><span>Услуга</span><select name="service_type_id">
          <?php foreach ($types as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="form-field"><span>Тип устройства</span><input name="device_type" required placeholder="Тип устройства"></label>
        <label class="form-field"><span>Модель (необязательно)</span><input name="model" placeholder="Модель"></label>
        <label class="form-field"><span>Серийный номер (необязательно)</span><input name="serial_number" placeholder="Серийный номер"></label>
        <label class="form-field"><span>Телефон для связи</span><input type="tel" autocomplete="tel" name="phone" required value="<?= h(user()['phone']) ?>" placeholder="Телефон"></label>
        <label class="form-field field-wide"><span>Что случилось с устройством?</span><textarea name="problem" required placeholder="Опишите проблему"></textarea></label>
        <button class="btn btn-primary">Отправить заявку</button>
      </form>
    <?php else: ?>
      <a class="btn btn-primary" href="?page=login&next=service">Войти и оставить заявку</a>
    <?php endif; ?>
  </section>
  <?php
}

if ($page === 'login') {
  ?>
  <section class="auth">
    <div>
      <div class="eyebrow">ЛИЧНЫЙ КАБИНЕТ</div>
      <h1>Личный<br><em>кабинет</em></h1>
      <p>Заказы, сервисные заявки и история обращений в одном личном кабинете.</p>
    </div>
    <div class="auth-box">
      <div class="tabs">
        <button class="active" data-tab="login">Вход</button>
        <button data-tab="register">Регистрация</button>
      </div>
      <form method="post" class="form tab-content active" id="login">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="next" value="<?= h(in_array(($_GET['next'] ?? ''), ['cart','service'], true) ? $_GET['next'] : 'account') ?>">
        <label class="form-field"><span>Электронная почта</span><input autocomplete="email" type="email" name="email" required placeholder="E-mail"></label>
        <label class="form-field"><span>Пароль</span><input autocomplete="current-password" type="password" name="password" required placeholder="Пароль"></label>
        <button class="btn btn-primary">Войти</button>
        <small>Нет аккаунта? Откройте вкладку «Регистрация».</small>
      </form>
      <form method="post" class="form tab-content" id="register">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="next" value="<?= h(in_array(($_GET['next'] ?? ''), ['cart','service'], true) ? $_GET['next'] : 'account') ?>">
        <label class="form-field"><span>Имя и фамилия</span><input name="name" required placeholder="Имя и фамилия"></label>
        <label class="form-field"><span>Электронная почта</span><input autocomplete="email" type="email" name="email" required placeholder="E-mail"></label>
        <label class="form-field"><span>Телефон для связи</span><input type="tel" autocomplete="tel" name="phone" required placeholder="Телефон"></label>
        <label class="form-field"><span>Пароль</span><input autocomplete="new-password" type="password" name="password" minlength="6" required placeholder="Пароль"></label>
        <button class="btn btn-primary">Создать аккаунт</button>
      </form>
    </div>
  </section>
  <?php
}

if ($page === 'account') {
  if (!user()) {
    header('Location: ?page=login');
    exit;
  }

  $st = $pdo->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC');
  $st->execute([user()['id']]);
  $orders = $st->fetchAll();

  $st = $pdo->prepare('SELECT sr.*, st.name service_name FROM service_requests sr LEFT JOIN service_types st ON st.id=sr.service_type_id WHERE sr.user_id=? ORDER BY sr.id DESC');
  $st->execute([user()['id']]);
  $reqs = $st->fetchAll();
  ?>
  <section class="inner-hero compact">
    <div class="eyebrow">ЛИЧНЫЙ КАБИНЕТ</div>
    <h1><?= h(user()['name']) ?></h1>
    <p><?= h(user()['email']) ?> · <?= h(user()['phone']) ?></p>
    <div class="account-actions">
      <?php if (is_admin()): ?><a class="btn btn-ghost" href="?page=admin">Админ-панель</a><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost">Выйти</button>
      </form>
    </div>
  </section>

  <div class="dashboard">
    <section>
      <h2>Заказы</h2>
      <?php if (!$orders): ?><p class="muted">Пока нет заказов.</p><?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <div class="dash-row">
          <span>#<?= $o['id'] ?></span>
          <div><b>Заказ на <?= number_format($o['total_amount'], 0, ',', ' ') ?> ₽</b><small><?= h($o['created_at']) ?></small></div>
          <em class="status"><?= h(order_status_label($o['status'])) ?></em>
        </div>
      <?php endforeach; ?>
    </section>

    <section>
      <h2>Сервисные заявки</h2>
      <?php if (!$reqs): ?><p class="muted">Пока нет заявок.</p><?php endif; ?>
      <?php foreach ($reqs as $r): ?>
        <div class="dash-row">
          <span>#<?= $r['id'] ?></span>
          <div><b><?= h($r['service_name'] . ' · ' . $r['device_type'] . ' ' . $r['model']) ?></b><small><?= h($r['problem']) ?></small></div>
          <em class="status"><?= h(service_status_label($r['status'])) ?></em>
        </div>
      <?php endforeach; ?>
    </section>
  </div>
  <?php
}

if ($page === 'admin') {
  if (!is_admin()) {
    empty_state('Доступ запрещён.');
  } else {
    $orders = $pdo->query('SELECT o.*, u.name user_name FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC')->fetchAll();
    $reqs = $pdo->query('SELECT sr.*, u.name user_name, st.name service_name FROM service_requests sr LEFT JOIN users u ON u.id=sr.user_id LEFT JOIN service_types st ON st.id=sr.service_type_id ORDER BY sr.id DESC')->fetchAll();
    $cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    $adminProducts = $pdo->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC')->fetchAll();
    $stats = [
      'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
      'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
      'service' => (int)$pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn(),
      'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    ];
    ?>
    <section class="inner-hero compact">
      <div class="eyebrow">УПРАВЛЕНИЕ СИСТЕМОЙ</div>
      <h1>Админ-панель</h1>
      <p>Управление товарами, заказами и сервисными заявками.</p>
    </section>

    <div class="admin-stats">
      <div><span>Товары</span><b><?= $stats['products'] ?></b></div>
      <div><span>Заказы</span><b><?= $stats['orders'] ?></b></div>
      <div><span>Сервис</span><b><?= $stats['service'] ?></b></div>
      <div><span>Пользователи</span><b><?= $stats['users'] ?></b></div>
    </div>

    <div class="admin-grid">
      <section class="admin-card">
        <h2>Новый товар</h2>
        <form method="post" class="form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="admin_product_add">
          <select name="category_id">
            <?php foreach ($cats as $c): ?>
              <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="name" required placeholder="Название">
          <div class="two"><input name="brand" placeholder="Бренд"><input name="model" placeholder="Модель"></div>
          <textarea name="description" placeholder="Описание"></textarea>
          <div class="two"><input type="number" name="price" placeholder="Цена"><input type="number" name="stock" placeholder="Остаток"></div>
          <div class="two"><input type="number" name="warranty_months" value="24" placeholder="Гарантия"><input name="image" placeholder="Путь или URL изображения"></div>
          <button class="btn btn-primary">Добавить товар</button>
        </form>
      </section>

      <section class="admin-card wide">
        <div class="admin-card-head"><h2>Товары</h2><span><?= count($adminProducts) ?> позиций</span></div>
        <div class="admin-product-list">
          <?php foreach ($adminProducts as $ap): ?>
            <div class="admin-product-row">
              <?= product_image($ap['image'], '', false, '60px') ?>
              <div class="admin-product-main"><b><?= h($ap['name']) ?></b><small><?= h($ap['category_name'] . ' · ' . $ap['model']) ?></small></div>
              <form method="post" class="admin-product-edit">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="admin_product_update">
                <input type="hidden" name="id" value="<?= $ap['id'] ?>">
                <label>Цена<input type="number" name="price" value="<?= (int)$ap['price'] ?>"></label>
                <label>Остаток<input type="number" name="stock" value="<?= (int)$ap['stock'] ?>"></label>
                <label>Гарантия<input type="number" name="warranty_months" value="<?= (int)$ap['warranty_months'] ?>"></label>
                <button class="mini-save" title="Сохранить" aria-label="Сохранить товар"><?= svg_icon('check') ?></button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="admin_product_toggle">
                <input type="hidden" name="id" value="<?= $ap['id'] ?>">
                <button class="visibility-btn <?= $ap['is_active'] ? 'active' : '' ?>" title="Изменить видимость"><?= $ap['is_active'] ? 'Показывается' : 'Скрыт' ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="admin-card">
        <h2>Заказы</h2>
        <?php foreach ($orders as $o): ?>
          <form method="post" class="admin-row">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="admin_order_status">
            <input type="hidden" name="id" value="<?= $o['id'] ?>">
            <div><b>#<?= $o['id'] ?> · <?= h($o['user_name']) ?></b><small><?= number_format($o['total_amount'], 0, ',', ' ') ?> ₽</small></div>
            <select name="status">
              <?php foreach (['new','processing','shipped','done','cancelled'] as $status): ?>
                <option value="<?= $status ?>" <?= $o['status'] === $status ? 'selected' : '' ?>><?= h(order_status_label($status)) ?></option>
              <?php endforeach; ?>
            </select>
            <button aria-label="Сохранить изменения"><?= svg_icon('check') ?></button>
          </form>
        <?php endforeach; ?>
      </section>

      <section class="admin-card wide">
        <h2>Сервисные заявки</h2>
        <?php foreach ($reqs as $r): ?>
          <form method="post" class="admin-row service-admin">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="admin_service_status">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <div><b>#<?= $r['id'] ?> · <?= h($r['user_name']) ?></b><small><?= h($r['service_name'] . ' · ' . $r['device_type'] . ' ' . $r['model']) ?></small></div>
            <select name="status">
              <?php foreach (['new','diagnostics','repair','done','cancelled'] as $status): ?>
                <option value="<?= $status ?>" <?= $r['status'] === $status ? 'selected' : '' ?>><?= h(service_status_label($status)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="cost" value="<?= h($r['cost']) ?>" placeholder="Стоимость">
            <button aria-label="Сохранить изменения"><?= svg_icon('check') ?></button>
          </form>
        <?php endforeach; ?>
      </section>
    </div>
    <?php
  }
}

if ($page === 'about') {
  ?>
  <section class="inner-hero">
    <div class="eyebrow">О КОМПАНИИ</div>
    <h1>Оргтехника и сервис<br><em>для ежедневной работы бизнеса</em></h1>
    <p>NEXA OFFICE объединяет поставку оргтехники, подбор оборудования, оформление заказов и сервисное обслуживание в одной системе.</p>
  </section>

  <div class="story-grid">
    <div class="story-card big">
      <span>01</span>
      <h2>От подбора техники<br>до сервисной поддержки</h2>
      <p>Пользователь может выбрать оборудование, оформить заказ и при необходимости сразу отправить технику на обслуживание, не переходя между разрозненными системами.</p>
    </div>
    <div class="story-card"><span>02</span><h3><?= $catalogCount ?></h3><p>товарных позиций уже в базе</p></div>
    <div class="story-card"><span>03</span><h3><?= $categoryCount ?></h3><p>категорий оргтехники</p></div>
    <div class="story-card"><span>04</span><h3>1</h3><p>единая админ-панель управления</p></div>
  </div>
  <?php
}

if ($page === 'contacts') {
  ?>
  <section class="inner-hero">
    <div class="eyebrow">КОНТАКТЫ</div>
    <h1>Связаться<br><em>с командой</em></h1>
    <p>Свяжитесь с отделом продаж или сервисным центром — поможем подобрать оборудование, оформить поставку или принять технику в ремонт.</p>
  </section>

  <div class="contacts">
    <div><small>ТЕЛЕФОН</small><b>+7 (495) 120-44-20</b></div>
    <div><small>E-MAIL</small><b>hello@nexa-office.ru</b></div>
    <div><small>АДРЕС</small><b>Москва, ул. Технологическая, 18</b></div>
    <div><small>РЕЖИМ</small><b>Пн–Пт · 09:00–19:00</b></div>
  </div>

  <div class="map-art">
    <span>NEXA OFFICE</span>
    <i></i>
    <b>55.75° / 37.61°</b>
  </div>
  <?php
}

if (!in_array($page, ['home','catalog','product','favorites','cart','service','login','account','admin','about','contacts'], true)) {
  empty_state('Страница не найдена.');
}

render_footer();
