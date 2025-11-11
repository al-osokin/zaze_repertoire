const fs = require('fs');
const os = require('os');
const path = require('path');
const puppeteer = require('puppeteer');
const { config } = require('./config');

const findCachedChrome = () => {
  const cacheDir = path.join(os.homedir(), '.cache', 'puppeteer', 'chrome');
  try {
    const entries = fs
      .readdirSync(cacheDir, { withFileTypes: true })
      .filter((dirent) => dirent.isDirectory() && dirent.name.startsWith('linux-'))
      .map((dirent) => dirent.name)
      .sort()
      .reverse();

    for (const entry of entries) {
      const candidate = path.join(cacheDir, entry, 'chrome-linux64', 'chrome');
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
  } catch (err) {
    // Cache directory отсутствует — игнорируем и используем дефолт.
  }
  return undefined;
};

const resolveExecutablePath = () => {
  const envExecutable = (process.env.PUPPETEER_EXECUTABLE_PATH || '').trim();
  const cachedExecutable = findCachedChrome();

  if (envExecutable) {
    const looksLikeSnapChromium = envExecutable.includes('chromium-browser');
    if (looksLikeSnapChromium && cachedExecutable) {
      console.warn(
        'Puppeteer: системный chromium-browser часто недоступен (snap). Использую скачанный Chrome из ~/.cache/puppeteer.'
      );
      return cachedExecutable;
    }
    return envExecutable;
  }

  if (cachedExecutable) {
    return cachedExecutable;
  }

  return puppeteer.executablePath();
};

async function launchBrowser() {
  const executablePath = resolveExecutablePath();
  if (!executablePath) {
    console.warn('Puppeteer: не удалось определить путь к браузеру, используется значение по умолчанию.');
  } else {
    console.log(`Puppeteer: запускаю браузер ${executablePath}`);
  }

  const browser = await puppeteer.launch({
    headless: config.puppeteer.headless,
    slowMo: config.puppeteer.slowMo,
    args: config.puppeteer.args,
    executablePath,
  });

  const page = await browser.newPage();
  page.setDefaultTimeout(config.puppeteer.defaultTimeout);
  return { browser, page };
}

module.exports = {
  launchBrowser,
  resolveExecutablePath,
};
