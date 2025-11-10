// Все селекторы заполняются после инспекции Temza (DevTools или Browser MCP).
// Структура оставлена модульной, чтобы легко переопределять проблемные элементы.
const selectors = {
  login: {
    usernameInput: '#mui-1',
    passwordInput: '#mui-2',
    submitButton:
      '#root > div > div > div > div > div.MuiGrid-root.MuiGrid-item.css-1wxaqej > div > form > div > div:nth-child(4) > button',
  },
  navigation: {
    scheduleMenu: '[data-testid="menu-schedule"]',
    monthViewButton: '[data-testid="menu-schedule-month"]',
    monthContainer: '#root > div > div > main > div.MuiBox-root.css-15c8g8m > div.MuiBox-root.css-oiv1r0 > div > table',
  },
  performance: {
    entry: '.MuiTableCell-root .title',
    popup: '.MuiDialog-container',
    popupClose: '.MuiDialog-container button[aria-label="close"]',
    castRows: 'table.MuiTable-root.css-jne798 tbody tr',
    responsiblesRows: '.MuiBox-root.css-121en5t',
  },
};

module.exports = selectors;
