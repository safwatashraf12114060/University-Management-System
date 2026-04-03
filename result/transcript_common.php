<?php

function transcriptTableExists($conn, $schemaDotTable) {
    $stmt = sqlsrv_query($conn, "SELECT OBJECT_ID(?) AS oid", [$schemaDotTable]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return isset($row["oid"]) && $row["oid"] !== null;
}

function transcriptColExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return isset($row["len"]) && $row["len"] !== null;
}

function transcriptH($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function transcriptGradePoint($grade) {
    $map = [
        "A+" => 4.00, "A" => 4.00, "A-" => 3.75,
        "B+" => 3.50, "B" => 3.00, "B-" => 2.75,
        "C+" => 2.50, "C" => 2.25, "C-" => 2.00,
        "D" => 1.00, "F" => 0.00,
    ];
    $g = strtoupper(trim((string)$grade));
    return $map[$g] ?? null;
}

function transcriptFormatNumber($value, $decimals = 2) {
    if ($value === null || $value === "") return "-";
    return number_format((float)$value, $decimals);
}

function transcriptStudentCode(array $student) {
    if (!empty($student["student_code"])) return (string)$student["student_code"];
    if (!empty($student["registration_no"])) return (string)$student["registration_no"];
    return "S" . str_pad((string)($student["student_id"] ?? "0"), 7, "0", STR_PAD_LEFT);
}

function transcriptResolveStudentId($conn, $resultId) {
    if ($resultId <= 0) return 0;
    $sql = "
        SELECT TOP 1 e.student_id
        FROM RESULT r
        JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
        WHERE r.result_id = ?
    ";
    $stmt = sqlsrv_query($conn, $sql, [$resultId]);
    if ($stmt === false) return 0;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return (int)($row["student_id"] ?? 0);
}

function transcriptSemesterSortValue($value) {
    $raw = trim((string)$value);
    if ($raw === "") return -1;
    if (is_numeric($raw)) return (int)$raw;

    $map = [
        "spring" => 1,
        "summer" => 2,
        "fall" => 3,
        "autumn" => 3,
        "winter" => 4,
    ];
    $key = strtolower($raw);
    return $map[$key] ?? 0;
}

function loadTranscriptData($conn, $studentId) {
    $studentId = (int)$studentId;
    if ($studentId <= 0) return null;

    $studentTable = transcriptTableExists($conn, "dbo.STUDENT") ? "dbo.STUDENT" : "STUDENT";
    $deptTable = transcriptTableExists($conn, "dbo.DEPARTMENT") ? "dbo.DEPARTMENT" : "DEPARTMENT";
    $enrollmentTable = transcriptTableExists($conn, "dbo.ENROLLMENT") ? "dbo.ENROLLMENT" : "ENROLLMENT";
    $courseTable = transcriptTableExists($conn, "dbo.COURSE") ? "dbo.COURSE" : "COURSE";
    $resultTable = transcriptTableExists($conn, "dbo.RESULT") ? "dbo.RESULT" : "RESULT";

    $studentNameCol = "student_name";
    foreach (["student_name", "name", "full_name"] as $candidate) {
        if (transcriptColExists($conn, $studentTable, $candidate)) {
            $studentNameCol = $candidate;
            break;
        }
    }

    $deptFkCol = transcriptColExists($conn, $studentTable, "dept_id") ? "dept_id" : "department_id";
    $deptNameCol = transcriptColExists($conn, $deptTable, "department_name") ? "department_name" : (transcriptColExists($conn, $deptTable, "dept_name") ? "dept_name" : "name");
    $emailCol = transcriptColExists($conn, $studentTable, "email") ? "email" : null;
    $phoneCol = transcriptColExists($conn, $studentTable, "phone") ? "phone" : null;
    $studentCodeCol = transcriptColExists($conn, $studentTable, "student_code") ? "student_code" : null;
    $registrationCol = transcriptColExists($conn, $studentTable, "registration_no") ? "registration_no" : null;

    $studentCols = [
        "s.student_id",
        "s.$studentNameCol AS student_name",
        "d.$deptNameCol AS department_name",
    ];
    if ($emailCol !== null) $studentCols[] = "s.$emailCol AS email";
    if ($phoneCol !== null) $studentCols[] = "s.$phoneCol AS phone";
    if ($studentCodeCol !== null) $studentCols[] = "s.$studentCodeCol AS student_code";
    if ($registrationCol !== null) $studentCols[] = "s.$registrationCol AS registration_no";

    $studentSql = "
        SELECT " . implode(", ", $studentCols) . "
        FROM $studentTable s
        LEFT JOIN $deptTable d ON d.dept_id = s.$deptFkCol
        WHERE s.student_id = ?
    ";
    $studentStmt = sqlsrv_query($conn, $studentSql, [$studentId]);
    $student = $studentStmt ? sqlsrv_fetch_array($studentStmt, SQLSRV_FETCH_ASSOC) : null;
    if ($studentStmt !== false) sqlsrv_free_stmt($studentStmt);
    if (!$student) return null;

    $courseIdCol = transcriptColExists($conn, $courseTable, "course_id") ? "course_id" : "id";
    $courseNameCol = "course_name";
    foreach (["course_name", "name", "title"] as $candidate) {
        if (transcriptColExists($conn, $courseTable, $candidate)) {
            $courseNameCol = $candidate;
            break;
        }
    }

    $courseCodeCol = null;
    foreach (["course_code", "code"] as $candidate) {
        if (transcriptColExists($conn, $courseTable, $candidate)) {
            $courseCodeCol = $candidate;
            break;
        }
    }

    $creditCol = null;
    foreach (["credit_hours", "credits", "credit"] as $candidate) {
        if (transcriptColExists($conn, $courseTable, $candidate)) {
            $creditCol = $candidate;
            break;
        }
    }

    $enrollmentIdCol = transcriptColExists($conn, $enrollmentTable, "enrollment_id") ? "enrollment_id" : "id";
    $semesterCol = transcriptColExists($conn, $enrollmentTable, "semester_name") ? "semester_name" : "semester";
    $hasYear = transcriptColExists($conn, $enrollmentTable, "year");
    $hasMarks = transcriptColExists($conn, $resultTable, "marks");
    $hasGrade = transcriptColExists($conn, $resultTable, "grade");

    $rowsSql = "
        SELECT
            e.$enrollmentIdCol AS enrollment_id,
            e.$semesterCol AS semester_value,
            " . ($hasYear ? "e.year AS year_value," : "NULL AS year_value,") . "
            " . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code," : "CONVERT(VARCHAR(50), c.$courseIdCol) AS course_code,") . "
            c.$courseNameCol AS course_name,
            " . ($creditCol !== null ? "c.$creditCol AS credit_hours," : "NULL AS credit_hours,") . "
            " . ($hasMarks ? "r.marks AS marks," : "NULL AS marks,") . "
            " . ($hasGrade ? "r.grade AS grade" : "NULL AS grade") . "
        FROM $enrollmentTable e
        JOIN $courseTable c ON c.$courseIdCol = e.course_id
        LEFT JOIN $resultTable r ON r.enrollment_id = e.$enrollmentIdCol
        WHERE e.student_id = ?
    ";
    $rowsStmt = sqlsrv_query($conn, $rowsSql, [$studentId]);
    $rows = [];
    if ($rowsStmt !== false) {
        while ($row = sqlsrv_fetch_array($rowsStmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($rowsStmt);
    }

    usort($rows, static function ($a, $b) {
        $yearA = (int)($a["year_value"] ?? 0);
        $yearB = (int)($b["year_value"] ?? 0);
        if ($yearA !== $yearB) return $yearB <=> $yearA;

        $semA = transcriptSemesterSortValue($a["semester_value"] ?? "");
        $semB = transcriptSemesterSortValue($b["semester_value"] ?? "");
        if ($semA !== $semB) return $semB <=> $semA;

        return strcasecmp((string)($a["course_name"] ?? ""), (string)($b["course_name"] ?? ""));
    });

    $termGroups = [];
    $earnedCredits = 0.0;
    $cgpaQualityPoints = 0.0;
    $cgpaCreditHours = 0.0;

    foreach ($rows as $row) {
        $semesterValue = trim((string)($row["semester_value"] ?? ""));
        $yearValue = trim((string)($row["year_value"] ?? ""));
        $termKey = $semesterValue . "|" . $yearValue;
        $credits = (float)($row["credit_hours"] ?? 0);
        $marks = $row["marks"];
        $grade = trim((string)($row["grade"] ?? ""));
        $gradePoint = transcriptGradePoint($grade);

        if (!isset($termGroups[$termKey])) {
            $label = is_numeric($semesterValue)
                ? ("Semester " . $semesterValue . ($yearValue !== "" ? " - " . $yearValue : ""))
                : ($semesterValue . ($yearValue !== "" ? " " . $yearValue : ""));

            $termGroups[$termKey] = [
                "semester" => $semesterValue,
                "year" => $yearValue,
                "label" => $label,
                "courses" => [],
                "semester_quality_points" => 0.0,
                "semester_credit_hours" => 0.0,
                "semester_gpa" => null,
            ];
        }

        $termGroups[$termKey]["courses"][] = [
            "course_code" => (string)($row["course_code"] ?? ""),
            "course_name" => (string)($row["course_name"] ?? ""),
            "credits" => $credits,
            "marks" => $marks !== null ? (float)$marks : null,
            "grade" => $grade,
        ];

        if ($gradePoint !== null && $credits > 0) {
            $termGroups[$termKey]["semester_quality_points"] += ($gradePoint * $credits);
            $termGroups[$termKey]["semester_credit_hours"] += $credits;
            $cgpaQualityPoints += ($gradePoint * $credits);
            $cgpaCreditHours += $credits;
            if ($grade !== "F") {
                $earnedCredits += $credits;
            }
        }
    }

    foreach ($termGroups as &$group) {
        if ($group["semester_credit_hours"] > 0) {
            $group["semester_gpa"] = round($group["semester_quality_points"] / $group["semester_credit_hours"], 2);
        }
    }
    unset($group);

    $termGroups = array_values($termGroups);
    if (isset($termGroups[0])) {
        $termGroups[0]["is_current"] = true;
    }
    for ($i = 1; $i < count($termGroups); $i++) {
        $termGroups[$i]["is_current"] = false;
    }

    return [
        "student" => $student,
        "student_code" => transcriptStudentCode($student),
        "terms" => $termGroups,
        "total_credits_earned" => round($earnedCredits, 2),
        "cgpa" => $cgpaCreditHours > 0 ? round($cgpaQualityPoints / $cgpaCreditHours, 2) : null,
    ];
}
