CREATE DATABASE IF NOT EXISTS nexa_office CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE nexa_office;

DROP TABLE IF EXISTS reviews;

DROP TABLE IF EXISTS service_requests;

DROP TABLE IF EXISTS service_types;

DROP TABLE IF EXISTS order_items;

DROP TABLE IF EXISTS orders;

DROP TABLE IF EXISTS product_images;

DROP TABLE IF EXISTS products;

DROP TABLE IF EXISTS categories;

DROP TABLE IF EXISTS users;

CREATE TABLE users ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL UNIQUE, phone VARCHAR(30), password_hash VARCHAR(255) NOT NULL, role ENUM("user","admin") NOT NULL DEFAULT "user", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB;

CREATE TABLE categories ( id INT UNSIGNED PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, description TEXT ) ENGINE=InnoDB;

CREATE TABLE products ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED, name VARCHAR(180) NOT NULL, brand VARCHAR(100), model VARCHAR(100), description TEXT, price DECIMAL(10,2) NOT NULL, stock INT NOT NULL DEFAULT 0, warranty_months INT NOT NULL DEFAULT 12, image VARCHAR(500), is_active TINYINT(1) NOT NULL DEFAULT 1, CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(id) ) ENGINE=InnoDB;

CREATE TABLE product_images ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, image_path VARCHAR(500) NOT NULL, alt_text VARCHAR(255), sort_order INT DEFAULT 0, CONSTRAINT fk_product_images_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE ) ENGINE=InnoDB;

CREATE TABLE orders ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, status ENUM("new","processing","shipped","done","cancelled") DEFAULT "new", total_amount DECIMAL(10,2) NOT NULL, delivery_method VARCHAR(60), address VARCHAR(255), phone VARCHAR(30), comment TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_orders_user FOREIGN KEY(user_id) REFERENCES users(id) ) ENGINE=InnoDB;

CREATE TABLE order_items ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, quantity INT NOT NULL, price DECIMAL(10,2) NOT NULL, CONSTRAINT fk_order_items_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, CONSTRAINT fk_order_items_product FOREIGN KEY(product_id) REFERENCES products(id) ) ENGINE=InnoDB;

CREATE TABLE service_types ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, description TEXT, base_price DECIMAL(10,2), estimated_days INT ) ENGINE=InnoDB;

CREATE TABLE service_requests ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, service_type_id INT UNSIGNED NOT NULL, device_type VARCHAR(120) NOT NULL, model VARCHAR(120), serial_number VARCHAR(120), problem TEXT NOT NULL, phone VARCHAR(30), status ENUM("new","diagnostics","repair","done","cancelled") DEFAULT "new", cost DECIMAL(10,2) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_service_user FOREIGN KEY(user_id) REFERENCES users(id), CONSTRAINT fk_service_type FOREIGN KEY(service_type_id) REFERENCES service_types(id) ) ENGINE=InnoDB;

CREATE TABLE reviews ( id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, rating TINYINT NOT NULL, text TEXT, status ENUM("pending","approved","rejected") DEFAULT "pending", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_review_user FOREIGN KEY(user_id) REFERENCES users(id), CONSTRAINT fk_review_product FOREIGN KEY(product_id) REFERENCES products(id) ) ENGINE=InnoDB;

INSERT INTO categories(id,name,slug,description) VALUES
(1,'Принтеры','printers','Лазерные и цветные решения для персональной и групповой печати'),
(2,'МФУ','mfu','Печать, копирование и сканирование для рабочих групп'),
(3,'Сканеры','scanners','Потоковые и настольные сканеры документов'),
(4,'Проекторы','projectors','Оборудование для переговорных, классов и конференц-залов'),
(5,'Мониторы','monitors','Мониторы для рабочих мест, аналитики и дизайна');

