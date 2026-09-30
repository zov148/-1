<?php
/** One-time V6 data upgrade. Existing product IDs, prices, stock and orders survive. */
function upgrade_catalog(PDO $pdo): void {
  $version = 'v6_catalog_50';
  // The normal request path reads the migration marker without schema writes.
  try {
    $check = $pdo->prepare('SELECT version FROM app_migrations WHERE version=?');
    $check->execute([$version]);
  } catch (PDOException $e) {
    $missingTable = $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table: app_migrations');
    if (!$missingTable) throw $e;
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (version VARCHAR(80) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $check = $pdo->prepare('SELECT version FROM app_migrations WHERE version=?');
    $check->execute([$version]);
  }
  if ($check->fetchColumn()) return;
  $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $lock = null;
  try {
    if ($mysql) {
      $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
      $lock = 'nexa_upgrade_' . sha1($database);
      $st = $pdo->prepare('SELECT GET_LOCK(?, 15)');
      $st->execute([$lock]);
      if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Обновление каталога уже выполняется. Обновите страницу через несколько секунд.');
    }
    $pdo->beginTransaction();
    $check->execute([$version]);
    if ($check->fetchColumn()) { $pdo->commit(); return; }
    $catalog = require __DIR__ . '/data/catalog.php';
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE slug=?');
    $addCategory = $pdo->prepare('INSERT INTO categories(id,name,slug,description) VALUES(?,?,?,?)');
    $nextCategory = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM categories')->fetchColumn();
    $categoryMap = [];
    foreach ($catalog['categories'] as $c) {
      $findCategory->execute([$c[2]]);
      $id = $findCategory->fetchColumn();
      if ($id === false) {
        $id = ++$nextCategory;
        $addCategory->execute([$id,$c[1],$c[2],$c[3]]);
      }
      $categoryMap[$c[0]] = (int)$id;
    }
    $find = $pdo->prepare('SELECT id FROM products WHERE LOWER(brand)=LOWER(?) AND LOWER(model)=LOWER(?) ORDER BY id LIMIT 1');
    $findName = $pdo->prepare('SELECT id FROM products WHERE name=? AND (brand IS NULL OR brand=?) ORDER BY id LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO products(category_id,name,brand,model,description,price,stock,warranty_months,image,is_active) VALUES(?,?,?,?,?,?,?,?,?,1)');
    $update = $pdo->prepare('UPDATE products SET category_id=?,image=?,is_active=1 WHERE id=?');
    $ids = [];
    foreach ($catalog['products'] as $product) {
      $find->execute([$product[2],$product[3]]);
      $id = $find->fetchColumn();
      if ($id === false) { $findName->execute([$product[1],$product[2]]); $id=$findName->fetchColumn(); }
      if ($id === false) {
        $product[0] = $categoryMap[$product[0]];
        $insert->execute($product);
        $id = $pdo->lastInsertId();
      } else {
        // Restores visibility once for the 50 bundled models requested by the owner.
        // Later admin edits/visibility settings are left alone because this version is marked applied.
        $update->execute([$categoryMap[$product[0]],$product[8],$id]);
      }
      $ids[] = (int)$id;
    }
    if (count(array_unique($ids)) !== 50) throw new RuntimeException('Не удалось сопоставить все 50 моделей каталога.');
    $done = $pdo->prepare('INSERT INTO app_migrations(version) VALUES(?)');
    $done->execute([$version]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  } finally {
    if ($mysql && $lock !== null) { $st=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $st->execute([$lock]); }
  }
}
