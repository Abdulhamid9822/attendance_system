<?php
// header.php - TUGATILGAN (t() funksiyasi bilan)

if (session_status() === PHP_SESSION_NONE) {
    require_once dirname(__DIR__) . '/includes/session.php';
    startAppSession();
}
$_sid = (string)($_SESSION['session_context'] ?? '');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$basePath = dirname(__FILE__);

// ============================================
// LOGIN TEKSHIRISH — iFrame OAuth bilan ishlaydi
// ============================================
function checkAuth() {
    if (!empty($_SESSION['is_logged_in']) && !empty($_SESSION['access_token'])) {
        return;
    }
    if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        return;
    }
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['access_token'])) {
        return;
    }
    die('<p style="font-family:sans-serif;padding:20px;color:red;">❌ Avtorizatsiya kerak. Poster iFrame orqali oching.</p>');
}

checkAuth();

// Kerakli fayllarni yuklash
if (file_exists($basePath . '/../includes/config/database.php'))
    require_once $basePath . '/../includes/config/database.php';

if (file_exists($basePath . '/../includes/language.php'))
    require_once $basePath . '/../includes/language.php';

$optionalFiles = [
    $basePath . '/../includes/auth.php',
    $basePath . '/../includes/functions/helpers.php',
    $basePath . '/../includes/functions/poster.php',
    $basePath . '/../includes/functions/employee.php',
    $basePath . '/../includes/functions/attendance.php',
    $basePath . '/../includes/functions/salary.php',
    $basePath . '/../includes/functions/report.php',
];
foreach ($optionalFiles as $f) {
    if (file_exists($f)) require_once $f;
}

// ============================================
// USER MA'LUMOTLARI
// ============================================
$userId        = $_SESSION['user_id']       ?? null;
$posterAccount = $_SESSION['poster_account'] ?? $_SESSION['account'] ?? null;
$accountName   = $_SESSION['account_name']   ?? $_SESSION['account'] ?? null;

if (!$accountName && $userId && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT account_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $accountName = $user['account_name'];
            $_SESSION['account_name'] = $accountName;
        }
    } catch (PDOException $e) {
        error_log("Get user name error: " . $e->getMessage());
    }
}

$settings       = function_exists('getSettings') ? getSettings() : [];
$companyName    = $settings['company_name']    ?? ($_SESSION['company_name']    ?? (function_exists('t') ? t('default_company') : 'Company'));
$defaultSalary  = $settings['default_salary']  ?? ($_SESSION['default_salary']  ?? 1000000);
$currencySymbol = $settings['currency_symbol'] ?? ($_SESSION['currency_symbol'] ?? "so'm");
$workStartTime  = $settings['work_start_time'] ?? ($_SESSION['work_start_time'] ?? '09:00:00');
$workEndTime    = $settings['work_end_time']   ?? ($_SESSION['work_end_time']   ?? '18:00:00');
$graceMinutes   = $settings['grace_minutes']   ?? ($_SESSION['grace_minutes']   ?? 20);

$tab         = isset($_GET['tab'])        ? $_GET['tab']        : 'dashboard';
$period      = isset($_GET['period'])     ? $_GET['period']     : 'month';
$customStart = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$customEnd   = isset($_GET['end_date'])   ? $_GET['end_date']   : null;

// ============================================================
// VAQT ORALIG'INI ANIQLASH
// ============================================================
switch($period){
    case 'today': 
        $dateFrom = date('Y-m-d'); 
        $dateTo = date('Y-m-d'); 
        break;
    case 'yesterday': 
        $dateFrom = date('Y-m-d', strtotime('-1 day')); 
        $dateTo = date('Y-m-d', strtotime('-1 day')); 
        break;
    case 'week': 
        $dateFrom = date('Y-m-d', strtotime('monday this week')); 
        $dateTo = date('Y-m-d'); 
        break;
    case 'last_week': 
        $dateFrom = date('Y-m-d', strtotime('monday last week')); 
        $dateTo = date('Y-m-d', strtotime('sunday last week')); 
        break;
    case 'month': 
        $dateFrom = date('Y-m-d', strtotime('first day of this month')); 
        $dateTo = date('Y-m-d'); 
        break;
    case 'last_month': 
        $dateFrom = date('Y-m-d', strtotime('first day of last month')); 
        $dateTo = date('Y-m-d', strtotime('last day of last month')); 
        break;
    case 'last_30_days': 
        $dateFrom = date('Y-m-d', strtotime('-30 days')); 
        $dateTo = date('Y-m-d'); 
        break;
    case 'custom':
        $dateFrom = ($customStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart)) ? $customStart : date('Y-m-d', strtotime('-30 days'));
        $dateTo   = ($customEnd   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd))   ? $customEnd   : date('Y-m-d');
        if ($dateFrom > $dateTo) { $temp = $dateFrom; $dateFrom = $dateTo; $dateTo = $temp; }
        break;
    default: 
        $dateFrom = date('Y-m-d', strtotime('first day of this month')); 
        $dateTo   = date('Y-m-d');
}

