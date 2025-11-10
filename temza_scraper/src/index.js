const fs = require('fs');
const path = require('path');
const { launchBrowser } = require('./browser');
const { loginToTemza, navigateToMonthSchedule } = require('./temzaClient');
const { assertTemzaCredentials } = require('./config');
const { parseCli } = require('./cli');
const { formatMonthLabel } = require('./months');
const { scrapeSpectacleEntries } = require('./scraper');

async function main() {
  assertTemzaCredentials();
  const { targetMonths, outputDir } = parseCli();
  fs.mkdirSync(outputDir, { recursive: true });

  const { browser, page } = await launchBrowser();
  try {
    await loginToTemza(page);
    for (const targetMonth of targetMonths) {
      await navigateToMonthSchedule(page, targetMonth);
      const monthLabel = formatMonthLabel(targetMonth);
      console.log(`Темза: открыт месяц ${monthLabel}. Начинаю парсинг всех спектаклей.`);
      const records = await scrapeSpectacleEntries(page);

      const payload = {
        month: monthLabel,
        scrapedAt: new Date().toISOString(),
        total: records.length,
        spectacles: records.map(({ preview, data }) => ({
          previewTitle: preview.title,
          previewDetails: preview.details || null,
          ...data,
        })),
      };

      const outputPath = path.join(outputDir, `temza-${monthLabel}.json`);
      fs.writeFileSync(outputPath, JSON.stringify(payload, null, 2));
      console.log(`Темза: сохранено ${records.length} спектаклей в ${outputPath}`);
    }
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
  });
}
