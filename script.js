// ── Окно заявки ─────────────────────────────────────────────────────────────

const orderModal = document.querySelector("#order-modal");
const closeOrderModalButton = document.querySelector("#close-modal");
const selectedProduct = document.querySelector("#selected-product");
const orderForm = document.querySelector("#order-form");
const orderStatus = document.querySelector("#order-status");
// PHP подставляет базовый путь: / либо /billiard-cues/.
const apiBasePath = document.querySelector("base").getAttribute("href");

// Название товара отправится вместе с именем и телефоном в PHP API.
let currentOrderProduct = "";

// Открывает форму заявки и показывает, какой товар выбрал посетитель.
function openOrderModal(productName) {
    currentOrderProduct = productName;
    selectedProduct.textContent = "Вы выбрали: " + productName;
    orderStatus.textContent = "";
    orderStatus.className = "form-status";
    orderModal.classList.remove("is-hidden");
}

function closeOrderModal() {
    orderModal.classList.add("is-hidden");
}

// Кнопки в карточках сразу открывают форму заявки.
const orderButtons = document.querySelectorAll(".order-button");

orderButtons.forEach(function (button) {
    button.addEventListener("click", function () {
        openOrderModal(button.dataset.cue);
    });
});

// Закрытие формы по кнопке × или нажатию на затемнённый фон.
closeOrderModalButton.addEventListener("click", closeOrderModal);

orderModal.addEventListener("click", function (event) {
    if (event.target === orderModal) {
        closeOrderModal();
    }
});

// Отправляем форму в PHP API и не перезагружаем страницу.
orderForm.addEventListener("submit", async function (event) {
    event.preventDefault();

    const submitButton = orderForm.querySelector("button[type='submit']");
    const originalButtonText = submitButton.textContent;

    // Блокируем кнопку, чтобы одна заявка не ушла дважды.
    submitButton.disabled = true;
    submitButton.textContent = "Отправляем…";
    orderStatus.textContent = "";
    orderStatus.className = "form-status";

    // На старой сохранённой вкладке ещё может не быть блока оплаты.
    // В таком случае не ломаем форму, а даём PHP сохранить «Не указан».
    const selectedPayment = orderForm.querySelector("input[name='payment_method']:checked");

    const order = {
        product: currentOrderProduct,
        name: document.querySelector("#customer-name").value,
        phone: document.querySelector("#customer-phone").value,
        quantity: document.querySelector("#customer-quantity").value,
        // Берём отмеченную радиокнопку: она определяет вариант оплаты в заявке.
        payment_method: selectedPayment ? selectedPayment.value : "not_selected",
    };

    try {
        const response = await fetch(`${apiBasePath}api/orders`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(order),
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || "Не удалось отправить заявку.");
        }

        if (result.checkout_url) {
            window.location.assign(result.checkout_url);
            return;
        }
        orderStatus.textContent = "Спасибо! Заявка №" + result.order_id + " сохранена.";
        orderStatus.classList.add("success");
        orderForm.reset();
    } catch (error) {
        orderStatus.textContent = error.message || "Не удалось связаться с сервером.";
        orderStatus.classList.add("error");
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = originalButtonText;
    }
});

// ── Окно «Подробнее» ───────────────────────────────────────────────────────

const productModal = document.querySelector("#product-modal");
const closeProductModalButton = document.querySelector("#close-product-modal");
const modalProductImage = document.querySelector("#modal-product-image");
const modalProductTitle = document.querySelector("#modal-product-title");
const modalProductDescription = document.querySelector("#modal-product-description");
const modalProductSpecs = document.querySelector("#modal-product-specs");
const modalOrderButton = document.querySelector("#modal-order-button");

// Здесь хранится название товара, открытого в окне «Подробнее».
let currentProductName = "";

function closeProductModal() {
    productModal.classList.add("is-hidden");
}

// Кнопка «Подробнее» берёт всю информацию из своей карточки.
const detailsButtons = document.querySelectorAll(".details-button");

detailsButtons.forEach(function (button) {
    button.addEventListener("click", function () {
        // Находим карточку, внутри которой была нажата кнопка.
        const card = button.closest(".product-card");
        const image = card.querySelector(".product-image");

        // Берём заголовок, фото и описание из HTML-карточки.
        currentProductName = card.querySelector("h3").textContent;
        modalProductImage.src = image.src;
        modalProductImage.alt = image.alt;
        modalProductTitle.textContent = currentProductName;
        modalProductDescription.textContent = card.dataset.description;

        // Превращаем строку «Клён|160 см|520 г» в HTML-список.
        modalProductSpecs.innerHTML = "";

        card.dataset.specs.split("|").forEach(function (specification) {
            const item = document.createElement("li");
            item.textContent = specification;
            modalProductSpecs.append(item);
        });

        productModal.classList.remove("is-hidden");
    });
});

