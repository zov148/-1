<?php
session_start();
require_once __DIR__ . '/catalog_upgrade.php';
$config = require __DIR__ . '/config.php';

define('APP_NAME', $config['brand']);

function db(): PDO {
  static $pdo = null;
  global $config;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $d = $config['db'];
  if (($d['driver'] ?? 'mysql') === 'mysql') {
    $ports = array_values(array_unique(array_filter([
      (string)($d['port'] ?? '3306'),
      '3306',
      '3307',
    ])));

    $last = null;
    foreach ($ports as $port) {
      try {
        $dsn = "mysql:host={$d['host']};port={$port};dbname={$d['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        break;
      } catch (PDOException $e) {
        $last = $e;
      }
    }

    if (!$pdo && $last) throw $last;
    upgrade_catalog($pdo);
    return $pdo;
  }

  if (!is_dir(__DIR__ . '/storage')) {
    mkdir(__DIR__ . '/storage', 0777, true);
  }
  $pdo = new PDO('sqlite:' . $d['sqlite_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  init_sqlite($pdo);
  upgrade_catalog($pdo);
  return $pdo;
}

function init_sqlite(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT UNIQUE NOT NULL,phone TEXT,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT UNIQUE NOT NULL,description TEXT);
CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT,category_id INTEGER,name TEXT NOT NULL,brand TEXT,model TEXT,description TEXT,price REAL NOT NULL,stock INTEGER DEFAULT 0,warranty_months INTEGER DEFAULT 12,image TEXT,is_active INTEGER DEFAULT 1,FOREIGN KEY(category_id) REFERENCES categories(id));
CREATE TABLE IF NOT EXISTS product_images (id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,image_path TEXT,alt_text TEXT,sort_order INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,status TEXT DEFAULT 'new',total_amount REAL,delivery_method TEXT,address TEXT,phone TEXT,comment TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS order_items (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,product_id INTEGER,quantity INTEGER,price REAL);
CREATE TABLE IF NOT EXISTS service_types (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,description TEXT,base_price REAL,estimated_days INTEGER);
CREATE TABLE IF NOT EXISTS service_requests (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,service_type_id INTEGER,device_type TEXT,model TEXT,serial_number TEXT,problem TEXT,phone TEXT,status TEXT DEFAULT 'new',cost REAL DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS reviews (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,product_id INTEGER,rating INTEGER,text TEXT,status TEXT DEFAULT 'approved',created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
  $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  if ($count === 0) {
    seed($pdo);
  }
}

function seed(PDO $pdo): void {
  upgrade_catalog($pdo);

  if ((int)$pdo->query('SELECT COUNT(*) FROM service_types')->fetchColumn() === 0) {
  $pdo->exec("INSERT INTO service_types(name,description,base_price,estimated_days) VALUES
('Диагностика','Проверка механики, печати, оптики и интерфейсов устройства',1500,1),
('Профилактика','Чистка, настройка и профилактическое обслуживание техники',2900,2),
('Ремонт принтера/МФУ','Восстановление узлов печати, подачи и электроники',4500,4),
('Настройка сети','Подключение техники к локальной сети и рабочим местам',2200,1),
('Договорное обслуживание','Ежемесячная поддержка оборудования компании',7900,3);");
  }

  if (!(int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='admin@nexa.local'")->fetchColumn()) {
  $st = $pdo->prepare('INSERT INTO users(name,email,phone,password_hash,role) VALUES(?,?,?,?,?)');
  $st->execute(['Администратор', 'admin@nexa.local', '+7 900 000-00-00', '$2y$12$/P5f0Cguj2IouxAVcHfEj.nkpGZMs2J3D52Ox9cReFuLMljjHJR4m', 'admin']);
  }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function user() { return $_SESSION['user'] ?? null; }
function is_admin() { return user() && user()['role'] === 'admin'; }
function cart() { return $_SESSION['cart'] ?? []; }
function cart_count() { return array_sum(cart()); }
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check() { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(419); exit('CSRF token mismatch'); } }
function flash($m) { $_SESSION['flash'] = $m; }
function get_flash() { $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m; }
