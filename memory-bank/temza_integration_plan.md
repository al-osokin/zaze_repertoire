Нас по большей части интересует состав исполнителей (блок "Состав" во всплывающем окне системы "Темза"), дирижёр (в блоке "Ответственные") и концертмейстер (блок "Ответственные").

## Статус на 2025‑11‑10
- Развернут отдельный Node‑workspace `temza_scraper/` с Puppeteer+cheerio. В `.env` задаём креды Темзы и шаблон `TEMZA_MONTH_URL_TEMPLATE`.
- Подтянуты рабочие селекторы: форма логина, таблица месяца и всплывающие окна спектаклей.
- Скрипт `npm run dev -- --months=…` логинится, последовательно открывает все карточки с префиксом `Сп.` за выбранные месяцы, парсит попапы и сохраняет JSON в `temza_scraper/output/temza-YYYY-MM.json`. Месяцы по умолчанию — текущий и следующий.
- Парсер вытягивает:
  * `title/date/time`, чипы/зал
  * `cast[]` (роль+исполнитель)
  * `responsibles{}` (режиссёр, дирижёр, балетмейстер, помреж и т.д.)
  * `departmentTasks[]` (таблица «Задачи цехам»)
  * `called{}` (блок «Вызваны» — здесь фиксируются концертмейстеры, оркестр, грим и т.п.)
  * `notes[]` (текстовый блок над «Составом») и `rawHtml` для оффлайн‑разбора при смене верстки.
- Уже выгружены и сохранены `temza-2025-11.json` (36 спектаклей, включая абонементы) и `temza-2025-12.json` (17 спектаклей). Это позволит работать оффлайн при сопоставлении с БД театра.
- В БД добавлены `temza_titles` + `temza_events`. CLI `php theater_repertoire/scripts/import_temza_json.php temza_scraper/output/temza-2025-11.json temza_scraper/output/temza-2025-12.json` очищает нужный месяц, заполняет события и пытается сопоставить их с `events_raw` по `date+time+hall`. Автоматически создаются гипотезы для названий (по совпадению с нашими спектаклями), хранится `suggested_play_id`.
- В админке появилась страница «Темза».
  * Верхняя таблица помогает сопоставить Temza‑названия со спектаклями. Видно чистое название, оригинал с «Сп.»/«АБОНЕМЕНТ», подсказка (гипотеза) и дата/время первого появления в текущем месяце. Можно либо выбрать спектакль вручную, либо принять гипотезу.
  * Нижняя таблица показывает события месяца: дата, время, hall, статус сопоставления с афишей и форма выбора `events_raw`. Для случаев вне афиши есть флажок «Игнорировать (вне афиши)», чтобы исключить событие из списка незакрытых.

## Интерфейс запуска скрапера (для PHP/CLI)
```
cd temza_scraper
npm run dev -- --months=current,next --outDir=/path/to/cache
```
- `--months` принимает `current`, `next`, либо явные `YYYY-MM` через запятую.
- `--month`/`-m` — короткий вариант для одиночного месяца.
- `--outDir` (опционально) задаёт каталог для JSON (по умолчанию `temza_scraper/output`).
- Выходной формат: файл `temza-YYYY-MM.json` c полями `month`, `scrapedAt`, `total`, `spectacles[]`. Каждый спектакль содержит превью (название/время), все разобранные блоки и оригинальный HTML. PHP сможет читать эти файлы без повторных запросов в Темзу.

## Ближайшие шаги
1. Доработать обработку составов: после подтверждения названия и, при необходимости, сопоставления с афишей, подтягивать `cast`, `responsibles`, `called` из `temza_events` и сравнивать/заполнять карточки спектаклей.
2. Добавить нормализацию ролей/ответственных (Temza → наши `roles` и артисты), чтобы исключить ручное переименование.
3. Продумать механизм обновления: например, CLI‑команда, которая проверяет свежесть JSON, вызывает скрапер и выполняет импорт/сопоставление (учитывая ignore‑флагов и гипотезы).
4. Добавить контроль изменений (checksum raw_html) и метки «требует обновления состава», если Temza‑состав отличается от текущей карточки.

**Рекомендация по алгоритму:**
- Парси “Состав исполнителей” как отдельный список (роль + исполнитель).
- Парси “Ответственных” в виде словаря (ключ — тип ответственности, значение — имя).
- Остальные блоки складывай в структуру “как есть” — их можно фильтровать по необходимости уже на стадии обработки.
- Выбор нужных полей (“дирижёр”, “режиссёр” и т.п.) производится уже в PHP/Node или прямо при формировании JSON.