closeProductModalButton.addEventListener("click", closeProductModal);

productModal.addEventListener("click", function (event) {
    if (event.target === productModal) {
        closeProductModal();
    }
});

// Кнопка в окне «Подробнее» открывает форму именно для выбранного товара.
modalOrderButton.addEventListener("click", function () {
    closeProductModal();
    openOrderModal(currentProductName);
});

// ── Фильтры каталога ────────────────────────────────────────────────────────

const filterButtons = document.querySelectorAll(".filter-button");
const productCards = document.querySelectorAll(".product-card");
const catalogSearch = document.querySelector("#catalog-search");
const catalogEmpty = document.querySelector("#catalog-empty");
let activeFilter = "all";

// Объединяет два условия: выбранную категорию и текст, введённый в поиск.
function updateCatalogVisibility() {
    const searchText = catalogSearch.value.trim().toLowerCase();
    let visibleProducts = 0;

    productCards.forEach(function (card) {
        const matchesCategory =
            activeFilter === "all" || card.dataset.category === activeFilter;

        // Добавляем невидимые на карточке описание и alt-текст фото. Поэтому
        // слово «мел» найдёт Master Chalk, даже если название на английском.
        const imageAlt = card.querySelector(".product-image").alt;
        const cardText = `${card.textContent} ${card.dataset.description} ${imageAlt}`
            .toLowerCase();
        const matchesSearch = cardText.includes(searchText);
        const shouldShow = matchesCategory && matchesSearch;

        card.classList.toggle("is-hidden", !shouldShow);

        if (shouldShow) {
            visibleProducts += 1;
        }
    });

    // hidden = true скрывает сообщение, когда найден хотя бы один товар.
    catalogEmpty.hidden = visibleProducts > 0;
}

filterButtons.forEach(function (button) {
    button.addEventListener("click", function () {
        activeFilter = button.dataset.filter;

        // Подсвечиваем только нажатую кнопку категории.
        filterButtons.forEach(function (item) {
            item.classList.remove("active");
        });

        button.classList.add("active");

        updateCatalogVisibility();
    });
});

// Событие input срабатывает при каждом введённом или удалённом символе.
catalogSearch.addEventListener("input", updateCatalogVisibility);

// ── Наличие товаров ─────────────────────────────────────────────────────────

// Публичный API отдаёт только наличие: личных данных клиентов здесь нет.
const availabilityNames = {
    in_stock: "В наличии",
    preorder: "Под заказ",
    out_of_stock: "Нет в наличии",
};

// Форматирует число из базы в привычную цену: 4800 превращается в «4 800 ₴».
function formatPrice(priceUah) {
    return `${new Intl.NumberFormat("uk-UA").format(priceUah)} ₴`;
}

// Загружает из PHP всё, что может оперативно изменить администратор: цену и наличие.
async function loadCatalogProductData() {
    try {
        const response = await fetch(`${apiBasePath}api/products`);

        if (!response.ok) {
            throw new Error("Не удалось получить наличие товаров.");
        }

        const products = await response.json();

        products.forEach(function (product) {
            const card = document.querySelector(
                `.product-card[data-product-slug="${product.slug}"]`,
            );

            // Если карточки ещё нет в HTML, просто пропускаем такой товар.
            if (!card) {
                return;
            }

            const price = card.querySelector(".price");
            const availability = card.querySelector(".availability");

            // Если сервер прислал цену, заменяем временную цену из HTML актуальной.
            if (Number.isInteger(product.price_uah)) {
                price.textContent = formatPrice(product.price_uah);
            }

            const availabilityText =
                product.availability === "in_stock"
                    ? `${availabilityNames[product.availability]}: ${product.stock_quantity} шт.`
                    : availabilityNames[product.availability];
            availability.textContent = availabilityText;
            availability.className = `availability availability-${product.availability}`;
        });
    } catch (error) {
        // Сайт остаётся удобным даже при временной недоступности сервера.
        document.querySelectorAll(".availability").forEach(function (availability) {
            availability.textContent = "Наличие уточняется";
            availability.className = "availability availability-preorder";
        });
    }
}

// После загрузки страницы сразу подставляем актуальные данные из базы.
loadCatalogProductData();
