<?php
session_start();
require_once __DIR__ . "/../db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$debug = (int)($_GET["debug"] ?? 0) === 1;
$MAX_CREDITS = 18;

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function timeToMinutes($t) {
    if ($t instanceof DateTime) {
        return ((int)$t->format("H")) * 60 + (int)$t->format("i");
    }
    if (is_string($t) && $t !== "") {
        $parts = explode(":", $t);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        return $h * 60 + $m;
    }
    return null;
}

function isPassingGrade($grade) {
    if ($grade === null) return false;
    $g = strtoupper(trim((string)$grade));
    return $g !== "" && $g !== "F";
}

function addSqlsrvError($baseMsg, $debug) {
    if (!$debug) return $baseMsg;
    $e = sqlsrv_errors();
    return $baseMsg . "\n" . print_r($e, true);
}

$error = "";
$ok = "";

/* Load students */
$students = [];
$st = sqlsrv_query($conn, "SELECT student_id, name FROM STUDENT ORDER BY name ASC");
if ($st !== false) {
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $students[] = $r;
}

/* Load courses */
$courses = [];
$ct = sqlsrv_query(
    $conn,
    "SELECT course_id, course_name, credit_hours, schedule_day, start_time, end_time
     FROM COURSE
     ORDER BY course_name ASC"
);
if ($ct !== false) {
    while ($r = sqlsrv_fetch_array($ct, SQLSRV_FETCH_ASSOC)) {
        $courses[] = $r;
    }
}

function getExistingEnrollments($conn, $studentId, $semester, $year) {
    $sql = "
        SELECT c.course_id, c.course_name, c.credit_hours, c.schedule_day, c.start_time, c.end_time
        FROM ENROLLMENT e
        JOIN COURSE c ON c.course_id = e.course_id
        WHERE e.student_id = ? AND e.semester = ? AND e.year = ?
    ";
    $st = sqlsrv_query($conn, $sql, [$studentId, $semester, $year]);
    $rows = [];
    if ($st !== false) {
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    }
    return $rows;
}

function getCurrentCredits($conn, $studentId, $semester, $year) {
    $sql = "
        SELECT ISNULL(SUM(c.credit_hours), 0) AS total_credits
        FROM ENROLLMENT e
        JOIN COURSE c ON c.course_id = e.course_id
        WHERE e.student_id = ? AND e.semester = ? AND e.year = ?
    ";
    $st = sqlsrv_query($conn, $sql, [$studentId, $semester, $year]);
    if ($st === false) return 0.0;
    $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    return (float)($row["total_credits"] ?? 0);
}

function hasScheduleConflict($aDay, $aStart, $aEnd, $bDay, $bStart, $bEnd) {
    if ($aDay === "" || $bDay === "") return false;
    if (strcasecmp($aDay, $bDay) !== 0) return false;

    $aS = timeToMinutes($aStart);
    $aE = timeToMinutes($aEnd);
    $bS = timeToMinutes($bStart);
    $bE = timeToMinutes($bEnd);

    if ($aS === null || $aE === null || $bS === null || $bE === null) return false;
    return ($aS < $bE && $aE > $bS);
}

function prerequisiteSatisfied($conn, $studentId, $courseId) {
    $preSql = "SELECT prerequisite_course_id FROM PREREQUISITE WHERE course_id = ?";
    $preSt = sqlsrv_query($conn, $preSql, [$courseId]);
    if ($preSt === false) return true;

    while ($pr = sqlsrv_fetch_array($preSt, SQLSRV_FETCH_ASSOC)) {
        $preCourseId = (int)($pr["prerequisite_course_id"] ?? 0);
        if ($preCourseId > 0) {
            $passSql = "
                SELECT TOP 1 r.grade
                FROM RESULT r
                JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
                WHERE e.student_id = ? AND e.course_id = ?
                ORDER BY r.result_id DESC
            ";
            $passSt = sqlsrv_query($conn, $passSql, [$studentId, $preCourseId]);
            $passRow = $passSt ? sqlsrv_fetch_array($passSt, SQLSRV_FETCH_ASSOC) : null;
            $grade = $passRow["grade"] ?? null;
            if (!isPassingGrade($grade)) return false;
        }
    }
    return true;
}

