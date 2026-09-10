<?php
declare(strict_types=1);

// Локальные настройки не публикуются; окружение сервера имеет приоритет.
$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
foreach ($localConfig as $name => $value) {
    if (is_string($name) && str_starts_with($name, 'CUECRAFT_') && getenv($name) === false && is_string($value)) {
        putenv($name . '=' . $value);
    }
}
