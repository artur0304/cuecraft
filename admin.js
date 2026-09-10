// Находим элементы, куда будут выводиться данные из базы.
const ordersBody = document.querySelector("#orders-body");
const ordersCount = document.querySelector("#orders-count");
const ordersStatus = document.querySelector("#orders-status");
const refreshButton = document.querySelector("#refresh-orders");
const logoutButton = document.querySelector("#logout-button");
const productsBody = document.querySelector("#products-body");
const productsStatus = document.querySelector("#products-status");
const refreshProductsButton = document.querySelector("#refresh-products");
// Один и тот же JavaScript работает на домене и в подпапке localhost.
const apiBasePath = document.querySelector("base").getAttribute("href");

// Читаемые названия для технических статусов, сохранённых в базе.
const statusNames = {
    new: "Новая",
    working: "В работе",
    closed: "Закрыта",
};

// Те же технические значения использует таблица products в SQLite.
const availabilityNames = {
    in_stock: "В наличии",
    preorder: "Под заказ",
    out_of_stock: "Нет в наличии",
};

// Понятные клиентские подписи для технических значений, сохранённых в SQLite.
const paymentMethodNames = {
    cash_on_delivery: "При получении",
    card_transfer: "Перевод на карту",
    not_selected: "Не указан",
};

// Превращает дату SQLite в привычный украинский формат.
function formatDate(dateText) {
    const date = new Date(`${dateText.replace(" ", "T")}Z`);

    return new Intl.DateTimeFormat("uk-UA", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(date);
}

// Безопасно создаёт одну ячейку таблицы из обычного текста.
function createCell(text) {
    const cell = document.createElement("td");
    cell.textContent = text;
    return cell;
}

// Создаёт выпадающий список, через который меняется этап заявки.
function createStatusSelector(order) {
    const cell = document.createElement("td");
    const select = document.createElement("select");
    select.classList.add("status-select");

    Object.entries(statusNames).forEach(function ([value, label]) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        option.selected = value === order.status;
        select.append(option);
    });

    // Сразу сохраняем выбранный этап в SQLite.
    select.addEventListener("change", async function () {
        select.disabled = true;
        ordersStatus.classList.remove("error");
        ordersStatus.textContent = "Сохраняем статус…";

        try {
            const response = await fetch(`${apiBasePath}api/orders/${order.id}/status`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ status: select.value }),
            });

            if (response.status === 401) {
                window.location.assign(`${apiBasePath}admin/login`);
                return;
            }

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || "Статус не сохранился.");
            }

            await loadOrders();
        } catch (error) {
            // Сначала возвращаем селектор к реальному значению из базы.
            await loadOrders();
            ordersStatus.classList.add("error");
            ordersStatus.textContent = error.message || "Не удалось сохранить статус. Попробуй ещё раз.";
        }
    });

    cell.append(select);
    return cell;
}

// Создаёт селектор наличия и сразу сохраняет изменение в базе данных.
function createAvailabilitySelector(product) {
    const cell = document.createElement("td");
    const select = document.createElement("select");
    select.classList.add("status-select");

    Object.entries(availabilityNames).forEach(function ([value, label]) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        option.selected = value === product.availability;
        select.append(option);
    });

    select.addEventListener("change", async function () {
        select.disabled = true;
        productsStatus.classList.remove("error");
        productsStatus.textContent = "Сохраняем наличие…";

        try {
            const response = await fetch(
                `${apiBasePath}api/admin/products/${product.slug}/availability`,
                {
                    method: "PATCH",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ availability: select.value }),
                },
            );

            if (response.status === 401) {
                window.location.assign(`${apiBasePath}admin/login`);
                return;
            }

            if (!response.ok) {
                throw new Error("Наличие не сохранилось.");
            }

            await loadProducts();
        } catch (error) {
            productsStatus.classList.add("error");
            productsStatus.textContent = "Не удалось сохранить наличие. Попробуй ещё раз.";
            await loadProducts();
        }
    });

    cell.append(select);
    return cell;
}

// Создаёт поле, в котором администратор задаёт реальный остаток на складе.
function createStockInput(product) {
    const cell = document.createElement("td");
    const input = document.createElement("input");
    input.classList.add("stock-input");
    input.type = "number";
    input.min = "0";
    input.max = "999";
    input.value = product.stock_quantity;

    // Изменение сохраняется после Enter или когда пользователь уходит из поля.
    input.addEventListener("change", async function () {
        const stockQuantity = input.valueAsNumber;

        if (!Number.isInteger(stockQuantity) || stockQuantity < 0 || stockQuantity > 999) {
            productsStatus.classList.add("error");
            productsStatus.textContent = "Остаток должен быть целым числом от 0 до 999.";
            input.value = product.stock_quantity;
            return;
        }

        input.disabled = true;
        productsStatus.classList.remove("error");
        productsStatus.textContent = "Сохраняем остаток…";

        try {
            const response = await fetch(`${apiBasePath}api/admin/products/${product.slug}/stock`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ stock_quantity: stockQuantity }),
            });

            if (response.status === 401) {
                window.location.assign(`${apiBasePath}admin/login`);
                return;
            }

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || "Остаток не сохранился.");
            }

            await loadProducts();
        } catch (error) {
            await loadProducts();
            productsStatus.classList.add("error");
            productsStatus.textContent = error.message || "Не удалось сохранить остаток. Попробуй ещё раз.";
        }
    });

    cell.append(input);
    return cell;
}

