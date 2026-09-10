<?php

declare(strict_types=1);

/*
 * Главный PHP-файл сайта CUECRAFT.
 *
 * Он заменяет Flask-приложение app.py: отдаёт страницы, принимает заявки,
 * защищает админ-панель и работает с SQLite-базой данных.
 */

// Используем один часовой пояс для времени блокировки входа.
date_default_timezone_set('Europe/Kyiv');

// Состояния заявок и товаров — только эти значения может сохранить администратор.
const ORDER_STATUSES = ['new', 'working', 'closed'];
const PRODUCT_AVAILABILITIES = ['in_stock', 'preorder', 'out_of_stock'];
const PAYMENT_METHODS = ['cash_on_delivery', 'card_transfer', 'online_demo'];

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/advisor.php';
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_SECONDS = 300;

// Без личной настройки вход администратора отключён.
const DEFAULT_ADMIN_PASSWORD_HASH = '';

// Эти товары добавляются только при первом запуске новой базы.
const INITIAL_PRODUCTS = [
    ['classic-maple', 'Classic Maple', 'in_stock', 3, 4800],
    ['walnut-pro', 'Walnut Pro', 'preorder', 0, 8900],
    ['carbon-strike', 'Carbon Strike', 'in_stock', 2, 12500],
    ['master-chalk', 'Master Chalk', 'in_stock', 15, 180],
    ['magnetic-chalk-holder', 'Magnetic Chalk Holder', 'preorder', 0, 650],
];

// Для тестов можно передать отдельный путь к базе через переменную окружения.
$databasePath = getenv('CUECRAFT_DATABASE') ?: __DIR__ . '/data/cuecraft.db';

// Сессии лежат внутри проекта, а не в общей временной папке Laragon.
// Так PHP может хранить вход админа даже при ограниченных правах на C:\laragon\tmp.
$sessionDirectory = __DIR__ . '/data/sessions';

if (!is_dir($sessionDirectory)) {
    mkdir($sessionDirectory, 0775, true);
}

ini_set('session.save_path', $sessionDirectory);

// Настраиваем безопасную cookie-сессию, в которой хранится факт входа админа.
session_name('cuecraft_session');
session_set_cookie_params([
    'httponly' => true,
    'path' => '/',
    'samesite' => 'Lax',
]);
session_start();

/** Открывает SQLite-базу. */
function database(): PDO
{
    global $databasePath;

    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory)) {
        mkdir($databaseDirectory, 0775, true);
    }

    $connection = new PDO('sqlite:' . $databasePath);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->exec('PRAGMA busy_timeout = 5000');

    return $connection;
}

/**
 * Возвращает путь к папке сайта в адресе браузера.
 *
 * На домене billiard-cues.test это будет "/", а при запуске через
 * localhost/billiard-cues/ — "/billiard-cues/".
 */
function basePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $directory = str_replace('\\', '/', dirname($scriptName));
    $directory = trim($directory, '/.');

    return $directory === '' ? '/' : '/' . $directory . '/';
}

/** Собирает адрес внутри текущей папки сайта. */
function appUrl(string $path = ''): string
{
    return basePath() . ltrim($path, '/');
}

/** Возвращает названия колонок таблицы — нужно для безопасного обновления старой базы. */
function tableColumns(PDO $connection, string $table): array
{
    $columns = [];

    foreach ($connection->query("PRAGMA table_info($table)") as $column) {
        $columns[] = $column['name'];
    }

    return $columns;
}

