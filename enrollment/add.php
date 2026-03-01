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

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$studentTable = "dbo.STUDENT";
$courseTable  = "dbo.COURSE";
$enrollTable  = "dbo.ENROLLMENT";

$studentNameCol = "name";
if (colExists($conn, $studentTable, "student_name")) $studentNameCol = "student_name";
if (colExists($conn, $studentTable, "full_name")) $studentNameCol = "full_name";

$courseNameCol = "course_name";
if (colExists($conn, $courseTable, "name")) $courseNameCol = "name";

$courseCodeCol = null;
foreach (["course_code", "code"] as $cand) {
    if (colExists($conn, $courseTable, $cand)) { $courseCodeCol = $cand; break; }
}

$creditCol = "credit_hours";
if (colExists($conn, $courseTable, "credits")) $creditCol = "credits";

$MAX_CREDITS = 18;

$students = [];
$st = sqlsrv_query($conn, "SELECT student_id, $studentNameCol AS student_name FROM $studentTable ORDER BY $studentNameCol ASC");
if ($st !== false) {
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $students[] = $r;
    sqlsrv_free_stmt($st);
}

$courses = [];
$courseSelectCols = "course_id, $courseNameCol AS course_name, $creditCol AS credit_hours";
if ($courseCodeCol !== null) $courseSelectCols .= ", $courseCodeCol AS course_code";
$cs = sqlsrv_query($conn, "SELECT $courseSelectCols FROM $courseTable ORDER BY " . ($courseCodeCol ? "$courseCodeCol ASC" : "$courseNameCol ASC"));
if ($cs !== false) {
    while ($r = sqlsrv_fetch_array($cs, SQLSRV_FETCH_ASSOC)) $courses[] = $r;
    sqlsrv_free_stmt($cs);
}

$errorMsg = "";
$okMsg = "";

$values = [
    "student_id" => "",
    "semester" => "",
    "year" => "",
    "course_ids" => []
];

