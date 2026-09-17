const {DatabaseSync} = require('node:sqlite');
const db = new DatabaseSync('C:/Users/info/.local/share/mimocode/mimocode.db', {readOnly:true});

// Get user-facing sessions in last 30 days
const cutoff = Date.now() - 30*24*60*60*1000;
const sessions = db.prepare(
  "SELECT id, title, time_created FROM session WHERE time_created > ? AND title NOT LIKE 'checkpoint-writer%' ORDER BY time_created DESC"
).all(cutoff);

const sessionIds = sessions.map(s => s.id);
console.log(`=== USER-FACING SESSIONS (last 30 days): ${sessionIds.length} ===`);
sessions.forEach(s => {
  console.log(`${s.id} | ${s.title} | ${new Date(s.time_created).toISOString().slice(0,10)}`);
});

// Find tool usage patterns across assistant messages
const placeholders = sessionIds.map(() => '?').join(',');
const toolRows = db.prepare(`
  SELECT json_extract(p.data, '$.tool') as tool,
         substr(json_extract(p.data, '$.state.input'), 1, 200) as input_preview,
         count(*) as n
  FROM message m
  JOIN part p ON p.message_id = m.id
  WHERE json_extract(m.data, '$.role') = 'assistant'
    AND json_extract(p.data, '$.type') = 'tool'
    AND m.session_id IN (${placeholders})
  GROUP BY tool, input_preview
  ORDER BY n DESC
  LIMIT 50
`).all(...sessionIds);

console.log('\n=== TOOL USAGE PATTERNS (last 30 days) ===');
toolRows.forEach(r => console.log(`${r.n}x | ${r.tool} | ${r.input_preview}`));
