<?php
declare(strict_types=1);

// Граница интеграции: будущий адаптер банка создаст свою защищённую страницу оплаты.
interface PaymentProvider
{
    public function checkoutUrl(int $orderId): string;
}

final class DemoPaymentProvider implements PaymentProvider
{
    public function checkoutUrl(int $orderId): string
    {
        return appUrl('checkout/' . $orderId);
    }
}

function demoPaymentsEnabled(): bool
{
    return (getenv('CUECRAFT_PAYMENT_PROVIDER') ?: 'demo') === 'demo';
}

function paymentProvider(): PaymentProvider
{
    // Не подменяем неизвестный реальный сервис демонстрацией.
    if (!demoPaymentsEnabled()) {
        throw new RuntimeException('Payment provider is not configured.');
    }
    return new DemoPaymentProvider();
}

function initializePayments(PDO $db, array $columns): void
{
    // Суммы в копейках; NULL у старых заявок означает, что цена тогда не фиксировалась.
    foreach ([
        'product_slug' => 'TEXT', 'unit_amount_minor' => 'INTEGER', 'amount_minor' => 'INTEGER',
        'currency' => "TEXT NOT NULL DEFAULT 'UAH'",
        'payment_status' => "TEXT NOT NULL DEFAULT 'unpaid'",
        'payment_provider' => "TEXT NOT NULL DEFAULT 'manual'", 'paid_at' => 'TEXT',
    ] as $name => $definition) {
        if (!in_array($name, $columns, true)) {
            $db->exec("ALTER TABLE orders ADD COLUMN $name $definition");
        }
    }
    $db->exec('CREATE TABLE IF NOT EXISTS payment_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL,
        provider TEXT NOT NULL, outcome TEXT NOT NULL, amount_minor INTEGER NOT NULL,
        currency TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
}

function paymentEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function handlePaymentRoutes(string $method, string $path): void
{
    if (!preg_match('#^/checkout/(\d+)$#', $path, $matches)) {
        return;
    }
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $id = (int) $matches[1];
    $token = $_SESSION['payment_orders'][$id] ?? null;
    if (!is_string($token)) {
        jsonResponse(['ok' => false, 'error' => 'Страница оплаты недоступна в этом браузере.'], 403);
    }
    $db = database();
    $find = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $find->execute([$id]);
    $order = $find->fetch();
    if (!$order) {
        jsonResponse(['ok' => false, 'error' => 'Заказ не найден.'], 404);
    }
    $isTransfer = $order['payment_method'] === 'card_transfer';
    if (!$isTransfer && ($order['payment_provider'] !== 'demo' || !demoPaymentsEnabled())) {
        jsonResponse(['ok' => false, 'error' => 'Способ оплаты недоступен.'], 403);
    }
    // Ручной перевод не подтверждается нажатием кнопки в браузере.
    if ($isTransfer && $method !== 'GET') {
        jsonResponse(['ok' => false, 'error' => 'Поступление перевода проверяет продавец.'], 405);
    }
    if ($method === 'POST') {
        // CSRF-токен привязан к заказу и сессии покупателя.
        if (!is_string($_POST['token'] ?? null) || !hash_equals($token, $_POST['token'])) {
            jsonResponse(['ok' => false, 'error' => 'Обновите страницу оплаты.'], 403);
        }
        $outcome = $_POST['outcome'] ?? '';
        if (!is_string($outcome) || !in_array($outcome, ['demo_paid', 'failed', 'cancelled'], true)) {
            jsonResponse(['ok' => false, 'error' => 'Неизвестный результат оплаты.'], 400);
        }
        // Блокировка и условное обновление защищают успешный результат от повторов.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $update = $db->prepare("UPDATE orders SET payment_status = ?,
                paid_at = CASE WHEN ? = 'demo_paid' THEN CURRENT_TIMESTAMP ELSE NULL END
                WHERE id = ? AND payment_provider = 'demo' AND payment_status <> 'demo_paid'");
            $update->execute([$outcome, $outcome, $id]);
            if ($update->rowCount() > 0) {
                $db->prepare('INSERT INTO payment_attempts (order_id, provider, outcome, amount_minor, currency) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, 'demo', $outcome, $order['amount_minor'], $order['currency']]);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            jsonResponse(['ok' => false, 'error' => 'Не удалось сохранить результат. Попробуйте ещё раз.'], 500);
        }
        // После POST обновление страницы не отправляет форму повторно.
        header('Location: ' . appUrl('checkout/' . $id), true, 303);
        exit;
    }
    if ($method !== 'GET') {
        jsonResponse(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
    }
    $paid = $order['payment_status'] === 'demo_paid';
    $title = $paid ? 'Оплачено — демо' : 'Демонстрационная оплата';
    $message = match ($order['payment_status']) {
        'demo_paid' => 'Оплата успешно смоделирована. Деньги не списывались.',
        'failed' => 'Платёж отклонён в демонстрации. Можно повторить попытку.',
        'cancelled' => 'Оплата отменена. Заказ сохранён; можно попробовать снова.',
        default => 'Проект для портфолио. Карта и банковские реквизиты не нужны.',
    };
    $amount = number_format((int) $order['amount_minor'] / 100, 2, ',', ' ');
    $product = paymentEscape($order['product']);
    $home = paymentEscape(appUrl());
    $action = paymentEscape(appUrl('checkout/' . $id));
    $stamp = $paid ? '<div class="stamp">✓ ОПЛАЧЕНО · ДЕМО</div><p>Подтверждение DEMO-' . $id . '</p><p>Не является банковской квитанцией или фискальным чеком.</p>' : '';
    $controls = $paid ? '' : '<form method="post" action="' . $action . '"><input type="hidden" name="token" value="' . paymentEscape($token) . '"><button name="outcome" value="demo_paid">Оплатить ' . $amount . ' ₴ — демо</button><div class="secondary"><button name="outcome" value="failed">Проверить отказ</button><button name="outcome" value="cancelled">Отменить оплату</button></div></form>';
    $notice = 'ДЕМОНСТРАЦИЯ · БЕЗ СПИСАНИЯ ДЕНЕГ';
    if ($isTransfer) {
        $title = '▣ Перевод по реквизитам';
        $notice = 'РЕКВИЗИТЫ ПОЛУЧАТЕЛЯ';
        $message = 'Заказ сохранён. Перевод выполняется в приложении вашего банка по IBAN. Поступление проверяет продавец.';
        // Только реквизиты зачисления. Данные безопасности карты нигде не хранятся.
        $iban = paymentEscape(getenv('CUECRAFT_TRANSFER_IBAN') ?: 'Реквизиты пока не настроены');
        $recipient = paymentEscape(getenv('CUECRAFT_TRANSFER_RECIPIENT') ?: 'Уточните полное имя получателя у продавца перед переводом.');
        $copyScript = paymentEscape(appUrl('static/js/transfer.js?v=1'));
        $controls = '<p>Получатель: ' . $recipient . '</p><label for="transfer-iban">IBAN</label><input id="transfer-iban" readonly value="' . $iban . '" style="width:100%;padding:14px;margin:12px 0;font:16px monospace"><button type="button" id="copy-iban">Скопировать IBAN</button><p id="copy-status" role="status"></p><p>Назначение: оплата заказа №' . $id . '</p><p>Сумма: ' . $amount . ' ₴. Возможную комиссию покажет ваш банк.</p><p>Сайт создан для портфолио, но эти реквизиты реальные. Для проверки проекта деньги не переводите.</p><script src="' . $copyScript . '" defer></script>';
    }
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>$title — CUECRAFT</title>
<style>
/* Самостоятельная страница: работает даже без JavaScript и внешних шрифтов. */
*{box-sizing:border-box}body{margin:0;padding:32px 18px;background:radial-gradient(ellipse at top right,#382719,#10100e 65%);color:#f8f2e7;font:16px/1.6 system-ui,sans-serif;min-height:100vh}main{max-width:620px;margin:32px auto;background:#1d1c18;padding:clamp(22px,5vw,42px);border:1px solid #655032;border-radius:28px}h1{font:clamp(28px,6vw,38px)/1.2 Georgia,serif}.brand,a{color:#dbb76f}.notice{color:#dbcbaa}.summary{padding:18px 0;border-block:1px solid #514738;margin:24px 0}.amount{font-size:28px;font-weight:700}.stamp{color:#8de0ab;border:3px solid currentColor;border-radius:12px;padding:14px;text-align:center;font-weight:800;letter-spacing:1px;margin:24px 0}button{width:100%;border:0;border-radius:14px;padding:16px;background:#c89f52;color:#16120b;font:600 16px system-ui;cursor:pointer;min-height:50px}button:hover{filter:brightness(1.1)}button:focus-visible,a:focus-visible{outline:3px solid #fff;outline-offset:4px}.secondary{display:flex;gap:12px;margin:12px 0 24px}.secondary button{background:#353128;color:#f8f2e7}p{overflow-wrap:anywhere}@media(max-width:420px){.secondary{flex-direction:column}main{margin:0 auto}}
</style></head><body><main><a class="brand" href="$home">CUECRAFT</a><p class="notice">$notice</p><h1>$title</h1><p role="status">$message</p><div class="summary"><p>Заказ №$id · $product</p><p>Количество: {$order['quantity']} шт.</p><div class="amount">$amount ₴</div></div>$stamp$controls<a href="$home">Вернуться в каталог</a></main></body></html>
HTML;
    exit;
}