$GLOBALS['dateFrom']       = $dateFrom;
$GLOBALS['dateTo']         = $dateTo;
$GLOBALS['period']         = $period;
$GLOBALS['defaultSalary']  = $defaultSalary;
$GLOBALS['currencySymbol'] = $currencySymbol;
$GLOBALS['workStartTime']  = $workStartTime;
$GLOBALS['workEndTime']    = $workEndTime;
$GLOBALS['graceMinutes']   = $graceMinutes;
$GLOBALS['settings']       = $settings;
$GLOBALS['tab']            = $tab;

$currentLang = $_SESSION['user_lang'] ?? 'en';
$GLOBALS['currentLang'] = $currentLang;

$_t = function($key) { return function_exists('t') ? t($key) : $key; };

// ============================================================
// DATE PICKER HOLATI (faqat settings da ko'rinmasin)
// ============================================================
$showDatePicker = ($tab !== 'settings');
$isEmployeesOrReports = in_array($tab, ['employees', 'reports']);

$qt = [
    'today'      => $_t('today'),
    'yesterday'  => $_t('yesterday'),
    'days7'      => $_t('days7'),
    'days30'     => $_t('days30'),
    'prev_month' => $_t('prev_month'),
    'this_month' => $_t('this_month'),
    'clear'      => $_t('clear'),
    'apply'      => $_t('apply'),
];

$monthNamesShort = [
    1  => $_t('january_short'),  2  => $_t('february_short'), 3  => $_t('march_short'),
    4  => $_t('april_short'),    5  => $_t('may_short'),       6  => $_t('june_short'),
    7  => $_t('july_short'),     8  => $_t('august_short'),    9  => $_t('september_short'),
    10 => $_t('october_short'),  11 => $_t('november_short'),  12 => $_t('december_short'),
];

// Account name ni tekshirish
$is_truthpost = false;
if (isset($_SESSION['account']) && $_SESSION['account'] === 'truthpos') {
    $is_truthpost = true;
}
if (isset($account_name) && $account_name === 'truthpos') {
    $is_truthpost = true;
}
if (isset($_SESSION['account_name']) && $_SESSION['account_name'] === 'truthpos') {
    $is_truthpost = true;
}
?>

