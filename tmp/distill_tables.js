const {DatabaseSync} = require('node:sqlite');
const db = new DatabaseSync('C:/Users/info/.local/share/mimocode/mimocode.db', {readOnly:true});
const rows = db.prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name").all();
rows.forEach(r => console.log(r.name));
