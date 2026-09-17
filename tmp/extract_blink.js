const fs = require('fs');
const lines = fs.readFileSync('assets/js/app-main.js', 'utf8').split('\n');
for (let i = 2699; i < Math.min(2830, lines.length); i++) {
    console.log((i + 1) + ': ' + lines[i]);
}
