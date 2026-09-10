<?php
// Скопируйте в config.local.php. Пустой хеш отключает вход администратора.
return [
    'CUECRAFT_ADMIN_PASSWORD_HASH' => '',
    'CUECRAFT_PAYMENT_PROVIDER' => 'demo',
    'CUECRAFT_TRANSFER_IBAN' => '',
    'CUECRAFT_TRANSFER_RECIPIENT' => '',
    // Имя модели, предварительно установленной в локальной Ollama.
    'CUECRAFT_OLLAMA_MODEL' => '',
];
