<?php
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from CLI.\n");
}

// Enable error reporting during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required libraries
require_once('TCPDF/tcpdf.php');
require_once('PHPMailer/src/PHPMailer.php');
require_once('PHPMailer/src/Exception.php');
require_once('PHPMailer/src/SMTP.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// Load INI config
$config = parse_ini_file('elearning.ini', true);

// Matomo/API setup
$matomo = $config['matomoURL'];
$auth   = $config['tokenAuth'];
$idsite = 2;
$period = 'week';
$date   = date('Y-m-d', strtotime('-1 day'));
if (isset($_GET['date'])) {
    $date = $_GET['date'];
}

// ─── TABLE HELPERS ───────────────────────────────────────────────────────────

define('TH_BG',    '#000000');
define('TH_COLOR', '#ffffff');
define('ROW_ODD',  '#ffffff');
define('ROW_EVEN', '#eaf4fb');
define('ROW_TOTAL','#e9ecef');

function tableStart($theadHtml) {
    // FIX #2: Table is no longer 100% width — uses 97% with auto margins to leave breathing room on both sides
    return '<table border="0" cellpadding="8" cellspacing="0" width="97%"
                style="border-collapse:collapse;
                       border:1px solid #dee2e6;
                       text-align:center;
                       font-family:helvetica;
                       margin-left:auto;
                       margin-right:auto;">
        <thead>' . $theadHtml . '</thead>
        <tbody>';
}

function tableEnd() {
    return '</tbody></table>';
}

function rowBg(int $i): string {
    return ROW_ODD;
}

function tdStyle(string $extra = ''): string {
    return 'padding:8px; border:1px solid #dee2e6; color:#212529;' . $extra;
}

function thStyle(string $extra = ''): string {
    return 'padding:10px; border:1px solid #dee2e6;' . $extra;
}

function evolutionColorSpan($evoValue) {
    $trimmed = str_replace(['+', '%'], '', $evoValue);
    if ($trimmed === '0' || $evoValue === '0%') {
        $color = '#6c757d';
    } elseif ($evoValue[0] === '-') {
        $color = '#dc3545';
    } else {
        $color = '#198754';
    }
    return '<span style="color:' . $color . '; font-weight:bold;">' . $evoValue . '</span>';
}

function sectionTitle(string $text): string {
    return '<h2 style="text-align:left; margin:0 0 4px 0; color:#343a40;
                       font-size:15px; border-bottom:2px solid #dee2e6;
                       padding-bottom:4px; padding-left:2px;">'
           . htmlspecialchars($text) . '</h2>';
}

// ─── PERIOD HELPERS ──────────────────────────────────────────────────────────

function previousPeriod($date, $period) {
    if (strtolower($period) === 'week') {
        // Use ISO-8601 day of week (1 = Monday, 7 = Sunday) to correctly
        // find the Monday of the current week for any given date, including Sundays.
        $ts         = strtotime($date);
        $dayOfWeek  = (int)date('N', $ts); // 1 (Mon) .. 7 (Sun)
        $startTs    = strtotime('-' . ($dayOfWeek - 1) . ' days', $ts); // this week's Monday
        $prevWeekTs = strtotime('-7 days', $startTs);                   // previous week's Monday
        return date('Y-m-d', $prevWeekTs);
    } elseif (strtolower($period) === 'month') {
        $first = date('Y-m-01', strtotime($date));
        return date('Y-m-d', strtotime('-1 month', strtotime($first)));
    }
    return date('Y-m-d', strtotime('-1 day', strtotime($date)));
}

function calculateEvolution($current, $previous) {
    $previous = $previous ?? 0;
    $current  = $current  ?? 0;
    if ($previous == 0) {
        return $current > 0 ? '+100%' : '0%';
    }
    $evo = ($current - $previous) / max(abs((float)$previous), 1) * 100;
    $evo = max(-100, min(100, $evo));
    return ($evo >= 0 ? '+' : '') . round($evo, 1) . '%';
}

// ─── FETCH VISITS SUMMARY DATA (used for dynamic cards on page 2) ─────────────

function fetchVisitsSummary($matomo, $auth, $idsite, $period, $date) {
    $raw = @file_get_contents("$matomo/index.php?module=API&method=VisitsSummary.get&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth&flat=1&expanded=0");
    $data = ($raw !== false) ? (@json_decode($raw, true) ?? []) : [];
    if (isset($data[0])) $data = $data[0];

    $g = function($arr, $key) {
        if (!isset($arr[$key])) return 0;
        $v = str_replace('%', '', $arr[$key]);
        return is_numeric($v) ? (float)$v : 0;
    };

    return [
        'nb_visits'        => (int)$g($data, 'nb_visits'),
        'nb_actions'       => (int)$g($data, 'nb_actions'),
        'bounce_rate'      => $g($data, 'bounce_rate'),
        'avg_time_on_site' => (int)$g($data, 'avg_time_on_site'),
    ];
}

// ─── KPI CARDS: VISITS INSIGHTS ──────────────────────────────────────────────
// Replaces the old table with 7 individual KPI cards (4 top row + 3 bottom row)
// plus an Evolution summary strip below.

// ─── VISITS INSIGHTS: Moved to Page 2 as native KPI cards ────────────────────
// Data is fetched by fetchPage2KPIs() below and rendered directly via TCPDF
// native drawing calls on Page 2. No HTML table version needed on Page 3.

// ─── TABLE: CHANNEL PERFORMANCE ──────────────────────────────────────────────

function channelPerformanceTable($matomo, $auth, $idsite, $period, $date) {
    $prev_date = previousPeriod($date, $period);
    $goals = "1,3,2";

    $resp     = @file_get_contents("$matomo/index.php?module=API&format=JSON&idSite=$idsite&period=$period&date=$date&method=Referrers.getReferrerType&flat=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals&expanded=1&idGoal=0&showMetadata=0&token_auth=$auth");
    $respPrev = @file_get_contents("$matomo/index.php?module=API&format=JSON&idSite=$idsite&period=$period&date=$prev_date&method=Referrers.getReferrerType&flat=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals&expanded=1&idGoal=0&showMetadata=0&token_auth=$auth");

    if ($resp === false) return "<div style='color:#dc3545;padding:10px;text-align:center;'>No Channel Performance data available.</div>";

    $data     = json_decode($resp,     true) ?? [];
    $dataPrev = $respPrev !== false ? (json_decode($respPrev, true) ?? []) : [];

    $prevMap = [];
    foreach ($dataPrev as $r) { if (isset($r['label'])) $prevMap[$r['label']] = $r; }

    $thead = '<tr style="background-color:' . TH_BG . ';color:' . TH_COLOR . ';font-weight:bold;">
        <th style="' . thStyle() . '">Channels</th>
        <th style="' . thStyle() . '">Total Visits</th>
        <th style="' . thStyle() . '">Unique Visitors</th>
        <th style="' . thStyle() . '">Course Views</th>
        <th style="' . thStyle() . '">Course Purchased</th>
        <th style="' . thStyle() . '">Contact Form</th>
        <th style="' . thStyle() . '">Evolution</th>
    </tr>';

    $html  = sectionTitle('Channel Performance Overview');
    $html .= tableStart($thead);

    $rows = [];
    $tV = $tU = $tCV = $tCP = $tCF = $tPV = 0;

    foreach ($data as $ch) {
        if (!isset($ch['label'])) continue;
        $label = $ch['label'];
        $nb_v  = (int)($ch['nb_visits']                  ?? 0);
        $nb_u  = (int)($ch['sum_daily_nb_uniq_visitors'] ?? 0);
        $cv    = (int)($ch['goal_3_nb_conversions']       ?? 0);
        $cp    = (int)($ch['goal_2_nb_conversions']       ?? 0);
        $cf    = (int)($ch['goal_1_nb_conversions']       ?? 0);
        $prev  = $prevMap[$label] ?? [];
        $pv    = (int)($prev['nb_visits'] ?? 0);

        if ($nb_v + $nb_u + $cv + $cp + $cf == 0 && $pv == 0) continue;

        $rows[] = compact('label', 'nb_v', 'nb_u', 'cv', 'cp', 'cf', 'pv');
        $tV += $nb_v; $tU += $nb_u; $tCV += $cv; $tCP += $cp; $tCF += $cf; $tPV += $pv;
    }

    if (empty($rows)) {
        $html .= '<tr><td colspan="7" style="' . tdStyle('text-align:center;color:#6c757d;padding:15px;') . '">No Channel Performance data available for this period.</td></tr>';
    } else {
        foreach ($rows as $i => $rw) {
            $evo = calculateEvolution($rw['nb_v'], $rw['pv']);
            $html .= '<tr style="background-color:' . rowBg($i) . ';">
                <td style="' . tdStyle() . '">' . htmlspecialchars($rw['label']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['nb_v'])     . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['nb_u'])     . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cv'])       . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cp'])       . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cf'])       . '</td>
                <td style="' . tdStyle() . '">' . evolutionColorSpan($evo)       . '</td>
            </tr>';
        }
        $html .= '<tr style="background-color:' . ROW_TOTAL . ';font-weight:bold;">
            <td style="' . tdStyle() . '">Total</td>
            <td style="' . tdStyle() . '">' . number_format($tV)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tU)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCV) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCP) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCF) . '</td>
            <td style="' . tdStyle() . '">' . evolutionColorSpan(calculateEvolution($tV, $tPV)) . '</td>
        </tr>';
    }

    $html .= tableEnd();
    return $html;
}

