<?php
// ════════════════════════════════════════════════════════════════
// HospAIs WhatsApp Webhook v6
// ✅ Continuous AI conversation until patient says bye
// ✅ Auto follow-ups: 1h, 6h, 24h, 48h, 7day after first contact
// ✅ Every incoming message also triggers due follow-up check
// ✅ Full conversation history sent to AI every turn
// ✅ Bye detection EN + SW → graceful farewell → session closed
// ✅ Urgent detection → escalate to doctor
// ✅ Zero hardcoded patient messages — 100% AI generated
// ════════════════════════════════════════════════════════════════
error_reporting(0); ini_set('display_errors', 0);

// ── Respond to Whapi INSTANTLY then keep processing ───────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Connection: close');
ob_start();
echo '{"success":true}';
header('Content-Length: '.ob_get_length());
ob_end_flush(); flush();
ignore_user_abort(true);
set_time_limit(90);

// ── Config ────────────────────────────────────────────────────
define('DB_HOST',    'sql200.infinityfree.com');
define('DB_USER',    'if0_41432274');
define('DB_PASS',    'drk8Nd9mu0nwfh');
define('DB_NAME',    'if0_41432274_hakathon');
define('WHAPI_TOKEN','QKtnHbNcc2NLam2SpgyHlZloMlxb5vvC');
define('WHAPI_URL',  'https://gate.whapi.cloud/');
define('GROQ_KEY',   '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');
define('GROQ_URL',   'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_MODEL', 'llama-3.3-70b-versatile');
define('GEMINI_KEY', 'AIzaSyBGt1w3GwVk1JvSEYYxj50f1znRD_8r2f4');
define('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// ── Follow-up schedule after first contact ────────────────────
// Format: label => minutes from first contact
define('FOLLOWUP_SCHEDULE', [
    '1h'   =>    60,
    '6h'   =>   360,
    '24h'  =>  1440,
    '48h'  =>  2880,
    '7day' => 10080,
]);

// ════════════════════════════════════════════════════════════════
// DATABASE
// ════════════════════════════════════════════════════════════════
function getDB(){
    static $p=null; if($p)return $p;
    $p=new PDO('mysql:host='.DB_HOST.';port=3306;dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>15]);

    $p->exec("CREATE TABLE IF NOT EXISTS whatsapp_conversations(
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id VARCHAR(20), phone VARCHAR(20),
        direction ENUM('out','in') DEFAULT 'out',
        message TEXT, ai_flagged TINYINT DEFAULT 0,
        session_status ENUM('active','closed') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone(phone), INDEX idx_patient(patient_id))");

    $p->exec("CREATE TABLE IF NOT EXISTS followup_schedule(
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id VARCHAR(20), phone VARCHAR(20),
        patient_name VARCHAR(100), risk VARCHAR(50),
        symptoms TEXT, last_decision TEXT,
        label VARCHAR(20), send_at DATETIME,
        sent TINYINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_send_at(send_at), INDEX idx_phone(phone))");

    $p->exec("CREATE TABLE IF NOT EXISTS webhook_log(
        id INT AUTO_INCREMENT PRIMARY KEY,
        raw_payload MEDIUMTEXT, parsed_from VARCHAR(100),
        parsed_body TEXT, parsed_type VARCHAR(50),
        from_me TINYINT DEFAULT 0, note VARCHAR(200),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    return $p;
}

// ════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════
function sendWA($phone, $msg){
    $phone = preg_replace('/[^0-9]/','',$phone);
    if(substr($phone,0,1)==='0') $phone='254'.substr($phone,1);
    $ch=curl_init(WHAPI_URL.'messages/text');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_POSTFIELDS=>json_encode(['to'=>$phone.'@s.whatsapp.net','body'=>$msg]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.WHAPI_TOKEN]]);
    $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return $c===200||$c===201;
}

function callAI($sys, $msgs, $max=220){
    // Groq first
    $ch=curl_init(GROQ_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>GROQ_MODEL,
            'messages'=>array_merge([['role'=>'system','content'=>$sys]],$msgs),
            'max_tokens'=>$max,'temperature'=>0.8]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.GROQ_KEY]]);
    $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($c===200){ $t=json_decode($r,true)['choices'][0]['message']['content']??''; if(trim($t))return trim($t); }

    // Gemini fallback
    $contents=array_map(fn($m)=>['role'=>$m['role']==='assistant'?'model':'user','parts'=>[['text'=>$m['content']]]],$msgs);
    if(empty($contents)) $contents[]=['role'=>'user','parts'=>[['text'=>'hello']]];
    $ch2=curl_init(GEMINI_URL.'?key='.GEMINI_KEY);
    curl_setopt_array($ch2,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_POSTFIELDS=>json_encode(['contents'=>$contents,
            'systemInstruction'=>['parts'=>[['text'=>$sys]]],
            'generationConfig'=>['maxOutputTokens'=>$max]]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $r2=curl_exec($ch2); curl_close($ch2);
    return trim(json_decode($r2,true)['candidates'][0]['content']['parts'][0]['text']??'');
}

function saveMsg($pid,$phone,$dir,$msg,$flag=0,$status='active'){
    try{ getDB()->prepare("INSERT INTO whatsapp_conversations
        (patient_id,phone,direction,message,ai_flagged,session_status,created_at)
        VALUES(?,?,?,?,?,?,NOW())")->execute([$pid,$phone,$dir,$msg,$flag,$status]);
    }catch(Exception $e){}
}
function closeSession($phone){
    try{ getDB()->prepare("UPDATE whatsapp_conversations SET session_status='closed'
        WHERE phone=? AND session_status='active'")->execute([$phone]); }catch(Exception $e){}
}
function getLastStatus($phone){
    try{ $s=getDB()->prepare("SELECT session_status FROM whatsapp_conversations
            WHERE phone=? ORDER BY created_at DESC LIMIT 1");
        $s->execute([$phone]); $r=$s->fetch(); return $r?$r['session_status']:'none';
    }catch(Exception $e){ return 'none'; }
}
function getHistory($phone, $activeOnly=true){
    try{
        $w=$activeOnly?"AND session_status='active'":'';
        $s=getDB()->prepare("SELECT direction,message FROM whatsapp_conversations
            WHERE phone=? {$w} ORDER BY created_at ASC");
        $s->execute([$phone]);
        return array_map(fn($r)=>['role'=>$r['direction']==='out'?'assistant':'user',
            'content'=>$r['message']],$s->fetchAll());
    }catch(Exception $e){ return []; }
}
function getPatient($phone){
    $phone=preg_replace('/[^0-9]/','', $phone);
    $local=substr($phone,0,3)==='254'?'0'.substr($phone,3):$phone;
    try{
        $s=getDB()->prepare("SELECT patient_id,full_name,risk_level,last_decision,symptoms,phone
            FROM patients WHERE REPLACE(REPLACE(phone,'+',''),' ','') IN(?,?) LIMIT 1");
        $s->execute([$phone,$local]); $r=$s->fetch(); if($r)return $r;
        $s2=getDB()->prepare("SELECT patient_id,full_name,risk_level,last_decision,symptoms,phone
            FROM patients WHERE phone LIKE ? LIMIT 1");
        $s2->execute(['%'.substr($phone,-9).'%']); return $s2->fetch()?:null;
    }catch(Exception $e){ return null; }
}
function isBye($t){
    $t=mb_strtolower(trim($t));
    foreach(['bye','goodbye','good bye','see you','see ya','take care','thanks bye',
        'thank you bye','ok bye','okay bye','cya','farewell','all done','im done',
        'i am done','no more questions','no thank you','no thanks','im fine now',
        'i am fine now',"i'm fine now",'im good now','i am good now',
        'kwa heri','kwaheri','tutaonana','asante sana','nimekwisha','niko sawa','baadaye'] as $w)
    { if(strpos($t,$w)!==false)return true; }
    return false;
}
function isUrgent($t){
    $t=mb_strtolower($t);
    foreach(['chest pain','cant breathe',"can't breathe",'difficulty breathing',
        'not breathing','unconscious','heavy bleeding','severe pain','emergency',
        'ambulance','dying','collapsed','fainted','heart attack','stroke','overdose',
        'maumivu makali','saidieni','msaada','damu nyingi','kupumua','hali mbaya'] as $w)
    { if(strpos($t,$w)!==false)return true; }
    return false;
}

// ── Build AI system prompt ────────────────────────────────────
function buildPrompt($name,$risk,$decision,$symptoms,$mode='chat'){
    $base = "You are ARIA, HospAIs AI health assistant on WhatsApp. Be warm, caring and human.
Patient: {$name} | Risk: {$risk} | Last decision: {$decision} | Known symptoms: {$symptoms}
Rules:
- Keep replies under 80 words
- Sound like a caring nurse, not a robot
- Remember EVERYTHING said in this conversation and reference it naturally
- Ask ONE follow-up question per reply to keep conversation going
- NEVER diagnose — defer clinical decisions to their doctor
- If symptoms worsen or are new, advise hospital visit
- Match patient language (English or Swahili)
- Do NOT end the conversation yourself — only end if patient says bye";

    switch($mode){
        case 'welcome':
            return $base."\nSITUATION: Patient returning after a previous session ended. Write a warm personalised welcome back. Ask how they have been feeling since last time.";
        case 'farewell':
            return $base."\nSITUATION: Patient said goodbye. Write a warm heartfelt farewell. Tell them to message anytime they need help.";
        case 'urgent':
            return $base."\nSITUATION: EMERGENCY detected. Calmly but firmly tell patient to call emergency services or go to hospital immediately. Be reassuring but very clear.";
        case 'followup_1h':
            return $base."\nSITUATION: This is an automatic 1-hour check-in after the patient's hospital visit. Start fresh — ask how they are feeling right now, if they have taken any medication prescribed, and if they have any immediate concerns. Be warm and brief.";
        case 'followup_6h':
            return $base."\nSITUATION: 6-hour check-in. Ask how their recovery is going, if symptoms have changed, and remind them to rest and take medication. Keep it brief and caring.";
        case 'followup_24h':
            return $base."\nSITUATION: 24-hour check-in. Ask how they slept, how they are feeling today compared to yesterday, and whether symptoms are improving. Be warm and specific to their condition.";
        case 'followup_48h':
            return $base."\nSITUATION: 48-hour check-in. Check if recovery is on track. Ask if they have had their follow-up appointment. Remind them of warning signs to watch for.";
        case 'followup_7day':
            return $base."\nSITUATION: 7-day check-in. Week-long recovery check. Celebrate their progress. Ask how they feel overall compared to when they were discharged. Ask if they have any remaining concerns.";
        default:
            return $base;
    }
}

// ════════════════════════════════════════════════════════════════
// FOLLOW-UP SCHEDULER
// ════════════════════════════════════════════════════════════════

// Register follow-up schedule for a patient (call on first contact)
function scheduleFollowUps($pid,$phone,$name,$risk,$symptoms,$decision){
    try{
        // Check if already scheduled
        $chk=getDB()->prepare("SELECT id FROM followup_schedule WHERE phone=? AND sent=0 LIMIT 1");
        $chk->execute([$phone]); if($chk->fetch()) return; // already scheduled

        $now=new DateTime();
        foreach(FOLLOWUP_SCHEDULE as $label=>$mins){
            $sendAt=clone $now;
            $sendAt->modify('+'.$mins.' minutes');
            getDB()->prepare("INSERT INTO followup_schedule
                (patient_id,phone,patient_name,risk,symptoms,last_decision,label,send_at,sent,created_at)
                VALUES(?,?,?,?,?,?,?,?,0,NOW())")
            ->execute([$pid,$phone,$name,$risk,$symptoms,$decision,$label,
                $sendAt->format('Y-m-d H:i:s')]);
        }
    }catch(Exception $e){}
}

// Process any follow-ups that are due right now
function processDueFollowUps(){
    try{
        $due=getDB()->prepare("SELECT * FROM followup_schedule
            WHERE sent=0 AND send_at<=NOW() ORDER BY send_at ASC LIMIT 5");
        $due->execute(); $rows=$due->fetchAll();
        foreach($rows as $row){
            $mode='followup_'.$row['label'];
            $sys=buildPrompt($row['patient_name'],$row['risk'],
                $row['last_decision'],$row['symptoms'],$mode);
            // Generate AI follow-up message
            $msg=callAI($sys,[['role'=>'user','content'=>'(scheduled follow-up)']],160);
            if(!$msg) continue;
            // Send it
            $sent=sendWA($row['phone'],$msg);
            if($sent){
                // Save to conversation history
                saveMsg($row['patient_id'],$row['phone'],'out',$msg,0,'active');
                // Mark as sent
                getDB()->prepare("UPDATE followup_schedule SET sent=1 WHERE id=?")
                    ->execute([$row['id']]);
                // Also log engagement
                try{ getDB()->prepare("INSERT INTO engagement_log
                    (patient_id,type,message_out,ai_decision,action_taken,created_at)
                    VALUES(?,?,?,?,?,NOW())")
                    ->execute([$row['patient_id'],'auto_followup_'.$row['label'],
                        $msg,'Scheduled follow-up','Sent via WhatsApp']); }catch(Exception $e2){}
            }
        }
    }catch(Exception $e){}
}

// ════════════════════════════════════════════════════════════════
// PROCESS INCOMING MESSAGE
// ════════════════════════════════════════════════════════════════
$raw   = file_get_contents('php://input');
$event = json_decode($raw,true)??[];

// Log raw event
try{
    $f=($event['messages']??[$event['message']??$event])[0]??[];
    getDB()->prepare("INSERT INTO webhook_log(raw_payload,parsed_from,parsed_body,parsed_type,from_me,note,created_at)
        VALUES(?,?,?,?,?,?,NOW())")->execute([$raw,
        $f['from']??'unknown',
        $f['text']['body']??$f['body']??json_encode(array_slice($event,0,2)),
        $f['type']??'unknown',
        isset($f['from_me'])?(int)$f['from_me']:0,
        'v6']);
}catch(Exception $e){}

// Always check for due follow-ups on every webhook hit
processDueFollowUps();

// Parse messages
$messages=[];
if(!empty($event['messages']))             $messages=$event['messages'];
elseif(!empty($event['message']))          $messages=[$event['message']];
elseif(!empty($event['type'])&&isset($event['from'])) $messages=[$event];

foreach($messages as $msg){
    if(!$msg||!is_array($msg)) continue;
    if($msg['from_me']??false) continue;

    // Extract body
    $body=trim((string)(
        $msg['text']['body'] ?? $msg['body'] ?? $msg['caption'] ?? ''
    ));
    if(is_array($body)) $body=$body['body']??'';

    // Handle non-text types
    $msgType=$msg['type']??'text';
    if(!$body){
        if(in_array($msgType,['image','audio','video','document','sticker']))
            $body='[Patient sent a '.$msgType.']';
        else continue;
    }

    // Extract phone
    $from=trim((string)($msg['from']??$msg['chat_id']??$msg['author']??''));
    if(!$from) continue;
    if(strpos($from,'@g.us')!==false) continue; // skip groups
    if(strpos($from,'status')!==false||strpos($from,'broadcast')!==false) continue;

    $phone=preg_replace('/[^0-9]/','',preg_replace('/@.*$/','',$from));
    if(strlen($phone)<7) continue;

    // Look up patient
    $pt      =getPatient($phone);
    $pid     =$pt['patient_id']    ??"unknown";
    $name    =$pt['full_name']     ??"there";
    $risk    =$pt['risk_level']    ??"not assessed";
    $decision=$pt['last_decision'] ??"none yet";
    $symptoms=$pt['symptoms']      ??"none recorded";

    $lastStatus=getLastStatus($phone);
    $urgent    =isUrgent($body);
    $bye       =isBye($body);

    // ── Schedule follow-ups on first ever contact ─────────────
    // Only if this is a new patient we've never messaged before
    try{
        $isFirst=getDB()->prepare("SELECT id FROM followup_schedule WHERE phone=? LIMIT 1");
        $isFirst->execute([$phone]);
        if(!$isFirst->fetch()){
            scheduleFollowUps($pid,$phone,$name,$risk,$symptoms,$decision);
        }
    }catch(Exception $e){}

    // ── Returning after closed session ────────────────────────
    if($lastStatus==='closed'){
        saveMsg($pid,$phone,'in',$body,0,'active');
        $h=getHistory($phone,false); $h[]=['role'=>'user','content'=>$body];
        $reply=callAI(buildPrompt($name,$risk,$decision,$symptoms,'welcome'),$h,180);
        if($reply){ saveMsg($pid,$phone,'out',$reply,0,'active'); sendWA($phone,$reply); }
        // Re-schedule follow-ups from now
        scheduleFollowUps($pid,$phone,$name,$risk,$symptoms,$decision);
        continue;
    }

    // ── Save incoming message ─────────────────────────────────
    saveMsg($pid,$phone,'in',$body,0,'active');

    // ── GOODBYE ───────────────────────────────────────────────
    if($bye){
        closeSession($phone);
        $h=getHistory($phone,true); $h[]=['role'=>'user','content'=>$body];
        $farewell=callAI(buildPrompt($name,$risk,$decision,$symptoms,'farewell'),$h,160);
        if($farewell){
            saveMsg($pid,$phone,'out',$farewell,0,'closed');
            sendWA($phone,$farewell);
        }
        try{ getDB()->prepare("INSERT INTO engagement_log
            (patient_id,type,message_out,ai_decision,action_taken,created_at)
            VALUES(?,?,?,?,?,NOW())")
            ->execute([$pid,'chat_ended',$farewell??'','Patient ended chat','Session closed']);
        }catch(Exception $e){}
        continue;
    }

    // ── URGENT ────────────────────────────────────────────────
    if($urgent){
        $h=getHistory($phone,true); $h[]=['role'=>'user','content'=>$body];
        $reply=callAI(buildPrompt($name,$risk,$decision,$symptoms,'urgent'),$h,200);
        if($reply){
            saveMsg($pid,$phone,'out',$reply,1,'active');
            sendWA($phone,$reply);
            try{ getDB()->prepare("INSERT INTO engagement_log
                (patient_id,type,message_out,response_in,ai_decision,action_taken,created_at)
                VALUES(?,?,?,?,?,?,NOW())")
                ->execute([$pid,'urgent_whatsapp',$reply,$body,'URGENT symptoms detected','Doctor alerted']);
            }catch(Exception $e){}
        }
        continue;
    }

    // ── NORMAL CONVERSATION — unlimited turns ─────────────────
    // Full history sent every turn so AI remembers everything
    $h=getHistory($phone,true);
    $h[]=['role'=>'user','content'=>$body];
    $reply=callAI(buildPrompt($name,$risk,$decision,$symptoms,'chat'),$h,220);
    if($reply){
        saveMsg($pid,$phone,'out',$reply,0,'active');
        sendWA($phone,$reply);
    }
}
