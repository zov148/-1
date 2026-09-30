<?php
session_start();
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
$config = require __DIR__ . '/config.php';
$message = null;
$type = 'info';
$usedPort = null;

function installer_connect(array $db, string $port): PDO {
  return new PDO(
    "mysql:host={$db['host']};port={$port};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Обновите страницу и повторите попытку.'); }
  $db = $config['db'];
  $ports = array_values(array_unique([(string)($db['port'] ?? '3306'), '3306', '3307']));
  $pdo = null;
  $lastError = null;

  foreach ($ports as $port) {
    try {
      $pdo = installer_connect($db, $port);
      $usedPort = $port;
      break;
    } catch (Throwable $e) {
      $lastError = $e;
    }
  }

  if (!$pdo) {
    $type = 'error';
    $message = 'Не удалось подключиться к MySQL на портах 3306 и 3307. Убедитесь, что MySQL запущен в XAMPP.';
    if ($lastError) {
      $message .= ' Ошибка: ' . $lastError->getMessage();
    }
  } else {
    try {
      $dbName = (string)$db['name'];
      if (!preg_match('/^[a-zA-Z0-9_]+$/D', $dbName)) throw new RuntimeException('Недопустимое имя базы.');
      $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      $pdo->exec("USE `$dbName`");
      $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
      $check->execute([$dbName, 'products']);
      $existing = (int)$check->fetchColumn() > 0;
      if ($existing) {
        // Revalidate the signed-in administrator against the database.
        $admin = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $admin->execute([(int)($_SESSION['user']['id'] ?? 0)]);
        if ($admin->fetchColumn() !== 'admin') {
          throw new RuntimeException('Для обновления существующего каталога сначала войдите на сайте как администратор. Данные не изменены.');
        }
        $catalog = require __DIR__ . '/data/catalog.php';
        $pdo->beginTransaction();
        $findCategory = $pdo->prepare('SELECT id FROM categories WHERE slug=?');
        $addCategory = $pdo->prepare('INSERT INTO categories(id,name,slug,description) VALUES(?,?,?,?)');
        $maxCategory = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM categories')->fetchColumn();
        $categoryMap = [];
        foreach ($catalog['categories'] as $category) {
          $findCategory->execute([$category[2]]);
          $categoryId = $findCategory->fetchColumn();
          if ($categoryId === false) {
            $categoryId = ++$maxCategory;
            $addCategory->execute([$categoryId, $category[1], $category[2], $category[3]]);
          }
          $categoryMap[$category[0]] = (int)$categoryId;
        }
        $findProduct = $pdo->prepare('SELECT id FROM products WHERE brand=? AND model=? LIMIT 1');
        $addProduct = $pdo->prepare('INSERT INTO products(category_id,name,brand,model,description,price,stock,warranty_months,image) VALUES(?,?,?,?,?,?,?,?,?)');
        $updateImage = $pdo->prepare('UPDATE products SET image=? WHERE id=?');
        foreach ($catalog['products'] as $product) {
          $findProduct->execute([$product[2], $product[3]]);
          $productId = $findProduct->fetchColumn();
          if ($productId === false) {
            $product[0] = $categoryMap[$product[0]];
            $addProduct->execute($product);
          } else {
            $updateImage->execute([$product[8], $productId]);
          }
        }
        $pdo->commit();
      } else {
        // Fresh installation only. Never execute DROP statements on an existing database.
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables) throw new RuntimeException('База содержит другие таблицы. Укажите отдельную пустую базу в config.php.');
        $sql = file_get_contents(__DIR__ . '/database.sql');
        if ($sql === false) throw new RuntimeException('Файл database.sql не найден.');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
          $statement = trim($statement);
          if ($statement === '' || preg_match('/^(DROP TABLE|CREATE DATABASE|USE )/i', $statement)) continue;
          $pdo->exec($statement);
        }
      }
      $type = 'success';
      $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
      $categoryCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
      $message = ($existing ? 'Каталог обновлён без удаления заказов, пользователей, цен и остатков. ' : 'База успешно установлена. ') . $productCount . ' товаров в ' . $categoryCount . ' категориях. MySQL: порт ' . $usedPort . '.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $type = 'error';
      $message = 'Ошибка установки базы: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Установка NEXA OFFICE</title>
  <style>
    :root{--deep:#37494d;--accent:#6a8d94;--light:#b1c4c7;--warm:#9c7b63;--bg:#eef2f1;--line:#dce3e2}
    *{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f6f5;color:#24363a;min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(760px,100%);background:#fff;border:1px solid var(--line);border-radius:8px;padding:34px;box-shadow:0 12px 34px rgba(55,73,77,.1)}
    .brand{display:flex;align-items:center;gap:12px;font-weight:800;letter-spacing:.06em}.brand span{width:40px;height:40px;border-radius:4px;background:var(--deep);color:#fff;display:grid;place-items:center}
    h1{font-size:38px;line-height:1.05;margin:28px 0 14px;letter-spacing:-.035em}p{color:#65787c;line-height:1.7}.steps{display:grid;gap:0;margin:24px 0;border:1px solid var(--line)}.steps div{padding:14px 16px;background:#fff;border-bottom:1px solid var(--line)}.steps div:last-child{border-bottom:0}
    button,a{display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:5px;padding:13px 18px;font-weight:700;text-decoration:none;cursor:pointer}.primary{background:var(--deep);color:#fff}.secondary{background:#fff;color:var(--deep);border-color:#cbd6d5;margin-left:8px}
    .msg{padding:14px 16px;border-radius:5px;margin:18px 0;border:1px solid transparent}.success{background:#edf5f2;color:#31574d;border-color:#d5e6df}.error{background:#f7eeeb;color:#704b40;border-color:#ead8d2}.info{background:#edf1f1}.small{font-size:13px;color:#718084;margin-top:18px}
  </style>
</head>
<body>
  <main class="card">
    <div class="brand"><span>N</span> NEXA OFFICE</div>
    <h1>Установка базы данных</h1>
    <p>Эта страница автоматически проверяет MySQL на портах 3306 и 3307, создаёт базу <b><?= htmlspecialchars($config['db']['name'], ENT_QUOTES, 'UTF-8') ?></b>, таблицы и каталог из 50 товаров: 10 принтеров, 10 МФУ, 10 сканеров, 10 проекторов и 10 мониторов, все с локальными изображениями.</p>

    <div class="steps">
      <div>1. В XAMPP должны быть запущены <b>Apache</b> и <b>MySQL</b>.</div>
      <div>2. Нажмите кнопку ниже. Новая база будет создана. В существующей базе обновится только каталог; для этого войдите как администратор.</div>
      <div>3. После установки откройте сайт.</div>
    </div>

    <?php if ($message): ?><div class="msg <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <button class="primary" type="submit">Создать / обновить базу</button>
    </form>
    <a class="secondary" href="index.php">Открыть сайт</a>

    <div class="small">Администратор после установки: <b>admin@nexa.local</b> / <b>admin123</b></div>
  </main>
</body>
</html>