// ─── TABLE: COUNTRY ──────────────────────────────────────────────────────────

function countryTable($matomo, $auth, $idsite, $period, $date) {
    $prev_date = previousPeriod($date, $period);
    $goals = "1,3,2";

    $resp     = @file_get_contents("$matomo/index.php?module=API&method=UserCountry.getCountry&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth&flat=0&expanded=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals");
    $respPrev = @file_get_contents("$matomo/index.php?module=API&method=UserCountry.getCountry&idSite=$idsite&period=$period&date=$prev_date&format=JSON&token_auth=$auth&flat=0&expanded=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals");

    if ($resp === false) return "<div style='color:#dc3545;padding:10px;text-align:center;'>No Country data available.</div>";

    $data     = json_decode($resp,     true) ?? [];
    $dataPrev = $respPrev !== false ? (json_decode($respPrev, true) ?? []) : [];

    $prevMap = [];
    foreach ($dataPrev as $r) { if (isset($r['label'])) $prevMap[$r['label']] = $r; }

    usort($data, fn($a,$b) => ($b['nb_visits'] ?? 0) <=> ($a['nb_visits'] ?? 0));
    $data = array_slice($data, 0, 10);

    $thead = '<tr style="background-color:' . TH_BG . ';color:' . TH_COLOR . ';font-weight:bold;">
        <th style="' . thStyle() . '">Country</th>
        <th style="' . thStyle() . '">Total Visits</th>
        <th style="' . thStyle() . '">Unique Visitors</th>
        <th style="' . thStyle() . '">Course Views</th>
        <th style="' . thStyle() . '">Course Purchased</th>
        <th style="' . thStyle() . '">Contact Form</th>
        <th style="' . thStyle() . '">Evolution</th>
    </tr>';

    $html  = sectionTitle('Visitors Insights by Country');
    $html .= tableStart($thead);

    $rows = [];
    $tV = $tU = $tCV = $tCP = $tCF = $tPV = 0;

    foreach ($data as $row) {
        if (!is_array($row) || !isset($row['label'])) continue;
        $label = $row['label'];
        $v     = (int)($row['nb_visits'] ?? 0);
        $u     = (int)($row['sum_daily_nb_uniq_visitors'] ?? 0);
        $ga    = isset($row['goals']) && is_array($row['goals']) ? $row['goals'] : [];
        $cf    = (int)($ga['idgoal=1']['nb_conversions'] ?? 0);
        $cp    = (int)($ga['idgoal=2']['nb_conversions'] ?? 0);
        $cv    = (int)($ga['idgoal=3']['nb_conversions'] ?? 0);
        $prev  = $prevMap[$label] ?? [];
        $pv    = (int)($prev['nb_visits'] ?? 0);

        if ($v + $u + $cv + $cp + $cf == 0 && $pv == 0) continue;

        $rows[] = compact('label','v','u','cv','cp','cf','pv');
        $tV += $v; $tU += $u; $tCV += $cv; $tCP += $cp; $tCF += $cf; $tPV += $pv;
    }

    if (empty($rows)) {
        $html .= '<tr><td colspan="7" style="' . tdStyle('text-align:center;color:#6c757d;padding:15px;') . '">No Country data available for this period.</td></tr>';
    } else {
        foreach ($rows as $i => $rw) {
            $evo = calculateEvolution($rw['v'], $rw['pv']);
            $html .= '<tr style="background-color:' . rowBg($i) . ';">
                <td style="' . tdStyle() . '">' . htmlspecialchars($rw['label']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['v'])  . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['u'])  . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cv']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cp']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cf']) . '</td>
                <td style="' . tdStyle() . '">' . evolutionColorSpan($evo)  . '</td>
            </tr>';
        }
        $html .= '<tr style="background-color:' . ROW_TOTAL . ';font-weight:bold;">
            <td style="' . tdStyle() . '">Total</td>
            <td style="' . tdStyle() . '">' . number_format($tV)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tU)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCV) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCP) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCF) . '</td>
            <td style="' . tdStyle() . '">' . evolutionColorSpan(calculateEvolution($tV, $tPV)) . '</td>
        </tr>';
    }

    $html .= tableEnd();
    return $html;
}