<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($companyName) ?> - <?= $_t('statistics') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="navbar">
    <div class="nav-tabs">
        <a href="/dashboard/<?= $_sid ?>" class="nav-tab <?= $tab=='dashboard' ? 'active' : '' ?>">
            <?= $_t('statistics') ?>
        </a>
        <a href="/employees/<?= $_sid ?>" class="nav-tab <?= $tab=='employees' ? 'active' : '' ?>">
            <?= $_t('employees') ?>
        </a>
        <a href="/attendance/<?= $_sid ?>" class="nav-tab <?= $tab=='attendance' ? 'active' : '' ?>">
            <?= $_t('attendance') ?>
        </a>
        <a href="/reports/<?= $_sid ?>" class="nav-tab <?= $tab=='reports' ? 'active' : '' ?>">
            <?= $_t('reports') ?>
        </a>
        <a href="/settings/<?= $_sid ?>" class="nav-tab <?= $tab=='settings' ? 'active' : '' ?>">
            <?= $_t('settings') ?>
        </a>
    </div>
    
    <div class="navbar-right">
        <div class="action-buttons">
            <button class="icon-btn" onclick="exportAttendanceToExcel()" title="<?= $_t('export_excel') ?>">
                <i class="fas fa-file-excel"></i>
            </button>
            <button class="icon-btn" onclick="window.print()" title="<?= $_t('print') ?>">
                <i class="fas fa-print"></i>
            </button>
        </div>
        
        <?php if ($showDatePicker): ?>
        <div style="position: relative;">
            <button class="date-btn" id="dateBtn">
                <span id="dateText">
                    <?php
                    if ($dateFrom == $dateTo) {
                        echo date('j', strtotime($dateFrom)) . ' ' . $monthNamesShort[(int)date('n', strtotime($dateFrom))];
                    } else {
                        echo date('j', strtotime($dateFrom)) . ' ' . $monthNamesShort[(int)date('n', strtotime($dateFrom))]
                           . ' - ' . date('j', strtotime($dateTo)) . ' ' . $monthNamesShort[(int)date('n', strtotime($dateTo))];
                    }
                    ?>
                </span>
                <i class="fas fa-chevron-down"></i>
            </button>
            
            <div class="date-dropdown" id="dateDropdown">
                <div class="dropdown-body">
                    <div class="calendars-wrapper">
                        <div class="calendar-box" id="calendar1">
                            <div class="calendar-header">
                                <button id="prevMonthBtn"><i class="fas fa-chevron-left"></i></button>
                                <span class="calendar-month-title" id="month1Title"></span>
                                <button style="visibility: hidden;"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            <div class="weekdays">
                                <div><?= $_t('mon_short') ?></div><div><?= $_t('tue_short') ?></div><div><?= $_t('wed_short') ?></div>
                                <div><?= $_t('thu_short') ?></div><div><?= $_t('fri_short') ?></div><div><?= $_t('sat_short') ?></div><div><?= $_t('sun_short') ?></div>
                            </div>
                            <div id="days1" class="days"></div>
                        </div>
                        
                        <div class="calendar-box" id="calendar2">
                            <div class="calendar-header">
                                <button style="visibility: hidden;"><i class="fas fa-chevron-left"></i></button>
                                <span class="calendar-month-title" id="month2Title"></span>
                                <button id="nextMonthBtn"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            <div class="weekdays">
                                <div><?= $_t('mon_short') ?></div><div><?= $_t('tue_short') ?></div><div><?= $_t('wed_short') ?></div>
                                <div><?= $_t('thu_short') ?></div><div><?= $_t('fri_short') ?></div><div><?= $_t('sat_short') ?></div><div><?= $_t('sun_short') ?></div>
                            </div>
                            <div id="days2" class="days"></div>
                        </div>
                    </div>
                    
                    <div class="quick-section">
                        <div class="quick-btns">
                            <?php if (!$isEmployeesOrReports): ?>
                            <button class="quick-btn" data-days="0"><?= $_t('today') ?></button>
                            <button class="quick-btn" data-days="-1"><?= $_t('yesterday') ?></button>
                            <button class="quick-btn" data-days="-7"><?= $_t('days7') ?></button>
                            <button class="quick-btn" data-days="-30"><?= $_t('days30') ?></button>
                            <?php endif; ?>
                            <button class="quick-btn" data-prev-month="1"><?= $_t('prev_month') ?></button>
                            <button class="quick-btn" data-this-month="1"><?= $_t('this_month') ?></button>
                        </div>
                    </div>
                </div>
                <div class="dropdown-footer">
                    <button class="apply-btn" id="applyBtn"><?= $_t('apply') ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container">

<script>
document.querySelectorAll('a').forEach(link => {
    link.addEventListener('mouseover', function(e) {
        window.status = '';
        return false;
    });
    link.addEventListener('mouseout', function(e) {
        window.status = '';
        return false;
    });
});

