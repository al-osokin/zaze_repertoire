const selectors = require('./selectors');
const { config, formatMonthUrl } = require('./config');

async function loginToTemza(page) {
  await page.goto(config.temza.loginUrl, { waitUntil: 'networkidle2' });

  const { usernameInput, passwordInput, submitButton } = selectors.login;
  await page.waitForSelector(usernameInput);
  await page.type(usernameInput, config.temza.username, { delay: 50 });

  await page.waitForSelector(passwordInput);
  await page.type(passwordInput, config.temza.password, { delay: 50 });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click(submitButton),
  ]);
}

const waitForMonthEntries = async (page) => {
  await page.waitForSelector(selectors.performance.entry, { timeout: 20000 });
};

async function navigateToMonthSchedule(page, targetMonth) {
  const monthUrl = targetMonth ? formatMonthUrl(targetMonth) : null;
  if (monthUrl) {
    await page.goto(monthUrl, { waitUntil: 'networkidle0' });
    await waitForMonthEntries(page);
    return;
  }

  const { scheduleMenu, monthViewButton, monthContainer } = selectors.navigation;
  await page.waitForSelector(scheduleMenu);
  await page.click(scheduleMenu);

  await page.waitForSelector(monthViewButton);
  await Promise.all([
    page.waitForSelector(monthContainer),
    page.click(monthViewButton),
  ]);

  await waitForMonthEntries(page);
}

module.exports = {
  loginToTemza,
  navigateToMonthSchedule,
};