$semesterOptions = ["Spring", "Summer", "Fall"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $errorMsg = "Invalid request token.";
    } else {
        $values["student_id"] = trim((string)($_POST["student_id"] ?? ""));
        $values["semester"] = trim((string)($_POST["semester"] ?? ""));
        $values["year"] = trim((string)($_POST["year"] ?? ""));
        $values["course_ids"] = $_POST["course_ids"] ?? [];
        if (!is_array($values["course_ids"])) $values["course_ids"] = [];

        $studentId = (int)$values["student_id"];
        $year = (int)$values["year"];

        $courseIds = [];
        foreach ($values["course_ids"] as $cid) {
            $cidInt = (int)$cid;
            if ($cidInt > 0) $courseIds[] = $cidInt;
        }
        $courseIds = array_values(array_unique($courseIds));

        if ($studentId <= 0) {
            $errorMsg = "Student is required.";
        } elseif ($values["semester"] === "") {
            $errorMsg = "Semester is required.";
        } elseif ($year < 2000 || $year > 2100) {
            $errorMsg = "Valid year is required.";
        } elseif (count($courseIds) === 0) {
            $errorMsg = "Please select at least one course.";
        } else {
            // Calculate selected credits
            $selectedCredits = 0;
            $courseCreditMap = [];
            foreach ($courses as $c) {
                $cid = (int)($c["course_id"] ?? 0);
                $cr = (int)($c["credit_hours"] ?? 0);
                if ($cid > 0) $courseCreditMap[$cid] = $cr;
            }
            foreach ($courseIds as $cid) $selectedCredits += (int)($courseCreditMap[$cid] ?? 0);

            if ($selectedCredits > $MAX_CREDITS) {
                $errorMsg = "Selected credits exceed maximum ($MAX_CREDITS).";
            } else {
                // Insert each enrollment row
                $insertSql = "
                    INSERT INTO $enrollTable (student_id, course_id, semester, year, enrollment_date)
                    VALUES (?, ?, ?, ?, ?)
                ";

                $today = date("Y-m-d");
                $connOk = true;

                // Optional: avoid duplicates for same term + student + course
                $existsSql = "
                    SELECT TOP 1 1 AS ok
                    FROM $enrollTable
                    WHERE student_id = ? AND course_id = ? AND semester = ? AND year = ?
                ";

                foreach ($courseIds as $cid) {
                    $ex = sqlsrv_query($conn, $existsSql, [$studentId, $cid, $values["semester"], $year]);
                    $already = false;
                    if ($ex !== false) {
                        $row = sqlsrv_fetch_array($ex, SQLSRV_FETCH_ASSOC);
                        $already = $row ? true : false;
                        sqlsrv_free_stmt($ex);
                    }

                    if ($already) {
                        continue;
                    }

                    $ins = sqlsrv_query($conn, $insertSql, [$studentId, $cid, $values["semester"], $year, $today]);
                    if ($ins === false) {
                        $connOk = false;
                        $errorMsg = "Insert failed.";
                        if ($debug) $errorMsg .= " " . print_r(sqlsrv_errors(), true);
                        break;
                    }
                    sqlsrv_free_stmt($ins);
                }

                if ($connOk && $errorMsg === "") {
                    header("Location: list.php?success=1");
                    exit();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Enroll Student</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;--sidebar:#ffffff;--danger:#ef4444;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
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
    .back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;margin:0 0 10px;}
    h1{margin:6px 0 14px;font-size:34px;letter-spacing:-0.6px;}
    .wrap{display:grid;grid-template-columns: 1fr 360px;gap:18px;align-items:start;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input,select{width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;font-weight:800;}
    input:focus,select:focus{border-color:var(--primary);}
    .actions{display:flex;gap:12px;margin-top:16px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;min-width:150px;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .alert-ok{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;font-size:14px;}
    .alert-err{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:900;font-size:14px;white-space:pre-wrap;}
    .courses{margin-top:6px;border:1px solid var(--border);border-radius:12px;padding:12px;max-height:280px;overflow:auto;background:#fff;}
    .course-item{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px;border-radius:10px;}
    .course-item:hover{background:#f7f8ff;}
    .course-left{display:flex;gap:10px;align-items:flex-start;}
    .course-left input{width:auto;margin-top:3px;}
    .course-title{font-weight:900;}
    .course-sub{color:var(--muted);font-weight:800;font-size:13px;margin-top:2px;}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(47,60,255,0.10);color:var(--primary);font-weight:900;font-size:12px;white-space:nowrap;}
    .sum-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:1px solid var(--border);font-weight:900;}
    .sum-row:first-of-type{border-top:0;}
    .bar{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;}
    .bar > div{height:100%;background:var(--primary);width:0%;}
    @media(max-width:980px){.sidebar{display:none;}.wrap{grid-template-columns:1fr;}}
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
      <a class="active" href="list.php">
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
      <a href="../result/list.php">
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
      <a class="back" href="list.php">← Back to Enrollments</a>
      <h1>Enroll Student in Courses</h1>

      <?php if ($okMsg !== ""): ?>
        <div class="alert-ok"><?php echo h($okMsg); ?></div>
      <?php endif; ?>
      <?php if ($errorMsg !== ""): ?>
        <div class="alert-err"><?php echo h($errorMsg); ?></div>
      <?php endif; ?>

      <div class="wrap">
        <div class="card">
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

            <div class="grid">
              <div>
                <label>Student ID *</label>
                <select name="student_id" required>
                  <option value="">Select Student</option>
                  <?php foreach ($students as $s): ?>
                    <?php $sid = (int)($s["student_id"] ?? 0); ?>
                    <option value="<?php echo $sid; ?>" <?php echo ((string)$values["student_id"] === (string)$sid) ? "selected" : ""; ?>>
                      <?php echo h($sid . " - " . ($s["student_name"] ?? "")); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>Semester *</label>
                <select name="semester" required>
                  <option value="">Select Semester</option>
                  <?php foreach ($semesterOptions as $opt): ?>
                    <option value="<?php echo h($opt); ?>" <?php echo ($values["semester"] === $opt) ? "selected" : ""; ?>>
                      <?php echo h($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>Year *</label>
                <input type="number" name="year" placeholder="e.g., 2025" value="<?php echo h($values["year"]); ?>" required />
              </div>

              <div></div>

              <div style="grid-column: 1 / -1;">
                <label>Select Courses *</label>

                <div class="courses" id="courseBox">
                  <?php if (count($courses) === 0): ?>
                    <div style="color:#64748b;font-weight:900;">No courses found.</div>
                  <?php else: ?>
                    <?php foreach ($courses as $c): ?>
                      <?php
                        $cid = (int)($c["course_id"] ?? 0);
                        $cname = (string)($c["course_name"] ?? "");
                        $ccode = (string)($c["course_code"] ?? "");
                        $cr = (int)($c["credit_hours"] ?? 0);
                        $checked = in_array((string)$cid, array_map("strval", $values["course_ids"]), true);
                      ?>
                      <div class="course-item">
                        <div class="course-left">
                          <input
                            class="courseCheck"
                            type="checkbox"
                            name="course_ids[]"
                            value="<?php echo $cid; ?>"
                            data-credits="<?php echo $cr; ?>"
                            <?php echo $checked ? "checked" : ""; ?>
                          />
                          <div>
                            <div class="course-title">
                              <?php echo h(($ccode !== "" ? ($ccode . " - ") : "") . $cname); ?>
                            </div>
                            <div class="course-sub"><?php echo h("Credits: " . $cr); ?></div>
                          </div>
                        </div>
                        <span class="pill"><?php echo h($cr . " cr"); ?></span>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>

              </div>
            </div>

            <div class="actions">
              <button class="btn btn-primary" type="submit">Enroll Student</button>
              <a class="btn" href="list.php">Cancel</a>
            </div>
          </form>
        </div>

        <div class="card">
          <div style="font-weight:950;font-size:22px;margin:0 0 10px;">Credit Summary</div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Current Credits</div>
            <div id="curCredits" style="font-weight:950;">0</div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Maximum Credits</div>
            <div style="font-weight:950;"><?php echo (int)$MAX_CREDITS; ?></div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Remaining</div>
            <div id="remCredits" style="font-weight:950;color:#16a34a;"><?php echo (int)$MAX_CREDITS; ?></div>
          </div>

          <div class="sum-row" style="align-items:flex-start;flex-direction:column;gap:10px;">
            <div style="width:100%;display:flex;justify-content:space-between;align-items:center;">
              <div style="color:var(--muted);font-weight:900;">Credit Usage</div>
              <div id="usagePct" style="font-weight:950;">0%</div>
            </div>
            <div class="bar" style="width:100%;">
              <div id="usageBar"></div>
            </div>
          </div>

          <div id="warnBox" class="alert-err" style="display:none;margin-top:12px;">
            Selected credits exceed maximum.
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
(function(){
  const MAX = <?php echo (int)$MAX_CREDITS; ?>;
  const checks = Array.from(document.querySelectorAll(".courseCheck"));
  const cur = document.getElementById("curCredits");
  const rem = document.getElementById("remCredits");
  const pct = document.getElementById("usagePct");
  const bar = document.getElementById("usageBar");
  const warn = document.getElementById("warnBox");

  function calc(){
    let total = 0;
    checks.forEach(ch => {
      if (ch.checked) total += parseInt(ch.getAttribute("data-credits") || "0", 10);
    });

    cur.textContent = total;
    const remaining = MAX - total;
    rem.textContent = remaining;
    rem.style.color = remaining < 0 ? "#dc2626" : "#16a34a";

    const usage = MAX === 0 ? 0 : Math.round((total / MAX) * 100);
    pct.textContent = usage + "%";
    bar.style.width = Math.max(0, Math.min(100, usage)) + "%";

    if (total > MAX){
      warn.style.display = "block";
    } else {
      warn.style.display = "none";
    }
  }

  checks.forEach(ch => ch.addEventListener("change", calc));
  calc();
})();
</script>

</body>
</html>