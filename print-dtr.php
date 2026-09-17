<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/cache_headers.php';

ctr_session_start();

$loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (!$loggedIn) {
    header('Location: ' . ctr_url('login'));
    exit;
}

// DTR prints are private per-user; cache a short window on the user's
// device so back/forward navigation is instant.
ctr_cache_headers('short', 30);

$sessionRole = (string)($_SESSION['role'] ?? '');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = $sessionRole !== 'superadmin';
if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
    header('Location: ' . ctr_url('login'));
    exit;
}

if ($sessionRole === 'admin') {
    $ok = false;
    if ($sessionUserId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT is_active, plan_expires_at FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
            $stmt->execute([':id' => $sessionUserId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $isActive = (int)($u['is_active'] ?? 0) === 1;
                $expRaw = $u['plan_expires_at'] ?? null;
                $expStr = is_string($expRaw) ? trim($expRaw) : '';
                $planActive = false;
                if ($expStr !== '') {
                    $tz = new DateTimeZone('Asia/Manila');
                    $now = new DateTimeImmutable('now', $tz);
                    $exp = new DateTimeImmutable($expStr, $tz);
                    $planActive = $exp > $now;
                }
                // Allow access but track if plan is needed
                $ok = true;
                $_SESSION['admin_needs_plan_request'] = !$isActive || !$planActive;
            }
        } catch (Throwable $e) {
            $ok = false;
        }
    }
}

function pdf_escape(string $s): string
{
    $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s) ?? $s;
    return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
}

function pdf_stream(string $content): string
{
    return "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
}