// ─── TABLE: BROWSERS ─────────────────────────────────────────────────────────

function browsersTable($matomo, $auth, $idsite, $period, $date) {
    $prev_date = previousPeriod($date, $period);
    $goals = "1,3,2";

    $resp     = @file_get_contents("$matomo/index.php?module=API&method=DevicesDetection.getBrowsers&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth&flat=0&expanded=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals");
    $respPrev = @file_get_contents("$matomo/index.php?module=API&method=DevicesDetection.getBrowsers&idSite=$idsite&period=$period&date=$prev_date&format=JSON&token_auth=$auth&flat=0&expanded=0&filter_update_columns_when_show_all_goals=0&filter_show_goal_columns_process_goals=$goals");

    if ($resp === false) return "<div style='color:#dc3545;padding:10px;text-align:center;'>No Browsers data available.</div>";

    $data     = json_decode($resp,     true) ?? [];
    $dataPrev = $respPrev !== false ? (json_decode($respPrev, true) ?? []) : [];

    $prevMap = [];
    foreach ($dataPrev as $r) { if (isset($r['label'])) $prevMap[$r['label']] = $r; }

    usort($data, function($a, $b) {
        $score = function($x) {
            $ga = isset($x['goals']) && is_array($x['goals']) ? $x['goals'] : [];
            $c  = (int)($ga['idgoal=1']['nb_conversions'] ?? 0)
                + (int)($ga['idgoal=2']['nb_conversions'] ?? 0)
                + (int)($ga['idgoal=3']['nb_conversions'] ?? 0);
            return ($x['nb_visits'] ?? 0) + $c;
        };
        $diff = $score($b) - $score($a);
        return $diff !== 0 ? $diff : ($b['nb_visits'] ?? 0) <=> ($a['nb_visits'] ?? 0);
    });
    $data = array_slice($data, 0, 10);

    $thead = '<tr style="background-color:' . TH_BG . ';color:' . TH_COLOR . ';font-weight:bold;">
        <th style="' . thStyle() . '">Browser</th>
        <th style="' . thStyle() . '">Total Visits</th>
        <th style="' . thStyle() . '">Unique Visitors</th>
        <th style="' . thStyle() . '">Course Views</th>
        <th style="' . thStyle() . '">Course Purchased</th>
        <th style="' . thStyle() . '">Contact Form</th>
        <th style="' . thStyle() . '">Evolution</th>
    </tr>';

    $html  = sectionTitle('Visitors Insights by Browser');
    $html .= tableStart($thead);

    $rows = [];
    $tV = $tU = $tCV = $tCP = $tCF = $tPV = 0;

    foreach ($data as $row) {
        if (!is_array($row) || !isset($row['label'])) continue;
        $label = $row['label'];
        $v     = (int)($row['nb_visits'] ?? 0);
        $u     = (int)($row['sum_daily_nb_uniq_visitors'] ?? 0);
        $ga    = isset($row['goals']) && is_array($row['goals']) ? $row['goals'] : [];
        $cf    = (int)($ga['idgoal=1']['nb_conversions'] ?? 0);
        $cp    = (int)($ga['idgoal=2']['nb_conversions'] ?? 0);
        $cv    = (int)($ga['idgoal=3']['nb_conversions'] ?? 0);
        $prev  = $prevMap[$label] ?? [];
        $pv    = (int)($prev['nb_visits'] ?? 0);

        if ($v + $u + $cv + $cp + $cf == 0 && $pv == 0) continue;

        $rows[] = compact('label','v','u','cv','cp','cf','pv');
        $tV += $v; $tU += $u; $tCV += $cv; $tCP += $cp; $tCF += $cf; $tPV += $pv;
    }

    if (empty($rows)) {
        $html .= '<tr><td colspan="7" style="' . tdStyle('text-align:center;color:#6c757d;padding:15px;') . '">No Browsers data available for this period.</td></tr>';
    } else {
        foreach ($rows as $i => $rw) {
            $evo = calculateEvolution($rw['v'], $rw['pv']);
            $html .= '<tr style="background-color:' . rowBg($i) . ';">
                <td style="' . tdStyle() . '">' . htmlspecialchars($rw['label']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['v'])  . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['u'])  . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cv']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cp']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($rw['cf']) . '</td>
                <td style="' . tdStyle() . '">' . evolutionColorSpan($evo)  . '</td>
            </tr>';
        }
        $html .= '<tr style="background-color:' . ROW_TOTAL . ';font-weight:bold;">
            <td style="' . tdStyle() . '">Total</td>
            <td style="' . tdStyle() . '">' . number_format($tV)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tU)  . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCV) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCP) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tCF) . '</td>
            <td style="' . tdStyle() . '">' . evolutionColorSpan(calculateEvolution($tV, $tPV)) . '</td>
        </tr>';
    }

    $html .= tableEnd();
    return $html;
}

// ─── TABLE: PAGEVIEWS ────────────────────────────────────────────────────────