**Формат JSON:**
```json
{
  "title": "Сп. Финист",
  "date": "1 ноября",
  "time": "12:00–14:15",
  "cast": [
    {"role": "Рассказчик", "actor": "Виталий Гордиенко"}
  ],
  "responsibles": {
    "Режиссёр": "Анна Снегова",
    "Дирижёр": "Василий Абрамов"
  },
  "other_blocks": {
    "departments": [...],
    "called": [...]
  }
}
```

## Основы для скриптов на Node.js с Puppeteer.

1. Авторизация

const puppeteer = require('puppeteer');

async function loginToTemza(loginUrl, user, pass) {
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();
    await page.goto(loginUrl);

    // Пример: ввод логина и пароля
    await page.type('input[name="username"]', user);
    await page.type('input[name="password"]', pass);
    await page.click('button[type="submit"]');
    await page.waitForNavigation({ waitUntil: "networkidle0" });

    return { browser, page };
}

Здесь селекторы под логин и пароль могут отличаться — проверь их по DevTools.

2. Навигация к "Месяц"

async function navigateToMonthSchedule(page) {
    // Эмуляция клика по пункту "Расписание", затем "Месяц"
    await page.click('selector-for-menu-raspisanie'); // Уточнить
    await page.click('selector-for-menu-month');      // Уточнить
    
    // Ждать появления блока расписания месяца
    await page.waitForSelector('selector-for-month-table');
}

3. Сбор элементов спектаклей

async function getAllSpectacles(page) {
    const spectacleSelectors = await page.$$eval('selector-spectacle', els =>
        els.filter(el => el.textContent.startsWith('Сп.')).map(el => el)
    );
    // spectacleSelectors — массив элементов/селекторов, которые надо кликать
    return spectacleSelectors;
}

Рекомендуется использовать кнопку/ссылку, реально открывающую popup.

4. Открытие popup и парсинг данных

async function parseSpectaclePopup(page, spectacleElement) {
    await spectacleElement.click();
    await page.waitForSelector('selector-for-popup'); // Селектор popup-блока

    // Получаем HTML popup для парсинга
    const popupHtml = await page.evaluate(() => {
        return document.querySelector('selector-for-popup').innerHTML;
    });

    // Здесь парсим popupHtml (можно через DOMParser или Cheerio)
    // Например:
    // 1. Состав исполнителей: пройти по строкам таблицы
    // 2. Ответственные: найти блок/строку по тексту
    // 3. Другие данные - аналогично
}

5. Извлечение нужных данных

const cheerio = require('cheerio');

function extractPopupData(html) {
    const $ = cheerio.load(html);

    // Состав исполнителей
    const cast = [];
    $('selector-for-cast-block tr').each((i, el) => {
        const role = $(el).find('td.role-selector').text().trim();
        const actor = $(el).find('td.actor-selector').text().trim();
        if (role && actor) cast.push({ role, actor });
    });

    // Ответственные
    const responsibles = {};
    $('selector-for-responsibles-block tr').each((i, el) => {
        const label = $(el).find('td.label-selector').text().trim();
        const name = $(el).find('td.name-selector').text().trim();
        if (label && name) responsibles[label] = name;
    });

    return { cast, responsibles };
}

Селекторы для таблиц, строк и ячеек уточняются по структуре popup в DevTools.

6. Сбор всех спектаклей в месяце

async function scrapeMonth(browser, page) {
    const spectacles = await getAllSpectacles(page);
    let results = [];
    for (let spectacleElement of spectacles) {
        await parseSpectaclePopup(page, spectacleElement);
        const html = await page.evaluate(() => {
            return document.querySelector('selector-for-popup').innerHTML;
        });
        const data = extractPopupData(html);
        results.push(data);
        await page.click('selector-for-popup-close'); // Закрытие popup
    }
    return results;
}

7. Сохранение результата

const fs = require('fs');
fs.writeFileSync('spectacles.json', JSON.stringify(results, null, 2));

Краткие технические примечания:

- Все селекторы уточняются в инспекторе браузера (“Темза” в Comet).
- Если селекторы сложные (динамические классы), ищи их по тексту или другим признакам.
- Для сложных popup рекомендуется парсить через Cheerio/DOMParser вне браузера (можно прямо в Puppeteer через evaluate).
- Учитывай задержки на открытие popup (waitForSelector или waitForTimeout).
- Возможен антибот (например, reCAPTCHA) — в таком случае потребуется ручное вмешательство.