(function() {
    'use strict';
    
    var dateBtn        = document.getElementById('dateBtn');
    var dateDropdown   = document.getElementById('dateDropdown');
    var applyBtn       = document.getElementById('applyBtn');
    var prevMonthBtn   = document.getElementById('prevMonthBtn');
    var nextMonthBtn   = document.getElementById('nextMonthBtn');
    var dateTextSpan   = document.getElementById('dateText');

    var isEmployeesOrReports = <?= json_encode($isEmployeesOrReports) ?>;

    var currentYear  = new Date().getFullYear();
    var currentMonth = new Date().getMonth();
    var startDate    = null;
    var endDate      = null;

    var phpStart = '<?= $dateFrom ?>';
    var phpEnd   = '<?= $dateTo ?>';

    if (phpStart && phpEnd) {
        startDate = new Date(phpStart); startDate.setHours(0,0,0,0);
        endDate   = new Date(phpEnd);   endDate.setHours(0,0,0,0);
    }

    var monthNames = <?= json_encode(array_map(function($k){ return function_exists('t') ? t($k) : $k; }, [
        'january','february','march','april','may','june',
        'july','august','september','october','november','december'
    ])) ?>;

    var monthNamesShort = <?= json_encode(array_map(function($k){ return function_exists('t') ? t($k) : $k; }, [
        'january_short','february_short','march_short','april_short','may_short','june_short',
        'july_short','august_short','september_short','october_short','november_short','december_short'
    ])) ?>;

    function formatYMD(date) {
        return date.getFullYear() + '-' +
               (date.getMonth()+1).toString().padStart(2,'0') + '-' +
               date.getDate().toString().padStart(2,'0');
    }
    function formatDateText(date) {
        return date.getDate() + ' ' + monthNamesShort[date.getMonth()];
    }
    function updateDateText() {
        if (dateTextSpan && startDate) {
            dateTextSpan.innerText = (endDate && startDate.toDateString() !== endDate.toDateString())
                ? formatDateText(startDate) + ' - ' + formatDateText(endDate)
                : formatDateText(startDate);
        }
    }
    function openDropdown()  { if (dateDropdown) dateDropdown.classList.add('open'); renderBoth(); }
    function closeDropdown() { if (dateDropdown) dateDropdown.classList.remove('open'); }

    if (dateBtn) dateBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openDropdown(); });
    if (dateDropdown) dateDropdown.addEventListener('click', function(e){ e.stopPropagation(); });
    document.addEventListener('click', function(e){
        if (dateDropdown && dateDropdown.classList.contains('open'))
            if (!dateBtn.contains(e.target) && !dateDropdown.contains(e.target)) closeDropdown();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && dateDropdown && dateDropdown.classList.contains('open')) closeDropdown();
    });

    if (applyBtn) {
        applyBtn.addEventListener('click', function(){
            if (startDate && endDate) {
                var url = new URL(window.location.href);
                if (isEmployeesOrReports) {
                    // Faqat oy parametrini yuboramiz (employees va reports uchun)
                    var month = startDate.getFullYear() + '-' + 
                                (startDate.getMonth()+1).toString().padStart(2,'0');
                    url.searchParams.set('month', month);
                    // Boshqa parametrlarni olib tashlaymiz
                    url.searchParams.delete('period');
                    url.searchParams.delete('start_date');
                    url.searchParams.delete('end_date');
                } else {
                    // Boshqa sahifalar uchun to'liq sana oralig'i
                    url.searchParams.set('period','custom');
                    url.searchParams.set('start_date', formatYMD(startDate));
                    url.searchParams.set('end_date',   formatYMD(endDate));
                    url.searchParams.delete('month');
                }
                window.location.href = url.toString();
            }
        });
    }

    function selectDate(date) {
        if (!startDate || (startDate && endDate)) { 
            startDate = date; 
            endDate = null; 
        }
        else if (date < startDate) { 
            endDate = startDate; 
            startDate = date; 
        }
        else { 
            endDate = date; 
        }
        updateDateText(); 
        renderBoth();
    }

    function renderCalendar(containerId, year, month, monthTitleId, daysId) {
        var firstDay     = new Date(year, month, 1);
        var startWeekday = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1;
        var daysInMonth  = new Date(year, month+1, 0).getDate();
        var prevMonthDays= new Date(year, month, 0).getDate();
        var monthTitle   = document.getElementById(monthTitleId);
        if (monthTitle) monthTitle.innerText = monthNames[month] + ' ' + year;
        var html = '';
        for (var i = 0; i < startWeekday; i++)
            html += '<div class="day other-month">' + (prevMonthDays - startWeekday + i + 1) + '</div>';
        for (var d = 1; d <= daysInMonth; d++) {
            var cur = new Date(year, month, d); cur.setHours(0,0,0,0);
            var classes = [];
            if (startDate && cur.toDateString() === startDate.toDateString()) classes.push('selected-start');
            if (endDate   && cur.toDateString() === endDate.toDateString())   classes.push('selected-end');
            if (startDate && endDate && cur > startDate && cur < endDate)     classes.push('in-range');
            html += '<div class="day ' + classes.join(' ') + '" data-date="' + formatYMD(cur) + '">' + d + '</div>';
        }
        var daysContainer = document.getElementById(daysId);
        if (daysContainer) {
            daysContainer.innerHTML = html;
            daysContainer.querySelectorAll('.day:not(.other-month)').forEach(function(day){
                day.addEventListener('click', function(e){ e.stopPropagation(); if (this.dataset.date) selectDate(new Date(this.dataset.date)); });
            });
        }
    }

    function renderBoth() {
        renderCalendar('calendar1', currentYear, currentMonth, 'month1Title', 'days1');
        var nm = currentMonth+1, ny = currentYear;
        if (nm > 11) { nm = 0; ny++; }
        renderCalendar('calendar2', ny, nm, 'month2Title', 'days2');
    }

    if (prevMonthBtn) prevMonthBtn.addEventListener('click', function(e){ e.stopPropagation(); currentMonth--; if(currentMonth<0){currentMonth=11;currentYear--;} renderBoth(); });
    if (nextMonthBtn) nextMonthBtn.addEventListener('click', function(e){ e.stopPropagation(); currentMonth++; if(currentMonth>11){currentMonth=0;currentYear++;} renderBoth(); });

    document.querySelectorAll('.quick-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            var today = new Date(); today.setHours(0,0,0,0);
            if (this.dataset.days !== undefined) {
                var s = new Date(); s.setDate(s.getDate() + parseInt(this.dataset.days)); s.setHours(0,0,0,0);
                startDate = s; endDate = new Date(today);
            } else if (this.dataset.prevMonth !== undefined) {
                startDate = new Date(today.getFullYear(), today.getMonth()-1, 1);
                endDate   = new Date(today.getFullYear(), today.getMonth(), 0);
            } else if (this.dataset.thisMonth !== undefined) {
                startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                endDate   = today;
            }
            updateDateText(); renderBoth();
        });
    });

    updateDateText();
    renderBoth();
})();