function pageviewsTable($matomo, $auth, $idsite, $period, $date) {
    $resp = @file_get_contents("$matomo/index.php?module=API&method=Actions.getEntryPageUrls&idSite=$idsite&period=$period&date=$date&flat=1&format=JSON&token_auth=$auth&force_api_session=1&filter_limit=100");

    if ($resp === false) return "<div style='color:#dc3545;padding:10px;text-align:center;'>No pageview data available.</div>";

    $data = json_decode($resp, true) ?? [];

    $thead = '<tr style="background-color:' . TH_BG . ';color:' . TH_COLOR . ';font-weight:bold;">
        <th style="' . thStyle('width:75%;') . '">URLs</th>
        <th style="' . thStyle('width:25%;') . '">Pageviews</th>
    </tr>';

    $html  = sectionTitle('Top 10 Best Performing Pages');
    $html .= tableStart($thead);

    $urls = [];
    foreach ($data as $page) {
        if (is_array($page) && isset($page['url'])) {
            $urls[] = ['url' => $page['url'], 'nb_hits' => $page['nb_hits']];
        }
    }
    usort($urls, fn($a,$b) => $b['nb_hits'] - $a['nb_hits']);

    if (empty($urls)) {
        $html .= '<tr><td colspan="2" style="' . tdStyle('text-align:center;color:#6c757d;padding:15px;') . '">No pageview data to show.</td></tr>';
    } else {
        $total = 0;
        foreach (array_slice($urls, 0, 10) as $i => $u) {
            $html .= '<tr style="background-color:' . rowBg($i) . ';">
                <td style="' . tdStyle('word-break:break-all;text-align:left;') . '">' . htmlspecialchars($u['url']) . '</td>
                <td style="' . tdStyle() . '">' . number_format($u['nb_hits']) . '</td>
            </tr>';
            $total += $u['nb_hits'];
        }
        $html .= '<tr style="background-color:' . ROW_TOTAL . ';font-weight:bold;">
            <td style="' . tdStyle() . '">Total</td>
            <td style="' . tdStyle() . '">' . number_format($total) . '</td>
        </tr>';
    }

    $html .= tableEnd();
    return $html;
}

// ─── TABLE: COURSE PERFORMANCE ───────────────────────────────────────────────

function coursePerformanceTable($matomo, $auth, $idsite, $period, $date) {
    $resp = @file_get_contents("$matomo/index.php?module=API&method=Events.getCategory&expanded=1&showMetadata=0&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth");
    $data = ($resp !== false) ? (json_decode($resp, true) ?? []) : [];

    $courses = [];

    foreach ($data as $cat) {
        if (!isset($cat['label']) || !is_array($cat['subtable'])) continue;
        $eventCat = $cat['label'];

        foreach ($cat['subtable'] as $ev) {
            if (!isset($ev['label'])) continue;
            $id = trim($ev['label']);

            // ── CASE 1: action label IS already the course ID (e.g. "Course Id: 42") ──
            // This covers the "Course Details Click Tracking" category where the
            // action label itself contains the course identifier.
            if ($id !== '' && strpos($id, 'Course Id:') === 0) {
                if (!isset($courses[$id])) $courses[$id] = ['views' => 0, 'purchases' => 0];
                $nb = (int)($ev['nb_events'] ?? 0);
                if ($eventCat === 'Course Details Click Tracking') $courses[$id]['views']     += $nb;
                elseif ($eventCat === 'Course Purchased')          $courses[$id]['purchases'] += $nb;
                continue;
            }

            // ── CASE 2: action label is NOT a course ID (e.g. "Course_successfully_purchased") ──
            // The course ID lives one level deeper in the event Name subtable.
            // We must recurse into $ev['subtable'] to find "Course Id: X" entries.
            if (!isset($ev['subtable']) || !is_array($ev['subtable'])) continue;

            foreach ($ev['subtable'] as $nameRow) {
                if (!isset($nameRow['label'])) continue;
                $subId = trim($nameRow['label']);
                if ($subId === '' || strpos($subId, 'Course Id:') !== 0) continue;

                if (!isset($courses[$subId])) $courses[$subId] = ['views' => 0, 'purchases' => 0];
                $nb = (int)($nameRow['nb_events'] ?? 0);
                if ($eventCat === 'Course Details Click Tracking') $courses[$subId]['views']     += $nb;
                elseif ($eventCat === 'Course Purchased')          $courses[$subId]['purchases'] += $nb;
            }
        }
    }

    foreach ($courses as $k => $v) { if ($v['views'] <= 0 && $v['purchases'] <= 0) unset($courses[$k]); }
    uasort($courses, fn($a,$b) => $b['views'] !== $a['views'] ? $b['views'] - $a['views'] : $b['purchases'] - $a['purchases']);
    $courses = array_slice($courses, 0, 10, true);

    $thead = '<tr style="background-color:' . TH_BG . ';color:' . TH_COLOR . ';font-weight:bold;">
        <th style="' . thStyle() . '">Course ID</th>
        <th style="' . thStyle() . '">Course Views</th>
        <th style="' . thStyle() . '">Course Purchase</th>
    </tr>';

    $html  = sectionTitle('Top Performing Courses by Views and Purchases');
    $html .= tableStart($thead);

    if (empty($courses)) {
        $html .= '<tr><td colspan="3" style="' . tdStyle('text-align:center;color:#6c757d;padding:15px;') . '">No course data available for this period.</td></tr>';
    } else {
        $tV = $tP = 0;
        $i  = 0;
        foreach ($courses as $courseId => $vals) {
            $html .= '<tr style="background-color:' . rowBg($i) . ';">
                <td style="' . tdStyle() . '">' . htmlspecialchars($courseId)       . '</td>
                <td style="' . tdStyle() . '">' . number_format($vals['views'])     . '</td>
                <td style="' . tdStyle() . '">' . number_format($vals['purchases']) . '</td>
            </tr>';
            $tV += $vals['views'];
            $tP += $vals['purchases'];
            $i++;
        }
        $html .= '<tr style="background-color:' . ROW_TOTAL . ';font-weight:bold;">
            <td style="' . tdStyle() . '">Total</td>
            <td style="' . tdStyle() . '">' . number_format($tV) . '</td>
            <td style="' . tdStyle() . '">' . number_format($tP) . '</td>
        </tr>';
    }

    $html .= tableEnd();
    return $html;
}

// ─── EMAIL / SMTP CONFIG ─────────────────────────────────────────────────────

$smtp_user       = $config['smtp_email'];
$smtp_pass       = $config['smtp_pass'];
$smtp_name       = $config['smtp_name'];
$smtp_server     = $config['smtp_server'];
$smtp_port       = $config['smtp_port'];
$smtp_encryption = $config['smtp_encryption'];

// ─── PERIOD LABELS ───────────────────────────────────────────────────────────

if ($period === 'month') {
    $periodLabel    = date('F Y', strtotime($date));
    $periodSubLabel = date('Y-m-01', strtotime($date)) . '  –  ' . date('Y-m-t', strtotime($date));
} elseif ($period === 'week') {
    $periodLabel    = 'Week of ' . date('M d, Y', strtotime('monday this week', strtotime($date)));
    $periodSubLabel = date('Y-m-d', strtotime('monday this week', strtotime($date)))
                    . '  –  '
                    . date('Y-m-d', strtotime('sunday this week', strtotime($date)));
} else {
    $periodLabel    = date('F d, Y', strtotime($date));
    $periodSubLabel = $date;
}