/** Создаёт таблицы при первом запуске и дополняет уже существующую SQLite-базу. */
function initializeDatabase(): void
{
    $connection = database();

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payment_method TEXT NOT NULL DEFAULT 'not_selected'
        )"
    );

    // Старые заявки не удаляем: лишь добавляем недостающие поля один раз.
    $orderColumns = tableColumns($connection, 'orders');
    initializePayments($connection, $orderColumns);

    if (!in_array('status', $orderColumns, true)) {
        $connection->exec("ALTER TABLE orders ADD COLUMN status TEXT NOT NULL DEFAULT 'new'");
    }

    if (!in_array('quantity', $orderColumns, true)) {
        $connection->exec("ALTER TABLE orders ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
    }

    if (!in_array('inventory_applied', $orderColumns, true)) {
        $connection->exec("ALTER TABLE orders ADD COLUMN inventory_applied INTEGER NOT NULL DEFAULT 0");
    }

    // Старые заявки остаются в истории: не приписываем им способ оплаты, которого тогда не выбирали.
    if (!in_array('payment_method', $orderColumns, true)) {
        $connection->exec(
            "ALTER TABLE orders ADD COLUMN payment_method TEXT NOT NULL DEFAULT 'not_selected'"
        );
    }

    // Таблица хранит только число ошибочных попыток и время окончания блокировки.
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS admin_login_attempts (
            client_address TEXT PRIMARY KEY,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0
        )"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS products (
            slug TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            availability TEXT NOT NULL DEFAULT 'in_stock',
            stock_quantity INTEGER NOT NULL DEFAULT 0,
            price_uah INTEGER NOT NULL DEFAULT 0
        )"
    );

    $productColumns = tableColumns($connection, 'products');

    if (!in_array('stock_quantity', $productColumns, true)) {
        $connection->exec('ALTER TABLE products ADD COLUMN stock_quantity INTEGER NOT NULL DEFAULT 0');
        $updateStock = $connection->prepare('UPDATE products SET stock_quantity = ? WHERE slug = ?');

        foreach (INITIAL_PRODUCTS as [$slug, $_name, $_availability, $quantity, $_priceUah]) {
            $updateStock->execute([$quantity, $slug]);
        }
    }

    // Цена хранится целым числом гривен: так в базе нет ошибок округления копеек.
    if (!in_array('price_uah', $productColumns, true)) {
        $connection->exec('ALTER TABLE products ADD COLUMN price_uah INTEGER NOT NULL DEFAULT 0');
        $updatePrice = $connection->prepare('UPDATE products SET price_uah = ? WHERE slug = ?');

        foreach (INITIAL_PRODUCTS as [$slug, $_name, $_availability, $_quantity, $priceUah]) {
            $updatePrice->execute([$priceUah, $slug]);
        }
    }

    // INSERT OR IGNORE не перезапишет остатки и цены, которые администратор уже настроил.
    $addProduct = $connection->prepare(
        'INSERT OR IGNORE INTO products (slug, name, availability, stock_quantity, price_uah) VALUES (?, ?, ?, ?, ?)'
    );

    foreach (INITIAL_PRODUCTS as $product) {
        $addProduct->execute($product);
    }
}

/** Отправляет JSON-ответ и прекращает выполнение текущего запроса. */
function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Отдаёт одну из готовых HTML-страниц. */
function servePage(string $page): never
{
    $pageContent = file_get_contents(__DIR__ . '/templates/' . $page);

    if ($pageContent === false) {
        jsonResponse(['ok' => false, 'error' => 'Не удалось открыть страницу сайта.'], 500);
    }

    header('Content-Type: text/html; charset=utf-8');
    // Подставляем правильный путь, чтобы CSS, JavaScript и API работали и в подпапке.
    echo str_replace('<!-- BASE_PATH -->', appUrl(), $pageContent);
    exit;
}

/** Читает JSON, который JavaScript передал в теле POST или PATCH-запроса. */
function readJsonBody(): ?array
{
    $decoded = json_decode(file_get_contents('php://input'), true);

    return is_array($decoded) ? $decoded : null;
}

/** Превращает безопасные текстовые поля JSON в обычную строку. */
function textValue(mixed $value): string
{
    return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
}