/* POST submit */
$selectedStudentId = (int)($_POST["student_id"] ?? 0);
$selectedSemester = trim($_POST["semester"] ?? "");
$selectedYear = (int)($_POST["year"] ?? 0);
$selectedCourseIds = $_POST["course_ids"] ?? [];
if (!is_array($selectedCourseIds)) $selectedCourseIds = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        if ($selectedStudentId <= 0 || $selectedSemester === "" || $selectedYear <= 0 || count($selectedCourseIds) === 0) {
            $error = "All fields are required.";
        } else {
            $selectedCourseIds = array_values(array_unique(array_map("intval", $selectedCourseIds)));
            $selectedCourseIds = array_filter($selectedCourseIds, fn($x) => $x > 0);

            if (count($selectedCourseIds) === 0) {
                $error = "Please select at least one course.";
            } else {
                $courseMap = [];
                foreach ($courses as $c) {
                    $cid = (int)($c["course_id"] ?? 0);
                    if ($cid > 0) $courseMap[$cid] = $c;
                }

                foreach ($selectedCourseIds as $cid) {
                    if (!isset($courseMap[$cid])) {
                        $error = "Selected course not found.";
                        break;
                    }
                }
            }
        }

        if ($error === "") {
            $existing = getExistingEnrollments($conn, $selectedStudentId, $selectedSemester, $selectedYear);
            $currentCredits = getCurrentCredits($conn, $selectedStudentId, $selectedSemester, $selectedYear);

            $selectedCourseRows = [];
            $selectedCredits = 0.0;
            foreach ($selectedCourseIds as $cid) {
                $row = null;
                foreach ($courses as $c) {
                    if ((int)$c["course_id"] === (int)$cid) { $row = $c; break; }
                }
                if ($row) {
                    $selectedCourseRows[] = $row;
                    $selectedCredits += (float)($row["credit_hours"] ?? 0);
                }
            }

            if (($currentCredits + $selectedCredits) > $MAX_CREDITS) {
                $error = "Credit limit exceeded.";
            }

            if ($error === "") {
                $dupSql = "
                    SELECT TOP 1 enrollment_id
                    FROM ENROLLMENT
                    WHERE student_id = ? AND course_id = ? AND semester = ? AND year = ?
                ";
                foreach ($selectedCourseIds as $cid) {
                    $dupSt = sqlsrv_query($conn, $dupSql, [$selectedStudentId, $cid, $selectedSemester, $selectedYear]);
                    if ($dupSt !== false && sqlsrv_fetch_array($dupSt, SQLSRV_FETCH_ASSOC)) {
                        $error = "Duplicate enrollment is not allowed.";
                        break;
                    }
                }
            }

            if ($error === "") {
                foreach ($selectedCourseIds as $cid) {
                    if (!prerequisiteSatisfied($conn, $selectedStudentId, $cid)) {
                        $error = "Prerequisite not satisfied.";
                        break;
                    }
                }
            }

            if ($error === "") {
                $allExisting = $existing;
                $toCheck = $selectedCourseRows;

                foreach ($toCheck as $sel) {
                    $selDay = (string)($sel["schedule_day"] ?? "");
                    $selStart = $sel["start_time"] ?? null;
                    $selEnd = $sel["end_time"] ?? null;

                    foreach ($allExisting as $ex) {
                        if (hasScheduleConflict(
                            $selDay, $selStart, $selEnd,
                            (string)($ex["schedule_day"] ?? ""), $ex["start_time"] ?? null, $ex["end_time"] ?? null
                        )) {
                            $error = "Schedule conflict detected.";
                            break 2;
                        }
                    }
                }

                if ($error === "") {
                    $n = count($toCheck);
                    for ($i = 0; $i < $n; $i++) {
                        for ($j = $i + 1; $j < $n; $j++) {
                            $a = $toCheck[$i];
                            $b = $toCheck[$j];
                            if (hasScheduleConflict(
                                (string)($a["schedule_day"] ?? ""), $a["start_time"] ?? null, $a["end_time"] ?? null,
                                (string)($b["schedule_day"] ?? ""), $b["start_time"] ?? null, $b["end_time"] ?? null
                            )) {
                                $error = "Schedule conflict detected.";
                                break 2;
                            }
                        }
                    }
                }
            }

            if ($error === "") {
                sqlsrv_begin_transaction($conn);

                $insSql = "
                    INSERT INTO ENROLLMENT (student_id, course_id, enrollment_date, semester, year)
                    VALUES (?, ?, CAST(GETDATE() AS date), ?, ?)
                ";

                $okAll = true;
                foreach ($selectedCourseIds as $cid) {
                    $insSt = sqlsrv_query($conn, $insSql, [$selectedStudentId, $cid, $selectedSemester, $selectedYear]);
                    if ($insSt === false) {
                        $okAll = false;
                        $error = addSqlsrvError("Insert failed.", $debug);
                        break;
                    }
                }

                if ($okAll) {
                    sqlsrv_commit($conn);
                    header("Location: list.php?semester=" . urlencode($selectedSemester) . "&year=" . urlencode((string)$selectedYear) . "&success=1");
                    exit();
                } else {
                    sqlsrv_rollback($conn);
                }
            }
        }
    }
}

