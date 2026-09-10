// Находим форму и элементы, которые понадобятся для входа.
const loginForm = document.querySelector("#login-form");
const passwordInput = document.querySelector("#admin-password");
const loginStatus = document.querySelector("#login-status");
const loginButton = loginForm.querySelector("button");
let lockoutTimer;
// Базовый путь нужен для запуска как с localhost, так и с отдельного домена .test.
const apiBasePath = document.querySelector("base").getAttribute("href");

// Переводит число секунд в понятный вид: например, 4:09.
function formatRemainingTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secondsPart = String(seconds % 60).padStart(2, "0");
    return `${minutes}:${secondsPart}`;
}

// Блокирует форму и показывает обратный отсчёт до следующей попытки.
function startLockoutCountdown(seconds) {
    window.clearInterval(lockoutTimer);
    let secondsLeft = Math.max(1, Math.ceil(seconds));
    passwordInput.disabled = true;
    loginButton.disabled = true;
    loginStatus.classList.add("error");

    function showRemainingTime() {
        loginStatus.textContent = `Слишком много попыток. Введите пароль через ${formatRemainingTime(secondsLeft)}.`;
    }

    showRemainingTime();

    lockoutTimer = window.setInterval(function () {
        secondsLeft -= 1;

        if (secondsLeft <= 0) {
            window.clearInterval(lockoutTimer);
            passwordInput.disabled = false;
            loginButton.disabled = false;
            loginStatus.classList.remove("error");
            loginStatus.textContent = "Можно попробовать войти снова.";
            passwordInput.focus();
            return;
        }

        showRemainingTime();
    }, 1000);
}

// После обновления страницы узнаём у сервера, не действует ли блокировка.
async function checkExistingLockout() {
    try {
        const response = await fetch(`${apiBasePath}api/admin/login-status`);
        const result = await response.json();

        if (result.locked) {
            startLockoutCountdown(result.retry_after);
        }
    } catch (error) {
        // Если сервер выключен, обычный текст ниже объяснит проблему при входе.
    }
}

// Отправляем пароль серверу, а не сравниваем его в браузере.
loginForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    loginStatus.textContent = "Проверяем пароль…";
    loginStatus.classList.remove("error");
    loginButton.disabled = true;

    try {
        const response = await fetch(`${apiBasePath}api/admin/login`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ password: passwordInput.value }),
        });
        const result = await response.json();

        if (!response.ok) {
            // Сервер сообщает точное время блокировки после серии ошибок.
            if (response.status === 429 && result.retry_after) {
                startLockoutCountdown(result.retry_after);
                return;
            }

            if (result.attempts_left) {
                throw new Error(`${result.error} Осталось попыток: ${result.attempts_left}.`);
            }

            throw new Error(result.error || "Не удалось выполнить вход.");
        }

        // После успешного входа открываем таблицу заявок.
        window.location.assign(`${apiBasePath}admin`);
    } catch (error) {
        loginStatus.textContent = error.message;
        loginStatus.classList.add("error");
        passwordInput.select();
    } finally {
        loginButton.disabled = false;
    }
});

// Запускаем проверку сразу, а не только после очередной неверной попытки.
checkExistingLockout();