/** Проверяет форму заявки и возвращает либо данные, либо понятный текст ошибки. */
function validateOrder(?array $data): array
{
    if ($data === null) {
        return [null, 'Нужны данные заявки в формате JSON.'];
    }

    $product = textValue($data['product'] ?? null);
    $customerName = textValue($data['name'] ?? null);
    $customerPhone = textValue($data['phone'] ?? null);
    $rawQuantity = $data['quantity'] ?? 1;
    // Старые открытые вкладки ещё могут отправить форму без поля оплаты.
    // Такая заявка не теряется, а честно помечается в админке как «Не указан».
    $paymentMethod = textValue($data['payment_method'] ?? 'not_selected');

    if ($product === '' || mb_strlen($product) > 100) {
        return [null, 'Выберите товар из каталога.'];
    }

    if (mb_strlen($customerName) < 2 || mb_strlen($customerName) > 100) {
        return [null, 'Введите имя длиной от 2 до 100 символов.'];
    }

    if (!preg_match('/^[0-9+() -]{7,30}$/', $customerPhone)) {
        return [null, 'Введите корректный номер телефона.'];
    }

    // Не принимаем дроби, отрицательные числа и true/false как количество.
    $quantityIsValid = is_int($rawQuantity)
        || (is_string($rawQuantity) && preg_match('/^\d+$/', $rawQuantity));

    if (!$quantityIsValid) {
        return [null, 'Укажите количество товара целым числом.'];
    }

    $quantity = (int) $rawQuantity;

    if ($quantity < 1 || $quantity > 10) {
        return [null, 'За одну заявку можно указать от 1 до 10 товаров.'];
    }

    // Браузерный код можно подменить, поэтому способ оплаты проверяет и PHP.
    if (!in_array($paymentMethod, PAYMENT_METHODS, true) && $paymentMethod !== 'not_selected') {
        return [null, 'Выберите доступный способ оплаты.'];
    }

    return [[
        'product' => $product,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'quantity' => $quantity,
        'payment_method' => $paymentMethod,
    ], null];
}

/** Проверяет, есть ли у текущего браузера сессия администратора. */
function hasAdminAccess(): bool
{
    return ($_SESSION['is_admin'] ?? false) === true;
}

/** Возвращает хеш пароля: на хостинге будет использоваться переменная окружения. */
function adminPasswordHash(): string
{
    $environmentHash = getenv('CUECRAFT_ADMIN_PASSWORD_HASH');

    return is_string($environmentHash) && $environmentHash !== ''
        ? $environmentHash
        : DEFAULT_ADMIN_PASSWORD_HASH;
}

/** Сравнивает хеши через hash_equals, чтобы сравнение не зависело от длины совпадения. */
function isCorrectAdminPassword(string $password): bool
{
    return hash_equals(adminPasswordHash(), hash('sha256', $password));
}

/** Возвращает IP-адрес браузера для отдельного счётчика неверных входов. */
function clientAddress(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 128);
}

/** Узнаёт, сколько секунд ещё действует блокировка входа. */
function lockoutSeconds(string $address): int
{
    $connection = database();
    $statement = $connection->prepare(
        'SELECT failed_attempts, locked_until FROM admin_login_attempts WHERE client_address = ?'
    );
    $statement->execute([$address]);
    $attempt = $statement->fetch();

    if ($attempt === false) {
        return 0;
    }

    $lockedUntil = (int) $attempt['locked_until'];
    $now = time();

    if ($lockedUntil > $now) {
        return $lockedUntil - $now;
    }

    // Закончившаяся блокировка не должна мешать новой серии попыток.
    if ($lockedUntil !== 0) {
        $connection->prepare('DELETE FROM admin_login_attempts WHERE client_address = ?')->execute([$address]);
    }

    return 0;
}

/** Записывает неудачный вход и возвращает остаток попыток либо время блокировки. */
function registerFailedLogin(string $address): array
{
    $connection = database();
    $findAttempt = $connection->prepare('SELECT failed_attempts FROM admin_login_attempts WHERE client_address = ?');
    $findAttempt->execute([$address]);
    $attempt = $findAttempt->fetch();
    $failedAttempts = ($attempt === false ? 0 : (int) $attempt['failed_attempts']) + 1;

    if ($failedAttempts >= MAX_LOGIN_ATTEMPTS) {
        $lockedUntil = time() + LOCKOUT_SECONDS;
        $attemptsLeft = 0;
        $retryAfter = LOCKOUT_SECONDS;
    } else {
        $lockedUntil = 0;
        $attemptsLeft = MAX_LOGIN_ATTEMPTS - $failedAttempts;
        $retryAfter = 0;
    }

    $saveAttempt = $connection->prepare(
        'INSERT INTO admin_login_attempts (client_address, failed_attempts, locked_until)
         VALUES (?, ?, ?)
         ON CONFLICT(client_address) DO UPDATE SET
             failed_attempts = excluded.failed_attempts,
             locked_until = excluded.locked_until'
    );
    $saveAttempt->execute([$address, $failedAttempts, $lockedUntil]);

    return [$attemptsLeft, $retryAfter];
}