function handleNavbarScroll() {
    var navbar = document.querySelector('.navbar');
    if (!navbar) return;
    if (window.scrollY > 100) { navbar.classList.add('relative'); document.body.classList.add('scrolled'); }
    else                      { navbar.classList.remove('relative'); document.body.classList.remove('scrolled'); }
}
window.addEventListener('scroll', handleNavbarScroll);
window.addEventListener('load',   handleNavbarScroll);

function printReport() {
    var printHeader = document.createElement('div');
    printHeader.className = 'print-header';
    printHeader.style.display = 'none';
    printHeader.innerHTML = `
        <div style="text-align:center; margin-bottom:20px;">
            <h2 style="margin:0;"><?= htmlspecialchars($companyName) ?></h2>
            <p style="margin:5px 0 0; color:#666; font-size:14px;">
                <?= $_t('report') ?>: <?= date('d.m.Y', strtotime($dateFrom)) ?> - <?= date('d.m.Y', strtotime($dateTo)) ?>
            </p>
            <p style="margin:2px 0 0; color:#888; font-size:12px;">
                <?= $_t('printed') ?>: <?= date('d.m.Y H:i') ?>
            </p>
        </div>
    `;
    document.body.prepend(printHeader);
    printHeader.style.display = 'block';
    window.print();
    setTimeout(function() {
        if (printHeader && printHeader.parentNode) {
            printHeader.parentNode.removeChild(printHeader);
        }
    }, 1000);
}