function build_dtr_pdf(array $user, array $records, string $dateFrom, string $dateTo, string $inChargeName): string
{
    $objects = [];
    $objNum = 1;

    $addObject = function (string $body) use (&$objects, &$objNum): int {
        $n = $objNum++;
        $objects[$n] = $body;
        return $n;
    };

    $fontR = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>");
    $fontB = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold >>");

    $pageW = 595;
    $pageH = 842;

    $formW = 2.6 * 72;
    $gapW = 1.0 * 72;
    $leftX = ($pageW - ((2 * $formW) + $gapW)) / 2;
    $rightX = $leftX + $formW + $gapW;
    $topY = $pageH - (0.5 * 72);

    $userName = trim((string)($user['name'] ?? ''));

    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $refTs = null;
    if ($dateFrom !== '') {
        $refTs = strtotime($dateFrom . ' 00:00:00');
    } elseif ($dateTo !== '') {
        $refTs = strtotime($dateTo . ' 00:00:00');
    } elseif (!empty($records) && !empty($records[0]['attend_date'])) {
        $refTs = strtotime((string)$records[0]['attend_date'] . ' 00:00:00');
    }
    if (!$refTs) {
        $refTs = time();
    }
    $mIdx = (int)date('n', $refTs) - 1;
    if ($mIdx < 0) $mIdx = 0;
    if ($mIdx > 11) $mIdx = 11;
    $monthLabel = $monthNames[$mIdx] . ' ' . date('Y', $refTs);

    $formatTimeOfficial = static function ($value): string {
        $t = trim((string)($value ?? ''));
        if ($t === '') return '';
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) return '';
        $hh = (int)$m[1];
        $mm = $m[2];
        $h12 = $hh % 12;
        if ($h12 === 0) $h12 = 12;
        return str_pad((string)$h12, 2, '0', STR_PAD_LEFT) . ':' . $mm;
    };

    $byDay = [];
    foreach ($records as $r) {
        $d = trim((string)($r['attend_date'] ?? ''));
        if ($d === '') continue;
        $ts = strtotime($d . ' 00:00:00');
        if (!$ts) continue;
        $day = (int)date('j', $ts);
        if ($day < 1 || $day > 31) continue;
        $byDay[$day] = [
            'am_in' => $formatTimeOfficial($r['am_in'] ?? ''),
            'am_out' => $formatTimeOfficial($r['am_out'] ?? ''),
            'pm_in' => $formatTimeOfficial($r['pm_in'] ?? ''),
            'pm_out' => $formatTimeOfficial($r['pm_out'] ?? ''),
        ];
    }

    $widthUpper = [
        'A' => 722, 'B' => 667, 'C' => 667, 'D' => 722, 'E' => 611, 'F' => 556, 'G' => 722, 'H' => 722, 'I' => 333,
        'J' => 389, 'K' => 722, 'L' => 611, 'M' => 889, 'N' => 722, 'O' => 722, 'P' => 556, 'Q' => 722, 'R' => 667,
        'S' => 556, 'T' => 611, 'U' => 722, 'V' => 722, 'W' => 944, 'X' => 722, 'Y' => 722, 'Z' => 611,
    ];
    $widthLower = [
        'a' => 444, 'b' => 500, 'c' => 444, 'd' => 500, 'e' => 444, 'f' => 333, 'g' => 500, 'h' => 500, 'i' => 278,
        'j' => 278, 'k' => 500, 'l' => 278, 'm' => 778, 'n' => 500, 'o' => 500, 'p' => 500, 'q' => 500, 'r' => 333,
        's' => 389, 't' => 278, 'u' => 500, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 444,
    ];
    $widthPunct = [
        ' ' => 250, '!' => 333, '"' => 408, '#' => 500, '$' => 500, '%' => 833, '&' => 778, "'" => 180, '(' => 333,
        ')' => 333, '*' => 500, '+' => 564, ',' => 250, '-' => 333, '.' => 250, '/' => 278, ':' => 278, ';' => 278,
        '<' => 564, '=' => 564, '>' => 564, '?' => 444, '@' => 921, '[' => 333, '\\' => 278, ']' => 333, '^' => 469,
        '_' => 500, '`' => 333, '{' => 480, '|' => 200, '}' => 480, '~' => 541,
    ];

    $charWidthUnits = static function (string $ch) use ($widthUpper, $widthLower, $widthPunct): int {
        if (isset($widthPunct[$ch])) return $widthPunct[$ch];
        if ($ch >= '0' && $ch <= '9') return 500;
        if (isset($widthUpper[$ch])) return $widthUpper[$ch];
        if (isset($widthLower[$ch])) return $widthLower[$ch];
        return 500;
    };

    $textWidth = static function (float $size, string $text, bool $bold = false) use ($charWidthUnits): float {
        $s = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $sum += $charWidthUnits($s[$i]);
        }
        $w = ($sum / 1000) * $size;
        if ($bold) {
            $w *= 1.04;
        }
        return $w;
    };

    $textAt = static function (string $fontKey, float $size, float $x, float $y, string $text): string {
        return "BT /{$fontKey} {$size} Tf 0 Tw 1 0 0 1 {$x} {$y} Tm (" . pdf_escape($text) . ") Tj ET";
    };

    $textAtJustified = static function (string $fontKey, float $size, float $x, float $y, string $text, float $maxWidth) use ($textWidth): string {
        $t = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
        $spaces = substr_count($t, ' ');
        $ws = 0.0;
        if ($spaces > 0) {
            $w = $textWidth($size, $t, $fontKey === 'F2');
            $extra = $maxWidth - $w;
            if ($extra > 0.1) {
                $ws = $extra / $spaces;
            }
        }
        return "BT /{$fontKey} {$size} Tf {$ws} Tw 1 0 0 1 {$x} {$y} Tm (" . pdf_escape($t) . ") Tj 0 Tw ET";
    };

    $textCentered = static function (string $fontKey, float $size, float $centerX, float $y, string $text) use ($textAt, $textWidth): string {
        $w = $textWidth($size, $text, $fontKey === 'F2');
        return $textAt($fontKey, $size, $centerX - ($w / 2), $y, $text);
    };

    $line = static function (float $x1, float $y1, float $x2, float $y2): string {
        return "{$x1} {$y1} m {$x2} {$y2} l S";
    };

    $rect = static function (float $x, float $y, float $w, float $h): string {
        return "{$x} {$y} {$w} {$h} re S";
    };

    $wrapText = static function (string $text, float $size, float $maxWidthPt) use ($textWidth): array {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') return [];

        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $w) {
            $candidate = $line === '' ? $w : ($line . ' ' . $w);
            if ($textWidth($size, $candidate, false) <= $maxWidthPt) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') $lines[] = $line;
            $line = $w;
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    };

    $drawFrontForm = static function (float $x0, float $yTop) use (&$byDay, $formW, $monthLabel, $userName, $inChargeName, $textAt, $textCentered, $textWidth, $line, $rect, $wrapText): string {
        $cmd = [];
        $cmd[] = "0 0 0 RG 0.6 w";

        $cmd[] = $textAt('F1', 6.6, $x0, $yTop - 8, 'CS Form 48');
        $cmd[] = $textCentered('F2', 10, $x0 + ($formW / 2), $yTop - 24, 'DAILY TIME RECORD');

        $nameLineY = $yTop - 50;
        $cmd[] = $line($x0 + 12, $nameLineY, $x0 + $formW - 12, $nameLineY);
        $cmd[] = $textCentered('F2', 9, $x0 + ($formW / 2), $nameLineY + 6, $userName);
        $cmd[] = $textCentered('F1', 7, $x0 + ($formW / 2), $nameLineY - 12, 'Name');

        $metaY = $yTop - 78;
        $lbl1 = 'For the month of';
        $cmd[] = $textAt('F1', 7.2, $x0, $metaY, $lbl1);
        $u1s = $x0 + $textWidth(7.2, $lbl1, false) + 8;
        $u1e = $x0 + $formW;
        $cmd[] = $line($u1s, $metaY - 3, $u1e, $metaY - 3);
        $cmd[] = $textCentered('F1', 7.2, $u1s + (($u1e - $u1s) / 2), $metaY - 1, $monthLabel);

        $metaY2 = $metaY - 14;
        $lbl2 = 'Office Hours (regular days)';
        $cmd[] = $textAt('F1', 7.2, $x0, $metaY2, $lbl2);
        $u2s = $x0 + $textWidth(7.2, $lbl2, false) + 8;
        $cmd[] = $line($u2s, $metaY2 - 3, $x0 + $formW, $metaY2 - 3);

        $metaY3 = $metaY2 - 14;
        $lbl3 = 'Arrival & Departure';
        $cmd[] = $textAt('F1', 7.2, $x0, $metaY3, $lbl3);
        $u3s = $x0 + $textWidth(7.2, $lbl3, false) + 8;
        $cmd[] = $line($u3s, $metaY3 - 3, $x0 + $formW, $metaY3 - 3);

        $metaY4 = $metaY3 - 14;
        $lbl4 = 'Saturdays';
        $cmd[] = $textAt('F1', 7.2, $x0, $metaY4, $lbl4);
        $u4s = $x0 + $textWidth(7.2, $lbl4, false) + 8;
        $cmd[] = $line($u4s, $metaY4 - 3, $x0 + $formW, $metaY4 - 3);

        $tableTop = $yTop - 142;
        $rowH = 10;
        $rows = 31;
        $headerRows = 2;
        $tableH = ($rows + $headerRows) * $rowH;
        $tableBottom = $tableTop - $tableH;

        $wDay = 14;
        $wA = 30;
        $wD = 30;
        $wRemain = $formW - ($wDay + ($wA + $wD + $wA + $wD));
        $wHM = $wRemain / 2;
        $colW = [$wDay, $wA, $wD, $wA, $wD, $wHM, $wHM];
        $xs = [$x0];
        for ($i = 0; $i < count($colW); $i++) {
            $xs[] = $xs[count($xs) - 1] + $colW[$i];
        }

        $header1Bottom = $tableTop - $rowH;
        $header2Bottom = $tableTop - (2 * $rowH);

        $cmd[] = "0 0 0 RG 0.8 w";
        $cmd[] = $rect($x0, $tableBottom, $formW, $tableH);
        $cmd[] = "0 0 0 RG 0.6 w";

        $cmd[] = $line($xs[1], $tableTop, $xs[1], $tableBottom);
        $cmd[] = $line($xs[3], $tableTop, $xs[3], $tableBottom);
        $cmd[] = $line($xs[5], $tableTop, $xs[5], $tableBottom);
        $cmd[] = $line($xs[6], $tableTop, $xs[6], $tableBottom);

        $cmd[] = $line($xs[2], $header1Bottom, $xs[2], $tableBottom);
        $cmd[] = $line($xs[4], $header1Bottom, $xs[4], $tableBottom);

        $cmd[] = $line($xs[1], $header1Bottom, $xs[5], $header1Bottom);
        $cmd[] = $line($x0, $header2Bottom, $x0 + $formW, $header2Bottom);
        for ($r = 1; $r <= $rows; $r++) {
            $y = $header2Bottom - ($r * $rowH);
            $cmd[] = $line($x0, $y, $x0 + $formW, $y);
        }

        $cmd[] = $textCentered('F2', 7, ($xs[1] + $xs[3]) / 2, $tableTop - 7, 'AM');
        $cmd[] = $textCentered('F2', 7, ($xs[3] + $xs[5]) / 2, $tableTop - 7, 'PM');
        $cmd[] = $textCentered('F2', 6.8, $xs[5] + ($colW[5] / 2), $tableTop - 12, 'Hours');
        $cmd[] = $textCentered('F2', 6.8, $xs[6] + ($colW[6] / 2), $tableTop - 12, 'Min.');

        $h2y = $header1Bottom - 7;
        $cmd[] = $textCentered('F2', 5.8, $xs[1] + ($colW[1] / 2), $h2y, 'Arrival');
        $cmd[] = $textCentered('F2', 5.8, $xs[2] + ($colW[2] / 2), $h2y, 'Departure');
        $cmd[] = $textCentered('F2', 5.8, $xs[3] + ($colW[3] / 2), $h2y, 'Arrival');
        $cmd[] = $textCentered('F2', 5.8, $xs[4] + ($colW[4] / 2), $h2y, 'Departure');

        $baseY = $tableTop - ($headerRows * $rowH);
        for ($day = 1; $day <= 31; $day++) {
            $rec = $byDay[$day] ?? ['am_in' => '', 'am_out' => '', 'pm_in' => '', 'pm_out' => ''];
            $cy = $baseY - (($day - 1) * $rowH) - 7;
            $cmd[] = $textCentered('F2', 6.8, $xs[0] + ($colW[0] / 2), $cy, (string)$day);
            $cmd[] = $textCentered('F2', 6.8, $xs[1] + ($colW[1] / 2), $cy, (string)$rec['am_in']);
            $cmd[] = $textCentered('F2', 6.8, $xs[2] + ($colW[2] / 2), $cy, (string)$rec['am_out']);
            $cmd[] = $textCentered('F2', 6.8, $xs[3] + ($colW[3] / 2), $cy, (string)$rec['pm_in']);
            $cmd[] = $textCentered('F2', 6.8, $xs[4] + ($colW[4] / 2), $cy, (string)$rec['pm_out']);
        }

        $totalY = $tableBottom - 18;
        $cmd[] = $textAt('F2', 7, $x0 + $formW - 90, $totalY, 'Total');
        $cmd[] = $line($x0 + $formW - 60, $totalY - 2, $x0 + $formW, $totalY - 2);

        $cert = 'I certify on my honor that the above is true and correct record of the hours of work performed of which was made daily at the time of arrival and departure from the office.';
        $certLines = $wrapText($cert, 7, $formW);
        $cy = $totalY - 16;
        foreach ($certLines as $ln) {
            $cmd[] = $textAt('F1', 7, $x0, $cy, $ln);
            $cy -= 9;
        }

        $sigLineY = $cy - 24;
        $cmd[] = $line($x0, $sigLineY, $x0 + $formW, $sigLineY);
        $cmd[] = $textCentered('F1', 7.5, $x0 + ($formW / 2), $sigLineY - 12, '(Signature)');

        $verifyY = $sigLineY - 28;
        $cmd[] = $textAt('F1', 7.5, $x0, $verifyY, 'Verified as to the prescribed office hours');
        $inChargeY = $verifyY - 28;
        $cmd[] = $line($x0, $inChargeY, $x0 + $formW, $inChargeY);
        $icName = trim((string)$inChargeName);
        if ($icName !== '') {
            $fs = 8.5;
            $maxW = $formW - 4;
            while ($fs > 6.5 && $textWidth($fs, $icName, true) > $maxW) {
                $fs -= 0.2;
            }
            if ($fs < 6.5) $fs = 6.5;
            $cmd[] = $textCentered('F2', $fs, $x0 + ($formW / 2), $inChargeY + 6, $icName);
        }
        $cmd[] = $textCentered('F1', 7.5, $x0 + ($formW / 2), $inChargeY - 12, '(In-charge)');

        return implode("\n", $cmd);
    };

    $drawInstructionForm = static function (float $x0, float $yTop) use ($formW, $textAt, $textAtJustified, $textCentered, $line, $wrapText): string {
        $cmd = [];
        $cmd[] = "0 0 0 RG 0.6 w";

        $cmd[] = $textCentered('F2', 13, $x0 + ($formW / 2), $yTop - 30, 'INSTRUCTIONS');
        $oooY = $yTop - 46;
        $cmd[] = $line($x0 + 10, $oooY, $x0 + ($formW / 2) - 18, $oooY);
        $cmd[] = $line($x0 + ($formW / 2) + 18, $oooY, $x0 + $formW - 10, $oooY);
        $cmd[] = $textCentered('F1', 9, $x0 + ($formW / 2), $oooY - 6, 'oOo');

        $paras = [
            'Civil Service Form No. 48, after completion, should be filed in the records of the Bureau or Office which submits the monthly report on Civil Service Form No. 3 to the Bureau of Civil Service.',
            'In lieu of the above, court interpreters and stenographers who accompany the judges of the Court of First Instance will fill out the daily time reports on this form in triplicate, after which they should be approved by the judge with whom service has been rendered, or by an officer of the Department of Justice authorized to do so. The original should be forwarded promptly after the end of the month to the Bureau of Civil Service, thru the Department of Justice; and the triplicate in the office of the Clerk of Court where the service was rendered.',
            'In the space provided for the purpose on the other side will be indicated the office hours the employee is required to observe; as for example, Regular days, 8:00 -12:00 and 1:00 - 4:00; Saturdays, 8:00 - 1:00".',
            'Attention is invited to paragraph 3, Civil Service Rule XV, Executive Order No. 5, series of 1909, which read as follows:',
            'Each Chief of a Bureau or Office shall require a daily record of attendance of all the officers and employees under him entitled to leave of absence or vacation (including teachers) to be kept on the proper form and also systematic office record showing for each day all absences from duty from any cause whatever. At the beginning of each month he shall report to the Commissioner on the proper form all absences from any cause whatever, including the exact amount of undertime of each person for each day. Officers and employees serving in the field or on the water need not be required to keep a daily record, but all absences of such employees must be included in the monthly report of changes and absences. Falsification of time records will render the offending officer or employee liable to summary removal from the service and criminal prosecution."',
        ];

        $y = $oooY - 26;
        $fontSize = 7.6;
        $lineH = 9.3;
        $colPad = 10;
        $textX = $x0 + $colPad;
        $maxW = $formW - (2 * $colPad);
        foreach ($paras as $p) {
            $lines = $wrapText($p, $fontSize, $maxW);
            $cnt = count($lines);
            foreach ($lines as $idx => $ln) {
                if ($idx < $cnt - 1) {
                    $cmd[] = $textAtJustified('F1', $fontSize, $textX, $y, $ln, $maxW);
                } else {
                    $cmd[] = $textAt('F1', $fontSize, $textX, $y, $ln);
                }
                $y -= $lineH;
            }
            $y -= 3;
        }

        $divY = $y - 6;
        for ($x = $x0 + 10; $x <= $x0 + $formW - 10; $x += 6) {
            $cmd[] = $line($x, $divY, $x, $divY - 10);
        }
        $y = $divY - 18;

        $note = '(NOTE. - A record made from memory at sometime subsequent to the occurrence of an event is not reliable. Non-observance of office hours deprives the employee of the leave privileges although he may have rendered overtime service. Where service rendered outside of the office of the whole morning or afternoon, notation to that effect should be made clearly.)';
        $noteFont = 7.2;
        $noteX = $textX;
        $noteW = $maxW;
        $noteLines = $wrapText($note, $noteFont, $noteW);
        $cnt2 = count($noteLines);
        foreach ($noteLines as $idx => $ln) {
            if ($idx < $cnt2 - 1) {
                $cmd[] = $textAtJustified('F1', $noteFont, $noteX, $y, $ln, $noteW);
            } else {
                $cmd[] = $textAt('F1', $noteFont, $noteX, $y, $ln);
            }
            $y -= 9.2;
        }

        return implode("\n", $cmd);
    };

    $contentPage1 = [];
    $contentPage1[] = "0 0 0 RG";
    $contentPage1[] = $drawFrontForm($leftX, $topY);
    $contentPage1[] = $drawFrontForm($rightX, $topY);
    $contentObj1 = $addObject(pdf_stream(implode("\n", $contentPage1)));

    $contentPage2 = [];
    $contentPage2[] = "0 0 0 RG";
    $contentPage2[] = $drawInstructionForm($leftX, $topY);
    $contentPage2[] = $drawInstructionForm($rightX, $topY);
    $contentObj2 = $addObject(pdf_stream(implode("\n", $contentPage2)));

    $pagesObjNum = $addObject("");
    $catalogObjNum = $addObject("");

    $kidsRefs = [];
    $pageBody1 = "<< /Type /Page /Parent {$pagesObjNum} 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /Font << /F1 {$fontR} 0 R /F2 {$fontB} 0 R >> >> /Contents {$contentObj1} 0 R >>";
    $page1 = $addObject($pageBody1);
    $kidsRefs[] = "{$page1} 0 R";

    $pageBody2 = "<< /Type /Page /Parent {$pagesObjNum} 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /Font << /F1 {$fontR} 0 R /F2 {$fontB} 0 R >> >> /Contents {$contentObj2} 0 R >>";
    $page2 = $addObject($pageBody2);
    $kidsRefs[] = "{$page2} 0 R";

    $objects[$pagesObjNum] = "<< /Type /Pages /Kids [ " . implode(' ', $kidsRefs) . " ] /Count " . count($kidsRefs) . " >>";
    $objects[$catalogObjNum] = "<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>";

    ksort($objects);

    $out = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $n => $body) {
        $offsets[$n] = strlen($out);
        $out .= $n . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefPos = strlen($out);
    $out .= "xref\n0 " . ($objNum) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($i = 1; $i < $objNum; $i++) {
        $off = $offsets[$i] ?? 0;
        $out .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $out .= "trailer\n<< /Size {$objNum} /Root {$catalogObjNum} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
    return $out;
}

if (isset($_GET['export']) && (string)($_GET['export'] ?? '') === 'pdf') {
    $userId = (int)($_GET['user_id'] ?? 0);
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));

    if ($userId <= 0) {
        http_response_code(400);
        exit('Invalid user.');
    }

    $stmt = $pdo->prepare("SELECT id, id_number, name, position, department FROM users WHERE id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
    if ($isAdmin && (int)($user['id'] ?? 0) !== $userId) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :uid AND created_by = :cb");
        $check->execute([':uid' => $userId, ':cb' => $sessionUserId]);
        if ((int)$check->fetchColumn() === 0) {
            http_response_code(403);
            exit('Forbidden.');
        }
    }

    $dateFilter = '';
    $params = [':uid' => $userId];
    if ($dateFrom !== '' && $dateTo !== '') {
        $dateFilter = ' AND attend_date BETWEEN :df AND :dt';
        $params[':df'] = $dateFrom;
        $params[':dt'] = $dateTo;
    } elseif ($dateFrom !== '') {
        $dateFilter = ' AND attend_date >= :df';
        $params[':df'] = $dateFrom;
    } elseif ($dateTo !== '') {
        $dateFilter = ' AND attend_date <= :dt';
        $params[':dt'] = $dateTo;
    }

    $sql = "SELECT
                attend_date,
                MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out
            FROM attendance_logs
            WHERE user_id = :uid $dateFilter
            GROUP BY attend_date
            ORDER BY attend_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inChargeName = '';
    if ($sessionRole === 'admin' && $sessionUserId > 0) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $sessionUserId]);
        $inChargeName = trim((string)($stmt->fetchColumn() ?? ''));
    }

    // Allow overriding the in-charge name via query parameter.
    $customInCharge = trim((string)($_GET['in_charge'] ?? ''));
    if ($customInCharge !== '') {
        $inChargeName = $customInCharge;
    }

    $pdf = build_dtr_pdf($user, $records, $dateFrom, $dateTo, $inChargeName);
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($user['id_number'] ?? '')) ?: 'id';
    $fn = 'DTR_' . $safeId . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

