const {DatabaseSync} = require('node:sqlite');
const db = new DatabaseSync('C:/Users/info/.local/share/mimocode/mimocode.db', {readOnly:true});

// Get ALL user-facing sessions (not checkpoint-writer)
const sessions = db.prepare(
  "SELECT id, title, time_created FROM session WHERE title NOT LIKE 'checkpoint-writer%' ORDER BY time_created DESC"
).all();
console.log('Total user sessions:', sessions.length);

// Get most-edited files across all sessions
const sessionIds = sessions.map(s => s.id);
const placeholders = sessionIds.map(() => '?').join(',');

// File edit frequency
const editFreq = db.prepare(`
  SELECT json_extract(p.data, '$.state.input') as input_json,
         count(*) as n
  FROM message m
  JOIN part p ON p.message_id = m.id
  WHERE json_extract(m.data, '$.role') = 'assistant'
    AND json_extract(p.data, '$.type') = 'tool'
    AND json_extract(p.data, '$.tool') = 'edit'
    AND m.session_id IN (${placeholders})
  GROUP BY json_extract(input_json, '$.file_path')
  ORDER BY n DESC
  LIMIT 20
`).all(...sessionIds);

console.log('\n=== MOST EDITED FILES ===');
editFreq.forEach(r => {
  const input = JSON.parse(r.input_json);
  console.log(`${r.n}x | ${input.file_path}`);
});

// File read frequency
const readFreq = db.prepare(`
  SELECT json_extract(p.data, '$.state.input') as input_json,
         count(*) as n
  FROM message m
  JOIN part p ON p.message_id = m.id
  WHERE json_extract(m.data, '$.role') = 'assistant'
    AND json_extract(p.data, '$.type') = 'tool'
    AND json_extract(p.data, '$.tool') = 'read'
    AND m.session_id IN (${placeholders})
  GROUP BY json_extract(input_json, '$.file_path')
  ORDER BY n DESC
  LIMIT 20
`).all(...sessionIds);

console.log('\n=== MOST READ FILES ===');
readFreq.forEach(r => {
  const input = JSON.parse(r.input_json);
  console.log(`${r.n}x | ${input.file_path}`);
});

// Repeated edit patterns on same file
const repeatedEdits = db.prepare(`
  SELECT json_extract(p.data, '$.state.input') as input_json,
         count(*) as n
  FROM message m
  JOIN part p ON p.message_id = m.id
  WHERE json_extract(m.data, '$.role') = 'assistant'
    AND json_extract(p.data, '$.type') = 'tool'
    AND json_extract(p.data, '$.tool') = 'edit'
    AND json_extract(p.data, '$.state.input') LIKE '%topbar%'
    AND m.session_id IN (${placeholders})
  GROUP BY json_extract(input_json, '$.file_path')
  ORDER BY n DESC
`).all(...sessionIds);

console.log('\n=== EDITS ON TOPBAR.PHP ===');
repeatedEdits.forEach(r => {
  const input = JSON.parse(r.input_json);
  console.log(`${r.n}x | ${input.file_path}`);
});
