const {DatabaseSync} = require('node:sqlite');
const db = new DatabaseSync('C:/Users/info/.local/share/mimocode/mimocode.db', {readOnly:true});

// Get user-facing sessions in last 30 days
const cutoff = Date.now() - 30*24*60*60*1000;
const sessions = db.prepare(
  "SELECT id, title, time_created FROM session WHERE time_created > ? AND title NOT LIKE 'checkpoint-writer%' ORDER BY time_created DESC"
).all(cutoff);
const sessionIds = sessions.map(s => s.id);
const placeholders = sessionIds.map(() => '?').join(',');

// Find user messages with repeated keywords
const keywordRows = db.prepare(`
  SELECT json_extract(m.data, '$.role') as role,
         substr(json_extract(p.data, '$.text'), 1, 300) as text_preview,
         m.session_id,
         m.time_created
  FROM message m
  JOIN part p ON p.message_id = m.id
  WHERE json_extract(m.data, '$.role') = 'user'
    AND json_extract(p.data, '$.type') = 'text'
    AND m.session_id IN (${placeholders})
    AND (
      json_extract(p.data, '$.text') LIKE '%again%'
      OR json_extract(p.data, '$.text') LIKE '%every time%'
      OR json_extract(p.data, '$.text') LIKE '%like last time%'
      OR json_extract(p.data, '$.text') LIKE '%the usual%'
      OR json_extract(p.data, '$.text') LIKE '%repeat%'
      OR json_extract(p.data, '$.text') LIKE '%same as before%'
      OR json_extract(p.data, '$.text') LIKE '%gawa%'
      OR json_extract(p.data, '$.text') LIKE '%ulit%'
      OR json_extract(p.data, '$.text') LIKE '%pareho%'
    )
  ORDER BY m.time_created DESC
  LIMIT 30
`).all(...sessionIds);

console.log('=== USER MESSAGES WITH REPEAT KEYWORDS ===');
keywordRows.forEach(r => console.log(`${r.session_id} | ${r.text_preview}\n---`));

// Also get all user messages (first text part) from main sessions for pattern analysis
console.log('\n=== ALL USER MESSAGES FROM MAIN SESSIONS ===');
const mainSessions = sessions.filter(s => !s.title.startsWith('Auto '));
const mainIds = mainSessions.map(s => s.id);
const mainPlaceholders = mainIds.map(() => '?').join(',');

const allUserMsgs = db.prepare(`
  SELECT json_extract(p.data, '$.text') as text,
         m.session_id,
         s.title as session_title
  FROM message m
  JOIN part p ON p.message_id = m.id
  JOIN session s ON s.id = m.session_id
  WHERE json_extract(m.data, '$.role') = 'user'
    AND json_extract(p.data, '$.type') = 'text'
    AND m.session_id IN (${mainPlaceholders})
  ORDER BY m.time_created ASC
`).all(...mainIds);

allUserMsgs.forEach(r => console.log(`[${r.session_title}] ${r.text}\n---`));
