<?php
// includes/functions/attendance.php - Davomat bilan ishlash

function getAttendance($employeeId, $dateFrom, $dateTo) {
    global $pdo;
    
    $attData = [];
    $stmt = $pdo->prepare("SELECT * FROM davomat WHERE xodim_id = ? AND sana BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $dateFrom, $dateTo]);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attData[$row['sana']] = $row;
    }
    return $attData;
}



function isWorkingDay($date) {
    $dayOfWeek = date('N', strtotime($date));
    return $dayOfWeek < 6;
}

function getWorkingDaysCount($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day');
    
    $workingDays = 0;
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $date) {
        if (isWorkingDay($date->format('Y-m-d'))) {
            $workingDays++;
        }
    }
    return $workingDays;
}

function syncAttendance($pdo) {
    try {
        // Sinxronlanmagan ma'lumotlarni qo'shish
        $sql = "
            INSERT INTO salary_system.attendance_data (
                employee_id, auth_datetime, auth_date, auth_time, direction, davomat_holati
            ) 
            SELECT 
                employee_id, auth_datetime, DATE(auth_datetime), TIME(auth_datetime), direction,
                CASE WHEN direction = 'IN' THEN 'present' ELSE 'absent' END
            FROM ivms.attendance_data ivms
            WHERE NOT EXISTS (
                SELECT 1 FROM salary_system.attendance_data ss 
                WHERE ss.employee_id = ivms.employee_id AND ss.auth_datetime = ivms.auth_datetime
            )
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("Sinxronlash xatosi: " . $e->getMessage());
        return 0;
    }
}


function deleteOldAttendanceByDatetime($pdo, $hours = 72) {
    try {
        // $hours soatdan eski ma'lumotlarni o'chirish
        $sql = "DELETE FROM ivms.attendance_data WHERE auth_datetime < DATE_SUB(NOW(), INTERVAL :hours HOUR)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['hours' => $hours]);
        
        $deleted_count = $stmt->rowCount();
        
        if($deleted_count > 0) {
            error_log(date('Y-m-d H:i:s') . " - $deleted_count ta eski ma'lumot o'chirildi (auth_datetime: $hours soat)");
        }
        
        return $deleted_count;
    } catch (Exception $e) {
        error_log("O'chirish xatosi: " . $e->getMessage());
        return 0;
    }
}

global $pdo;


?>