INSERT INTO products(category_id,name,brand,model,description,price,stock,warranty_months,image) VALUES
(1,'MonoPrint A210','Nexa','A210','Компактный лазерный принтер для персонального рабочего места. Дуплекс, USB и тихий режим.',17990,28,24,'assets/img/products/printer-01.jpg'),
(1,'MonoPrint A240 Wi‑Fi','Nexa','A240W','Быстрый монохромный принтер с Wi‑Fi, мобильной печатью и автоматическим дуплексом.',21990,24,24,'assets/img/products/printer-02.jpg'),
(1,'WorkPrint P310','Aster','P310','Надёжный принтер для малых рабочих групп с Ethernet и увеличенным лотком бумаги.',26990,19,24,'assets/img/products/printer-03.jpg'),
(1,'WorkPrint P410 Pro','Aster','P410','Производительный офисный принтер с защищённой печатью и ресурсным картриджем.',32990,16,36,'assets/img/products/printer-04.jpg'),
(1,'ColorLine C360','Linea','C360','Цветной лазерный принтер для презентаций и деловых документов, поддержка Wi‑Fi Direct.',39990,13,24,'assets/img/products/printer-05.jpg'),
(1,'ColorLine C520','Linea','C520','Скоростной цветной принтер для отдела маркетинга и административных задач.',48990,10,36,'assets/img/products/printer-06.jpg'),
(1,'OfficeJet L610','Miro','L610','Экономичный принтер с высокой месячной нагрузкой и удобным сетевым управлением.',55990,9,36,'assets/img/products/printer-07.jpg'),
(1,'OfficeJet L720 Secure','Miro','L720S','Флагманская модель для корпоративной печати с PIN-доступом и журналом заданий.',67990,7,36,'assets/img/products/printer-08.jpg'),
(1,'ProLaser T830 Network','Kern','T830N','Сетевой принтер для отделов закупок и бухгалтерии с повышенным ресурсом печати.',72990,6,36,'assets/img/products/printer-09.jpg'),
(1,'ProLaser T910 Color','Kern','T910C','Премиальный цветной принтер для офисов с высокой ежемесячной нагрузкой.',88990,4,36,'assets/img/products/printer-10.jpg'),
(2,'SmartHub M320','Nexa','M320','Компактное МФУ A4: печать, копирование и сканирование для небольшого офиса.',28990,22,24,'assets/img/products/mfu-01.jpg'),
(2,'SmartHub M440','Nexa','M440','МФУ с автоподатчиком, двусторонним сканированием и мобильной печатью.',45990,17,36,'assets/img/products/mfu-02.jpg'),
(2,'OfficeFlow M510','Aster','M510','Сетевое МФУ для рабочих групп с удобной панелью управления и быстрой печатью.',52990,14,36,'assets/img/products/mfu-03.jpg'),
(2,'OfficeFlow M680','Aster','M680','Высокопроизводительное МФУ с сенсорным экраном и расширенной обработкой документов.',68990,11,36,'assets/img/products/mfu-04.jpg'),
(2,'ColorHub C550','Linea','C550','Цветное МФУ для офиса с качественной печатью презентационных материалов.',74990,9,36,'assets/img/products/mfu-05.jpg'),
(2,'ColorHub C720','Linea','C720','МФУ с высокой скоростью цветной печати, двусторонним ADF и сетевым сканированием.',89990,7,36,'assets/img/products/mfu-06.jpg'),
(2,'BusinessFlow X810','Miro','X810','МФУ для интенсивной нагрузки с расширенными лотками и учётом пользователей.',109990,5,36,'assets/img/products/mfu-07.jpg'),
(2,'BusinessFlow X950','Miro','X950','Корпоративное МФУ для централизованной печати и электронного документооборота.',139990,4,36,'assets/img/products/mfu-08.jpg'),
(2,'OfficeCenter Q880','Kern','Q880','МФУ бизнес‑класса с удалённым мониторингом и интеграцией в сетевую печать.',119990,5,36,'assets/img/products/mfu-09.jpg'),
(2,'OfficeCenter Q980 Pro','Kern','Q980P','Флагманское МФУ для большого офиса с высокой скоростью и продвинутой безопасностью.',154990,3,36,'assets/img/products/mfu-10.jpg'),
(3,'StreamScan S120','Nexa','S120','Компактный сканер для договоров и счетов, до 25 страниц в минуту.',14990,31,24,'assets/img/products/scanner-01.jpg'),
(3,'StreamScan S220','Nexa','S220','Потоковый сканер с автоматической подачей, двусторонним режимом и USB‑C.',20990,25,24,'assets/img/products/scanner-02.jpg'),
(3,'StreamScan S280','Aster','S280','Документ-сканер до 45 стр/мин с интеллектуальным выравниванием изображения.',27990,19,24,'assets/img/products/scanner-03.jpg'),
(3,'ArchiveScan D360','Aster','D360','Сканер для архива и бухгалтерии с распознаванием пустых страниц и OCR.',34990,15,24,'assets/img/products/scanner-04.jpg'),
(3,'ArchiveScan D480','Linea','D480','Высокоскоростной потоковый сканер для центра обработки документов.',43990,12,36,'assets/img/products/scanner-05.jpg'),
(3,'DeskScan F210','Linea','F210','Планшетный сканер для документов, фотографий и материалов нестандартного формата.',18990,18,24,'assets/img/products/scanner-06.jpg'),
(3,'DeskScan F320 Pro','Miro','F320P','Профессиональный планшетный сканер с точной цветопередачей и высокой детализацией.',29990,11,36,'assets/img/products/scanner-07.jpg'),
(3,'CaptureStation S600','Miro','S600','Сканирующая станция для регулярной оцифровки больших комплектов документов.',57990,6,36,'assets/img/products/scanner-08.jpg'),
(3,'DocMaster A700','Kern','A700','Потоковый сканер для фронт‑офиса и бухгалтерии с двусторонним захватом.',38990,10,36,'assets/img/products/scanner-09.jpg'),
(3,'DocMaster A900','Kern','A900','Премиальный документ‑сканер для постоянной потоковой загрузки.',64990,5,36,'assets/img/products/scanner-10.jpg'),
(4,'BeamCast P320','Nexa','P320','Компактный Full HD проектор для небольших переговорных и учебных классов.',44990,15,24,'assets/img/products/projector-01.jpg'),
(4,'BeamCast P420','Nexa','P420','Яркий проектор для презентаций с HDMI, USB и автоматической коррекцией трапеции.',52990,12,24,'assets/img/products/projector-02.jpg'),
(4,'BeamCast P520','Aster','P520','Проектор Full HD для переговорных с беспроводной трансляцией и тихим охлаждением.',62990,10,24,'assets/img/products/projector-03.jpg'),
(4,'LumaVision P6','Aster','P6','Универсальный проектор для офиса с яркой картинкой и быстрым запуском.',69990,8,36,'assets/img/products/projector-04.jpg'),
(4,'LumaVision P8','Linea','P8','Проектор повышенной яркости для конференц-залов и учебных аудиторий.',74990,7,36,'assets/img/products/projector-05.jpg'),
(4,'LumaVision 4K P10','Linea','P10','4K-проектор для презентаций, демонстрационных залов и видеоконференций.',99990,5,36,'assets/img/products/projector-06.jpg'),
(4,'ConferenceBeam X12','Miro','X12','Проектор для больших помещений с гибкой настройкой геометрии и удалённым управлением.',129990,4,36,'assets/img/products/projector-07.jpg'),
(4,'ConferenceBeam X15 Laser','Miro','X15L','Лазерный проектор для интенсивной эксплуатации с увеличенным ресурсом источника света.',169990,3,36,'assets/img/products/projector-08.jpg'),
(4,'VisionRoom V18','Kern','V18','Проектор для переговорных премиум‑уровня с высоким контрастом и тихой работой.',139990,4,36,'assets/img/products/projector-09.jpg'),
(4,'VisionRoom V24 4K','Kern','V24','Флагманский 4K‑проектор для шоурумов и больших залов.',189990,2,36,'assets/img/products/projector-10.jpg'),
(5,'ViewLine 24F','Nexa','24F','24-дюймовый IPS Full HD монитор для стандартного офисного рабочего места.',18990,34,36,'assets/img/products/monitor-01.jpg'),
(5,'ViewLine 27Q','Nexa','27Q','27-дюймовый QHD IPS монитор для таблиц, аналитики и многозадачной работы.',34990,26,36,'assets/img/products/monitor-02.jpg'),
(5,'ViewLine 27U USB-C','Aster','27U','27-дюймовый 4K монитор с USB‑C, зарядкой ноутбука и регулируемой подставкой.',45990,18,36,'assets/img/products/monitor-03.jpg'),
(5,'ViewLine Ultra 34U','Aster','34U','Ультраширокий монитор для многозадачной работы и контроля нескольких приложений.',55990,14,36,'assets/img/products/monitor-04.jpg'),
(5,'StudioView 27P','Linea','27P','Монитор для дизайна и контента с заводской калибровкой и расширенным цветовым охватом.',62990,11,36,'assets/img/products/monitor-05.jpg'),
(5,'StudioView 32P','Linea','32P','32-дюймовый 4K монитор для детальной работы с графикой и большим объёмом данных.',74990,9,36,'assets/img/products/monitor-06.jpg'),
(5,'DeskWide 38C','Miro','38C','Изогнутый широкоформатный монитор для финансовых и аналитических рабочих мест.',89990,6,36,'assets/img/products/monitor-07.jpg'),
(5,'DeskWide 40U','Miro','40U','Большой 40-дюймовый монитор для диспетчерских, контроля процессов и презентаций.',119990,4,36,'assets/img/products/monitor-08.jpg'),
(5,'OfficeVision 32Q','Kern','32Q','Сбалансированный монитор QHD для многооконной работы и видеоконференций.',51990,8,36,'assets/img/products/monitor-09.jpg'),
(5,'OfficeVision 34C Pro','Kern','34CP','Изогнутый монитор бизнес‑класса для аналитики и сложных интерфейсов.',96990,5,36,'assets/img/products/monitor-10.jpg');

INSERT INTO service_types(name,description,base_price,estimated_days) VALUES ("Диагностика","Проверка механики, печати, оптики и интерфейсов устройства",1500,1),("Профилактика","Чистка, настройка и профилактическое обслуживание техники",2900,2),("Ремонт принтера/МФУ","Восстановление узлов печати, подачи и электроники",4500,4),("Настройка сети","Подключение техники к локальной сети и рабочим местам",2200,1),("Договорное обслуживание","Ежемесячная поддержка оборудования компании",7900,3);

INSERT INTO users(name,email,phone,password_hash,role) VALUES ("Администратор","admin@nexa.local","+7 900 000-00-00","$2y$12$/P5f0Cguj2IouxAVcHfEj.nkpGZMs2J3D52Ox9cReFuLMljjHJR4m","admin");