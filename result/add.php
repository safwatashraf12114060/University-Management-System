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

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function addSqlsrvError($baseMsg, $debug) {
    if (!$debug) return $baseMsg;
    $e = sqlsrv_errors();
    return $baseMsg . "\n" . print_r($e, true);
}

function calcGrade($marks) {
    $m = (float)$marks;
    if ($m >= 90) return "A";
    if ($m >= 85) return "A-";
    if ($m >= 80) return "B+";
    if ($m >= 75) return "B";
    if ($m >= 70) return "B-";
    if ($m >= 65) return "C+";
    if ($m >= 60) return "C";
    if ($m >= 55) return "C-";
    if ($m >= 50) return "D";
    return "F";
}

$error = "";
$student_id = (string)($_POST["student_id"] ?? "");
$course_id = (int)($_POST["course_id"] ?? 0);
$semester = trim($_POST["semester"] ?? "");
$year = (int)($_POST["year"] ?? 0);
$marks = trim($_POST["marks"] ?? "");

$students = [];
$st = sqlsrv_query($conn, "SELECT student_id, name FROM STUDENT ORDER BY name ASC");
if ($st !== false) {
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $students[] = $r;
}

$courses = [];
$ct = sqlsrv_query($conn, "SELECT course_id, course_code, course_name FROM COURSE ORDER BY course_code ASC");
if ($ct !== false) {
    while ($r = sqlsrv_fetch_array($ct, SQLSRV_FETCH_ASSOC)) $courses[] = $r;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        if ($student_id === "" || $course_id <= 0 || $semester === "" || $year <= 0 || $marks === "") {
            $error = "All required fields must be filled.";
        } else {
            $m = (float)$marks;
            if ($m < 0 || $m > 100) {
                $error = "Marks must be between 0 and 100.";
            }
        }

        if ($error === "") {
            $enSql = "
                SELECT TOP 1 e.enrollment_id
                FROM ENROLLMENT e
                WHERE e.student_id = ? AND e.course_id = ? AND e.semester = ? AND e.year = ?
                ORDER BY e.enrollment_id DESC
            ";
            $enSt = sqlsrv_query($conn, $enSql, [$student_id, $course_id, $semester, $year]);
            $enRow = $enSt ? sqlsrv_fetch_array($enSt, SQLSRV_FETCH_ASSOC) : null;
            $enrollmentId = (int)($enRow["enrollment_id"] ?? 0);

            if ($enrollmentId <= 0) {
                $error = "Enrollment not found for the selected student, course, and semester.";
            } else {
                $dupSql = "SELECT TOP 1 result_id FROM RESULT WHERE enrollment_id = ?";
                $dupSt = sqlsrv_query($conn, $dupSql, [$enrollmentId]);
                if ($dupSt !== false && sqlsrv_fetch_array($dupSt, SQLSRV_FETCH_ASSOC)) {
                    $error = "Result already exists for this enrollment.";
                } else {
                    $grade = calcGrade((float)$marks);

                    $insSql = "INSERT INTO RESULT (enrollment_id, marks, grade) VALUES (?, ?, ?)";
                    $insSt = sqlsrv_query($conn, $insSql, [$enrollmentId, (float)$marks, $grade]);

                    if ($insSt === false) {
                        $error = addSqlsrvError("Insert failed.", $debug);
                    } else {
                        header("Location: list.php?success=1");
                        exit();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add Result</title>
  <style>
    :root{
      --bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;
      --primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;--sidebar:#ffffff;--danger:#ef4444;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{display:flex;align-items:center;gap:12px;padding:12px 12px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:700;opacity:.92;}
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);}
    .nav svg{width:18px;height:18px;flex:0 0 auto;}
    .nav a.active svg path,.nav a.active svg rect,.nav a.active svg circle{stroke:#fff;}

    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;margin-bottom:12px;}
    h1{margin:6px 0 14px;font-size:34px;letter-spacing:-0.6px;}

    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;max-width:920px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .full{grid-column:1 / -1;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input,select,textarea{
      width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;font-weight:800;
    }
    input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(47,60,255,0.10);}
    .actions{display:flex;gap:12px;margin-top:16px;}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 16px;border-radius:12px;border:1px solid var(--border);
      background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;min-width:160px;
    }
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-ghost{background:#f3f4f6;border-color:#f3f4f6;}
    .alert{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:800;font-size:14px;white-space:pre-wrap;}

    .scale{
      margin-top:14px;border:1px solid #c7d2fe;background:#eff6ff;border-radius:12px;padding:14px;
    }
    .scale h3{margin:0 0 10px;font-size:18px;font-weight:900;}
    .scale-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;color:#0f172a;font-weight:900;}
    .muted{color:var(--muted);font-weight:800;}

    @media(max-width:860px){
      .sidebar{display:none;}
      .grid{grid-template-columns:1fr;}
      .btn{min-width:0;flex:1;}
      .actions{flex-wrap:wrap;}
      .scale-grid{grid-template-columns:repeat(2,1fr);}
    }
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">
        <svg viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="3" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
        </svg>
        Dashboard
      </a>

      <a href="../student/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="9" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Students
      </a>

      <a href="../teacher/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Teachers
      </a>

      <a href="../department/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 21V8l8-5 8 5v13" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 21v-6h6v6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Departments
      </a>

      <a href="../course/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Courses
      </a>

      <a href="../enrollment/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M8 6h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 12h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 18h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M3 6h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 12h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 18h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
        </svg>
        Enrollments
      </a>

      <a class="active" href="list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M7 3h10a2 2 0 0 1 2 2v16l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 0 1 2-2Z" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 8h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M9 12h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Results
      </a>
    </nav>
  </aside>

  <main class="content">
    <div class="topbar">
      <div style="font-weight:900;"><?php echo h($_SESSION["name"] ?? "User"); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <a class="back" href="list.php">← Back to Results</a>
      <h1>Add Result</h1>

      <?php if ($error !== ""): ?>
        <div class="alert"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

          <div class="grid">
            <div>
              <label for="student_id">Student *</label>
              <select id="student_id" name="student_id" required>
                <option value="">Select Student</option>
                <?php foreach ($students as $s): ?>
                  <?php $sid = (string)($s["student_id"] ?? ""); ?>
                  <option value="<?php echo h($sid); ?>" <?php echo ($student_id === $sid) ? "selected" : ""; ?>>
                    <?php echo h($sid . " - " . (string)($s["name"] ?? "")); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="course_id">Course *</label>
              <select id="course_id" name="course_id" required>
                <option value="">Select Course</option>
                <?php foreach ($courses as $c): ?>
                  <?php
                    $cid = (int)($c["course_id"] ?? 0);
                    $cc = (string)($c["course_code"] ?? "");
                    $cn = (string)($c["course_name"] ?? "");
                    $label = trim($cc . " - " . $cn);
                  ?>
                  <option value="<?php echo h($cid); ?>" <?php echo ($course_id === $cid) ? "selected" : ""; ?>>
                    <?php echo h($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="semester">Semester *</label>
              <select id="semester" name="semester" required>
                <option value="">Select Semester</option>
                <?php foreach (["Spring","Summer","Fall"] as $opt): ?>
                  <option value="<?php echo h($opt); ?>" <?php echo ($semester === $opt) ? "selected" : ""; ?>>
                    <?php echo h($opt); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="year">Year *</label>
              <input id="year" name="year" type="number" placeholder="e.g., 2025" value="<?php echo $year > 0 ? h($year) : ""; ?>" required>
            </div>

            <div>
              <label for="marks">Total Marks (out of 100) *</label>
              <input id="marks" name="marks" type="number" min="0" max="100" step="0.01" placeholder="e.g., 85" value="<?php echo h($marks); ?>" required>
            </div>

            <div>
              <label for="grade">Grade (Auto-calculated)</label>
              <input id="grade" type="text" value="" placeholder="Grade will appear here" disabled>
            </div>

            <div class="full scale">
              <h3>Grading Scale</h3>
              <div class="scale-grid">
                <div>A: 90-100</div>
                <div>A-: 85-89</div>
                <div>B+: 80-84</div>
                <div>B: 75-79</div>
                <div>B-: 70-74</div>
                <div>C+: 65-69</div>
                <div>C: 60-64</div>
                <div>C-: 55-59</div>
                <div>D: 50-54</div>
                <div>F: Below 50</div>
              </div>
              <div class="muted" style="margin-top:10px;">Grade is calculated automatically from marks.</div>
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Add Result</button>
            <a class="btn btn-ghost" href="list.php">Cancel</a>
          </div>

        </form>
      </div>
    </div>
  </main>
</div>

<script>
(function(){
  const marksEl = document.getElementById('marks');
  const gradeEl = document.getElementById('grade');

  function calcGrade(m){
    const x = Number(m);
    if (!Number.isFinite(x)) return "";
    if (x >= 90) return "A";
    if (x >= 85) return "A-";
    if (x >= 80) return "B+";
    if (x >= 75) return "B";
    if (x >= 70) return "B-";
    if (x >= 65) return "C+";
    if (x >= 60) return "C";
    if (x >= 55) return "C-";
    if (x >= 50) return "D";
    return "F";
  }

  function render(){
    const v = marksEl.value;
    if (v === "") { gradeEl.value = ""; return; }
    gradeEl.value = calcGrade(v);
  }

  marksEl.addEventListener('input', render);
  render();
})();
</script>
</body>
</html>