function exportAttendanceToExcel() {
    var table = document.querySelector('.attendance-wrap table');
    if (!table) {
        table = document.querySelector('table');
    }
    if (!table) {
        var tables = document.querySelectorAll('table');
        if (tables.length > 0) {
            table = tables[0];
        }
    }
    if (!table) {
        alert('Sahifada jadval topilmadi!');
        return;
    }
    
    var cloneTable = table.cloneNode(true);
    
    function cleanCellContent(cell) {
        var iconElements = cell.querySelectorAll('i, .fa, .fas, .far, .fab, .material-icons, svg, img, .status-icon, .badge, .progress');
        iconElements.forEach(function(el) {
            el.remove();
        });
        
        var spans = cell.querySelectorAll('span:not(.employee-name)');
        spans.forEach(function(span) {
            span.replaceWith(span.textContent);
        });
        
        var text = cell.innerText.trim();
        
        var fullMatch = text.match(/(\d{2}:\d{2})\s*[-–—]\s*(\d{2}:\d{2})/);
        if (fullMatch) {
            return fullMatch[1] + ' - ' + fullMatch[2];
        }
        
        var allTimes = text.match(/\b\d{2}:\d{2}\b/g);
        if (allTimes && allTimes.length > 0) {
            allTimes = [...new Set(allTimes)];
            if (allTimes.length >= 2) {
                return { html: allTimes.join('<br>') };
            }
            return allTimes[0];
        }
        
        var dateMatch = text.match(/(\d+)\s*[-–—]\s*([A-Za-z]{3})/);
        if (dateMatch) {
            var monthMap = {
                'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
                'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
                'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
            };
            var day = dateMatch[1].padStart(2, '0');
            var month = monthMap[dateMatch[2]] || '00';
            return "'" + month + '/' + day;
        }
        
        var slashMatch = text.match(/(\d+)\s*\/\s*(\d+)/);
        if (slashMatch) {
            var day = slashMatch[1].padStart(2, '0');
            var month = slashMatch[2].padStart(2, '0');
            return "'" + month + '/' + day;
        }
        
        if (/^\d+$/.test(text)) {
            return text;
        }
        
        if (!text || text === '--:--' || text === '' || text === '0' || text === '00:00') {
            return '-';
        }
        
        return text;
    }
    
    cloneTable.querySelectorAll('td').forEach(function(td) {
        var cleaned = cleanCellContent(td);
        if (cleaned && typeof cleaned === 'object' && cleaned.html) {
            td.innerHTML = cleaned.html;
        } else {
            td.textContent = cleaned;
        }
    });
    
    cloneTable.querySelectorAll('th').forEach(function(th) {
        var text = th.innerText.trim();
        if (/Worked|Ishlagan/i.test(text)) {
            th.textContent = 'Ishlagan kun';
            return;
        }
        if (/Day|Kun/i.test(text) && !text.includes('Worked')) {
            th.textContent = 'Kun';
            return;
        }
        var dateMatch = text.match(/(\d+)\.(\d+)/);
        if (dateMatch) {
            var day = dateMatch[1].padStart(2, '0');
            var month = dateMatch[2].padStart(2, '0');
            th.textContent = month + '/' + day;
            return;
        }
        if (/^[A-Za-z]{3}$/.test(text)) {
            th.textContent = text;
            return;
        }
        th.textContent = text;
    });
    
    var html = `
    <html xmlns:o="urn:schemas-microsoft-com:office:office" 
          xmlns:x="urn:schemas-microsoft-com:office:excel" 
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            table { border-collapse: collapse; font-size: 11px; font-family: 'Segoe UI', Arial, sans-serif; width: 100%; border: 1px solid #666; }
            th { background: #4472C4 !important; color: #FFFFFF !important; font-weight: bold; border: 1px solid #333 !important; padding: 6px 4px !important; text-align: center !important; white-space: nowrap !important; vertical-align: middle !important; }
            td { border: 1px solid #333 !important; padding: 4px 4px !important; text-align: center !important; white-space: nowrap !important; vertical-align: middle !important; mso-number-format: "@"; }
            .employee-name { text-align: left !important; font-weight: bold !important; white-space: nowrap !important; background: #E8F0FE !important; }
            .weekend { background: #FFEBEE !important; color: #C62828 !important; }
            .worked-cell { background: #FFF3E0 !important; font-weight: bold !important; }
            .text-left { text-align: left !important; }
            .text-center { text-align: center !important; }
            .text-right { text-align: right !important; }
        </style>
    </head>
    <body>
        <table>${cloneTable.innerHTML}</table>
    </body>
    </html>`;
    
    try {
        var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        var dateStr = new Date().toISOString().slice(0, 10);
        link.download = 'jadval_' + dateStr + '.xls';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function() {
            URL.revokeObjectURL(link.href);
        }, 150);
    } catch (error) {
        console.error('Excel export xatosi:', error);
        alert('Excel faylni yuklab olishda xatolik yuz berdi!');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var exportBtn = document.querySelector('.export-excel-btn, #exportExcelBtn, .btn-export-excel');
    if (exportBtn) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            exportAttendanceToExcel();
        });
    }
});
</script>
