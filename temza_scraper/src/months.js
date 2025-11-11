const padMonth = (month) => String(month).padStart(2, '0');

const addMonths = ({ year, month }, delta) => {
  const date = new Date(Date.UTC(year, month - 1 + delta, 1));
  return { year: date.getUTCFullYear(), month: date.getUTCMonth() + 1 };
};

const getCurrentMonth = () => {
  const now = new Date();
  return { year: now.getFullYear(), month: now.getMonth() + 1 };
};

const parseMonthToken = (token, nowRef) => {
  const normalized = token.trim().toLowerCase();
  if (!normalized) {
    throw new Error('Пустое значение месяца недопустимо.');
  }

  if (normalized === 'current') {
    return nowRef;
  }

  if (normalized === 'next') {
    return addMonths(nowRef, 1);
  }

  const match = normalized.match(/^(\d{4})-(\d{1,2})$/);
  if (match) {
    const [, yearRaw, monthRaw] = match;
    const year = Number(yearRaw);
    const month = Number(monthRaw);
    if (month < 1 || month > 12) {
      throw new Error(`Месяц должен быть в диапазоне 01-12, получено ${monthRaw}.`);
    }
    return { year, month };
  }

  throw new Error(`Не удалось интерпретировать месяц "${token}". Используйте формат YYYY-MM, current или next.`);
};

const parseMonthsOption = (value) => {
  const now = getCurrentMonth();
  if (!value) {
    return [now, addMonths(now, 1)];
  }

  const tokens = value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

  if (!tokens.length) {
    throw new Error('Не заданы месяцы для обработки.');
  }

  return tokens.map((token) => parseMonthToken(token, now));
};

const formatMonthLabel = ({ year, month }) => `${year}-${padMonth(month)}`;

module.exports = {
  parseMonthsOption,
  formatMonthLabel,
};