/** После удачного входа счётчик неверных паролей больше не нужен. */
function clearFailedLogins(string $address): void
{
    database()->prepare('DELETE FROM admin_login_attempts WHERE client_address = ?')->execute([$address]);
}

/** Неавторизованный запрос к админским API останавливаем до чтения личных данных. */
function requireAdmin(): void
{
    if (!hasAdminAccess()) {
        jsonResponse(['ok' => false, 'error' => 'Нужен вход в админ-панель.'], 401);
    }
}

// Создаём или обновляем таблицы до обработки каждого запроса.
initializeDatabase();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentBasePath = basePath();
$baseWithoutTrailingSlash = rtrim($currentBasePath, '/');

// Убираем /billiard-cues из URL, чтобы маршруты ниже всегда видели /api/... или /admin.
if (
    $currentBasePath !== '/'
    && ($requestPath === $baseWithoutTrailingSlash || str_starts_with($requestPath, $currentBasePath))
) {
    $path = substr($requestPath, strlen($baseWithoutTrailingSlash));
} else {
    $path = $requestPath;
}

$path = $path === '' || $path === '/' ? '/' : rtrim($path, '/');

// Встроенный PHP-сервер должен самостоятельно отдавать CSS, JavaScript и изображения.
if (PHP_SAPI === 'cli-server' && $method === 'GET' && str_starts_with($path, '/static/') && is_file(__DIR__ . $path)) {
    return false;
}

// ── Страницы сайта ─────────────────────────────────────────────────────────
handlePaymentRoutes($method, $path);
handleAdvisorRoutes($method, $path);

if ($method === 'GET' && $path === '/') {
    servePage('index.html');
}

if ($method === 'GET' && $path === '/admin') {
    if (!hasAdminAccess()) {
        header('Location: ' . appUrl('admin/login'), true, 302);
        exit;
    }

    servePage('admin.html');
}

if ($method === 'GET' && $path === '/admin/login') {
    if (hasAdminAccess()) {
        header('Location: ' . appUrl('admin'), true, 302);
        exit;
    }

    servePage('admin-login.html');
}

// ── Публичный API каталога и заявки ─────────────────────────────────────────

if ($method === 'POST' && $path === '/api/orders') {
    [$order, $error] = validateOrder(readJsonBody());

    if ($error !== null) {
        jsonResponse(['ok' => false, 'error' => $error], 400);
    }

    $connection = database();
    // Цена фиксируется сервером: значения суммы из браузера игнорируются.
    $findProduct = $connection->prepare('SELECT * FROM products WHERE name = ?');
    $findProduct->execute([$order['product']]);
    $product = $findProduct->fetch();
    if (!$product || (int) $product['price_uah'] < 1) {
        jsonResponse(['ok' => false, 'error' => 'Выберите доступный товар из каталога.'], 400);
    }
    $amountMinor = (int) $product['price_uah'] * 100 * $order['quantity'];
    $isDemo = $order['payment_method'] === 'online_demo';
    if ($isDemo && !demoPaymentsEnabled()) {
        jsonResponse(['ok' => false, 'error' => 'Демонстрационная оплата отключена.'], 409);
    }
    $addOrder = $connection->prepare(
        'INSERT INTO orders (product, customer_name, customer_phone, quantity, payment_method, product_slug, unit_amount_minor, amount_minor, payment_status, payment_provider) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $addOrder->execute([
        $order['product'],
        $order['customer_name'],
        $order['customer_phone'],
        $order['quantity'],
        $order['payment_method'],
        $product['slug'], (int) $product['price_uah'] * 100, $amountMinor,
        $isDemo ? 'pending' : 'unpaid', $isDemo ? 'demo' : 'manual',
    ]);

    $orderId = (int) $connection->lastInsertId();
    // Доступ к странице оплаты принадлежит только браузеру, создавшему заказ.
    if ($isDemo || $order['payment_method'] === 'card_transfer') {
        $_SESSION['payment_orders'][$orderId] = bin2hex(random_bytes(24));
    }
    jsonResponse(['ok' => true, 'order_id' => $orderId, 'amount_minor' => $amountMinor,
        'checkout_url' => $isDemo ? paymentProvider()->checkoutUrl($orderId)
            : ($order['payment_method'] === 'card_transfer' ? appUrl('checkout/' . $orderId) : null)], 201);
}