// ─── FETCH DYNAMIC DATA FOR PAGE 2 CARDS (all 7 KPIs + evolution) ───────────

function fetchPage2KPIs($matomo, $auth, $idsite, $period, $date) {
    $prev_date = previousPeriod($date, $period);

    // Visits summary — current & previous
    $cRaw = @file_get_contents("$matomo/index.php?module=API&method=VisitsSummary.get&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth&flat=1&expanded=0");
    $pRaw = @file_get_contents("$matomo/index.php?module=API&method=VisitsSummary.get&idSite=$idsite&period=$period&date=$prev_date&format=JSON&token_auth=$auth&flat=1&expanded=0");
    $curr = $cRaw !== false ? (@json_decode($cRaw, true) ?? []) : [];
    $prev = $pRaw !== false ? (@json_decode($pRaw, true) ?? []) : [];
    if (isset($curr[0])) $curr = $curr[0];
    if (isset($prev[0])) $prev = $prev[0];

    $g = function($arr, $key) {
        if (!isset($arr[$key])) return 0;
        $v = str_replace('%', '', $arr[$key]);
        return is_numeric($v) ? (float)$v : 0;
    };

    // Goals — current & previous
    $goals = [1 => 'Contact Form', 2 => 'Course Purchased', 3 => 'Course Views'];
    $cGoals = []; $pGoals = [];
    foreach ([1,2,3] as $gid) {
        $gcRaw = @file_get_contents("$matomo/index.php?module=API&method=Goals.get&idSite=$idsite&period=$period&date=$date&format=JSON&token_auth=$auth&idGoal=$gid");
        $gpRaw = @file_get_contents("$matomo/index.php?module=API&method=Goals.get&idSite=$idsite&period=$period&date=$prev_date&format=JSON&token_auth=$auth&idGoal=$gid");
        $gcA = $gcRaw !== false ? (@json_decode($gcRaw, true) ?? []) : [];
        $gpA = $gpRaw !== false ? (@json_decode($gpRaw, true) ?? []) : [];
        if (isset($gcA[0])) $gcA = $gcA[0];
        if (isset($gpA[0])) $gpA = $gpA[0];
        $cGoals[$gid] = (float)($gcA['nb_conversions'] ?? 0);
        $pGoals[$gid] = (float)($gpA['nb_conversions'] ?? 0);
    }

    // Build the 7 KPI definitions
    $kpis = [
        [
            'label'   => 'Total Visits',
            'value'   => number_format((int)$g($curr, 'nb_visits')),
            'rawVal'  => $g($curr, 'nb_visits'),
            'rawPrev' => $g($prev, 'nb_visits'),
            'showEvo' => true,
        ],
        [
            'label'   => 'Total Actions',
            'value'   => number_format((int)$g($curr, 'nb_actions')),
            'rawVal'  => $g($curr, 'nb_actions'),
            'rawPrev' => $g($prev, 'nb_actions'),
            'showEvo' => true,
        ],
        [
            'label'   => 'Bounce Rate',
            'value'   => round($g($curr, 'bounce_rate'), 1) . '%',
            'rawVal'  => $g($curr, 'bounce_rate'),
            'rawPrev' => $g($prev, 'bounce_rate'),
            'showEvo' => true,
        ],
        [
            'label'   => 'Avg. Time on Site',
            'value'   => gmdate('i:s', (int)$g($curr, 'avg_time_on_site')),
            'rawVal'  => 0,
            'rawPrev' => 0,
            'showEvo' => false,
        ],
        [
            'label'   => 'Course Views',
            'value'   => number_format((int)$cGoals[3]),
            'rawVal'  => $cGoals[3],
            'rawPrev' => $pGoals[3],
            'showEvo' => true,
        ],
        [
            'label'   => 'Course Purchased',
            'value'   => number_format((int)$cGoals[2]),
            'rawVal'  => $cGoals[2],
            'rawPrev' => $pGoals[2],
            'showEvo' => true,
        ],
        [
            'label'   => 'Contact Form',
            'value'   => number_format((int)$cGoals[1]),
            'rawVal'  => $cGoals[1],
            'rawPrev' => $pGoals[1],
            'showEvo' => true,
        ],
    ];

    // Pre-compute evolution strings
    foreach ($kpis as &$kpi) {
        $kpi['evo'] = $kpi['showEvo']
            ? calculateEvolution($kpi['rawVal'], $kpi['rawPrev'])
            : '—';
    }
    unset($kpi);

    return $kpis;
}

$page2KPIs = fetchPage2KPIs($matomo, $auth, $idsite, $period, $date);

// ─── CUSTOM TCPDF ────────────────────────────────────────────────────────────

class ReportPDF extends TCPDF {
    public $reportTitle    = '';
    public $periodLabel    = '';
    public $periodSubLabel = '';
    public $smtpName       = '';

    public function Header() {
        // Only draw header from page 3 onward
        if ($this->getPage() < 3) return;
        $this->drawContentHeader();
    }

