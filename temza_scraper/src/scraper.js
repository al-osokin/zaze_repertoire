const selectors = require('./selectors');
const { extractPopupData } = require('./parser');

const includePatterns = [/^Сп\./i, /^АБОНЕМЕНТ/i];
const excludePatterns = [
  /^ЦЕЛЕВОЙ/i,
  /^Реп[:\s]/i,
  /^Орк[:\s]/i,
  /^Урок[:\s]/i,
  /^Выход/i,
  /^Монтаж/i,
  /^Демонтаж/i,
  /^Съёмка/i,
  /^Лекция/i,
  /^Танец[:\s]/i,
];
const specialTitles = ['Скрипач на крыше'];

const shouldProcessTitle = (title) => {
  if (!title) return false;
  const normalized = title.trim();
  if (!includePatterns.some((regex) => regex.test(normalized)) && !specialTitles.includes(normalized)) {
    return false;
  }
  if (excludePatterns.some((regex) => regex.test(title))) return false;
  return true;
};
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const getEntryPreview = (page, handle) =>
  handle.evaluate((node) => {
    const title = node.textContent.trim();
    const detailsNode =
      node.closest('.MuiBox-root')?.querySelector('.details') ??
      node.parentElement?.querySelector('.details');
    const details = detailsNode ? detailsNode.textContent.trim() : '';
    const isCancelled = !!node.closest('.canceled');
    return { title, details, status: isCancelled ? 'cancelled' : 'scheduled' };
  });

async function openPopup(page, entryHandle) {
  const clickTarget = await entryHandle.evaluateHandle((node) => node.closest('.MuiBox-root') || node);
  try {
    await Promise.all([
      page.waitForSelector(selectors.performance.popup, { visible: true }),
      clickTarget.click(),
    ]);
    await page
      .waitForFunction(
        (popupSelector, castSelector, responsiblesSelector) => {
          const popup = document.querySelector(popupSelector);
          if (!popup) return false;
          const castReady = popup.querySelector(castSelector);
          if (castReady && castReady.textContent.trim().length > 0) {
            return true;
          }
          const respReady = popup.querySelector(responsiblesSelector);
          return !!respReady;
        },
        { timeout: 10000 },
        selectors.performance.popup,
        selectors.performance.castRows,
        selectors.performance.responsiblesRows
      )
      .catch(async () => {
        await delay(500);
      });
  } finally {
    await clickTarget.dispose();
  }
}

async function closePopup(page) {
  try {
    await page.click(selectors.performance.popupClose);
  } catch (err) {
    const [closeButton] = await page.$x("//button[contains(., 'Закрыть')]");
    if (closeButton) {
      await closeButton.click();
    } else {
      throw err;
    }
  }
  await page.waitForSelector(selectors.performance.popup, { hidden: true });
}

async function scrapeFirstSpectacle(page) {
  const [record] = await scrapeSpectacleEntries(page, { limit: 1 });
  if (!record) {
    throw new Error('Не найден ни один подходящий спектакль (Сп./АБОНЕМЕНТ) на текущем месяце.');
  }
  return record.data;
}

async function scrapeSpectacleEntries(page, { limit } = {}) {
  const handles = await page.$$(selectors.performance.entry);
  const records = [];

  for (const handle of handles) {
    const preview = await getEntryPreview(page, handle);
    if (!shouldProcessTitle(preview.title)) {
      await handle.dispose();
      continue;
    }

    console.log(`Открываю спектакль: ${preview.title}`);
    await openPopup(page, handle);
    const popupHtml = await page.$eval(selectors.performance.popup, (el) => el.outerHTML);
    await closePopup(page);

    const data = extractPopupData(popupHtml, { fallbackTitle: preview.title });
    data.status = preview.status;
    records.push({
      preview,
      data,
    });

    await handle.dispose();
    if (limit && records.length >= limit) break;

    await delay(400);
  }

  return records;
}

module.exports = {
  scrapeFirstSpectacle,
  scrapeSpectacleEntries,
};
