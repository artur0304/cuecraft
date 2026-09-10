<?php
declare(strict_types=1);

/** Помощник читает только публичный каталог: заявки и реквизиты ему недоступны. */
function handleAdvisorRoutes(string $method, string $path): void
{
    if ($path !== '/api/advisor' || $method !== 'POST') return;
    header('Cache-Control: no-store');
    $data = readJsonBody();
    $message = textValue($data['message'] ?? '');
    if ($message === '' || mb_strlen($message) > 500) {
        jsonResponse(['error' => 'Напишите вопрос длиной до 500 символов.'], 400);
    }
    // Ограничиваем частоту и объём истории в одной сессии.
    if (time() - ($_SESSION['advisor_last'] ?? 0) < 1) {
        jsonResponse(['error' => 'Подождите секунду перед следующим сообщением.'], 429);
    }
    $_SESSION['advisor_last'] = time();
    if (($data['reset'] ?? false) === true) unset($_SESSION['advisor_preferences'], $_SESSION['advisor_history']);
    $preferences = $_SESSION['advisor_preferences'] ?? [];
    $lower = mb_strtolower($message);
    if (preg_match('/пул|pool|американ/u', $lower)) $preferences['game'] = 'pool';
    elseif (preg_match('/русск|пірамід|пирамид/u', $lower)) $preferences['game'] = 'russian';
    elseif (preg_match('/аксессуар|мел|міл|держател/u', $lower)) $preferences['game'] = 'accessories';
    if (preg_match('/нович|начина|початк/u', $lower)) $preferences['level'] = 'beginner';
    elseif (preg_match('/опыт|досвід|профи|профессион/u', $lower)) $preferences['level'] = 'experienced';
    if (preg_match('/без огранич|не важен|неважен/u', $lower)) $preferences['budget'] = PHP_INT_MAX;
    elseif (preg_match('/(\d[\d ]*)\s*(тыс|тис|к\b|k\b)?/u', $lower, $amount)) {
        $budget = (int) str_replace(' ', '', $amount[1]);
        if (!empty($amount[2])) $budget *= 1000;
        if ($budget > 0) $preferences['budget'] = $budget;
    }
    $_SESSION['advisor_preferences'] = $preferences;
    $products = database()->query('SELECT slug, name, price_uah, availability, stock_quantity FROM products')->fetchAll();
    $suggestions = [];
    $choices = [];
    if (!isset($preferences['game'])) {
        $reply = 'Для какой игры выбираем кий? Или нужны аксессуары?';
        $choices = ['Русский бильярд', 'Пул', 'Аксессуары'];
    } elseif (!isset($preferences['level']) && $preferences['game'] !== 'accessories') {
        $reply = 'Какой у вас опыт игры? Это поможет выбрать подходящую модель.';
        $choices = ['Я новичок', 'Я опытный игрок'];
    } elseif (!isset($preferences['budget'])) {
        $reply = 'Какой максимальный бюджет в гривнах? Например: 6000 или «без ограничений».';
        $choices = ['До 6000', 'До 15000', 'Без ограничений'];
    } else {
        $slugs = match ($preferences['game']) {
            'pool' => ['carbon-strike'], 'accessories' => ['master-chalk', 'magnetic-chalk-holder'],
            default => ($preferences['level'] ?? '') === 'experienced' ? ['walnut-pro', 'classic-maple'] : ['classic-maple', 'walnut-pro'],
        };
        foreach ($slugs as $slug) foreach ($products as $product) {
            if ($product['slug'] === $slug && (int) $product['price_uah'] <= $preferences['budget'] && $product['availability'] !== 'out_of_stock') $suggestions[] = $product;
        }
        $reply = $suggestions ? 'Вот подходящие по игре и бюджету варианты. Актуальные цены и наличие указаны ниже; откройте карточку, чтобы изучить характеристики.' : 'Сейчас в каталоге нет подходящих доступных товаров в этом бюджете. Можно изменить бюджет или вид игры.';
        $choices = ['Без ограничений', 'Русский бильярд', 'Пул', 'Аксессуары'];
    }
    $mode = 'rules';
    // Необязательная настоящая локальная нейросеть. По умолчанию соединений нет.
    $model = getenv('CUECRAFT_OLLAMA_MODEL');
    if (is_string($model) && $model !== '' && function_exists('curl_init')) {
        $history = array_slice($_SESSION['advisor_history'] ?? [], -6);
        $history[] = ['role' => 'user', 'content' => $message];
        $system = 'Ты помощник магазина бильярдных товаров. Отвечай кратко по-русски, только о подборе. Не запрашивай личные или банковские данные. Не принимай оплаты, не обещай доставку. Не выдумывай характеристики. Это весь актуальный каталог: ' . json_encode($products, JSON_UNESCAPED_UNICODE) . '. Выбранные предпочтения: ' . json_encode($preferences, JSON_UNESCAPED_UNICODE) . '. Следующий полезный шаг: ' . $reply;
        $curl = curl_init('http://127.0.0.1:11434/api/chat');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'stream' => false,
                'messages' => array_merge([['role' => 'system', 'content' => $system]], $history),
                'options' => ['num_predict' => 220, 'temperature' => 0.2]])]);
        $answer = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = is_string($answer) ? json_decode($answer, true) : null;
        if ($status === 200 && is_string($decoded['message']['content'] ?? null) && trim($decoded['message']['content']) !== '') {
            $reply = mb_substr($decoded['message']['content'], 0, 2200);
            $mode = 'ai';
            $history[] = ['role' => 'assistant', 'content' => $reply];
            $_SESSION['advisor_history'] = array_slice($history, -6);
        }
    }
    jsonResponse(['reply' => $reply, 'mode' => $mode, 'choices' => $choices, 'products' => $suggestions]);
}
