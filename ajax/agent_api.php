<?php
// Autentifikatsiyasiz fayl navbati xavfsizlik sababli o'chirildi.
// Agent bilan aloqa api/agent_sync.php va bazadagi agent_jobs orqali ishlaydi.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Endpoint disabled']);
exit;

// ajax/agent_api.php - Token tekshiruvsiz

header('Content-Type: application/json');

// ============================================================
// 1. KONFIGURATSIYA
// ============================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// 2. JOB QUEUE (file based)
// ============================================================
$JOB_FILE = __DIR__ . '/agent_jobs.json';
$RESULT_FILE = __DIR__ . '/agent_results.json';

// ============================================================
// 3. TOKEN TEKSHIRUVINI O'CHIRISH
// ============================================================
// $token = $_GET['token'] ?? $_POST['token'] ?? '';
// if ($token !== $TOKEN) {
//     echo json_encode(['success' => false, 'message' => 'Invalid token']);
//     exit;
// }

// ============================================================
// 4. FUNKSIYALAR
// ============================================================
function getJobs() {
    global $JOB_FILE;
    if (!file_exists($JOB_FILE)) {
        file_put_contents($JOB_FILE, json_encode(['jobs' => []]));
    }
    $data = json_decode(file_get_contents($JOB_FILE), true);
    return $data['jobs'] ?? [];
}

function saveJobs($jobs) {
    global $JOB_FILE;
    file_put_contents($JOB_FILE, json_encode(['jobs' => $jobs]));
}

function getResults() {
    global $RESULT_FILE;
    if (!file_exists($RESULT_FILE)) {
        file_put_contents($RESULT_FILE, json_encode(['results' => []]));
    }
    $data = json_decode(file_get_contents($RESULT_FILE), true);
    return $data['results'] ?? [];
}

function saveResults($results) {
    global $RESULT_FILE;
    file_put_contents($RESULT_FILE, json_encode(['results' => $results]));
}

// ============================================================
// 5. ACTION HANDLERS
// ============================================================
switch ($action) {
    case 'get_job':
        $jobs = getJobs();
        if (empty($jobs)) {
            echo json_encode(['success' => true, 'job' => null]);
        } else {
            $job = array_shift($jobs);
            saveJobs($jobs);
            echo json_encode(['success' => true, 'job' => $job]);
        }
        break;
        
    case 'submit_result':
        $input = json_decode(file_get_contents('php://input'), true);
        $job = $input['job'] ?? '';
        $result = $input['result'] ?? '';
        
        $results = getResults();
        $results[] = [
            'time' => date('Y-m-d H:i:s'),
            'job' => json_decode($job, true),
            'result' => json_decode($result, true)
        ];
        saveResults($results);
        
        echo json_encode(['success' => true]);
        break;
        
    case 'add_job':
        $input = json_decode(file_get_contents('php://input'), true);
        $action_type = $input['action'] ?? 'unknown';
        $data = $input['data'] ?? [];
        
        $jobs = getJobs();
        $jobs[] = [
            'action' => $action_type,
            'data' => $data,
            'added' => date('Y-m-d H:i:s')
        ];
        saveJobs($jobs);
        
        echo json_encode(['success' => true, 'message' => 'Job added', 'queue' => count($jobs)]);
        break;
        
    case 'status':
        $jobs = getJobs();
        $results = getResults();
        echo json_encode([
            'success' => true,
            'queue' => count($jobs),
            'results' => count($results),
            'last_result' => end($results)
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}
?>