// Создаёт поле цены. Храним и вводим её целыми гривнами, без дробных копеек.
function createPriceInput(product) {
    const cell = document.createElement("td");
    const input = document.createElement("input");
    input.classList.add("price-input");
    input.type = "number";
    input.min = "1";
    input.max = "999999";
    input.step = "1";
    input.value = product.price_uah;
    input.setAttribute("aria-label", `Цена ${product.name} в гривнах`);

    // Когда поле изменили и нажали Enter или ушли из него, цена сохраняется в SQLite.
    input.addEventListener("change", async function () {
        const priceUah = input.valueAsNumber;

        if (!Number.isInteger(priceUah) || priceUah < 1 || priceUah > 999999) {
            productsStatus.classList.add("error");
            productsStatus.textContent = "Цена должна быть целым числом от 1 до 999 999 ₴.";
            input.value = product.price_uah;
            return;
        }

        input.disabled = true;
        productsStatus.classList.remove("error");
        productsStatus.textContent = "Сохраняем цену…";

        try {
            const response = await fetch(`${apiBasePath}api/admin/products/${product.slug}/price`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ price_uah: priceUah }),
            });

            if (response.status === 401) {
                window.location.assign(`${apiBasePath}admin/login`);
                return;
            }

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || "Цена не сохранилась.");
            }

            await loadProducts();
        } catch (error) {
            await loadProducts();
            productsStatus.classList.add("error");
            productsStatus.textContent = error.message || "Не удалось сохранить цену. Попробуй ещё раз.";
        }
    });

    cell.append(input);
    return cell;
}

// Загружает товары для управления ценой, наличием и остатком в админ-панели.
async function loadProducts() {
    productsStatus.textContent = "Загружаем товары…";
    productsStatus.classList.remove("error");
    refreshProductsButton.disabled = true;

    try {
        const response = await fetch(`${apiBasePath}api/products`);

        if (!response.ok) {
            throw new Error("Сервер не смог отдать товары.");
        }

        const products = await response.json();
        productsBody.replaceChildren();

        products.forEach(function (product) {
            const row = document.createElement("tr");
            row.append(
                createCell(product.name),
                createPriceInput(product),
                createAvailabilitySelector(product),
                createStockInput(product),
            );
            productsBody.append(row);
        });

        productsStatus.textContent = `Товаров в каталоге: ${products.length}.`;
    } catch (error) {
        productsStatus.textContent = "Не удалось загрузить товары. Проверь, что сервер запущен.";
        productsStatus.classList.add("error");
    } finally {
        refreshProductsButton.disabled = false;
    }
}

// Получает заявки от PHP и рисует таблицу.
async function loadOrders() {
    ordersStatus.textContent = "Загружаем заявки…";
    ordersStatus.classList.remove("error");
    refreshButton.disabled = true;

    try {
        const response = await fetch(`${apiBasePath}api/orders`);

        // Сессия могла закончиться после перезапуска сервера.
        if (response.status === 401) {
            window.location.assign(`${apiBasePath}admin/login`);
            return;
        }

        if (!response.ok) {
            throw new Error("Сервер не смог отдать заявки.");
        }

        const orders = await response.json();
        ordersBody.replaceChildren();
        ordersCount.textContent = orders.length;

        // Если заявок ещё нет, показываем понятное сообщение.
        if (orders.length === 0) {
            const row = document.createElement("tr");
            const cell = createCell("Заявок пока нет. Отправь тестовую заявку с сайта.");
            cell.colSpan = 8;
            cell.classList.add("empty-cell");
            row.append(cell);
            ordersBody.append(row);
            ordersStatus.textContent = "Новых заявок пока нет.";
            return;
        }

        // Для каждой заявки создаём отдельную строку таблицы.
        orders.forEach(function (order) {
            const row = document.createElement("tr");
            row.append(
                createCell(order.id),
                createCell(order.product),
                createCell(order.customer_name),
                createCell(order.customer_phone),
                createCell(`${order.quantity} шт.`),
                createCell(order.payment_method === 'online_demo'
                    ? `Демо: ${{pending: 'ожидает оплаты', demo_paid: 'Оплачено — демо', failed: 'отказ', cancelled: 'отмена'}[order.payment_status] || order.payment_status} · ${(order.amount_minor / 100).toLocaleString('uk-UA')} ₴`
                    : (paymentMethodNames[order.payment_method] || "Не указан") + (order.amount_minor == null ? '' : ` · ${(order.amount_minor / 100).toLocaleString('uk-UA')} ₴ · не оплачено`)),
                createStatusSelector(order),
                createCell(formatDate(order.created_at)),
            );
            ordersBody.append(row);
        });

        ordersStatus.textContent = `Показано заявок: ${orders.length}.`;
    } catch (error) {
        ordersStatus.textContent = "Не удалось загрузить заявки. Проверь, что сервер запущен.";
        ordersStatus.classList.add("error");
    } finally {
        refreshButton.disabled = false;
    }
}

// Кнопка позволяет увидеть новую заявку, не обновляя страницу вручную.
refreshButton.addEventListener("click", loadOrders);
refreshProductsButton.addEventListener("click", loadProducts);

// Выход удаляет сессию, но не удаляет сохранённые заявки из базы.
logoutButton.addEventListener("click", async function () {
    await fetch(`${apiBasePath}api/admin/logout`, { method: "POST" });
    window.location.assign(`${apiBasePath}admin/login`);
});

// Загружаем данные сразу после открытия страницы.
loadOrders();
loadProducts();
