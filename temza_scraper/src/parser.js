const cheerio = require('cheerio');

const cleanText = (value) => (value || '').replace(/\s+/g, ' ').trim();

const EMPTY_ROLE_PLACEHOLDER = '<EMPTY>';

const normalizeJoinedNames = (value) => {
  if (!value) return '';
  const withSpacing = value.replace(
    /([а-яёa-z])([А-ЯЁA-Z][а-яёa-z]*)/g,
    '$1, $2'
  );
  return cleanText(withSpacing.replace(/\s*,\s*/g, ', '));
};
const cleanMultiline = (value) =>
  (value || '')
    .replace(/\r/g, '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);

const findHeadingSection = ($, title) => {
  const heading = $('h2')
    .filter((_, el) => cleanText($(el).text()).toLowerCase() === title.toLowerCase())
    .first();
  if (!heading.length) return null;

  const headingBox = heading.closest('.MuiBox-root');
  if (!headingBox.length) return null;

  return headingBox.parent();
};

const parseRoleCell = ($, cell) => {
  const $cell = $(cell);
  const textNodes = [];
  $cell.contents().each((_, node) => {
    if (node.type === 'text' && node.data) {
      const value = cleanText(node.data);
      if (value) textNodes.push(value);
    }
  });

  let baseRole = textNodes.join(' ').trim();
  if (!baseRole) {
    const headingText = cleanText(
      $cell
        .find('h1, h2, h3, h4, h5, h6, strong, b')
        .first()
        .text()
    );
    if (headingText) {
      baseRole = headingText;
    } else {
      const cloned = $cell.clone();
      cloned.find('div').each((_, el) => {
        const text = cleanText($(el).text());
        cloned.text(cloned.text().replace(text, ' '));
      });
      baseRole = cleanText(cloned.text());
    }
  }

  const notes = [];
  $cell.find('div').each((_, el) => {
    const text = cleanText(
      $(el)
        .clone()
        .children()
        .remove()
        .end()
        .text()
    );
    if (text) notes.push(text);
  });

  const normalizedNotes = [...new Set(notes)];
  const isDebut = normalizedNotes.some((note) => /^ввод/i.test(note));

  normalizedNotes.forEach((note) => {
    if (!note) {
      return;
    }
    baseRole = baseRole.replace(note, ' ');
  });
  baseRole = baseRole.trim();

  return {
    role: baseRole,
    notes: normalizedNotes,
    isDebut,
  };
};

const extractCast = ($) => {
  const section = findHeadingSection($, 'Состав');
  if (!section) return [];

  const table = section.find('table').first();
  if (!table.length) return [];

  const rows = [];
  let currentGroup = null;
  table.find('tbody tr').each((_, row) => {
    const cells = $(row).find('td');
    if (!cells.length) return;

    const firstCell = $(cells[0]);
    const colSpan = Number(firstCell.attr('colspan') || 1);
    const isHeadingRow = cells.length === 1 || colSpan > 1;
    if (isHeadingRow) {
      const headingText = cleanText(firstCell.text());
      if (headingText) {
        currentGroup = headingText;
      }
      return;
    }

    if (cells.length < 2) return;

    const { role, notes, isDebut } = parseRoleCell($, cells[0]);
    const trimmedRole = role ? role.trim() : '';
    const isIndexRole =
      trimmedRole && /^\d+(?:[.)]?)?(?:\([^)]*\))?$/.test(trimmedRole);
    const startsWithNumber = Boolean(trimmedRole && /^\d/.test(trimmedRole));
    const continueGroup = Boolean(currentGroup && startsWithNumber);
    const actorRaw = cleanText($(cells[1]).text());
    const actor = normalizeJoinedNames(actorRaw);
    if (role && !actor) {
      currentGroup = role;
      return;
    }
    const resolvedRole =
      (!continueGroup && !isIndexRole && role) ||
      currentGroup ||
      EMPTY_ROLE_PLACEHOLDER;
    if (resolvedRole && actor) {
      if (role && !continueGroup && !isIndexRole) {
        currentGroup = null;
      }
      const entry = { role: resolvedRole, actor };
      if (notes.length) {
        entry.roleNotes = notes;
      }
      if (isDebut) {
        entry.isDebut = true;
      }
      rows.push(entry);
    }
  });
  return rows;
};

