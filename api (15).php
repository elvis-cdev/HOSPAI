<?php
// HospAIs API v3 - Bulletproof for InfinityFree
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

define('DB_HOST', 'sql200.infinityfree.com');
define('DB_USER', 'if0_41432274');
define('DB_PASS', 'drk8Nd9mu0nwfh');
define('DB_NAME', 'if0_41432274_hakathon');

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';port=3306;dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_TIMEOUT=>15]
    );
    return $pdo;
}

function ok($data=[]) {
    echo json_encode(array_merge(['success'=>true], $data));
    exit();
}
function fail($msg) {
    echo json_encode(['success'=>false,'error'=>$msg]);
    exit();
}

$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── TEST ──────────────────────────────────────────
        case 'test':
            $tables = getDB()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            ok(['message'=>'Connected OK','tables'=>$tables]);

        // ── SIGNUP ────────────────────────────────────────
        case 'signup':
            $fname  = trim($input['fname']    ?? '');
            $lname  = trim($input['lname']    ?? '');
            $email  = trim($input['email']    ?? '');
            $pass   = trim($input['password'] ?? '');
            $phone  = trim($input['phone']    ?? '');
            $dob    = trim($input['dob']      ?? '') ?: null;
            $gender = trim($input['gender']   ?? '');

            if (!$fname||!$lname||!$email||!$pass) fail('Please fill in all required fields.');
            if (strlen($pass)<6) fail('Password must be at least 6 characters.');

            $db = getDB();

            $chk = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $chk->execute([$email]);
            if ($chk->fetch()) fail('That email is already registered. Please sign in.');

            // Accept role from frontend — validate it
    $role = in_array($input['role']??'patient', ['patient','doctor','admin'])
          ? ($input['role']??'patient') : 'patient';
    // Generate ID prefix by role
    $prefix = $role==='doctor' ? 'D' : ($role==='admin' ? 'A' : 'P');
    $pid    = $input['patientId'] ?? ($prefix.substr(strval(time()),-5).rand(10,99));
            $full = $fname.' '.$lname;
            $hash = password_hash($pass, PASSWORD_BCRYPT);

            $db->prepare(
                "INSERT INTO users (patient_id,fname,lname,full_name,email,password,role,phone,dob,gender)
                 VALUES (?,?,?,?,?,?,'patient',?,?,?)"
            )->execute([$pid,$fname,$lname,$full,$email,$hash,$phone,$dob,$gender]);

            $db->prepare(
                "INSERT IGNORE INTO patients (patient_id,full_name,email,phone,dob,gender,
                 risk_level,last_decision,priority_score) VALUES (?,?,?,?,?,?,'Not assessed','No assessment yet',0)"
            )->execute([$pid,$full,$email,$phone,$dob,$gender]);

            ok(['user'=>[
                'patientId'=>$pid,'id'=>$pid,'fname'=>$fname,'lname'=>$lname,
                'fullName'=>$full,'name'=>$full,'email'=>$email,
                'phone'=>$phone,'dob'=>$dob,'gender'=>$gender,'role'=>'patient'
            ]]);

        // ── LOGIN ─────────────────────────────────────────
        case 'login':
            $email = trim($input['email']    ?? '');
            $pass  = trim($input['password'] ?? '');
            if (!$email||!$pass) fail('Please enter your email and password.');

            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) fail('No account found with that email address.');

            // Verify password — supports bcrypt and demo hash
            $demoHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uRpMa0dCa';
            $ok = password_verify($pass, $user['password'])
               || ($pass==='password123' && $user['password']===$demoHash);
            if (!$ok) fail('Incorrect password. Please try again.');

            $stmt2 = $db->prepare("SELECT * FROM patients WHERE patient_id=? LIMIT 1");
            $stmt2->execute([$user['patient_id']]);
            $pat = $stmt2->fetch() ?: [];

            ok([
                'user'=>[
                    'patientId'=>$user['patient_id'],'id'=>$user['patient_id'],
                    'fname'=>$user['fname'],'lname'=>$user['lname'],
                    'fullName'=>$user['full_name'],'name'=>$user['full_name'],
                    'email'=>$user['email'],'phone'=>$user['phone']??'',
                    'dob'=>$user['dob']??'','gender'=>$user['gender']??'',
                    'role'=>$user['role']
                ],
                'patient'=>$pat
            ]);

        // ── SAVE DISPATCH ─────────────────────────────────
        case 'saveDispatch':
            getDB()->prepare(
                "INSERT INTO dispatches
                 (patient_id,patient_name,email,phone,location,description,severity,dispatched,ambulance_id,eta)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $input['patientId']  ??'',
                $input['patientName']??'',
                $input['email']      ??'',
                $input['phone']      ??'',
                $input['location']   ??'',
                $input['description']??'',
                $input['severity']   ??'',
                empty($input['dispatched'])?0:1,
                $input['ambulanceId']??'',
                $input['eta']        ??'',
            ]);
            ok();

        // ── SAVE ANALYSIS ─────────────────────────────────
        case 'saveAnalysis':
            $syms = $input['symptoms']??'';
            if (is_array($syms)) $syms = implode(', ',$syms);
            $rf   = $input['riskFactors']??'';
            if (is_array($rf)) $rf = implode(', ',$rf);
            $pid  = $input['patientId']??$input['patient_id']??'';

            getDB()->prepare(
                "INSERT INTO analyses
                 (patient_id,patient_name,email,age,gender,vitals,symptoms,
                  risk_factors,risk_level,decision,priority,reasoning)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $pid,
                $input['patientName']??$input['name']??'',
                $input['email']??'',
                intval($input['age']??0),
                $input['gender']??'',
                $input['vitals']??'',
                $syms,
                $rf,
                $input['riskLevel']??$input['risk']??'',
                $input['decision']??$input['action']??'',
                intval($input['priority']??0),
                $input['reasoning']??'',
            ]);

            if ($pid) {
                getDB()->prepare(
                    "UPDATE patients SET risk_level=?,last_decision=?,priority_score=?,updated_at=NOW()
                     WHERE patient_id=?"
                )->execute([
                    $input['riskLevel']??'',
                    $input['decision']??'',
                    intval($input['priority']??0),
                    $pid
                ]);
            }
            ok();

        // ── UPDATE PATIENT ────────────────────────────────
        case 'updatePatient':
            getDB()->prepare(
                "UPDATE patients SET risk_level=?,last_decision=?,priority_score=?,
                 vitals=?,symptoms=?,updated_at=NOW() WHERE patient_id=?"
            )->execute([
                $input['riskLevel']    ??'',
                $input['lastDecision'] ??'',
                intval($input['priorityScore']??0),
                $input['vitals']       ??'',
                $input['symptoms']     ??'',
                $input['patientId']    ??'',
            ]);
            ok();

        // ── GET POPULATION HEALTH STATS ───────────────────
        case 'getStats':
            $db   = getDB();
            $week = (int)date('W');
            $year = (int)date('Y');

            // Daily chart
            try {
                $daily = array_map('intval', array_reverse(
                    $db->query("SELECT total_consult FROM daily_stats ORDER BY stat_date DESC LIMIT 7")
                       ->fetchAll(PDO::FETCH_COLUMN)
                ));
            } catch(Exception $e) { $daily = [18,24,31,19,38,27,14]; }

            // Disease bars
            try {
                $s = $db->prepare("SELECT disease_name name, case_count count,
                    ROUND(case_count/(SELECT MAX(case_count)+1 FROM disease_stats
                    WHERE week_number=? AND year=?)*100) pct, trend
                    FROM disease_stats WHERE week_number=? AND year=?
                    ORDER BY case_count DESC LIMIT 8");
                $s->execute([$week,$year,$week,$year]);
                $diseases = $s->fetchAll();
            } catch(Exception $e) { $diseases = []; }

            // Alerts
            try {
                $alerts = $db->query("SELECT severity level, title, description msg, location, disease
                    FROM health_alerts WHERE active=1
                    ORDER BY FIELD(severity,'CRITICAL','HIGH','MEDIUM','LOW') LIMIT 5")
                    ->fetchAll();
            } catch(Exception $e) { $alerts = []; }

            // Totals
            try {
                $t = $db->query("SELECT COALESCE(SUM(total_consult),0) t,
                    COALESCE(SUM(critical_cases),0) c FROM daily_stats
                    WHERE stat_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)")->fetch();
                $total = (int)$t['t']; $crit = (int)$t['c'];
                if (!$total) {
                    $total = (int)$db->query("SELECT COUNT(*) FROM analyses")->fetchColumn();
                    $crit  = (int)$db->query("SELECT COUNT(*) FROM analyses WHERE risk_level='CRITICAL'")->fetchColumn();
                }
            } catch(Exception $e) { $total=0; $crit=0; }

            // Top disease
            try {
                $s = $db->prepare("SELECT disease_name FROM disease_stats
                    WHERE week_number=? AND year=? ORDER BY case_count DESC LIMIT 1");
                $s->execute([$week,$year]);
                $top = $s->fetchColumn() ?: 'Malaria';
            } catch(Exception $e) { $top='Malaria'; }

            ok(['data'=>[
                'total'=>$total,'critical'=>$crit,'topDisease'=>$top,
                'alerts'=>count($alerts),'diseases'=>$diseases,
                'daily'=>$daily ?: [18,24,31,19,38,27,14],
                'alerts_data'=>$alerts,
            ]]);

        // ── SAVE FACE PHOTO ──────────────────────────────
        case 'saveFace':
            $pid  = $input['patientId'] ?? '';
            $face = $input['faceData']  ?? '';
            if (!$pid || !$face) fail('Missing patientId or faceData');
            // Store in users table face_photo column
            getDB()->prepare("UPDATE users SET face_photo=? WHERE patient_id=?")
                   ->execute([$face, $pid]);
            // Also store in patients table for quick access
            getDB()->prepare("UPDATE patients SET face_photo=? WHERE patient_id=?")
                   ->execute([$face, $pid]);
            ok(['message'=>'Face saved']);

        // ── GET FACE PHOTO ────────────────────────────────
        case 'getFace':
            $pid = $input['patientId'] ?? '';
            if (!$pid) fail('Missing patientId');
            $stmt = getDB()->prepare("SELECT face_photo FROM users WHERE patient_id=? LIMIT 1");
            $stmt->execute([$pid]);
            $row = $stmt->fetch();
            ok(['faceData' => $row['face_photo'] ?? null]);

        // ── LIST ALL FACES (for face login matching) ──────
        case 'listFaces':
            $stmt = getDB()->query(
                "SELECT u.patient_id, u.email, u.full_name, u.face_photo
                 FROM users u WHERE u.face_photo IS NOT NULL AND u.face_photo != '' LIMIT 100"
            );
            ok(['faces' => $stmt->fetchAll()]);

        // ── DELETE FACE PHOTO ─────────────────────────────
        case 'deleteFace':
            $pid = $input['patientId'] ?? '';
            if ($pid) {
                getDB()->prepare("UPDATE users    SET face_photo=NULL WHERE patient_id=?")->execute([$pid]);
                getDB()->prepare("UPDATE patients SET face_photo=NULL WHERE patient_id=?")->execute([$pid]);
            }
            ok();

        // ── FACE LOGIN (no password needed) ───────────────
        case 'faceLogin':
            $pid = $input['patientId'] ?? '';
            if (!$pid) fail('Missing patientId');
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE patient_id=? LIMIT 1");
            $stmt->execute([$pid]);
            $user = $stmt->fetch();
            if (!$user) fail('Patient not found');
            // Verify they actually have a face photo registered
            if (empty($user['face_photo'])) fail('No face profile registered for this patient');
            $stmt2 = $db->prepare("SELECT * FROM patients WHERE patient_id=? LIMIT 1");
            $stmt2->execute([$pid]);
            $pat = $stmt2->fetch() ?: [];
            ok([
                'user'=>[
                    'patientId'=>$user['patient_id'],'id'=>$user['patient_id'],
                    'fname'=>$user['fname'],'lname'=>$user['lname'],
                    'fullName'=>$user['full_name'],'name'=>$user['full_name'],
                    'email'=>$user['email'],'phone'=>$user['phone']??'',
                    'dob'=>$user['dob']??'','gender'=>$user['gender']??'',
                    'role'=>$user['role']
                ],
                'patient'=>$pat
            ]);

        // ── GET ALL PATIENTS (doctor/admin) ──────────────
        case 'getPatients':
            $stmt = getDB()->query("SELECT patient_id, full_name, email, phone, dob, gender, risk_level, last_decision, priority_score, vitals, symptoms FROM patients ORDER BY priority_score DESC, FIELD(risk_level,'CRITICAL','HIGH','MEDIUM','LOW','Not assessed') LIMIT 50");
            ok(['patients'=>$stmt->fetchAll()]);

        // ── GET ANALYSES COUNT ────────────────────────────
        case 'getAnalysesCount':
            $count = getDB()->query("SELECT COUNT(*) FROM analyses WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            ok(['count'=>(int)$count]);

        // ── GET BED STATUS ────────────────────────────────
        case 'getBeds':
            try {
                $stmt = getDB()->query("SELECT ward, total_beds, occupied, reserved FROM bed_status ORDER BY ROUND(occupied/total_beds*100) DESC");
                ok(['beds'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['beds'=>[]]); }

        // ── GET STAFF ─────────────────────────────────────
        case 'getStaff':
            try {
                $stmt = getDB()->query("SELECT staff_id, full_name, role, department, speciality, shift, on_duty, phone FROM staff ORDER BY on_duty DESC, full_name");
                ok(['staff'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['staff'=>[]]); }

        // ── GET DRUG INVENTORY ────────────────────────────
        case 'getDrugs':
            try {
                $stmt = getDB()->query("SELECT drug_name, generic_name, category, stock_units, reorder_level, expiry_date FROM drug_inventory ORDER BY CASE WHEN stock_units<=reorder_level THEN 0 ELSE 1 END, stock_units LIMIT 20");
                ok(['drugs'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['drugs'=>[]]); }

        // ── GET PATIENT ANALYSES ─────────────────────────
        case 'getPatientAnalyses':
            $pid = $input['patientId'] ?? '';
            if (!$pid) fail('Missing patientId');
            $stmt = getDB()->prepare("SELECT id, symptoms, risk_level, decision, priority, reasoning, created_at FROM analyses WHERE patient_id=? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$pid]);
            ok(['analyses'=>$stmt->fetchAll()]);

        // ── GET DISPATCH COUNT ───────────────────────────────
        case 'getDispatchCount':
            $count = getDB()->query("SELECT COUNT(*) FROM dispatches WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
            ok(['count'=>(int)$count]);

        // ── GET ALL DISPATCHES ────────────────────────────────
        case 'getDispatches':
            $stmt = getDB()->query("SELECT id, patient_id, patient_name, location, description, severity, dispatched, ambulance_id, eta, resolved, created_at FROM dispatches ORDER BY created_at DESC LIMIT 50");
            ok(['dispatches'=>$stmt->fetchAll()]);

        // ── GET AMBULANCES ────────────────────────────────
        case 'getAmbulances':
            ok(['units'=>[
                ['id'=>'AMB-001','status'=>'available','location'=>'Kenyatta Hospital','type'=>'ALS'],
                ['id'=>'AMB-002','status'=>'available','location'=>'Nairobi Hospital','type'=>'BLS'],
                ['id'=>'AMB-003','status'=>'on-call','location'=>'Westlands','type'=>'ALS'],
                ['id'=>'AMB-004','status'=>'available','location'=>'Karen','type'=>'BLS'],
            ]]);

        // ── GET FOLLOW-UPS ────────────────────────────────
        case 'getFollowUps':
            try {
                $stmt = getDB()->query("SELECT * FROM follow_ups ORDER BY FIELD(status,'active','escalated','completed') , created_at DESC LIMIT 50");
                ok(['followups'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['followups'=>[]]); }

        // ── GET LAB RESULTS ───────────────────────────────
        case 'getLabResults':
            try {
                $stmt = getDB()->query("SELECT * FROM lab_results ORDER BY notified ASC, created_at DESC LIMIT 100");
                ok(['labs'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['labs'=>[]]); }

        // ── ADD LAB RESULT ────────────────────────────────
        case 'addLabResult':
            try {
                // Look up patient info
                $pid = $input['patientId']??'';
                $stmt = getDB()->prepare("SELECT full_name,email,phone FROM patients WHERE patient_id=? LIMIT 1");
                $stmt->execute([$pid]);
                $pat = $stmt->fetch() ?: ['full_name'=>$pid,'email'=>'','phone'=>''];
                getDB()->prepare("INSERT INTO lab_results (patient_id,patient_name,phone,email,test_name,result_value,result_status,reference_range,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$pid,$pat['full_name'],$pat['phone'],$pat['email'],$input['testName']??'',$input['resultValue']??'',$input['resultStatus']??'pending',$input['referenceRange']??'',$input['notes']??'']);
                ok();
            } catch(Exception $e) { fail($e->getMessage()); }

        // ── UPDATE LAB RESULT (mark notified) ────────────
        case 'updateLabResult':
            try {
                $db = getDB();
                if (isset($input['aiMessage'])) {
                    $db->prepare("UPDATE lab_results SET ai_message=?,notified=?,notified_at=NOW() WHERE id=?")
                       ->execute([$input['aiMessage'],(int)($input['notified']??0),(int)$input['id']]);
                }
                ok();
            } catch(Exception $e) { ok(); }

        // ── LOG ENGAGEMENT ────────────────────────────────
        case 'logEngagement':
            try {
                getDB()->prepare("INSERT INTO engagement_log (patient_id,type,message_out,response_in,ai_decision,action_taken) VALUES (?,?,?,?,?,?)")
                       ->execute([$input['patientId']??'',$input['type']??'checkin',$input['messageOut']??'',$input['responseIn']??'',$input['aiDecision']??'',$input['actionTaken']??'']);
                ok();
            } catch(Exception $e) { ok(); }


        // ══════════════════════════════════════════════════
        // SMART QUEUE MANAGER
        // ══════════════════════════════════════════════════

        case 'initQueueTables':
            try {
                $db = getDB();
                $db->exec("CREATE TABLE IF NOT EXISTS queue_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id VARCHAR(20), patient_name VARCHAR(100),
                    phone VARCHAR(20), complaint TEXT,
                    risk_level VARCHAR(20) DEFAULT 'MEDIUM',
                    risk_score INT DEFAULT 50,
                    arrival_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                    priority_score DECIMAL(8,2) DEFAULT 50,
                    queue_number VARCHAR(20),
                    status ENUM('waiting','called','in_progress','done','cancelled') DEFAULT 'waiting',
                    assigned_doctor_id VARCHAR(20),
                    assigned_doctor_name VARCHAR(100),
                    doctor_tier INT DEFAULT 2,
                    department VARCHAR(50) DEFAULT 'triage',
                    called_at DATETIME,
                    started_at DATETIME,
                    completed_at DATETIME,
                    wait_minutes INT DEFAULT 0,
                    ai_notes TEXT,
                    INDEX idx_status(status), INDEX idx_priority(priority_score DESC),
                    INDEX idx_arrival(arrival_time), INDEX idx_patient(patient_id))");

                $db->exec("CREATE TABLE IF NOT EXISTS prescriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id VARCHAR(20), queue_id INT,
                    doctor_id VARCHAR(20), doctor_name VARCHAR(100),
                    diagnosis TEXT, drugs JSON, lab_tests JSON,
                    next_action ENUM('discharge','admit','pharmacy','lab','refer') DEFAULT 'discharge',
                    consultation_fee DECIMAL(10,2) DEFAULT 500,
                    status ENUM('pending','dispensed','billed','paid') DEFAULT 'pending',
                    ai_clinical_note TEXT, ai_drug_explanation TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_patient(patient_id), INDEX idx_status(status))");

                $db->exec("CREATE TABLE IF NOT EXISTS billing (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id VARCHAR(20), prescription_id INT,
                    queue_id INT,
                    consultation_fee DECIMAL(10,2) DEFAULT 0,
                    pharmacy_total DECIMAL(10,2) DEFAULT 0,
                    lab_total DECIMAL(10,2) DEFAULT 0,
                    total_amount DECIMAL(10,2) DEFAULT 0,
                    payment_status ENUM('pending','simulated','paid') DEFAULT 'pending',
                    mpesa_ref VARCHAR(50),
                    paid_at DATETIME,
                    invoice_items JSON,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_patient(patient_id), INDEX idx_status(payment_status))");

                $db->exec("CREATE TABLE IF NOT EXISTS doctor_tiers (
                    doctor_id VARCHAR(20) PRIMARY KEY,
                    doctor_name VARCHAR(100),
                    tier INT DEFAULT 2,
                    max_capacity INT DEFAULT 5,
                    current_load INT DEFAULT 0,
                    specialisation VARCHAR(100),
                    available TINYINT DEFAULT 1,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");

                ok(['message'=>'Queue tables ready']);
            } catch(Exception $e) { fail('Init failed: '.$e->getMessage()); }

        case 'registerQueue':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS queue_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY, patient_id VARCHAR(20),
                    patient_name VARCHAR(100), phone VARCHAR(20), complaint TEXT,
                    risk_level VARCHAR(20) DEFAULT 'MEDIUM', risk_score INT DEFAULT 50,
                    arrival_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                    priority_score DECIMAL(8,2) DEFAULT 50,
                    queue_number VARCHAR(20),
                    status ENUM('waiting','called','in_progress','done','cancelled') DEFAULT 'waiting',
                    assigned_doctor_id VARCHAR(20), assigned_doctor_name VARCHAR(100),
                    doctor_tier INT DEFAULT 2, department VARCHAR(50) DEFAULT 'triage',
                    called_at DATETIME, started_at DATETIME, completed_at DATETIME,
                    wait_minutes INT DEFAULT 0, ai_notes TEXT,
                    INDEX idx_status(status), INDEX idx_priority(priority_score DESC))");

                $pid      = $input['patientId']  ?? '';
                $name     = $input['name']        ?? '';
                $phone    = $input['phone']       ?? '';
                $complaint= $input['complaint']   ?? '';
                $risk     = strtoupper($input['riskLevel'] ?? 'MEDIUM');
                $aiNotes  = $input['aiNotes']     ?? '';

                // Risk weight: CRITICAL=100, HIGH=80, MEDIUM=55, LOW=30
                $riskWeights = ['CRITICAL'=>100,'HIGH'=>80,'MEDIUM'=>55,'LOW'=>30];
                $riskScore   = $riskWeights[$risk] ?? 55;

                // Priority = risk_weight*0.7 + wait_minutes*0.3 (starts at 0 wait)
                $priority = $riskScore * 0.7;

                // Queue number format: RISK-NNN
                $prefix = substr($risk,0,1); // C, H, M, L
                $countStmt = getDB()->prepare("SELECT COUNT(*)+1 as n FROM queue_entries WHERE DATE(arrival_time)=CURDATE() AND risk_level=?");
                $countStmt->execute([$risk]);
                $num = str_pad($countStmt->fetch()['n'], 3, '0', STR_PAD_LEFT);
                $queueNum = $prefix.'-'.$num;

                // Find best available doctor
                $tier = ($risk==='CRITICAL'||$risk==='HIGH') ? 3 : ($risk==='MEDIUM' ? 2 : 1);
                $docStmt = getDB()->prepare("SELECT doctor_id, doctor_name FROM doctor_tiers
                    WHERE tier>=? AND available=1 AND current_load<max_capacity
                    ORDER BY current_load ASC, tier ASC LIMIT 1");
                $docStmt->execute([$tier]);
                $doc = $docStmt->fetch();
                $assignedDocId   = $doc['doctor_id']   ?? '';
                $assignedDocName = $doc['doctor_name'] ?? 'To be assigned';

                getDB()->prepare("INSERT INTO queue_entries
                    (patient_id,patient_name,phone,complaint,risk_level,risk_score,
                     priority_score,queue_number,assigned_doctor_id,assigned_doctor_name,
                     doctor_tier,ai_notes,arrival_time)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$pid,$name,$phone,$complaint,$risk,$riskScore,$priority,
                           $queueNum,$assignedDocId,$assignedDocName,$tier,$aiNotes]);

                $insertId = getDB()->lastInsertId();

                // Update doctor load
                if ($assignedDocId) {
                    getDB()->prepare("UPDATE doctor_tiers SET current_load=current_load+1 WHERE doctor_id=?")
                    ->execute([$assignedDocId]);
                }

                ok(['queueNumber'=>$queueNum,'assignedDoctor'=>$assignedDocName,
                    'tier'=>$tier,'queueId'=>$insertId,'riskLevel'=>$risk]);
            } catch(Exception $e) { fail('Queue register failed: '.$e->getMessage()); }

        case 'getQueue':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS queue_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY, patient_id VARCHAR(20),
                    patient_name VARCHAR(100), phone VARCHAR(20), complaint TEXT,
                    risk_level VARCHAR(20) DEFAULT 'MEDIUM', risk_score INT DEFAULT 50,
                    arrival_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                    priority_score DECIMAL(8,2) DEFAULT 50, queue_number VARCHAR(20),
                    status ENUM('waiting','called','in_progress','done','cancelled') DEFAULT 'waiting',
                    assigned_doctor_id VARCHAR(20), assigned_doctor_name VARCHAR(100),
                    doctor_tier INT DEFAULT 2, department VARCHAR(50) DEFAULT 'triage',
                    called_at DATETIME, started_at DATETIME, completed_at DATETIME,
                    wait_minutes INT DEFAULT 0, ai_notes TEXT,
                    INDEX idx_status(status))");

                // Update priority scores based on wait time
                getDB()->exec("UPDATE queue_entries
                    SET priority_score = (risk_score * 0.7) + (TIMESTAMPDIFF(MINUTE, arrival_time, NOW()) * 0.3),
                        wait_minutes   = TIMESTAMPDIFF(MINUTE, arrival_time, NOW())
                    WHERE status='waiting'");

                $docFilter = $input['doctorId'] ?? '';
                $status    = $input['status']   ?? 'waiting';

                if ($docFilter) {
                    $stmt = getDB()->prepare("SELECT * FROM queue_entries
                        WHERE assigned_doctor_id=? AND status IN ('waiting','called','in_progress')
                        ORDER BY priority_score DESC, arrival_time ASC");
                    $stmt->execute([$docFilter]);
                } else {
                    $stmt = getDB()->query("SELECT * FROM queue_entries
                        WHERE DATE(arrival_time)=CURDATE()
                        ORDER BY
                          CASE status WHEN 'in_progress' THEN 0 WHEN 'called' THEN 1 WHEN 'waiting' THEN 2 ELSE 3 END,
                          priority_score DESC, arrival_time ASC
                        LIMIT 100");
                }
                $q = $stmt->fetchAll();

                // Stats
                $stats = [
                    'total'    => count($q),
                    'waiting'  => count(array_filter($q,fn($r)=>$r['status']==='waiting')),
                    'critical' => count(array_filter($q,fn($r)=>$r['risk_level']==='CRITICAL'&&$r['status']==='waiting')),
                    'high'     => count(array_filter($q,fn($r)=>$r['risk_level']==='HIGH'&&$r['status']==='waiting')),
                ];
                ok(['queue'=>$q,'stats'=>$stats]);
            } catch(Exception $e) { ok(['queue'=>[],'stats'=>[]]); }

        case 'updateQueueStatus':
            try {
                $id     = (int)($input['id'] ?? 0);
                $status = $input['status'] ?? 'waiting';
                $timeField = ['called'=>'called_at','in_progress'=>'started_at','done'=>'completed_at','cancelled'=>'completed_at'][$status]??null;
                $sql = $timeField
                    ? "UPDATE queue_entries SET status=?, {$timeField}=NOW() WHERE id=?"
                    : "UPDATE queue_entries SET status=? WHERE id=?";
                getDB()->prepare($sql)->execute([$status,$id]);

                // Free up doctor capacity when done/cancelled
                if (in_array($status,['done','cancelled'])) {
                    $row=getDB()->prepare("SELECT assigned_doctor_id FROM queue_entries WHERE id=?");
                    $row->execute([$id]); $r=$row->fetch();
                    if($r&&$r['assigned_doctor_id']) {
                        getDB()->prepare("UPDATE doctor_tiers SET current_load=GREATEST(0,current_load-1) WHERE doctor_id=?")->execute([$r['assigned_doctor_id']]);
                    }
                }
                ok();
            } catch(Exception $e) { fail($e->getMessage()); }

        case 'savePrescription':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS prescriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY, patient_id VARCHAR(20),
                    queue_id INT, doctor_id VARCHAR(20), doctor_name VARCHAR(100),
                    diagnosis TEXT, drugs JSON, lab_tests JSON,
                    next_action ENUM('discharge','admit','pharmacy','lab','refer') DEFAULT 'discharge',
                    consultation_fee DECIMAL(10,2) DEFAULT 500,
                    status ENUM('pending','dispensed','billed','paid') DEFAULT 'pending',
                    ai_clinical_note TEXT, ai_drug_explanation TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_patient(patient_id), INDEX idx_status(status))");

                $pid      = $input['patientId']       ?? '';
                $qid      = (int)($input['queueId']   ?? 0);
                $docId    = $input['doctorId']         ?? '';
                $docName  = $input['doctorName']       ?? '';
                $diagnosis= $input['diagnosis']        ?? '';
                $drugs    = json_encode($input['drugs'] ?? []);
                $labs     = json_encode($input['labTests'] ?? []);
                $action   = $input['nextAction']       ?? 'discharge';
                $fee      = (float)($input['consultationFee'] ?? 500);
                $note     = $input['aiClinicalNote']   ?? '';
                $drugExp  = $input['aiDrugExplanation']?? '';

                getDB()->prepare("INSERT INTO prescriptions
                    (patient_id,queue_id,doctor_id,doctor_name,diagnosis,drugs,lab_tests,
                     next_action,consultation_fee,ai_clinical_note,ai_drug_explanation)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid,$qid,$docId,$docName,$diagnosis,$drugs,$labs,$action,$fee,$note,$drugExp]);

                $presId = getDB()->lastInsertId();

                // Update queue status
                if ($qid) {
                    getDB()->prepare("UPDATE queue_entries SET status='done', completed_at=NOW(), department=? WHERE id=?")
                    ->execute([$action,$qid]);
                }

                // Auto-generate billing
                $drugsArr = json_decode($drugs,true)??[];
                $pharmTotal = array_sum(array_column($drugsArr,'total_cost'));
                $total = $fee + $pharmTotal;

                getDB()->exec("CREATE TABLE IF NOT EXISTS billing (
                    id INT AUTO_INCREMENT PRIMARY KEY, patient_id VARCHAR(20),
                    prescription_id INT, queue_id INT,
                    consultation_fee DECIMAL(10,2) DEFAULT 0,
                    pharmacy_total DECIMAL(10,2) DEFAULT 0,
                    lab_total DECIMAL(10,2) DEFAULT 0,
                    total_amount DECIMAL(10,2) DEFAULT 0,
                    payment_status ENUM('pending','simulated','paid') DEFAULT 'pending',
                    mpesa_ref VARCHAR(50), paid_at DATETIME, invoice_items JSON,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

                getDB()->prepare("INSERT INTO billing
                    (patient_id,prescription_id,queue_id,consultation_fee,pharmacy_total,total_amount,invoice_items)
                    VALUES(?,?,?,?,?,?,?)")
                ->execute([$pid,$presId,$qid,$fee,$pharmTotal,$total,$drugs]);

                ok(['prescriptionId'=>$presId,'billingGenerated'=>true,'total'=>$total]);
            } catch(Exception $e) { fail('Prescription save failed: '.$e->getMessage()); }

        case 'getPrescription':
            try {
                $pid = $input['patientId'] ?? '';
                $qid = (int)($input['queueId'] ?? 0);
                $stmt = $pid && $qid
                    ? getDB()->prepare("SELECT * FROM prescriptions WHERE patient_id=? AND queue_id=? ORDER BY created_at DESC LIMIT 1")
                    : ($pid ? getDB()->prepare("SELECT * FROM prescriptions WHERE patient_id=? ORDER BY created_at DESC LIMIT 1") : null);
                if(!$stmt) fail('Missing patient ID');
                $stmt->execute($qid ? [$pid,$qid] : [$pid]);
                $pres = $stmt->fetch();
                if($pres){
                    $pres['drugs'] = json_decode($pres['drugs'],true)??[];
                    $pres['lab_tests'] = json_decode($pres['lab_tests'],true)??[];
                }
                ok(['prescription'=>$pres]);
            } catch(Exception $e) { ok(['prescription'=>null]); }

        case 'getBilling':
            try {
                $pid = $input['patientId'] ?? '';
                $stmt = getDB()->prepare("SELECT b.*, p.diagnosis, p.doctor_name FROM billing b
                    LEFT JOIN prescriptions p ON b.prescription_id=p.id
                    WHERE b.patient_id=? ORDER BY b.created_at DESC LIMIT 1");
                $stmt->execute([$pid]);
                $bill = $stmt->fetch();
                ok(['billing'=>$bill]);
            } catch(Exception $e) { ok(['billing'=>null]); }

        case 'processPayment':
            try {
                $pid    = $input['patientId'] ?? '';
                $billId = (int)($input['billId'] ?? 0);
                $method = $input['method'] ?? 'mpesa';
                $ref    = 'MPESA'.strtoupper(substr(md5(time().$pid),0,8));
                getDB()->prepare("UPDATE billing SET payment_status='simulated', mpesa_ref=?, paid_at=NOW() WHERE id=? AND patient_id=?")
                ->execute([$ref,$billId,$pid]);
                getDB()->prepare("UPDATE prescriptions SET status='paid' WHERE patient_id=? ORDER BY created_at DESC LIMIT 1")
                ->execute([$pid]);
                ok(['ref'=>$ref,'message'=>'Payment simulated successfully']);
            } catch(Exception $e) { fail($e->getMessage()); }

        case 'getDoctorTiers':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS doctor_tiers (
                    doctor_id VARCHAR(20) PRIMARY KEY, doctor_name VARCHAR(100),
                    tier INT DEFAULT 2, max_capacity INT DEFAULT 5,
                    current_load INT DEFAULT 0, specialisation VARCHAR(100),
                    available TINYINT DEFAULT 1,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
                $stmt = getDB()->query("SELECT * FROM doctor_tiers ORDER BY tier, doctor_name");
                ok(['doctors'=>$stmt->fetchAll()]);
            } catch(Exception $e) { ok(['doctors',[]]); }

        case 'saveDoctorTier':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS doctor_tiers (
                    doctor_id VARCHAR(20) PRIMARY KEY, doctor_name VARCHAR(100),
                    tier INT DEFAULT 2, max_capacity INT DEFAULT 5,
                    current_load INT DEFAULT 0, specialisation VARCHAR(100),
                    available TINYINT DEFAULT 1,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
                $docId = $input['doctorId'] ?? '';
                $name  = $input['name']     ?? '';
                $tier  = (int)($input['tier'] ?? 2);
                $cap   = (int)($input['capacity'] ?? 5);
                $spec  = $input['specialisation'] ?? '';
                $avail = (int)($input['available'] ?? 1);
                getDB()->prepare("INSERT INTO doctor_tiers (doctor_id,doctor_name,tier,max_capacity,specialisation,available)
                    VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                    doctor_name=VALUES(doctor_name), tier=VALUES(tier),
                    max_capacity=VALUES(max_capacity), specialisation=VALUES(specialisation),
                    available=VALUES(available)")
                ->execute([$docId,$name,$tier,$cap,$spec,$avail]);
                ok();
            } catch(Exception $e) { fail($e->getMessage()); }

        case 'scanQR':
            // Called when any department scans a QR code
            // Returns patient full record + current journey status
            try {
                $pid  = $input['patientId'] ?? '';
                $dept = $input['department'] ?? 'triage'; // triage, doctor, pharmacy, billing, discharge
                if(!$pid) fail('No patient ID in QR');

                // Get patient
                $ps = getDB()->prepare("SELECT * FROM patients WHERE patient_id=? LIMIT 1");
                $ps->execute([$pid]); $patient = $ps->fetch();

                // Get current queue entry
                $qs = getDB()->prepare("SELECT * FROM queue_entries WHERE patient_id=? AND DATE(arrival_time)=CURDATE() ORDER BY arrival_time DESC LIMIT 1");
                $qs->execute([$pid]); $queue = $qs->fetch();

                // Get latest prescription
                $prs = getDB()->prepare("SELECT * FROM prescriptions WHERE patient_id=? ORDER BY created_at DESC LIMIT 1");
                $prs->execute([$pid]); $presc = $prs->fetch();
                if($presc){$presc['drugs']=json_decode($presc['drugs'],true)??[];$presc['lab_tests']=json_decode($presc['lab_tests'],true)??[];}

                // Get billing
                $bs = getDB()->prepare("SELECT * FROM billing WHERE patient_id=? ORDER BY created_at DESC LIMIT 1");
                $bs->execute([$pid]); $bill = $bs->fetch();

                // Determine next step
                $nextStep = 'triage';
                if($queue&&$queue['status']==='done') {
                    if($presc&&$presc['next_action']==='pharmacy'&&$presc['status']==='pending') $nextStep='pharmacy';
                    elseif($bill&&$bill['payment_status']==='pending') $nextStep='billing';
                    elseif($bill&&$bill['payment_status']==='simulated') $nextStep='discharge';
                    else $nextStep='discharge';
                } elseif($queue&&in_array($queue['status'],['called','in_progress'])) {
                    $nextStep='doctor';
                } elseif($queue&&$queue['status']==='waiting') {
                    $nextStep='waiting';
                }

                ok(['patient'=>$patient,'queue'=>$queue,'prescription'=>$presc,
                    'billing'=>$bill,'nextStep'=>$nextStep,'scannedAt'=>$dept]);
            } catch(Exception $e) { fail('Scan failed: '.$e->getMessage()); }

        case 'dispensePharmacy':
            try {
                $presId = (int)($input['prescriptionId'] ?? 0);
                $pid    = $input['patientId'] ?? '';
                getDB()->prepare("UPDATE prescriptions SET status='dispensed' WHERE id=? AND patient_id=?")
                ->execute([$presId,$pid]);
                getDB()->prepare("UPDATE billing SET payment_status='pending' WHERE prescription_id=? AND patient_id=?")
                ->execute([$presId,$pid]);
                ok(['message'=>'Dispensed — billing updated']);
            } catch(Exception $e) { fail($e->getMessage()); }


        case 'scheduleFollowUps':
            try {
                getDB()->exec("CREATE TABLE IF NOT EXISTS followup_schedule (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id VARCHAR(20), phone VARCHAR(20),
                    patient_name VARCHAR(100), risk VARCHAR(20),
                    symptoms TEXT, last_decision TEXT,
                    label VARCHAR(50), send_at DATETIME,
                    sent TINYINT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_due(send_at,sent))");
                $pid  = $input['patientId'] ?? '';
                $ph   = $input['phone']     ?? '';
                $name = $input['name']      ?? '';
                $risk = $input['risk']      ?? '';
                $sym  = $input['symptoms']  ?? '';
                $dec  = $input['decision']  ?? '';
                $now  = time();
                $schedule = [
                    ['1h',   $now+3600],
                    ['6h',   $now+21600],
                    ['24h',  $now+86400],
                    ['48h',  $now+172800],
                    ['7day', $now+604800],
                ];
                foreach($schedule as [$label,$ts]) {
                    getDB()->prepare("INSERT INTO followup_schedule
                        (patient_id,phone,patient_name,risk,symptoms,last_decision,label,send_at)
                        VALUES(?,?,?,?,?,?,?,FROM_UNIXTIME(?))")
                    ->execute([$pid,$ph,$name,$risk,$sym,$dec,$label,$ts]);
                }
                ok(['scheduled'=>5]);
            } catch(Exception $e) { fail($e->getMessage()); }

        case 'sendFollowUp':
            try {
                $pid    = $input['patientId']   ?? '';
                $phone  = $input['phone']        ?? '';
                $name   = $input['name']         ?? '';
                $risk   = $input['risk']         ?? '';
                $type   = $input['followUpType'] ?? '24h';
                // Return data for AI to generate message
                ok(['success'=>true,'message'=>'Follow-up data ready',
                    'patientName'=>$name,'risk'=>$risk,'type'=>$type]);
            } catch(Exception $e) { fail($e->getMessage()); }

        // ── DEFAULT ───────────────────────────────────────
        default:
            ok(['message'=>'HospAIs API v3 running OK','time'=>date('Y-m-d H:i:s')]);
    }

} catch (PDOException $e) {
    fail('Database error: '.$e->getMessage());
} catch (Exception $e) {
    fail('Server error: '.$e->getMessage());
}
?>
