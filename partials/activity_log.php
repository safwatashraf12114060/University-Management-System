<?php

function umsActivityTableExists($conn) {
    $stmt = sqlsrv_query($conn, "SELECT OBJECT_ID('dbo.activity_log') AS oid");
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return isset($row["oid"]) && $row["oid"] !== null;
}

function umsActivityColExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return isset($row["len"]) && $row["len"] !== null;
}

function umsResolveActivityColumn($conn, $table, array $candidates, $fallback = null) {
    foreach ($candidates as $candidate) {
        if (umsActivityColExists($conn, $table, $candidate)) {
            return $candidate;
        }
    }

    return $fallback;
}

function umsFetchActivityEntityLabel($conn, $table, $idColumn, $idValue, array $labelCandidates) {
    $labelColumn = umsResolveActivityColumn($conn, $table, $labelCandidates);
    if ($labelColumn === null) return "";

    $sql = "SELECT TOP 1 $labelColumn AS label_value FROM $table WHERE $idColumn = ?";
    $stmt = sqlsrv_query($conn, $sql, [$idValue]);
    if ($stmt === false) return "";

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return trim((string)($row["label_value"] ?? ""));
}

function umsEnsureActivityLogTable($conn) {
    static $ensured = false;

    if ($ensured) return true;
    if (umsActivityTableExists($conn)) {
        $ensured = true;
        return true;
    }

    $sql = "
        CREATE TABLE dbo.activity_log (
            id INT IDENTITY(1,1) PRIMARY KEY,
            activity_type VARCHAR(50),
            message VARCHAR(255),
            created_at DATETIME DEFAULT GETDATE()
        )
    ";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return false;

    sqlsrv_free_stmt($stmt);
    $ensured = true;
    return true;
}

function umsLogActivity($conn, $activityType, $message) {
    $activityType = trim((string)$activityType);
    $message = trim((string)$message);

    if ($activityType === "" || $message === "") return false;
    if (!umsEnsureActivityLogTable($conn)) return false;

    $stmt = sqlsrv_query(
        $conn,
        "INSERT INTO dbo.activity_log (activity_type, message) VALUES (?, ?)",
        [$activityType, substr($message, 0, 255)]
    );
    if ($stmt === false) return false;

    sqlsrv_free_stmt($stmt);
    return true;
}

function umsFetchRecentActivities($conn, $limit = 5) {
    $limit = max(1, (int)$limit);
    if (!umsEnsureActivityLogTable($conn)) return [];

    $sql = "
        SELECT TOP ($limit) activity_type, message, created_at
        FROM dbo.activity_log
        ORDER BY created_at DESC, id DESC
    ";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return [];

    $items = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $items[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    return $items;
}

function umsActivityTimeText($value) {
    if ($value instanceof DateTimeInterface) {
        $time = $value->getTimestamp();
    } elseif (is_object($value) && method_exists($value, "getTimestamp")) {
        $time = (int)$value->getTimestamp();
    } else {
        $parsed = strtotime((string)$value);
        if ($parsed === false) return "";
        $time = $parsed;
    }

    $diff = time() - $time;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " min ago";
    if ($diff < 86400) return floor($diff / 3600) . " hours ago";
    if ($diff < 172800) return "1 day ago";
    if ($diff < 604800) return floor($diff / 86400) . " days ago";

    return date("d M Y", $time);
}

function umsActivityDotColor($activityType) {
    $activityType = strtolower(trim((string)$activityType));

    if (str_contains($activityType, "student")) return "#2f3cff";
    if (str_contains($activityType, "teacher")) return "#10b981";
    if (str_contains($activityType, "course")) return "#f59e0b";
    if (str_contains($activityType, "department")) return "#8b5cf6";
    if (str_contains($activityType, "enrollment")) return "#ec4899";
    if (str_contains($activityType, "result")) return "#0ea5e9";

    return "#2f3cff";
}