const extractResponsibles = ($) => {
  const section = findHeadingSection($, 'Ответственные');
  if (!section) return {};

  const responsibles = {};
  section.find('.MuiBox-root.css-121en5t').each((_, block) => {
    const labelNode = $(block)
      .contents()
      .filter((_, node) => node.type === 'text')
      .text();
    const label = cleanText(labelNode).replace(/:$/, '');
    const nameRaw = cleanText($(block).find('.MuiBox-root.css-rn6a4m').first().text());
    const name = normalizeJoinedNames(nameRaw);
    if (label && name) {
      responsibles[label] = name;
    }
  });
  return responsibles;
};

const extractDepartmentTasks = ($) => {
  const section = findHeadingSection($, 'Задачи цехам');
  if (!section) return [];

  const rows = [];
  section.find('table tbody tr').each((_, row) => {
    const cells = $(row).find('td');
    if (cells.length < 2) return;

    const departmentCell = $(cells[0]);
    const detailsCell = $(cells[1]);
    const department = cleanText(departmentCell.text()).replace(/:$/, '');
    const assignments =
      detailsCell.find('.MuiBox-root.css-rn6a4m').length > 0
        ? detailsCell
            .find('.MuiBox-root.css-rn6a4m')
            .map((__, el) => normalizeJoinedNames(cleanText($(el).text())))
            .get()
            .filter(Boolean)
        : cleanMultiline(detailsCell.text())
            .map((text) => normalizeJoinedNames(text))
            .filter(Boolean);

    if (department || assignments.length) {
      rows.push({
        department,
        assignments,
      });
    }
  });

  return rows;
};

const extractCalled = ($) => {
  const section = findHeadingSection($, 'Вызваны');
  if (!section) return {};

  const called = {};
  section.find('table tbody tr').each((_, row) => {
    const cells = $(row).find('td');
    if (cells.length < 2) return;
    const label = cleanText($(cells[0]).text()).replace(/:$/, '');
    if (!label) return;

    const names = [];
    $(cells[1])
      .find('.MuiBox-root.css-rn6a4m')
      .each((__, node) => {
        const name = normalizeJoinedNames(cleanText($(node).text()));
        if (name) names.push(name);
      });

    if (!names.length) {
      cleanMultiline($(cells[1]).text()).forEach((name) => {
        const normalized = normalizeJoinedNames(name);
        if (normalized) {
          names.push(normalized);
        }
      });
    }

    if (names.length) {
      called[label] = names;
    }
  });

  return called;
};

const extractNotes = ($) => {
  const block = $('.MuiBox-root.css-1yeyb6d').first();
  if (!block.length) return [];

  const htmlContent = block.html();
  if (!htmlContent) {
    return cleanMultiline(block.text());
  }

  return htmlContent
    .split(/<br\s*\/?>/i)
    .map((chunk) => cleanText(cheerio.load(`<div>${chunk}</div>`)('div').text()))
    .filter(Boolean);
};

const extractChips = ($) => {
  const chips = [];
  $('.MuiChip-root .MuiChip-label').each((_, chip) => {
    const value = cleanText($(chip).text());
    if (value) chips.push(value);
  });
  return chips;
};

function extractPopupData(html, { fallbackTitle } = {}) {
  const $ = cheerio.load(html);

  const title =
    cleanText($('.MuiBox-root.css-ynkb88').first().text()) ||
    cleanText($('.MuiDialog-container [role="heading"]').first().text()) ||
    cleanText(fallbackTitle);

  const date = cleanText($('.MuiDialog-container .day').first().text());
  const time = cleanText($('.MuiDialog-container .time').first().text());

  const chips = extractChips($);
  const hall = chips.find((chip) => /зал/i.test(chip)) || null;

  const startTime = time && time.split(/[–-]/)[0] ? time.split(/[–-]/)[0].trim() : null;

  return {
    title,
    date,
    time,
    startTime: startTime || null,
    hall,
    chips,
    cast: extractCast($),
    responsibles: extractResponsibles($),
    departmentTasks: extractDepartmentTasks($),
    called: extractCalled($),
    notes: extractNotes($),
    rawHtml: html,
  };
}

module.exports = {
  extractPopupData,
};