    public function drawContentHeader() {
        $w = $this->getPageWidth();

        // Black banner full width
        $bannerH = 28;
        $this->SetFillColor(0, 0, 0);
        $this->Rect(0, 0, $w, $bannerH, 'F');

        // Gold accent line under banner
        $this->SetFillColor(180, 140, 60);
        $this->Rect(0, $bannerH, $w, 0.8, 'F');

        // Left: small label
        $this->SetTextColor(160, 160, 160);
        $this->SetFont('helvetica', '', 6.5);
        $this->SetXY(12, 4);
        $this->Cell(0, 4, 'Weekly PERFORMANCE REPORT', 0, 1, 'L');

        // Left: report title
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetXY(12, 10);
        $this->Cell(120, 7, $this->reportTitle, 0, 0, 'L');

        // Right: period label
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(160, 160, 160);
        $tw = 70;
        $this->SetXY($w - $tw - 10, 4);
        $this->Cell($tw, 4, 'REPORTING PERIOD', 0, 1, 'R');
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY($w - $tw - 10, 10);
        $this->Cell($tw, 7, $this->periodLabel, 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
    }

    public function Footer() {
        if ($this->getPage() === 1) return;

        $w = $this->getPageWidth();
        $this->SetY(-14);

        $this->SetFillColor(220, 220, 220);
        $this->Rect(0, $this->GetY() - 1, $w, 0.4, 'F');

        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(150, 150, 150);

        $this->SetX(12);
        $this->Cell(80, 8, $this->smtpName . '  •  eLearning Analytics', 0, 0, 'L');

        $this->SetX(0);
        $this->Cell($w, 8, 'Generated on ' . date('F j, Y'), 0, 0, 'C');

        $this->SetX($w - 45);
        $this->Cell(35, 8, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

// ─── GENERATE PDF ────────────────────────────────────────────────────────────

$pdf = new ReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->reportTitle    = $config['title'];
$pdf->periodLabel    = $periodLabel;
$pdf->periodSubLabel = $periodSubLabel;
$pdf->smtpName       = $smtp_name;

$pdf->SetCreator($smtp_name);
$pdf->SetAuthor($smtp_name);
$pdf->SetTitle($config['title']);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetAutoPageBreak(true, 20);

// ═══════════════════════════════════════════════════════════════
// ── PAGE 1: COVER PAGE
// ═══════════════════════════════════════════════════════════════
$pdf->SetMargins(0, 0, 0, true);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage();

$w = $pdf->getPageWidth();
$h = $pdf->getPageHeight();

$topSectionHeight = $h * 0.38;

$bgFile = null;
foreach (['cover_bg.png', 'cover_bg.jpg', 'cover_bg.jpeg'] as $bgName) {
    $tryPath = __DIR__ . DIRECTORY_SEPARATOR . $bgName;
    if (file_exists($tryPath) && is_readable($tryPath)) {
        $bgFile = $tryPath;
        break;
    }
}

if ($bgFile !== null) {
    $imgType = strtoupper(pathinfo($bgFile, PATHINFO_EXTENSION));
    if ($imgType === 'JPG') $imgType = 'JPEG';
    $pdf->Image($bgFile, 0, 0, $w, $topSectionHeight, $imgType, '', 'T', true, 150, '', false, false, 0, 'CT', false, false);
    $pdf->SetAlpha(0.30, 'Normal');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $w, $topSectionHeight, 'F');
    $pdf->SetAlpha(1, 'Normal');
} else {
    $pdf->SetFillColor(15, 25, 50);
    $pdf->Rect(0, 0, $w, $topSectionHeight, 'F');
}

$pdf->SetAlpha(0.5, 'Normal');
$pdf->SetFillColor(0, 0, 0);
$pdf->Rect(0, $topSectionHeight - 42, $w, 42, 'F');
$pdf->SetAlpha(1, 'Normal');

$pdf->SetFont('helvetica', 'B', 52);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(0, $topSectionHeight - 38);
$pdf->Cell($w, 32, 'REPORT', 0, 1, 'C', false, '', 1);

$bandY      = $topSectionHeight;
$bandHeight = 36;
$pdf->SetFillColor(0, 0, 0);
$pdf->Rect(0, $bandY, $w, $bandHeight, 'F');

$whiteBandH = 26;
$whiteBandY = $bandY + (($bandHeight - $whiteBandH) / 2);
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(0, $whiteBandY, $w, $whiteBandH, 'F');

$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetTextColor(20, 20, 20);
$stretched = implode('  ', str_split('Weekly REPORT'));
$pdf->SetXY(0, $whiteBandY + (($whiteBandH - 12) / 2));
$pdf->Cell($w, 12, $stretched, 0, 1, 'C', false, '', 1);

$lowerY = $bandY + $bandHeight;
$pdf->SetFillColor(0, 0, 0);
$pdf->Rect(0, $lowerY, $w, $h - $lowerY, 'F');

$pdf->SetTextColor(120, 120, 120);
$pdf->SetFont('helvetica', '', 11);
$dateSpaced = implode(' ', str_split(strtoupper(date('d/m/Y', strtotime($date)))));
$pdf->SetXY(0, $h - 22);
$pdf->Cell($w, 8, $dateSpaced, 0, 1, 'C', false, '', 1);

// ═══════════════════════════════════════════════════════════════
// ── PAGE 2: SUMMARY / INTRO PAGE
// ═══════════════════════════════════════════════════════════════
$pdf->SetMargins(0, 0, 0, true);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(16);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$w = $pdf->getPageWidth();
$h = $pdf->getPageHeight();

$bannerH = 28;
$pdf->SetFillColor(0, 0, 0);
$pdf->Rect(0, 0, $w, $bannerH, 'F');

$pdf->SetFillColor(180, 140, 60);
$pdf->Rect(0, $bannerH, $w, 0.8, 'F');

$pdf->SetTextColor(160, 160, 160);
$pdf->SetFont('helvetica', '', 6.5);
$pdf->SetXY(12, 4);
$pdf->Cell(0, 4, 'Weekly PERFORMANCE REPORT', 0, 1, 'L');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetXY(12, 10);
$pdf->Cell(120, 7, $config['title'], 0, 0, 'L');

$tw = 70;
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(160, 160, 160);
$pdf->SetXY($w - $tw - 10, 4);
$pdf->Cell($tw, 4, 'REPORTING PERIOD', 0, 1, 'R');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY($w - $tw - 10, 10);
$pdf->Cell($tw, 7, $periodLabel, 0, 0, 'R');

$lowerStartY = $bannerH + 0.8;

$pdf->SetFillColor(20, 20, 25);
$pdf->Rect(0, $lowerStartY, $w, $h - $lowerStartY, 'F');

$goldBarY = $lowerStartY + 18;
$pdf->SetFillColor(180, 140, 60);
$pdf->Rect(18, $goldBarY, 4, 55, 'F');

$pdf->SetTextColor(160, 160, 160);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(30, $goldBarY + 2);
$pdf->Cell(0, 6, 'A N A L Y T I C S   R E P O R T', 0, 1, 'L');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetXY(30, $goldBarY + 10);
$pdf->MultiCell($w - 48, 11, $config['title'], 0, 'L', false, 1);

$pdf->SetFillColor(180, 140, 60);
$pdf->Rect(30, $goldBarY + 42, 55, 0.8, 'F');

$pdf->SetTextColor(180, 180, 180);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->SetXY(30, $goldBarY + 48);
$pdf->Cell(0, 5, 'REPORTING PERIOD', 0, 1, 'L');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetXY(30, $goldBarY + 55);
$pdf->Cell(0, 7, $periodLabel, 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(160, 160, 160);
$pdf->SetXY(30, $goldBarY + 64);
$pdf->Cell(0, 5, $periodSubLabel, 0, 1, 'L');

// ── KPI Overview label above cards ───────────────────────────────────────────
$kpiLabelY = $goldBarY + 76;
$pdf->SetTextColor(180, 140, 60);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetXY(10, $kpiLabelY);
$pdf->Cell(0, 5, 'K P I   O V E R V I E W   —   V I S I T S   I N S I G H T S', 0, 1, 'L');

// Thin gold line under the label
$pdf->SetFillColor(180, 140, 60);
$pdf->Rect(10, $kpiLabelY + 5.5, $w - 20, 0.4, 'F');

$cardAreaY = $kpiLabelY + 9;  // start Y for card grid

// ── Helper: determine evolution text colour ───────────────────────────────────
// Returns [R, G, B] and text string for the evolution badge
$evoProps = function($evo) {
    $trimmed = str_replace(['+','%'], '', $evo);
    if ($evo === '—' || $trimmed === '0' || $evo === '0%') {
        return ['color' => [120, 120, 120], 'bg' => [50, 50, 50]];
    } elseif ($evo[0] === '-') {
        return ['color' => [220, 80, 80],  'bg' => [60, 20, 20]];
    } else {
        return ['color' => [80, 200, 100], 'bg' => [20, 55, 25]];
    }
};

// ── Card renderer ─────────────────────────────────────────────────────────────
// Draws one KPI card using TCPDF direct drawing (no writeHTML — more reliable
// for absolute positioning on the dark Page 2 background).
$drawCard = function($kpi, $cx, $cy, $cw, $ch) use ($pdf, $evoProps) {
    $ep = $evoProps($kpi['evo']);

    // Card dark background
    $pdf->SetFillColor(28, 28, 35);
    $pdf->RoundedRect($cx, $cy, $cw, $ch, 2, '1111', 'F');

    // Gold top accent bar
    $pdf->SetFillColor(180, 140, 60);
    $pdf->Rect($cx, $cy, $cw, 1.8, 'F');

    // KPI value — large gold text
    $pdf->SetTextColor(180, 140, 60);
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetXY($cx + 4, $cy + 5);
    $pdf->Cell($cw - 8, 7, $kpi['value'], 0, 0, 'L');

    // Label — small white uppercase
    $pdf->SetTextColor(210, 210, 210);
    $pdf->SetFont('helvetica', 'B', 5.8);
    $pdf->SetXY($cx + 4, $cy + 13);
    $pdf->Cell($cw - 8, 4, strtoupper($kpi['label']), 0, 0, 'L');

    // Thin gold divider line inside card
    $pdf->SetFillColor(180, 140, 60);
    $pdf->Rect($cx + 4, $cy + 19.5, $cw - 8, 0.4, 'F');

    // Evolution badge
    if ($kpi['showEvo']) {
        // Badge background pill
        $ew = $cw - 8;
        $pdf->SetFillColor(...$ep['bg']);
        $pdf->RoundedRect($cx + 4, $cy + 22, $ew, 7.5, 1.5, '1111', 'F');

        // Evolution text coloured
        $pdf->SetTextColor(...$ep['color']);
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->SetXY($cx + 4, $cy + 23.5);
        $pdf->Cell($ew, 4, 'vs prev: ' . $kpi['evo'], 0, 0, 'C');
    } else {
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->SetXY($cx + 4, $cy + 22.5);
        $pdf->Cell($cw - 8, 4, 'No comparison', 0, 0, 'C');
    }
};

// ── Layout: 4 cards on row 1, 3 cards on row 2 ───────────────────────────────
$marginX  = 10;   // page left/right padding
$gapX     = 3;    // horizontal gap between cards
$cardH    = 34;   // card height mm — slightly taller for breathing room
$rowGap   = 5;    // vertical gap between rows

$row1Count = 4;
$row2Count = 3;
$row1W     = ($w - 2*$marginX - ($row1Count-1)*$gapX) / $row1Count;
$row2W     = ($w - 2*$marginX - ($row2Count-1)*$gapX) / $row2Count;

// Row 1 (indices 0–3)
for ($i = 0; $i < $row1Count; $i++) {
    $cx = $marginX + $i * ($row1W + $gapX);
    $drawCard($page2KPIs[$i], $cx, $cardAreaY, $row1W, $cardH);
}

// Row 2 (indices 4–6)
$row2Y = $cardAreaY + $cardH + $rowGap;
for ($i = 0; $i < $row2Count; $i++) {
    $cx = $marginX + $i * ($row2W + $gapX);
    $drawCard($page2KPIs[4 + $i], $cx, $row2Y, $row2W, $cardH);
}

// ═══════════════════════════════════════════════════════════════
// ── PAGE 3+: CONTENT PAGES (tables)
// ═══════════════════════════════════════════════════════════════

// FIX #1: Top margin increased from 29 to 38 to create clear visual separation
//         between the header banner (28mm black + 0.8mm gold) and the table content.
$pdf->SetMargins(15, 38, 15);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(16);
$pdf->SetAutoPageBreak(true, 20);

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);

// ── Helper: write a table block with no-split protection ─────────────────────
// FIX #3: Each table is rendered using TCPDF's transaction mechanism.
//         We attempt to write the table at the current position. If it would
//         overflow the page (i.e. the cursor ends up below the safe zone),
//         we roll back and start a fresh page before rendering.
function writeTableSafe($pdf, $html) {
    // FIX #3: Estimate whether the table will fit in the remaining page space.
    // We count <tr> tags to approximate the rendered height, then add a new
    // page upfront if needed — avoiding the PHP 8 incompatible transaction API.

    $pageH      = $pdf->getPageHeight();
    $safeBottom = $pageH - 20; // 20mm bottom margin/footer zone

    // Rough height estimate (mm)
    $rowCount   = substr_count(strtolower($html), '<tr');
    $estimated  = 10              // section title <h2>
                + 10              // thead row
                + ($rowCount * 11); // ~11mm per data row

    if (($pdf->GetY() + $estimated) > $safeBottom) {
        $pdf->AddPage();
    }

    $pdf->writeHTML($html, true, false, true, false, '');
}

// Build each section's HTML (title + table combined so they stay together)
// NOTE: Visits Insights has been moved to Page 2 as KPI cards — not repeated here.
$sections = [
    channelPerformanceTable($matomo, $auth, $idsite, $period, $date),
    countryTable($matomo, $auth, $idsite, $period, $date),
    browsersTable($matomo, $auth, $idsite, $period, $date),
    pageviewsTable($matomo, $auth, $idsite, $period, $date),
    coursePerformanceTable($matomo, $auth, $idsite, $period, $date),
];

foreach ($sections as $idx => $sectionHtml) {
    if ($idx > 0) {
        $pdf->Ln(5); // spacing between tables
    }
    writeTableSafe($pdf, $sectionHtml);
}

$pdfContent = $pdf->Output('', 'S');

// ─── HTML EMAIL BODY ─────────────────────────────────────────────────────────

$htmlBody = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . htmlspecialchars($config['subject']) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:\'Segoe UI\',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:30px 0;">
    <tr>
      <td align="center">
        <table width="620" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">

          <!-- HEADER -->
          <tr>
            <td style="background-color:#000000;padding:32px 40px;">
              <p style="margin:0;font-size:11px;color:#888888;letter-spacing:2px;text-transform:uppercase;">Weekly Performance</p>
              <h1 style="margin:8px 0 0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">eLearning Analytics Report</h1>
              <p style="margin:10px 0 0;font-size:13px;color:#aaaaaa;">Reporting Period: <strong style="color:#ffffff;">' . $periodSubLabel . '</strong></p>
            </td>
          </tr>

          <!-- GREETING -->
          <tr>
            <td style="padding:36px 40px 10px;">
              <p style="margin:0;font-size:15px;color:#1a1a2e;font-weight:600;">Dear Team,</p>
            </td>
          </tr>

          <!-- INTRO -->
          <tr>
            <td style="padding:12px 40px 24px;">
              <p style="margin:0;font-size:14px;color:#444444;line-height:1.8;">
                We are pleased to share the <strong>eLearning Weekly Performance Report</strong> for
                <strong>' . $periodSubLabel . '</strong>. Please find the full report attached as a PDF for your review.
              </p>
            </td>
          </tr>

          <!-- DIVIDER -->
          <tr><td style="padding:0 40px;"><hr style="border:none;border-top:1px solid #eeeeee;margin:0;"></td></tr>

          <!-- WHAT\'S INSIDE -->
          <tr>
            <td style="padding:28px 40px 10px;">
              <p style="margin:0 0 16px;font-size:13px;font-weight:700;color:#000000;letter-spacing:1px;text-transform:uppercase;">What\'s Inside</p>
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                    <table cellpadding="0" cellspacing="0"><tr>
                      <td style="width:28px;height:28px;background-color:#000000;border-radius:6px;text-align:center;vertical-align:middle;"><span style="color:#ffffff;font-size:13px;font-weight:bold;">&#128200;</span></td>
                      <td style="padding-left:10px;font-size:13px;color:#333333;vertical-align:middle;">Total Visits &amp; User Activity</td>
                    </tr></table>
                  </td>
                  <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                    <table cellpadding="0" cellspacing="0"><tr>
                      <td style="width:28px;height:28px;background-color:#000000;border-radius:6px;text-align:center;vertical-align:middle;"><span style="color:#ffffff;font-size:13px;font-weight:bold;">&#128257;</span></td>
                      <td style="padding-left:10px;font-size:13px;color:#333333;vertical-align:middle;">Channel Performance Breakdown</td>
                    </tr></table>
                  </td>
                </tr>
                <tr>
                  <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                    <table cellpadding="0" cellspacing="0"><tr>
                      <td style="width:28px;height:28px;background-color:#000000;border-radius:6px;text-align:center;vertical-align:middle;"><span style="color:#ffffff;font-size:13px;font-weight:bold;">&#127891;</span></td>
                      <td style="padding-left:10px;font-size:13px;color:#333333;vertical-align:middle;">Top Performing Pages &amp; Courses</td>
                    </tr></table>
                  </td>
                  <td width="50%" style="padding-bottom:14px;vertical-align:top;">
                    <table cellpadding="0" cellspacing="0"><tr>
                      <td style="width:28px;height:28px;background-color:#000000;border-radius:6px;text-align:center;vertical-align:middle;"><span style="color:#ffffff;font-size:13px;font-weight:bold;">&#127758;</span></td>
                      <td style="padding-left:10px;font-size:13px;color:#333333;vertical-align:middle;">Visitor Insights by Country &amp; Browser</td>
                    </tr></table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- DIVIDER -->
          <tr><td style="padding:0 40px;"><hr style="border:none;border-top:1px solid #eeeeee;margin:0;"></td></tr>

          <!-- CLOSING -->
          <tr>
            <td style="padding:24px 40px 8px;">
              <p style="margin:0;font-size:14px;color:#444444;line-height:1.8;">
                Kindly review the report at your earliest convenience. Should you have any questions or require further clarification, please do not hesitate to reach out.
              </p>
            </td>
          </tr>

          <!-- SIGN OFF -->
          <tr>
            <td style="padding:16px 40px 36px;">
              <p style="margin:0;font-size:14px;color:#444444;line-height:1.8;">
                Best regards,<br>
                <strong style="color:#000000;">eLearning Analytics Team</strong>
              </p>
            </td>
          </tr>

          <!-- FOOTER -->
          <tr>
            <td style="background-color:#f8f9fa;padding:18px 40px;border-top:1px solid #eeeeee;">
              <p style="margin:0;font-size:11px;color:#999999;text-align:center;line-height:1.6;">
                This is an automated report generated by the eLearning Analytics System.<br>
                Please do not reply directly to this email.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>';

$plainBody = "Dear Team,\n\nPlease find attached the eLearning Weekly Performance Report for {$periodSubLabel}.\n\nThe report covers:\n- Total visits & user activity\n- Channel performance breakdown\n- Top performing pages & courses\n- Visitor insights by country and browser\n\nKindly review at your earliest convenience.\n\nBest regards,\neLearning Analytics Team";

// ─── SEND EMAILS ─────────────────────────────────────────────────────────────

$emailList = $config['emails'];
foreach ($emailList as $email) {
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host       = $smtp_server;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp_user;
    $mail->Password   = $smtp_pass;
    $mail->SMTPSecure = $smtp_encryption;
    $mail->Port       = $smtp_port;
    $mail->setFrom($smtp_user, $smtp_name);
    $mail->addAddress($email);
    $mail->Subject  = $config['subject'];
    $mail->isHTML(true);
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $plainBody;
    $mail->AddStringAttachment($pdfContent, $config['reportName']);

    echo $mail->send()
        ? "Email sent successfully to $email<br>"
        : "Error sending to $email: {$mail->ErrorInfo}<br>";
}
?>