include __DIR__ . '/includes/header.php';
ob_start();
ctr_cookie_consent_banner();
?>

<div class="container-fluid px-4 py-4">
    <?php if (!empty($_SESSION['admin_needs_plan_request'])): ?>
    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
        <i class="feather-alert-triangle me-2 fs-4"></i>
        <div class="flex-grow-1">
            <strong>Plan Required</strong><br>
            <span>Your account needs an active plan to access all features. Please contact the Superadmin to request a plan activation.</span>
        </div>
        <button type="button" id="adminPlanExtendBtn" class="btn btn-warning btn-sm ms-3">
            <i class="feather-mail me-1"></i>Request Plan
        </button>
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Daily Time Record (DTR)</h4>
            <div class="text-muted small">Search employee and generate printable DTR</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="attendance.php">Back to Attendance</a>
        </div>
    </div>

    <div style="position:relative;">
    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <div class="fw-semibold">Search Employee</div>
                    <div class="text-muted small">By name, ID number, or department</div>
                </div>
                <div class="card-body">
                    <input class="form-control mb-2" id="dtrSearchInput" placeholder="Type to search...">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="form-check mb-0 d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" id="dtrSelectAllUsers">
                            <span class="small">Select all (visible)</span>
                        </label>
                        <div class="text-muted small">Selected: <span id="dtrSelectedCount">0</span></div>
                    </div>
                    <div class="list-group" id="dtrSearchResults"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold" id="dtrSelectedName">No employee selected</div>
                        <div class="text-muted small" id="dtrSelectedMeta"></div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" type="button" id="dtrViewBtn" disabled>View DTR</button>
                        <button class="btn btn-success" type="button" id="dtrBulkPrintBtn" disabled>Print Selected</button>
                        <button class="btn btn-outline-dark" type="button" id="dtrPrintBackBtn" title="Print the back page with the CS Form 48 instructions">Print Back</button>
                        <a class="btn btn-outline-primary disabled" id="dtrPdfBtn" href="#" role="button" aria-disabled="true">Download PDF</a>
                    </div>
                </div>
                <div class="card-body">
                    <input type="hidden" id="dtrUserId">
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" id="dtrDateFrom">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" id="dtrDateTo">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <button class="btn btn-outline-secondary w-100" type="button" id="dtrClearDatesBtn">Clear Dates</button>
                        </div>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label">In-charge Name <span class="text-muted">(for print/PDF)</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="dtrInChargeName" placeholder="e.g. Juan Dela Cruz" data-admin-id="<?php echo (int)$sessionUserId; ?>">
                                <button type="button" class="btn btn-outline-success" id="dtrSaveInChargeBtn"><i class="feather-check"></i> Save</button>
                            </div>
                            <div class="form-text">Customize the name shown as "In-charge" at the bottom of the DTR. Leave blank to use your account name.</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="dtrPreviewEmpty" class="text-muted">Select an employee then click View DTR.</div>
                    <div id="dtrPreview" style="display:none;">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="width:140px;">Date</th>
                                    <th style="width:140px;">AM In</th>
                                    <th style="width:140px;">AM Out</th>
                                    <th style="width:140px;">PM In</th>
                                    <th style="width:140px;">PM Out</th>
                                    <th style="width:120px;">Actions</th>
                                </tr>
                                </thead>
                                <tbody id="dtrRows"></tbody>
                            </table>
        </div>
    </div>
    <?php if (!empty($_SESSION['admin_needs_plan_request'])): ?>
    <div class="ctr-lock-overlay">
        <div class="text-center p-4">
            <div style="font-size:42px;margin-bottom:8px;">&#128274;</div>
            <div class="fw-bold text-muted" style="font-size:14px;">Content locked — plan required</div>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dtrEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="dtrEditForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit DTR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="dtrEditDate">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="text" class="form-control" id="dtrEditDateLabel" readonly>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">AM In</label>
                            <input type="time" class="form-control" id="dtrEditAmIn">
                        </div>
                        <div class="col-6">
                            <label class="form-label">AM Out</label>
                            <input type="time" class="form-control" id="dtrEditAmOut">
                        </div>
                        <div class="col-6">
                            <label class="form-label">PM In</label>
                            <input type="time" class="form-control" id="dtrEditPmIn">
                        </div>
                        <div class="col-6">
                            <label class="form-label">PM Out</label>
                            <input type="time" class="form-control" id="dtrEditPmOut">
                        </div>
                    </div>
                    <div class="form-text mt-2">Times use 24-hour format (HH:MM).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="dtrEditSaveBtn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$body = ob_get_clean();
$etag = ctr_etag_from($body);
ctr_handle_conditional($etag, 30);
echo $body;
?>

