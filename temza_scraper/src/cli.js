const path = require('path');
const minimist = require('minimist');
const { parseMonthsOption } = require('./months');

const parseCli = () => {
  const argv = minimist(process.argv.slice(2), {
    string: ['month', 'months', 'outDir'],
    alias: {
      month: ['m'],
      outDir: ['o'],
    },
    '--': true,
  });

  const monthsInput = argv.months || argv.month;
  const targetMonths = parseMonthsOption(monthsInput);
  const outputDir = argv.outDir
    ? path.resolve(process.cwd(), argv.outDir)
    : path.resolve(__dirname, '..', 'output');

  return {
    targetMonths,
    outputDir,
  };
};

module.exports = {
  parseCli,
};