/* Credit summary default (for UI) */
$uiCurrentCredits = 0.0;
if ($selectedStudentId > 0 && $selectedSemester !== "" && $selectedYear > 0) {
    $uiCurrentCredits = getCurrentCredits($conn, $selectedStudentId, $selectedSemester, $selectedYear);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enroll Student - UMS</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --border:#e5e7eb;
      --primary:#2f3cff;
      --primary-weak:rgba(47,60,255,0.12);
      --shadow:0 10px 25px rgba(15,23,42,0.08);
      --radius:14px;
      --good:#10b981;
      --danger:#ef4444;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .app{display:flex;min-height:100vh;}
    .sidebar{
      width:260px;background:#fff;border-right:1px solid var(--border);
      padding:18px 14px;position:sticky;top:0;height:100vh;
    }
    .brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:20px;padding:6px 10px;margin-bottom:14px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:10px;}
    .nav a{
      display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;
      color:var(--text);font-weight:800;
    }
    .nav a:hover{background:#f3f4f6;}
    .nav a.active{background:var(--primary);color:#fff;}
    .ico{width:18px;height:18px;display:inline-block}
    .main{flex:1;display:flex;flex-direction:column;}
    .topbar{
      height:60px;background:#fff;border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:flex-end;padding:0 18px;gap:14px;
      position:sticky;top:0;z-index:5;
    }
    .logout{
      display:inline-flex;align-items:center;gap:8px;font-weight:900;color:#0f172a;
      padding:8px 10px;border-radius:10px;
    }
    .logout:hover{background:#f3f4f6;}
    .container{padding:22px 22px 28px;}
    .back{
      display:inline-flex;align-items:center;gap:10px;color:#0f172a;
      font-weight:900;margin-bottom:8px;
    }
    .back:hover{opacity:0.85;}
    .page-title{font-size:34px;font-weight:950;letter-spacing:-0.8px;margin:0 0 14px;}
    .grid{
      display:grid;grid-template-columns: 1fr 380px;gap:18px;align-items:start;
    }
    .card{
      background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);padding:16px;
    }
    .form-grid{
      display:grid;grid-template-columns:1fr 1fr;gap:14px;
    }
    .field label{display:block;font-weight:900;font-size:13px;margin:0 0 8px;}
    select,input{
      width:100%;padding:12px 12px;border:1px solid var(--border);border-radius:12px;outline:none;
      background:#fff;font-weight:800;font-size:14px;
    }
    select:focus,input:focus{border-color:var(--primary);}
    .full{grid-column:1 / -1;}
    .courses-title{font-weight:950;margin:2px 0 10px;font-size:14px;}
    .course-item{
      border:1px solid var(--border);border-radius:12px;padding:12px 12px;
      display:flex;gap:12px;align-items:center;background:#fff;margin-bottom:10px;
    }
    .course-item:hover{background:#fafbff;}
    .course-item input{width:18px;height:18px;}
    .course-main{display:flex;flex-direction:column;gap:4px;}
    .course-name{font-weight:950;}
    .course-sub{color:var(--muted);font-weight:800;font-size:13px;}
    .actions{
      display:flex;gap:12px;margin-top:12px;
    }
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;border:1px solid var(--border);
      background:#fff;font-weight:950;cursor:pointer;min-width:160px;
    }
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-primary:hover{filter:brightness(0.97);}
    .btn-ghost{background:#f3f4f6;}
    .msg-err{margin-bottom:12px;padding:12px 14px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;white-space:pre-wrap;}
    .summary-title{font-size:22px;font-weight:950;margin:0 0 14px;}
    .summary-row{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #eef2f7;}
    .summary-row:last-child{border-bottom:none;}
    .summary-label{color:var(--muted);font-weight:900;}
    .summary-val{font-weight:950;}
    .summary-val.good{color:var(--good);}
    .progress-wrap{margin-top:14px;}
    .progress-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
    .bar{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;}
    .fill{height:100%;width:0%;background:var(--primary);border-radius:999px;}
    @media (max-width: 1050px){
      .grid{grid-template-columns:1fr;}
    }
    @media (max-width: 760px){
      .form-grid{grid-template-columns:1fr;}
      .btn{min-width:0;flex:1;}
      .actions{flex-wrap:wrap;}
    }
  </style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 13h8V3H3v10zM13 21h8V11h-8v10zM13 3h8v6h-8V3zM3 21h8v-6H3v6z"/>
        </svg>
        Dashboard
      </a>
      <a href="../students/list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
        Students
      </a>
      <a href="../teachers/list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M4 21v-2a4 4 0 0 1 3-3.87"/>
          <circle cx="12" cy="7" r="4"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          <path d="M8 3.13a4 4 0 0 0 0 7.75"/>
        </svg>
        Teachers
      </a>
      <a href="../departments/list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 22h18"/>
          <path d="M7 22V8"/>
          <path d="M17 22V8"/>
          <path d="M12 2l9 6H3l9-6z"/>
        </svg>
        Departments
      </a>
      <a href="../courses/list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20"/>
          <path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20"/>
          <path d="M6.5 2v20"/>
        </svg>
        Courses
      </a>
      <a class="active" href="list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <path d="M7 8h10"/>
          <path d="M7 12h10"/>
          <path d="M7 16h10"/>
        </svg>
        Enrollments
      </a>
      <a href="../results/list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 11l3 3L22 4"/>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        Results
      </a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <a class="logout" href="../logout.php" title="Logout">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <path d="M16 17l5-5-5-5"/>
          <path d="M21 12H9"/>
        </svg>
        Logout
      </a>
    </div>

    <div class="container">
      <a class="back" href="list.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M15 18l-6-6 6-6"/>
        </svg>
        Back to Enrollments
      </a>
      <h1 class="page-title">Enroll Student in Courses</h1>

      <?php if ($error !== ""): ?>
        <div class="msg-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="grid">
        <div class="card">
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

            <div class="form-grid">
              <div class="field">
                <label for="student_id">Student ID *</label>
                <select id="student_id" name="student_id" required>
                  <option value="">Select Student</option>
                  <?php foreach ($students as $s): ?>
                    <?php $sid = (int)($s["student_id"] ?? 0); ?>
                    <option value="<?php echo $sid; ?>" <?php echo $selectedStudentId === $sid ? "selected" : ""; ?>>
                      <?php echo h(((string)($s["student_id"] ?? "")) . " - " . ((string)($s["name"] ?? ""))); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="semester">Semester *</label>
                <select id="semester" name="semester" required>
                  <option value="">Select Semester</option>
                  <?php
                    $opts = ["Spring","Summer","Fall"];
                    foreach ($opts as $opt):
                  ?>
                    <option value="<?php echo h($opt); ?>" <?php echo $selectedSemester === $opt ? "selected" : ""; ?>>
                      <?php echo h($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="year">Year *</label>
                <input id="year" name="year" type="number" placeholder="e.g., 2025" value="<?php echo $selectedYear > 0 ? h($selectedYear) : ""; ?>" required>
              </div>

              <div class="field"></div>

              <div class="full">
                <div class="courses-title">Select Courses *</div>

                <?php foreach ($courses as $c): ?>
                  <?php
                    $cid = (int)($c["course_id"] ?? 0);
                    $cname = (string)($c["course_name"] ?? "");
                    $cr = (float)($c["credit_hours"] ?? 0);
                    $day = (string)($c["schedule_day"] ?? "");
                    $st = fmtTime($c["start_time"] ?? null);
                    $et = fmtTime($c["end_time"] ?? null);
                    $sched = trim($day . " " . (($st !== "" && $et !== "") ? ($st . "-" . $et) : ""));
                    $checked = in_array($cid, array_map("intval", $selectedCourseIds), true);
                  ?>
                  <label class="course-item">
                    <input
                      type="checkbox"
                      name="course_ids[]"
                      value="<?php echo $cid; ?>"
                      data-credits="<?php echo h($cr); ?>"
                      <?php echo $checked ? "checked" : ""; ?>
                    />
                    <div class="course-main">
                      <div class="course-name"><?php echo h($cid . " - " . $cname); ?> <span class="course-sub">(<?php echo h($cr); ?> credits)</span></div>
                      <div class="course-sub"><?php echo h($sched !== "" ? $sched : "Schedule: -"); ?></div>
                    </div>
                  </label>
                <?php endforeach; ?>

                <div class="actions">
                  <button class="btn btn-primary" type="submit">Enroll Student</button>
                  <a class="btn btn-ghost" href="list.php">Cancel</a>
                </div>
              </div>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="summary-title">Credit Summary</div>

          <div class="summary-row">
            <div class="summary-label">Current Credits</div>
            <div class="summary-val" id="curCredits"><?php echo h(number_format($uiCurrentCredits, 0)); ?></div>
          </div>

          <div class="summary-row">
            <div class="summary-label">Maximum Credits</div>
            <div class="summary-val" id="maxCredits"><?php echo h((string)$MAX_CREDITS); ?></div>
          </div>

          <div class="summary-row">
            <div class="summary-label">Remaining</div>
            <div class="summary-val good" id="remainCredits"><?php echo h(number_format(max(0, $MAX_CREDITS - $uiCurrentCredits), 0)); ?></div>
          </div>

          <div class="progress-wrap">
            <div class="progress-head">
              <div class="summary-label">Credit Usage</div>
              <div class="summary-val" id="usagePct">0%</div>
            </div>
            <div class="bar"><div class="fill" id="usageFill"></div></div>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
(function(){
  const maxCredits = parseFloat(document.getElementById('maxCredits').textContent || '0') || 0;
  const baseCredits = parseFloat(document.getElementById('curCredits').textContent || '0') || 0;

  const remainEl = document.getElementById('remainCredits');
  const usageEl = document.getElementById('usagePct');
  const fillEl = document.getElementById('usageFill');

  function calcSelectedCredits(){
    let sum = 0;
    document.querySelectorAll('input[type="checkbox"][name="course_ids[]"]').forEach(cb => {
      if(cb.checked){
        sum += parseFloat(cb.getAttribute('data-credits') || '0') || 0;
      }
    });
    return sum;
  }

  function render(){
    const selected = calcSelectedCredits();
    const total = baseCredits + selected;
    const remaining = Math.max(0, maxCredits - total);

    remainEl.textContent = String(Math.round(remaining));

    const pct = maxCredits > 0 ? Math.min(100, Math.round((total / maxCredits) * 100)) : 0;
    usageEl.textContent = pct + "%";
    fillEl.style.width = pct + "%";
  }

  document.querySelectorAll('input[type="checkbox"][name="course_ids[]"]').forEach(cb => {
    cb.addEventListener('change', render);
  });

  render();
})();
</script>
</body>
</html>