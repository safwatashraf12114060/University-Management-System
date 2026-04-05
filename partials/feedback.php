<?php

function umsFriendlyDbMessage($action, $entity, $errors = null) {
    $entityLabel = trim((string)$entity) !== "" ? trim((string)$entity) : "item";
    $action = strtolower(trim((string)$action));
    $errors = is_array($errors) ? $errors : [];

    $raw = "";
    foreach ($errors as $error) {
        $raw .= " " . strtolower((string)($error["message"] ?? ""));
    }
    $raw = trim($raw);

    if ($raw !== "") {
        if (strpos($raw, "foreign key") !== false || strpos($raw, "reference constraint") !== false || strpos($raw, "conflicted with the reference") !== false) {
            if ($action === "delete") {
                return ucfirst($entityLabel) . " cannot be deleted because it is already used in other records.";
            }
            return "This " . $entityLabel . " is linked to other records. Please review the related information and try again.";
        }

        if (strpos($raw, "duplicate") !== false || strpos($raw, "unique key") !== false || strpos($raw, "unique index") !== false || strpos($raw, "already exists") !== false) {
            return ucfirst($entityLabel) . " already exists. Please use a different value.";
        }

        if (strpos($raw, "cannot insert the value null") !== false || strpos($raw, "cannot be null") !== false) {
            return "Please fill in all required fields and try again.";
        }

        if (strpos($raw, "conversion failed") !== false || strpos($raw, "out-of-range") !== false || strpos($raw, "date") !== false || strpos($raw, "time") !== false) {
            return "Some information is not in the correct format. Please check the entered values.";
        }

        if (strpos($raw, "string or binary data would be truncated") !== false) {
            return "Some text is too long. Please shorten it and try again.";
        }
    }

    if ($action === "delete") {
        return "Could not delete this " . $entityLabel . " right now. Please try again.";
    }
    if ($action === "update") {
        return "Could not update this " . $entityLabel . " right now. Please try again.";
    }
    if ($action === "create" || $action === "insert" || $action === "add") {
        return "Could not save this " . $entityLabel . " right now. Please try again.";
    }

    return "Something went wrong while saving this " . $entityLabel . ". Please try again.";
}

function umsSetFlash($key, $type, $message) {
    if (!isset($_SESSION["_ums_flash"]) || !is_array($_SESSION["_ums_flash"])) {
        $_SESSION["_ums_flash"] = [];
    }

    $_SESSION["_ums_flash"][$key] = [
        "type" => $type,
        "message" => $message,
    ];
}

function umsPullFlash($key) {
    if (empty($_SESSION["_ums_flash"][$key])) {
        return null;
    }

    $flash = $_SESSION["_ums_flash"][$key];
    unset($_SESSION["_ums_flash"][$key]);
    return $flash;
}
