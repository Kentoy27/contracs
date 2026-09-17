<?php
$db = new PDO('sqlite:C:\\Users\\info\\.local\\share\\mimocode\\mimocode.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = $argv[1] ?? 'tables';

switch ($action) {
    case 'tables':
        $rows = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($rows);
        break;
    case 'sessions':
        $rows = $db->query("SELECT id, directory, title, time_created FROM session ORDER BY time_created DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
    case 'session_schema':
        $rows = $db->query("SELECT sql FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($rows);
        break;
    case 'search_user':
        $keyword = $argv[2] ?? '';
        $stmt = $db->prepare("SELECT m.id, m.session_id, m.agent_id, m.time_created, substr(json_extract(m.data, '$.role'), 1, 20) as role, substr(json_extract(p.data, '$.text'), 1, 300) as preview FROM message m JOIN part p ON p.message_id = m.id WHERE json_extract(m.data, '$.role') = 'user' AND json_extract(p.data, '$.type') = 'text' AND json_extract(p.data, '$.text') LIKE '%' || :kw || '%' ORDER BY m.time_created DESC LIMIT 20");
        $stmt->execute([':kw' => $keyword]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'assistant_text':
        $sid = $argv[2] ?? '';
        $stmt = $db->prepare("SELECT m.id, m.time_created, substr(json_extract(p.data, '$.text'), 1, 500) as text_preview FROM message m JOIN part p ON p.message_id = m.id WHERE m.session_id = :sid AND json_extract(m.data, '$.role') = 'assistant' AND json_extract(p.data, '$.type') = 'text' ORDER BY m.time_created");
        $stmt->execute([':sid' => $sid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'session_parts':
        $sid = $argv[2] ?? '';
        $stmt = $db->prepare("SELECT m.id as msg_id, m.agent_id, m.time_created, json_extract(m.data, '$.role') as role, json_extract(p.data, '$.type') as part_type, json_extract(p.data, '$.tool') as tool, substr(p.data, 1, 600) as preview FROM message m JOIN part p ON p.message_id = m.id WHERE m.session_id = :sid ORDER BY m.time_created, p.time_created");
        $stmt->execute([':sid' => $sid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'all_sessions':
        $rows = $db->query("SELECT id, directory, title, time_created FROM session ORDER BY time_created DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
    case 'project_sessions':
        $dir = $argv[2] ?? '';
        $stmt = $db->prepare("SELECT id, directory, title, time_created FROM session WHERE directory LIKE '%' || :dir || '%' ORDER BY time_created DESC");
        $stmt->execute([':dir' => $dir]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'user_msgs':
        $keyword = $argv[2] ?? '';
        $stmt = $db->prepare("SELECT m.id, m.session_id, m.time_created, json_extract(p.data, '$.text') as text FROM message m JOIN part p ON p.message_id = m.id WHERE json_extract(m.data, '$.role') = 'user' AND json_extract(p.data, '$.type') = 'text' AND json_extract(p.data, '$.text') LIKE '%' || :kw || '%' ORDER BY m.time_created DESC LIMIT 30");
        $stmt->execute([':kw' => $keyword]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'tasks':
        $rows = $db->query("SELECT * FROM task ORDER BY time_created DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
    case 'task_events':
        $rows = $db->query("SELECT * FROM task_event ORDER BY time_created DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
}
