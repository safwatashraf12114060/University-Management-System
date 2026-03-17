<?php

function ums_nav_icon($key) {
    $icons = [
        "dashboard" => '<svg viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="3" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
        </svg>',

        "students" => '<svg viewBox="0 0 24 24" fill="none">
          <circle cx="9" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>',

        "teachers" => '<svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>',

        "departments" => '<svg viewBox="0 0 24 24" fill="none">
          <path d="M4 21V8l8-5 8 5v13" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 21v-6h6v6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>',

        "courses" => '<svg viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>',

        "enrollments" => '<svg viewBox="0 0 24 24" fill="none">
          <path d="M8 6h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 12h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 18h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M3 6h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 12h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 18h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
        </svg>',

        "results" => '<svg viewBox="0 0 24 24" fill="none">
          <path d="M7 3h10a2 2 0 0 1 2 2v16l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 0 1 2-2Z" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 8h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M9 12h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>',
    ];

    return $icons[$key] ?? "";
}

function renderSidebar($active, $basePath = "") {
    $items = [
        ["key" => "dashboard",   "label" => "Dashboard",  "href" => $basePath . "index.php"],
        ["key" => "students",    "label" => "Students",   "href" => $basePath . "student/list.php"],
        ["key" => "teachers",    "label" => "Teachers",   "href" => $basePath . "teacher/list.php"],
        ["key" => "departments", "label" => "Departments","href" => $basePath . "department/list.php"],
        ["key" => "courses",     "label" => "Courses",    "href" => $basePath . "course/list.php"],
        ["key" => "enrollments", "label" => "Enrollments","href" => $basePath . "enrollment/list.php"],
        ["key" => "results",     "label" => "Results",    "href" => $basePath . "result/list.php"],
    ];

    echo '<aside class="sidebar">';
    echo '<div class="brand">UMS</div>';
    echo '<nav class="nav">';

    foreach ($items as $item) {
        $isActive = ($active === $item["key"]) ? "active" : "";
        echo '<a class="' . $isActive . '" href="' . htmlspecialchars($item["href"]) . '">';
        echo ums_nav_icon($item["key"]);
        echo htmlspecialchars($item["label"]);
        echo '</a>';
    }

    echo '</nav>';
    echo '</aside>';
}

function renderTopbar($name, $email = "", $logoutHref = "logout.php", $showEmail = false) {
    echo '<div class="topbar">';
    echo '  <div class="userbox">';
    echo '    <div class="name">' . htmlspecialchars($name) . '</div>';

    if ($showEmail) {
        echo '    <div class="email">' . htmlspecialchars($email) . '</div>';
    }

    echo '  </div>';

    echo '  <a class="logout" href="' . htmlspecialchars($logoutHref) . '">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M10 17l5-5-5-5" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M15 12H3" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
                <path d="M21 3v18" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
              </svg>
              Logout
            </a>';
    echo '</div>';
}