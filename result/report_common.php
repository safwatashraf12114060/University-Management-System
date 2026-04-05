<?php

function resultReportColExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($stmt !== false) sqlsrv_free_stmt($stmt);
    return isset($row["len"]) && $row["len"] !== null;
}

function resultReportH($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function resultReportBuildQuery(array $source, array $override = []) {
    $q = $source;
    foreach ($override as $k => $v) {
        if ($v === null || $v === "") unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ("?" . $qs) : "";
}

function resultReportPdfEscape($text) {
    $text = (string)$text;
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("(", "\\(", $text);
    $text = str_replace(")", "\\)", $text);
    return preg_replace('/[^\x20-\x7E]/', '?', $text);
}

function resultReportBuildSimplePdf(array $pages) {
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageIds = [];
    $contentIds = [];
    $nextId = 4;
    foreach ($pages as $page) {
        $contentIds[] = $nextId++;
        $pageIds[] = $nextId++;
    }

    $kids = [];
    foreach ($pageIds as $pageId) {
        $kids[] = $pageId . " 0 R";
    }
    $objects[] = "<< /Type /Pages /Kids [" . implode(" ", $kids) . "] /Count " . count($pageIds) . " >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";

    foreach ($pages as $index => $pageLines) {
        $content = "BT\n/F1 9 Tf\n";
        $y = 560;
        foreach ($pageLines as $line) {
            $content .= "1 0 0 1 24 " . $y . " Tm (" . resultReportPdfEscape($line) . ") Tj\n";
            $y -= 12;
        }
        $content .= "ET";

        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentIds[$index] . " 0 R >>";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

function resultReportFetchData($conn, array $input) {
    $courseNameCol = resultReportColExists($conn, "dbo.COURSE", "course_name") ? "course_name" : (resultReportColExists($conn, "dbo.COURSE", "title") ? "title" : "name");
    $studentNameCol = resultReportColExists($conn, "dbo.STUDENT", "student_name") ? "student_name" : (resultReportColExists($conn, "dbo.STUDENT", "name") ? "name" : "full_name");
    $studentDeptFkCol = resultReportColExists($conn, "dbo.STUDENT", "dept_id") ? "dept_id" : (resultReportColExists($conn, "dbo.STUDENT", "department_id") ? "department_id" : "dept_id");
    $deptIdCol = resultReportColExists($conn, "dbo.DEPARTMENT", "dept_id") ? "dept_id" : (resultReportColExists($conn, "dbo.DEPARTMENT", "department_id") ? "department_id" : "dept_id");
    $deptNameCol = resultReportColExists($conn, "dbo.DEPARTMENT", "name") ? "name" : (resultReportColExists($conn, "dbo.DEPARTMENT", "department_name") ? "department_name" : "dept_name");

    $courseCodeCol = null;
    foreach (["course_code", "code"] as $candidate) {
        if (resultReportColExists($conn, "dbo.COURSE", $candidate)) {
            $courseCodeCol = $candidate;
            break;
        }
    }

    $courseDeptFkCol = resultReportColExists($conn, "dbo.COURSE", "dept_id")
        ? "dept_id"
        : (resultReportColExists($conn, "dbo.COURSE", "department_id") ? "department_id" : null);

    $hasEnrollYear = resultReportColExists($conn, "dbo.ENROLLMENT", "year");
    $semesterCol = resultReportColExists($conn, "dbo.ENROLLMENT", "semester_name") ? "semester_name" : "semester";

    $q = trim((string)($input["q"] ?? ""));
    $departmentId = trim((string)($input["department_id"] ?? ""));
    $courseId = trim((string)($input["course_id"] ?? ""));
    $semester = trim((string)($input["semester"] ?? ""));
    $year = trim((string)($input["year"] ?? ""));

    $params = [];
    $where = "1=1";

    if ($q !== "") {
        $where .= " AND (
            CONVERT(VARCHAR(50), s.student_id) LIKE ? OR
            s.$studentNameCol LIKE ? OR
            " . ($courseCodeCol !== null ? "c.$courseCodeCol LIKE ? OR" : "") . "
            c.$courseNameCol LIKE ? OR
            r.grade LIKE ?
        )";
        $like = "%" . $q . "%";
        $params = [$like, $like];
        if ($courseCodeCol !== null) $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($departmentId !== "") {
        $where .= " AND s.$studentDeptFkCol = ?";
        $params[] = (int)$departmentId;
    }
    if ($courseId !== "") {
        $where .= " AND e.course_id = ?";
        $params[] = (int)$courseId;
    }
    if ($semester !== "") {
        $where .= " AND CONVERT(VARCHAR(50), e.$semesterCol) = ?";
        $params[] = $semester;
    }
    if ($year !== "" && $hasEnrollYear) {
        $where .= " AND e.year = ?";
        $params[] = (int)$year;
    }

    $sql = "
        SELECT
            r.result_id,
            r.marks,
            r.grade,
            e.$semesterCol AS semester,
            " . ($hasEnrollYear ? "e.year," : "NULL AS year,") . "
            s.student_id AS student_code,
            s.$studentNameCol AS student_name,
            d.$deptNameCol AS department_name,
            " . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code," : "CONVERT(VARCHAR(50), c.course_id) AS course_code,") . "
            c.$courseNameCol AS course_name
        FROM RESULT r
        JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
        JOIN STUDENT s ON s.student_id = e.student_id
        JOIN COURSE c ON c.course_id = e.course_id
        LEFT JOIN DEPARTMENT d ON d.$deptIdCol = s.$studentDeptFkCol
        WHERE $where
        ORDER BY " . ($hasEnrollYear ? "e.year DESC," : "") . " e.$semesterCol DESC, s.$studentNameCol ASC, " . ($courseCodeCol !== null ? "c.$courseCodeCol" : "c.$courseNameCol") . " ASC
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);
    $rows = [];
    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
        sqlsrv_free_stmt($stmt);
    }

    $semesterOptions = [];
    $semesterStmt = sqlsrv_query($conn, "
        SELECT DISTINCT CONVERT(VARCHAR(50), $semesterCol) AS semester_value
        FROM dbo.ENROLLMENT
        WHERE $semesterCol IS NOT NULL
        ORDER BY CONVERT(VARCHAR(50), $semesterCol) ASC
    ");
    if ($semesterStmt !== false) {
        while ($r = sqlsrv_fetch_array($semesterStmt, SQLSRV_FETCH_ASSOC)) {
            $value = trim((string)($r["semester_value"] ?? ""));
            if ($value !== "") $semesterOptions[] = $value;
        }
        sqlsrv_free_stmt($semesterStmt);
    }

    $yearOptions = [];
    if ($hasEnrollYear) {
        $yearStmt = sqlsrv_query($conn, "
            SELECT DISTINCT year
            FROM dbo.ENROLLMENT
            WHERE year IS NOT NULL
            ORDER BY year DESC
        ");
        if ($yearStmt !== false) {
            while ($r = sqlsrv_fetch_array($yearStmt, SQLSRV_FETCH_ASSOC)) {
                $value = trim((string)($r["year"] ?? ""));
                if ($value !== "") $yearOptions[] = $value;
            }
            sqlsrv_free_stmt($yearStmt);
        }
    }

    $departmentOptions = [];
    $departmentStmt = sqlsrv_query($conn, "
        SELECT $deptIdCol AS dept_id, $deptNameCol AS department_name
        FROM dbo.DEPARTMENT
        ORDER BY $deptNameCol ASC
    ");
    if ($departmentStmt !== false) {
        while ($r = sqlsrv_fetch_array($departmentStmt, SQLSRV_FETCH_ASSOC)) {
            $departmentOptions[] = $r;
        }
        sqlsrv_free_stmt($departmentStmt);
    }

    $courseOptions = [];
    $courseOptionParams = [];
    $courseSql = "
        SELECT
            course_id,
            " . ($courseCodeCol !== null ? "$courseCodeCol" : "CONVERT(VARCHAR(50), course_id)") . " AS course_code,
            $courseNameCol AS course_name
        FROM dbo.COURSE
    ";
    if ($departmentId !== "" && $courseDeptFkCol !== null) {
        $courseSql .= " WHERE $courseDeptFkCol = ?";
        $courseOptionParams[] = (int)$departmentId;
    }
    $courseSql .= "
        ORDER BY " . ($courseCodeCol !== null ? "$courseCodeCol ASC," : "") . " $courseNameCol ASC
    ";
    $courseStmt = sqlsrv_query($conn, $courseSql, $courseOptionParams);
    if ($courseStmt !== false) {
        while ($r = sqlsrv_fetch_array($courseStmt, SQLSRV_FETCH_ASSOC)) {
            $courseOptions[] = $r;
        }
        sqlsrv_free_stmt($courseStmt);
    }

    if ($departmentId !== "" && $courseId !== "") {
        $courseMatchesDepartment = false;
        foreach ($courseOptions as $courseOption) {
            if ((string)($courseOption["course_id"] ?? "") === $courseId) {
                $courseMatchesDepartment = true;
                break;
            }
        }
        if (!$courseMatchesDepartment) {
            $courseId = "";
        }
    }

    return [
        "q" => $q,
        "department_id" => $departmentId,
        "course_id" => $courseId,
        "semester" => $semester,
        "year" => $year,
        "has_year" => $hasEnrollYear,
        "department_options" => $departmentOptions,
        "course_options" => $courseOptions,
        "semester_options" => $semesterOptions,
        "year_options" => $yearOptions,
        "rows" => $rows,
    ];
}
