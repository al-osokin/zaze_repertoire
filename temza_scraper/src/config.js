const path = require('path');
const dotenv = require('dotenv');

const envPath = path.resolve(__dirname, '..', '.env');
dotenv.config({ path: envPath });

const readEnv = (key, fallback = undefined) => {
  const value = process.env[key];
  return typeof value === 'undefined' || value === '' ? fallback : value;
};

const config = {
  temza: {
    loginUrl: readEnv('TEMZA_LOGIN_URL', 'https://system.temza.online'),
    monthUrlTemplate:
      readEnv('TEMZA_MONTH_URL_TEMPLATE', readEnv('TEMZA_MONTH_URL', 'https://system.temza.online/month/{year}/{month}')),
    username: readEnv('TEMZA_USERNAME'),
    password: readEnv('TEMZA_PASSWORD'),
  },
  puppeteer: {
    headless: readEnv('PUPPETEER_HEADLESS', 'true') !== 'false',
    slowMo: Number(readEnv('PUPPETEER_SLOWMO_MS', 0)) || 0,
    defaultTimeout: 15000,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  },
};

const assertTemzaCredentials = () => {
  if (!config.temza.username || !config.temza.password) {
    throw new Error('TEMZA_USERNAME и TEMZA_PASSWORD обязательны. Заполните .env.');
  }
};

const formatMonthUrl = ({ year, month }) => {
  const template = config.temza.monthUrlTemplate;
  if (!template) return null;

  const paddedMonth = String(month).padStart(2, '0');

  if (template.includes('{year}') || template.includes('{month}')) {
    return template.replace('{year}', String(year)).replace('{month}', paddedMonth);
  }

  const normalized = template.endsWith('/') ? template.slice(0, -1) : template;
  return `${normalized}/${year}/${paddedMonth}`;
};

module.exports = {
  config,
  assertTemzaCredentials,
  formatMonthUrl,
};