if ($method === 'GET' && $path === '/api/products') {
    $products = database()->query(
        'SELECT slug, name, availability, stock_quantity, price_uah FROM products ORDER BY rowid'
    )->fetchAll();

    jsonResponse($products);
}

// ── Защищённый API заявок ───────────────────────────────────────────────────

if ($method === 'GET' && $path === '/api/orders') {
    requireAdmin();
    $orders = database()->query(
        'SELECT id, product, customer_name, customer_phone, quantity, payment_method, payment_status, payment_provider, amount_minor, status, created_at
         FROM orders ORDER BY id DESC'
    )->fetchAll();

    jsonResponse($orders);
}

if ($method === 'PATCH' && preg_match('#^/api/orders/(\d+)/status$#', $path, $matches)) {
    requireAdmin();
    $data = readJsonBody();
    $status = $data['status'] ?? null;

    if (!is_string($status) || !in_array($status, ORDER_STATUSES, true)) {
        jsonResponse(['ok' => false, 'error' => 'Неизвестный статус заявки.'], 400);
    }

    $orderId = (int) $matches[1];
    $connection = database();
    $connection->beginTransaction();

    try {
        $findOrder = $connection->prepare(
            'SELECT product, quantity, inventory_applied FROM orders WHERE id = ?'
        );
        $findOrder->execute([$orderId]);
        $order = $findOrder->fetch();

        if ($order === false) {
            $connection->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Заявка не найдена.'], 404);
        }

        $quantity = (int) $order['quantity'];
        $inventoryApplied = (bool) $order['inventory_applied'];

        // Закрытие заявки уменьшает склад только один раз.
        if ($status === 'closed' && !$inventoryApplied) {
            $decreaseStock = $connection->prepare(
                "UPDATE products
                 SET stock_quantity = stock_quantity - ?,
                     availability = CASE
                         WHEN stock_quantity - ? = 0 THEN 'out_of_stock'
                         ELSE availability
                     END
                 WHERE name = ? AND stock_quantity >= ?"
            );
            $decreaseStock->execute([$quantity, $quantity, $order['product'], $quantity]);

            if ($decreaseStock->rowCount() === 0) {
                $connection->rollBack();
                jsonResponse([
                    'ok' => false,
                    'error' => 'Недостаточно товара на складе. Измени остаток в админ-панели.',
                ], 409);
            }

            $inventoryApplied = true;
        }

        // Возврат заявки в работу возвращает ранее списанный товар на склад.
        if ($status !== 'closed' && $inventoryApplied) {
            $restoreStock = $connection->prepare(
                "UPDATE products
                 SET stock_quantity = stock_quantity + ?,
                     availability = CASE
                         WHEN availability = 'out_of_stock' THEN 'in_stock'
                         ELSE availability
                     END
                 WHERE name = ?"
            );
            $restoreStock->execute([$quantity, $order['product']]);
            $inventoryApplied = false;
        }

        $saveStatus = $connection->prepare(
            'UPDATE orders SET status = ?, inventory_applied = ? WHERE id = ?'
        );
        $saveStatus->execute([$status, (int) $inventoryApplied, $orderId]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        jsonResponse(['ok' => false, 'error' => 'Не удалось сохранить статус заявки.'], 500);
    }

    jsonResponse(['ok' => true, 'status' => $status]);
}

// ── Защищённый API управления товарами ──────────────────────────────────────

if ($method === 'PATCH' && preg_match('#^/api/admin/products/([a-z0-9-]+)/availability$#', $path, $matches)) {
    requireAdmin();
    $data = readJsonBody();
    $availability = $data['availability'] ?? null;

    if (!is_string($availability) || !in_array($availability, PRODUCT_AVAILABILITIES, true)) {
        jsonResponse(['ok' => false, 'error' => 'Неизвестное наличие товара.'], 400);
    }

    $updateAvailability = database()->prepare('UPDATE products SET availability = ? WHERE slug = ?');
    $updateAvailability->execute([$availability, $matches[1]]);

    if ($updateAvailability->rowCount() === 0) {
        jsonResponse(['ok' => false, 'error' => 'Товар не найден.'], 404);
    }

    jsonResponse(['ok' => true, 'availability' => $availability]);
}

if ($method === 'PATCH' && preg_match('#^/api/admin/products/([a-z0-9-]+)/stock$#', $path, $matches)) {
    requireAdmin();
    $data = readJsonBody();
    $rawQuantity = $data['stock_quantity'] ?? null;
    $quantityIsValid = is_int($rawQuantity)
        || (is_string($rawQuantity) && preg_match('/^\d+$/', $rawQuantity));

    if (!$quantityIsValid) {
        jsonResponse(['ok' => false, 'error' => 'Остаток должен быть целым числом.'], 400);
    }

    $stockQuantity = (int) $rawQuantity;

    if ($stockQuantity < 0 || $stockQuantity > 999) {
        jsonResponse(['ok' => false, 'error' => 'Укажите остаток от 0 до 999.'], 400);
    }

    // Ноль делает товар недоступным, а пополнение возвращает «В наличии».
    $updateStock = database()->prepare(
        "UPDATE products
         SET stock_quantity = ?,
             availability = CASE
                 WHEN ? = 0 THEN 'out_of_stock'
                 WHEN availability = 'out_of_stock' THEN 'in_stock'
                 ELSE availability
             END
         WHERE slug = ?"
    );
    $updateStock->execute([$stockQuantity, $stockQuantity, $matches[1]]);

    if ($updateStock->rowCount() === 0) {
        jsonResponse(['ok' => false, 'error' => 'Товар не найден.'], 404);
    }

    jsonResponse(['ok' => true, 'stock_quantity' => $stockQuantity]);
}

// Цена меняется только после входа в админ-панель и только целым числом гривен.
if ($method === 'PATCH' && preg_match('#^/api/admin/products/([a-z0-9-]+)/price$#', $path, $matches)) {
    requireAdmin();
    $data = readJsonBody();
    $rawPrice = $data['price_uah'] ?? null;
    $priceIsValid = is_int($rawPrice)
        || (is_string($rawPrice) && preg_match('/^\d+$/', $rawPrice));

    if (!$priceIsValid) {
        jsonResponse(['ok' => false, 'error' => 'Цена должна быть целым числом гривен.'], 400);
    }

    $priceUah = (int) $rawPrice;

    if ($priceUah < 1 || $priceUah > 999999) {
        jsonResponse(['ok' => false, 'error' => 'Укажите цену от 1 до 999 999 ₴.'], 400);
    }

    $updatePrice = database()->prepare('UPDATE products SET price_uah = ? WHERE slug = ?');
    $updatePrice->execute([$priceUah, $matches[1]]);

    if ($updatePrice->rowCount() === 0) {
        jsonResponse(['ok' => false, 'error' => 'Товар не найден.'], 404);
    }

    jsonResponse(['ok' => true, 'price_uah' => $priceUah]);
}

// ── Вход и выход из админ-панели ────────────────────────────────────────────

if ($method === 'POST' && $path === '/api/admin/login') {
    $data = readJsonBody();
    $password = $data['password'] ?? '';
    $address = clientAddress();
    $retryAfter = lockoutSeconds($address);

    if ($retryAfter > 0) {
        jsonResponse([
            'ok' => false,
            'error' => 'Слишком много попыток. Попробуйте позже.',
            'retry_after' => $retryAfter,
        ], 429);
    }

    if (!is_string($password) || !isCorrectAdminPassword($password)) {
        [$attemptsLeft, $retryAfter] = registerFailedLogin($address);

        if ($retryAfter > 0) {
            jsonResponse([
                'ok' => false,
                'error' => 'Слишком много попыток. Попробуйте позже.',
                'retry_after' => $retryAfter,
            ], 429);
        }

        jsonResponse([
            'ok' => false,
            'error' => 'Неверный пароль.',
            'attempts_left' => $attemptsLeft,
        ], 401);
    }

    clearFailedLogins($address);
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    jsonResponse(['ok' => true]);
}

if ($method === 'GET' && $path === '/api/admin/login-status') {
    $retryAfter = lockoutSeconds(clientAddress());
    jsonResponse(['locked' => $retryAfter > 0, 'retry_after' => $retryAfter]);
}

if ($method === 'POST' && $path === '/api/admin/logout') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['ok' => true]);
}

// Неизвестный адрес не должен случайно отдать страницу с данными.
jsonResponse(['ok' => false, 'error' => 'Страница не найдена.'], 404);
