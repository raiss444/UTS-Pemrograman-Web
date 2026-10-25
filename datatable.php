<?php
require_once __DIR__ . "/connect.php";

function getStudentPhotoPaths(string $nim): array
{
    $photoDirectory = __DIR__ . "/uploads/mahasiswa";
    if (!is_dir($photoDirectory)) {
        return [];
    }

    $paths = glob($photoDirectory . "/" . hash("sha256", $nim) . "*");
    if ($paths === false) {
        error_log("Gagal mencari foto mahasiswa untuk NIM " . $nim);
        return [];
    }

    usort($paths, static function (string $firstPath, string $secondPath): int {
        $firstName = basename($firstPath);
        $secondName = basename($secondPath);
        $firstHasTimestamp = preg_match('/-([0-9]{20})-[a-f0-9]{16}\./', $firstName, $firstMatch) === 1;
        $secondHasTimestamp = preg_match('/-([0-9]{20})-[a-f0-9]{16}\./', $secondName, $secondMatch) === 1;

        if ($firstHasTimestamp && $secondHasTimestamp) {
            return strcmp($secondMatch[1], $firstMatch[1]);
        }
        if ($firstHasTimestamp !== $secondHasTimestamp) {
            return $firstHasTimestamp ? -1 : 1;
        }

        $firstModified = filemtime($firstPath);
        $secondModified = filemtime($secondPath);
        return ($secondModified ?: 0) <=> ($firstModified ?: 0);
    });

    return $paths;
}

$actionError = "";
$statusMessages = [
    "added" => "Data mahasiswa berhasil ditambahkan.",
    "updated" => "Data mahasiswa berhasil diperbarui.",
    "deleted" => "Data mahasiswa berhasil dihapus.",
];
$status = $_GET["status"] ?? "";
$statusMessage = is_string($status) ? ($statusMessages[$status] ?? "") : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "update_student") {
        $nim = is_string($_POST["nim"] ?? null) ? trim($_POST["nim"]) : "";
        $name = is_string($_POST["nama"] ?? null) ? trim($_POST["nama"]) : "";
        $scoreInput = is_string($_POST["nilai"] ?? null) ? trim($_POST["nilai"]) : "";
        $photoTemporaryPath = null;
        $photoExtension = null;
        $uploadedPhoto = $_FILES["foto"] ?? null;

        if ($uploadedPhoto !== null) {
            if (!is_array($uploadedPhoto) || !isset($uploadedPhoto["error"])) {
                $actionError = "Foto tidak dapat diproses.";
            } elseif ($uploadedPhoto["error"] !== UPLOAD_ERR_NO_FILE) {
                if ($uploadedPhoto["error"] !== UPLOAD_ERR_OK) {
                    $actionError = "Foto gagal diunggah.";
                } elseif (!isset($uploadedPhoto["size"], $uploadedPhoto["tmp_name"]) ||
                    !is_int($uploadedPhoto["size"]) ||
                    $uploadedPhoto["size"] < 1 ||
                    $uploadedPhoto["size"] > 2 * 1024 * 1024 ||
                    !is_string($uploadedPhoto["tmp_name"]) ||
                    !is_uploaded_file($uploadedPhoto["tmp_name"])) {
                    $actionError = "Foto harus berukuran maksimal 2 MB.";
                } else {
                    $photoInfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($photoInfo === false) {
                        error_log("Gagal menginisialisasi pemeriksa foto mahasiswa saat update.");
                        $actionError = "Foto tidak dapat diperiksa.";
                    } else {
                        $photoMimeType = finfo_file($photoInfo, $uploadedPhoto["tmp_name"]);
                        finfo_close($photoInfo);
                        $allowedPhotoTypes = [
                            "image/jpeg" => "jpg",
                            "image/png" => "png",
                            "image/webp" => "webp",
                        ];

                        if (!is_string($photoMimeType) || !isset($allowedPhotoTypes[$photoMimeType]) ||
                            getimagesize($uploadedPhoto["tmp_name"]) === false) {
                            $actionError = "Foto harus berupa gambar JPG, PNG, atau WEBP.";
                        } else {
                            $photoTemporaryPath = $uploadedPhoto["tmp_name"];
                            $photoExtension = $allowedPhotoTypes[$photoMimeType];
                        }
                    }
                }
            }
        }

        if ($actionError === "" && !preg_match("/^[0-9]{10}$/", $nim)) {
            $actionError = "NIM tidak valid.";
        } elseif ($actionError === "" &&
            ($name === "" || strlen($name) > 30 || !preg_match("/^[a-zA-Z' ]+$/", $name))) {
            $actionError = "Nama wajib diisi, maksimal 30 karakter, dan hanya boleh berisi huruf, tanda petik, serta spasi.";
        } elseif ($actionError === "" &&
            (!preg_match("/^[0-9]+(\\.[0-9]+)?$/", $scoreInput) || (float) $scoreInput < 0 || (float) $scoreInput > 100)) {
            $actionError = "Nilai harus berupa angka antara 0 sampai 100.";
        } elseif ($actionError === "") {
            $score = (float) $scoreInput;
            if ($score >= 85) {
                $grade = "A";
                $predicate = "Sangat Baik";
            } elseif ($score >= 75) {
                $grade = "B";
                $predicate = "Baik";
            } elseif ($score >= 65) {
                $grade = "C";
                $predicate = "Cukup";
            } elseif ($score >= 50) {
                $grade = "D";
                $predicate = "Kurang";
            } else {
                $grade = "E";
                $predicate = "Sangat Kurang";
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE nilaimahasiswa SET namamhsw = ?, nilaiakhir = ?, nilaihuruf = ?, predikat = ? WHERE nim = ?"
            );
            if (!$stmt) {
                error_log("Gagal menyiapkan query update mahasiswa: " . mysqli_error($conn));
                $actionError = "Data mahasiswa gagal diperbarui.";
            } else {
                mysqli_stmt_bind_param($stmt, "sdsss", $name, $score, $grade, $predicate, $nim);
                $oldPhotoPaths = getStudentPhotoPaths($nim);
                $newPhotoPath = null;
                $photoSaved = true;

                if ($photoTemporaryPath !== null && $photoExtension !== null) {
                    $photoDirectory = __DIR__ . "/uploads/mahasiswa";
                    if (!is_dir($photoDirectory) &&
                        !mkdir($photoDirectory, 0755, true) &&
                        !is_dir($photoDirectory)) {
                        error_log("Gagal membuat direktori penyimpanan foto mahasiswa saat update.");
                        $actionError = "Foto gagal disimpan. Data mahasiswa tidak diperbarui.";
                        $photoSaved = false;
                    } else {
                        $photoTimestamp = sprintf("%020.0f", microtime(true) * 1000000);
                        $newPhotoPath = $photoDirectory . "/" . hash("sha256", $nim) . "-" .
                            $photoTimestamp . "-" . bin2hex(random_bytes(8)) . "." . $photoExtension;
                        if (!move_uploaded_file($photoTemporaryPath, $newPhotoPath)) {
                            error_log("Gagal menyimpan foto mahasiswa saat update.");
                            $actionError = "Foto gagal disimpan. Data mahasiswa tidak diperbarui.";
                            $photoSaved = false;
                        }
                    }
                }

                if ($photoSaved && mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    foreach ($oldPhotoPaths as $oldPhotoPath) {
                        if (!unlink($oldPhotoPath)) {
                            error_log("Gagal menghapus foto mahasiswa lama: " . $oldPhotoPath);
                        }
                    }
                    header("Location: datatable.php?status=updated");
                    exit;
                }

                if ($photoSaved) {
                    error_log("Gagal memperbarui mahasiswa: " . mysqli_stmt_error($stmt));
                    $actionError = "Data mahasiswa gagal diperbarui.";
                }
                if ($newPhotoPath !== null && is_file($newPhotoPath) && !unlink($newPhotoPath)) {
                    error_log("Gagal membersihkan foto mahasiswa yang tidak tersimpan: " . $newPhotoPath);
                }
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($action === "delete_student") {
        $nim = is_string($_POST["nim"] ?? null) ? trim($_POST["nim"]) : "";
        if (!preg_match("/^[0-9]{10}$/", $nim)) {
            $actionError = "NIM tidak valid.";
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM nilaimahasiswa WHERE nim = ?");
            if (!$stmt) {
                error_log("Gagal menyiapkan query hapus mahasiswa: " . mysqli_error($conn));
                $actionError = "Data mahasiswa gagal dihapus.";
            } else {
                mysqli_stmt_bind_param($stmt, "s", $nim);
                if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1) {
                    mysqli_stmt_close($stmt);
                    foreach (getStudentPhotoPaths($nim) as $photoPath) {
                        if (!unlink($photoPath)) {
                            error_log("Gagal menghapus foto mahasiswa: " . $photoPath);
                        }
                    }
                    header("Location: datatable.php?status=deleted");
                    exit;
                }

                if (mysqli_stmt_errno($stmt) !== 0) {
                    error_log("Gagal menghapus mahasiswa: " . mysqli_stmt_error($stmt));
                    $actionError = "Data mahasiswa gagal dihapus.";
                } else {
                    $actionError = "Data mahasiswa tidak ditemukan.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$sql = "SELECT nim, namamhsw, nilaiakhir, nilaihuruf, predikat, created_date, modified_date
        FROM nilaimahasiswa
        ORDER BY created_date DESC, nim DESC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    error_log("Gagal mengambil data nilaimahasiswa: " . mysqli_error($conn));
    http_response_code(500);
    exit("Gagal mengambil data nilai mahasiswa.");
}

$students = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_free_result($result);
mysqli_close($conn);

$studentAvatars = [
    "user1-128x128.jpg",
    "user3-128x128.jpg",
    "user6-128x128.jpg",
    "user4-128x128.jpg",
    "user7-128x128.jpg",
    "user8-128x128.jpg",
    "user2-160x160.jpg",
    "user5-128x128.jpg",
];
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Nilai Mahasiswa</title>

    <!--begin::Theme Init (prevents flash of incorrect theme on load, #6043)-->
    <script>
      (() => {
        'use strict';
        const root = document.documentElement;

        // Applications with their own theming opt out of AdminLTE's color mode
        // entirely, here as well as in the bundle.
        if (root.getAttribute('data-lte-color-mode') === 'off') {
          return;
        }

        const STORAGE_KEY = 'lte-theme';
        let stored = null;
        try {
          stored = localStorage.getItem(STORAGE_KEY);
        } catch {
          // localStorage may be unavailable (private mode, sandboxed iframe).
        }
        // Mirror the precedence in color-mode.ts: the visitor's stored choice
        // wins, then a theme this page declared itself, then the OS preference.
        const authored = root.getAttribute('data-bs-theme');
        let resolved = 'light';
        if (stored === 'dark' || stored === 'light') {
          resolved = stored;
        } else if (authored === 'dark' || authored === 'light') {
          resolved = authored;
        } else if (globalThis.matchMedia('(prefers-color-scheme: dark)').matches) {
          resolved = 'dark';
        }
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
        // Flag values computed here, so the bundle does not mistake them for a
        // theme the page declared and stop following the OS preference.
        if (resolved !== authored) {
          root.setAttribute('data-lte-theme-resolved', '');
        }
      })();
    </script>
    <!--end::Theme Init-->

    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->

    <!--begin::Primary Meta Tags-->
    <meta name="title" content="Nilai Mahasiswa" />
    <meta name="author" content="ColorlibHQ" />
    <meta
      name="description"
      content="AdminLTE is a free Bootstrap 5 admin dashboard template with almost 50 example pages, built with vanilla JS and designed with accessibility in mind."
    />
    <meta
      name="keywords"
      content="bootstrap 5, bootstrap, bootstrap 5 admin dashboard, bootstrap 5 dashboard, bootstrap 5 charts, bootstrap 5 calendar, bootstrap 5 datepicker, bootstrap 5 tables, bootstrap 5 datatable, vanilla js datatable, colorlibhq, colorlibhq dashboard, colorlibhq admin dashboard, accessible admin panel"
    />
    <!--end::Primary Meta Tags-->

    <!--begin::Accessibility Features-->
    <!-- Skip links will be dynamically added by accessibility.js -->
    <meta name="supported-color-schemes" content="light dark" />
    <link rel="preload" href="../uts_pweb/dist/css/adminlte.css" as="style" />
    <!--end::Accessibility Features-->

    <!--begin::Fonts-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
      integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q="
      crossorigin="anonymous"
      media="print"
      onload="this.media = 'all'"
    />
    <!--end::Fonts-->

    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(OverlayScrollbars)-->

    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(Bootstrap Icons)-->

    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="../uts_pweb/dist/css/adminlte.css" />
    <style>
      .app-wrapper {
        grid-template-areas: "lte-app-header" "lte-app-main" "lte-app-footer";
        grid-template-columns: minmax(0, 1fr);
      }

      .app-sidebar {
        display: none;
      }

      .app-content-header,
      .app-content {
        padding-right: 1rem;
        padding-left: 1rem;
      }

      .app-content .card {
        overflow: hidden;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 1rem rgb(0 0 0 / 6%);
      }

      .app-content .card-header {
        padding: 1rem 1.25rem;
      }

      .app-content .card-title {
        font-size: 1.05rem;
        font-weight: 600;
      }

      .app-content .table {
        width: 100%;
        table-layout: fixed;
      }

      .app-content .table th:nth-child(1),
      .app-content .table td:nth-child(1) {
        width: 12%;
      }

      .app-content .table th:nth-child(2),
      .app-content .table td:nth-child(2) {
        width: 7%;
      }

      .app-content .table th:nth-child(3),
      .app-content .table td:nth-child(3) {
        width: 18%;
      }

      .app-content .table th:nth-child(4),
      .app-content .table td:nth-child(4) {
        width: 10%;
      }

      .app-content .table th:nth-child(5),
      .app-content .table td:nth-child(5) {
        width: 8%;
      }

      .app-content .table th:nth-child(6),
      .app-content .table td:nth-child(6),
      .app-content .table th:nth-child(7),
      .app-content .table td:nth-child(7) {
        width: 12%;
      }

      .app-content .table th:nth-child(8),
      .app-content .table td:nth-child(8) {
        width: 13%;
      }

      .app-content .table th:nth-child(9),
      .app-content .table td:nth-child(9) {
        width: 8%;
      }

      .app-content .table thead th {
        padding: 0.8rem 0.45rem;
        background-color: var(--bs-tertiary-bg);
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.035em;
        line-height: 1.2;
        text-transform: uppercase;
        white-space: normal;
      }

      .app-content .table tbody td {
        padding: 0.7rem 0.45rem;
      }

      .app-content .table tbody td:first-child,
      .app-content .table tbody td:nth-child(7),
      .app-content .table tbody td:nth-child(8),
      .app-content .table tbody td:last-child {
        white-space: nowrap;
      }

      .app-content .table tbody td:first-child {
        font-variant-numeric: tabular-nums;
      }

      .app-content .table tbody td:nth-child(7) {
        font-size: 0.78rem;
      }

      .app-content .table tbody td:nth-child(8) {
        font-size: 0.78rem;
      }

      .app-content .table .badge {
        min-width: 2.4rem;
        padding: 0.4rem 0.55rem;
        font-size: 0.8rem;
      }

      .app-content .table .btn-group .btn {
        min-width: 2.25rem;
      }

      .app-content .card-footer {
        padding: 0.8rem 1.25rem;
        font-size: 0.875rem;
      }

      @media (max-width: 767.98px) {
        .app-content-header,
        .app-content {
          padding-right: 0.5rem;
          padding-left: 0.5rem;
        }

        .app-content .card-header {
          padding: 1rem;
        }

        .app-content .table {
          min-width: 1000px;
        }
      }

      .badge.grade-orange {
        color: #fff;
        background-color: #fd7e14;
      }
    </style>
    <!--end::Required Plugin(AdminLTE)-->
  </head>
  <!--end::Head-->
  <!--begin::Body-->
  <body class="layout-fixed bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <ul class="navbar-nav">
            <li class="nav-item d-none d-md-block">
              <a href="./index.html" class="nav-link">
                <i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>
                Live preview
              </a>
            </li>
            <li class="nav-item d-none d-md-block">
              <a href="./docs/introduction.html" class="nav-link">
                <i class="bi bi-book me-1" aria-hidden="true"></i>
                Documentation
              </a>
            </li>
          </ul>
          <!--end::Start Navbar Links-->

          <!--begin::Navbar Search-->
          <form
            class="navbar-search d-none d-md-block ms-3"
            role="search"
            action="./pages/search-results.html"
          >
            <label for="navbar-search-input" class="visually-hidden">Search</label>
            <div class="navbar-search-field">
              <input
                type="search"
                id="navbar-search-input"
                name="q"
                class="form-control"
                placeholder="Search…"
                autocomplete="off"
              />
              <button class="navbar-search-submit" type="submit" aria-label="Submit search">
                <i class="bi bi-search" aria-hidden="true"></i>
              </button>
            </div>
          </form>
          <!--end::Navbar Search-->

          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <!--begin::Search (small screens: the field above is hidden, so link to the search page)-->
            <li class="nav-item d-md-none">
              <a class="nav-link" href="./pages/search-results.html" aria-label="Search">
                <i class="bi bi-search" aria-hidden="true"></i>
              </a>
            </li>
            <!--end::Search-->
            <!--begin::Messages Dropdown Menu-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                data-bs-toggle="dropdown"
                href="#"
                aria-label="Messages: 3 unread"
              >
                <i class="bi bi-chat-text"></i>
                <span class="navbar-badge badge text-bg-danger">3</span>
              </a>
              <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <a href="#" class="dropdown-item">
                  <!--begin::Message-->
                  <div class="d-flex">
                    <div class="flex-shrink-0">
                      <img
                        src="../uts_pweb/dist/assets/img/user1-128x128.jpg"
                        alt=""
                        class="img-size-50 rounded-circle me-3"
                      />
                    </div>
                    <div class="flex-grow-1">
                      <p class="dropdown-item-title">
                        Brad Diesel
                        <span class="float-end fs-7 text-danger"
                          ><i class="bi bi-star-fill"></i
                        ></span>
                      </p>
                      <p class="fs-7">Call me whenever you can...</p>
                      <p class="fs-7 text-secondary">
                        <i class="bi bi-clock-fill me-1"></i> 4 Hours Ago
                      </p>
                    </div>
                  </div>
                  <!--end::Message-->
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">
                  <!--begin::Message-->
                  <div class="d-flex">
                    <div class="flex-shrink-0">
                      <img
                        src="../uts_pweb/dist/assets/img/user8-128x128.jpg"
                        alt=""
                        class="img-size-50 rounded-circle me-3"
                      />
                    </div>
                    <div class="flex-grow-1">
                      <p class="dropdown-item-title">
                        John Pierce
                        <span class="float-end fs-7 text-secondary">
                          <i class="bi bi-star-fill"></i>
                        </span>
                      </p>
                      <p class="fs-7">I got your message bro</p>
                      <p class="fs-7 text-secondary">
                        <i class="bi bi-clock-fill me-1"></i> 4 Hours Ago
                      </p>
                    </div>
                  </div>
                  <!--end::Message-->
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">
                  <!--begin::Message-->
                  <div class="d-flex">
                    <div class="flex-shrink-0">
                      <img
                        src="../uts_pweb/dist/assets/img/user3-128x128.jpg"
                        alt=""
                        class="img-size-50 rounded-circle me-3"
                      />
                    </div>
                    <div class="flex-grow-1">
                      <p class="dropdown-item-title">
                        Nora Silvester
                        <span class="float-end fs-7 text-warning">
                          <i class="bi bi-star-fill"></i>
                        </span>
                      </p>
                      <p class="fs-7">The subject goes here</p>
                      <p class="fs-7 text-secondary">
                        <i class="bi bi-clock-fill me-1"></i> 4 Hours Ago
                      </p>
                    </div>
                  </div>
                  <!--end::Message-->
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item dropdown-footer">See All Messages</a>
              </div>
            </li>
            <!--end::Messages Dropdown Menu-->

            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                data-bs-toggle="dropdown"
                href="#"
                aria-label="Notifications: 15 unread"
              >
                <i class="bi bi-bell-fill"></i>
                <span class="navbar-badge badge text-bg-warning">15</span>
              </a>
              <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <span class="dropdown-item dropdown-header">15 Notifications</span>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">
                  <i class="bi bi-envelope me-2"></i> 4 new messages
                  <span class="float-end text-secondary fs-7">3 mins</span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">
                  <i class="bi bi-people-fill me-2"></i> 8 friend requests
                  <span class="float-end text-secondary fs-7">12 hours</span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">
                  <i class="bi bi-file-earmark-fill me-2"></i> 3 new reports
                  <span class="float-end text-secondary fs-7">2 days</span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item dropdown-footer"> See All Notifications </a>
              </div>
            </li>
            <!--end::Notifications Dropdown Menu-->

            <!--begin::Language Menu-->
            <!-- Markup only: swapping the locale is the application's job. The docs
           Recipes page shows how to wire this to a real locale switch. -->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                href="#"
                id="language-menu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Change language, current language English"
              >
                <i class="bi bi-translate" aria-hidden="true"></i>
              </a>
              <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="language-menu">
                <li>
                  <a class="dropdown-item active" href="#" hreflang="en" aria-current="true">
                    English
                    <i class="bi bi-check-lg ms-2" aria-hidden="true"></i>
                  </a>
                </li>
                <li><a class="dropdown-item" href="#" hreflang="es">Español</a></li>
                <li><a class="dropdown-item" href="#" hreflang="fr">Français</a></li>
                <li><a class="dropdown-item" href="#" hreflang="de">Deutsch</a></li>
                <li><a class="dropdown-item" href="#" hreflang="ar">العربية</a></li>
              </ul>
            </li>
            <!--end::Language Menu-->

            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
              <a
                class="nav-link"
                href="#"
                data-lte-toggle="fullscreen"
                aria-label="Toggle fullscreen"
              >
                <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                <i data-lte-icon="minimize" class="bi bi-fullscreen-exit d-none"></i>
              </a>
            </li>
            <!--end::Fullscreen Toggle-->

            <!--begin::Color Mode Toggle (#6010)-->
            <li class="nav-item dropdown">
              <a
                class="nav-link"
                href="#"
                id="bd-theme"
                aria-label="Toggle color scheme"
                data-bs-toggle="dropdown"
                aria-expanded="false"
              >
                <i class="bi bi-sun-fill" data-lte-theme-icon="light"></i>
                <i class="bi bi-moon-fill d-none" data-lte-theme-icon="dark"></i>
                <i class="bi bi-circle-half d-none" data-lte-theme-icon="auto"></i>
              </a>
              <ul
                class="dropdown-menu dropdown-menu-end"
                aria-labelledby="bd-theme"
                style="--bs-dropdown-min-width: 8rem"
              >
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-bs-theme-value="light"
                    aria-pressed="false"
                  >
                    <i class="bi bi-sun-fill me-2"></i>
                    Light
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center"
                    data-bs-theme-value="dark"
                    aria-pressed="false"
                  >
                    <i class="bi bi-moon-fill me-2"></i>
                    Dark
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center active"
                    data-bs-theme-value="auto"
                    aria-pressed="true"
                  >
                    <i class="bi bi-circle-half me-2"></i>
                    Auto
                    <i class="bi bi-check-lg ms-auto d-none"></i>
                  </button>
                </li>
              </ul>
            </li>
            <!--end::Color Mode Toggle-->

            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img
                  src="../uts_pweb/dist/assets/img/user2-160x160.jpg"
                  class="user-image rounded-circle shadow"
                  alt="Rais Asa Madani"
                />
                <span class="d-none d-md-inline">Rais Asa Madani</span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!--begin::User Image-->
                <li class="user-header text-bg-primary">
                  <img
                    src="../uts_pweb/dist/assets/img/user2-160x160.jpg"
                    class="rounded-circle shadow"
                    alt="Rais Asa Madani"
                  />
                  <p>
                    Rais Asa Madani - Web Developer
                    <small>Member since Nov. 2026</small>
                  </p>
                </li>
                <!--end::User Image-->
                <!--begin::Menu Body-->
                <li class="user-body">
                  <!--begin::Row-->
                  <div class="row">
                    <div class="col-4 text-center">
                      <a href="#">Followers</a>
                    </div>
                    <div class="col-4 text-center">
                      <a href="#">Sales</a>
                    </div>
                    <div class="col-4 text-center">
                      <a href="#">Friends</a>
                    </div>
                  </div>
                  <!--end::Row-->
                </li>
                <!--end::Menu Body-->
                <!--begin::Menu Footer-->
                <li class="user-footer">
                  <a href="#" class="btn btn-outline-secondary">Profile</a>
                  <a href="#" class="btn btn-outline-danger float-end">Sign out</a>
                </li>
                <!--end::Menu Footer-->
              </ul>
            </li>
            <!--end::User Menu Dropdown-->
          </ul>
          <!--end::End Navbar Links-->
        </div>
        <!--end::Container-->
      </nav>
      <!--end::Header-->
      <!--begin::Sidebar-->
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <!--begin::Sidebar Brand-->
        <div class="sidebar-brand">
          <!--begin::Brand Link-->
          <a href="./index.html" class="brand-link">
            <!--begin::Brand Image-->
            <img
              src="../uts_pweb/dist/assets/img/AdminLTELogo.png"
              alt="AdminLTE Logo"
              class="brand-image opacity-75 shadow"
            />
            <!--end::Brand Image-->
            <!--begin::Brand Text-->
            <span class="brand-text fw-light">AdminLTE 4</span>
            <!--end::Brand Text-->
          </a>
          <!--end::Brand Link-->
        </div>
        <!--end::Sidebar Brand-->
        <!--begin::Sidebar Search-->
        <div class="sidebar-search" role="search">
          <label for="sidebar-search-input" class="visually-hidden">Filter menu</label>
          <input
            type="search"
            id="sidebar-search-input"
            class="form-control form-control-sm"
            placeholder="Filter menu…"
            autocomplete="off"
            data-lte-toggle="sidebar-search"
            data-lte-target="#navigation"
          />
          <p class="fs-7 text-secondary mt-2 mb-0" data-lte-search-empty role="status" hidden>
            No matching pages.
          </p>
        </div>
        <!--end::Sidebar Search-->
        <!--begin::Sidebar Wrapper-->
        <div class="sidebar-wrapper">
          <nav class="mt-2" aria-label="Main navigation">
            <!--begin::Sidebar Menu-->
            <ul
              class="nav sidebar-menu flex-column"
              data-lte-toggle="treeview"
              data-accordion="false"
              id="navigation"
            >
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-speedometer"></i>
                  <p>
                    Dashboard
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./index.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Dashboard v1</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./index2.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Dashboard v2</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./index3.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Dashboard v3</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="./starter.html" class="nav-link">
                  <i class="nav-icon bi bi-file-earmark"></i>
                  <p>Starter Page</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="./generate/theme.html" class="nav-link">
                  <i class="nav-icon bi bi-palette"></i>
                  <p>Theme Generate</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-box-seam-fill"></i>
                  <p>
                    Widgets
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./widgets/small-box.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Small Box</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./widgets/info-box.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>info Box</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./widgets/cards.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Cards</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./widgets/social.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Social &amp; Post</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-clipboard-fill"></i>
                  <p>
                    Layout Options
                    <span class="nav-badge badge text-bg-secondary me-3">12</span>
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./layout/unfixed-sidebar.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Default Sidebar</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/fixed-sidebar.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Fixed Sidebar</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/fixed-header.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Fixed Header</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/fixed-footer.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Fixed Footer</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/fixed-complete.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Fixed Complete</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/layout-custom-area.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Layout <small>+ Custom Area </small></p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/sidebar-mini.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Sidebar Mini</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/collapsed-sidebar.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Sidebar Mini <small>+ Collapsed</small></p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/collapsed-sidebar-without-hover.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Sidebar Mini <small>+ Collapsed + No Hover</small></p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/logo-switch.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Sidebar Mini <small>+ Logo Switch</small></p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/top-nav.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Top Nav <small>+ No Sidebar</small></p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./layout/layout-rtl.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Layout RTL</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-tree-fill"></i>
                  <p>
                    UI Elements
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./UI/general.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>General</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./UI/icons.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Icons</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./UI/timeline.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Timeline</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./UI/ribbons.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Ribbons</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./UI/colors.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Colors</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-envelope"></i>
                  <p>
                    Mailbox
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./mailbox/inbox.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Inbox</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./mailbox/read.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Read Message</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./mailbox/compose.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Compose</p>
                    </a>
                  </li>
                </ul>
              </li>

              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-pencil-square"></i>
                  <p>
                    Forms
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./forms/elements.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Elements</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./forms/layout.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Layout</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./forms/validation.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Validation</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./forms/wizard.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Wizard</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./forms/advanced.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Advanced Elements</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./forms/editors.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Editors</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-table"></i>
                  <p>
                    Tables
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./tables/simple.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Simple Tables</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./tables/data.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Data Tables</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-graph-up"></i>
                  <p>
                    Charts
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./charts/chartjs.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Chart.js</p>
                    </a>
                  </li>
                </ul>
              </li>

              <li class="nav-header">PAGES</li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-file-earmark-text"></i>
                  <p>
                    Pages
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="./pages/profile.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Profile</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/settings.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Settings</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/invoice.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Invoice</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/calendar.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Calendar</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/kanban.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Kanban</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/chat.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Chat</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/file-manager.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>File Manager</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/projects.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Projects</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/gallery.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Gallery</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/search-results.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Search Results</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/pricing.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Pricing</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="./pages/faq.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>FAQ</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>
                        Error
                        <i class="nav-arrow bi bi-chevron-right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview">
                      <li class="nav-item">
                        <a href="./pages/404.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>404</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="./pages/500.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>500</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="./pages/maintenance.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Maintenance</p>
                        </a>
                      </li>
                    </ul>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="./users.html" class="nav-link active">
                  <i class="nav-icon bi bi-people"></i>
                  <p>Users</p>
                </a>
              </li>

              <li class="nav-header">EXAMPLES</li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-box-arrow-in-right"></i>
                  <p>
                    Auth
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-box-arrow-in-right"></i>
                      <p>
                        Version 1
                        <i class="nav-arrow bi bi-chevron-right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview">
                      <li class="nav-item">
                        <a href="./examples/login.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Login</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="./examples/register.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Register</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="./examples/forgot-password.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Forgot Password</p>
                        </a>
                      </li>
                    </ul>
                  </li>
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-box-arrow-in-right"></i>
                      <p>
                        Version 2
                        <i class="nav-arrow bi bi-chevron-right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview">
                      <li class="nav-item">
                        <a href="./examples/login-v2.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Login</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="./examples/register-v2.html" class="nav-link">
                          <i class="nav-icon bi bi-circle"></i>
                          <p>Register</p>
                        </a>
                      </li>
                    </ul>
                  </li>
                  <li class="nav-item">
                    <a href="./examples/lockscreen.html" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Lockscreen</p>
                    </a>
                  </li>
                </ul>
              </li>

              <li class="nav-header">MULTI LEVEL EXAMPLE</li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle-fill"></i>
                  <p>Level 1</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle-fill"></i>
                  <p>
                    Level 1
                    <i class="nav-arrow bi bi-chevron-right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview">
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Level 2</p>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>
                        Level 2
                        <i class="nav-arrow bi bi-chevron-right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview">
                      <li class="nav-item">
                        <a href="#" class="nav-link">
                          <i class="nav-icon bi bi-record-circle-fill"></i>
                          <p>Level 3</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#" class="nav-link">
                          <i class="nav-icon bi bi-record-circle-fill"></i>
                          <p>Level 3</p>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#" class="nav-link">
                          <i class="nav-icon bi bi-record-circle-fill"></i>
                          <p>Level 3</p>
                        </a>
                      </li>
                    </ul>
                  </li>
                  <li class="nav-item">
                    <a href="#" class="nav-link">
                      <i class="nav-icon bi bi-circle"></i>
                      <p>Level 2</p>
                    </a>
                  </li>
                </ul>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle-fill"></i>
                  <p>Level 1</p>
                </a>
              </li>

              <li class="nav-header">LABELS</li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle text-danger"></i>
                  <p class="text">Important</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle text-warning"></i>
                  <p>Warning</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="#" class="nav-link">
                  <i class="nav-icon bi bi-circle text-info"></i>
                  <p>Informational</p>
                </a>
              </li>
            </ul>
            <!--end::Sidebar Menu-->

            <!-- Docs CTA (bottom of sidebar) -->
            <div class="p-3 mt-3 border-top border-secondary border-opacity-25">
              <a
                href="./docs/introduction.html"
                class="btn btn-sm btn-outline-light w-100 d-flex align-items-center justify-content-center gap-2"
              >
                <i class="bi bi-book" aria-hidden="true"></i>
                View documentation
              </a>
            </div>
          </nav>
        </div>
        <!--end::Sidebar Wrapper-->
      </aside>
      <!--end::Sidebar-->
      <!--begin::App Main-->
      <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3">Nilai Mahasiswa</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Nilai Mahasiswa</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content Header-->
        <!--begin::App Content-->
        <div class="app-content">
          <div class="container-fluid">
            <div class="row">
              <div class="col-12">
                <div class="card mb-4">
                  <div class="card-header">
                    <div class="row g-2 align-items-center">
                      <div class="col-12 col-md-4">
                        <h3 class="card-title">Daftar Nilai Mahasiswa</h3>
                      </div>
                      <div class="col-12 col-md-8">
                        <div class="d-flex flex-wrap justify-content-md-end gap-2">
                          <div class="input-group input-group-sm w-auto">
                            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                            <input type="search" id="student-search" class="form-control" placeholder="Cari NIM atau nama" aria-label="Cari NIM atau nama mahasiswa" style="width: 200px" />
                          </div>
                          <select id="grade-filter" class="form-select form-select-sm w-auto" aria-label="Filter nilai huruf">
                            <option value="all" selected>Semua nilai</option>
                            <option value="A">Nilai A</option>
                            <option value="B">Nilai B</option>
                            <option value="C">Nilai C</option>
                            <option value="D">Nilai D</option>
                            <option value="E">Nilai E</option>
                          </select>
                          <select id="score-sort" class="form-select form-select-sm w-auto" aria-label="Urutkan mahasiswa berdasarkan nilai">
                            <option value="default" selected>Urutan terbaru</option>
                            <option value="highest">Nilai terbesar</option>
                          </select>
                          <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#modal-add-student"
                          >
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                            Tambah Mahasiswa
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php if ($actionError !== ""): ?>
                    <div class="alert alert-danger mx-3 mt-3 mb-0" role="alert">
                      <?= htmlspecialchars($actionError, ENT_QUOTES, "UTF-8") ?>
                    </div>
                  <?php elseif ($statusMessage !== ""): ?>
                    <div
                      class="alert alert-success mx-3 mt-3 mb-0<?= in_array($status, ["deleted", "updated"], true) ? " fade show" : "" ?>"
                      role="status"
                      <?= in_array($status, ["added", "deleted", "updated"], true) ? 'id="action-success-alert"' : "" ?>
                    >
                      <?= htmlspecialchars($statusMessage, ENT_QUOTES, "UTF-8") ?>
                    </div>
                  <?php endif; ?>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-hover align-middle m-0">
                        <thead>
                          <tr>
                            <th scope="col">NIM</th>
                            <th scope="col" class="text-center">Profil</th>
                            <th scope="col" class="text-center">Nama Mahasiswa</th>
                            <th scope="col">Nilai Akhir</th>
                            <th scope="col">Nilai Huruf</th>
                            <th scope="col">Predikat</th>
                            <th scope="col">Tanggal Dibuat</th>
                            <th scope="col">Tanggal Diubah</th>
                            <th scope="col" class="text-end">Aksi</th>
                          </tr>
                        </thead>
                        <tbody id="student-rows">
                          <?php if ($students): ?>
                            <?php foreach ($students as $index => $student): ?>
                              <?php
                                $score = (float) $student["nilaiakhir"];
                                $photoPaths = getStudentPhotoPaths($student["nim"]);
                                $photoFileModified = $photoPaths ? filemtime($photoPaths[0]) : false;
                                $photoSource = $photoPaths
                                    ? "uploads/mahasiswa/" . basename($photoPaths[0]) .
                                        ($photoFileModified !== false ? "?v=" . $photoFileModified : "")
                                    : "../uts_pweb/dist/assets/img/" . $studentAvatars[$index % count($studentAvatars)];
                                if ($score >= 85) {
                                    $gradeClass = "text-bg-success";
                                } elseif ($score >= 75) {
                                    $gradeClass = "text-bg-info";
                                } elseif ($score >= 65) {
                                    $gradeClass = "text-bg-warning";
                                } elseif ($score >= 50) {
                                    $gradeClass = "grade-orange";
                                } else {
                                    $gradeClass = "text-bg-danger";
                                }
                              ?>
                              <tr
                                data-grade="<?= htmlspecialchars($student["nilaihuruf"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                data-score="<?= htmlspecialchars((string) $student["nilaiakhir"], ENT_QUOTES, "UTF-8") ?>"
                              >
                                <td><?= htmlspecialchars($student["nim"], ENT_QUOTES, "UTF-8") ?></td>
                                <td class="text-center">
                                  <img
                                    src="<?= htmlspecialchars($photoSource, ENT_QUOTES, "UTF-8") ?>"
                                    alt="Foto <?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?>"
                                    class="img-size-32 rounded-circle"
                                  />
                                </td>
                                <td class="text-center"><span class="fw-medium"><?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?></span></td>
                                <td><span class="badge <?= $gradeClass ?>"><?= htmlspecialchars(number_format($score, 2), ENT_QUOTES, "UTF-8") ?></span></td>
                                <td><span class="badge <?= $gradeClass ?>"><?= htmlspecialchars($student["nilaihuruf"] ?? "-", ENT_QUOTES, "UTF-8") ?></span></td>
                                <td><?= htmlspecialchars($student["predikat"] ?? "-", ENT_QUOTES, "UTF-8") ?></td>
                                <td><?= htmlspecialchars($student["created_date"] ?? "-", ENT_QUOTES, "UTF-8") ?></td>
                                <td><?= htmlspecialchars($student["modified_date"] ?? "-", ENT_QUOTES, "UTF-8") ?></td>
                                <td class="text-end">
                                  <div class="btn-group btn-group-sm">
                                    <button
                                      type="button"
                                      class="btn btn-outline-primary"
                                      aria-label="Update <?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?>"
                                      data-bs-toggle="modal"
                                      data-bs-target="#modal-edit-student"
                                      data-student-nim="<?= htmlspecialchars($student["nim"], ENT_QUOTES, "UTF-8") ?>"
                                      data-student-name="<?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?>"
                                      data-student-score="<?= htmlspecialchars((string) $student["nilaiakhir"], ENT_QUOTES, "UTF-8") ?>"
                                      data-student-photo="<?= htmlspecialchars($photoSource, ENT_QUOTES, "UTF-8") ?>"
                                    >
                                      <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button
                                      type="button"
                                      class="btn btn-outline-danger"
                                      aria-label="Hapus <?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?>"
                                      data-bs-toggle="modal"
                                      data-bs-target="#modal-delete-student"
                                      data-student-nim="<?= htmlspecialchars($student["nim"], ENT_QUOTES, "UTF-8") ?>"
                                      data-student-name="<?= htmlspecialchars($student["namamhsw"], ENT_QUOTES, "UTF-8") ?>"
                                    >
                                      <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                  </div>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <tr><td colspan="9" class="text-center text-body-secondary py-4">Belum ada data nilai mahasiswa.</td></tr>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="text-body-secondary" id="student-count" aria-live="polite">Menampilkan <?= count($students) ?> mahasiswa</span>
                    <nav aria-label="Navigasi halaman mahasiswa">
                      <ul class="pagination pagination-sm mb-0" id="student-pagination"></ul>
                    </nav>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!--end::App Content-->
        <!--begin::Add Student Modal-->
        <div
          class="modal fade"
          id="modal-add-student"
          tabindex="-1"
          aria-labelledby="modal-add-student-label"
          aria-hidden="true"
        >
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" action="formnilai.php" enctype="multipart/form-data">
                <div class="modal-header">
                  <h5 class="modal-title" id="modal-add-student-label">Tambah Mahasiswa</h5>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Tutup"
                  ></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label for="new-student-nim" class="form-label">NIM</label>
                    <input
                      type="text"
                      class="form-control"
                      id="new-student-nim"
                      name="nim"
                      inputmode="numeric"
                      maxlength="10"
                      pattern="[0-9]{10}"
                      placeholder="cth. 3202416087"
                      required
                    />
                  </div>
                  <div class="mb-3">
                    <label for="new-student-name" class="form-label">Nama Mahasiswa</label>
                    <input
                      type="text"
                      class="form-control"
                      id="new-student-name"
                      name="nama"
                      pattern="[A-Za-z' ]+"
                      placeholder="cth. John Doe"
                      required
                    />
                  </div>
                  <div class="mb-3">
                    <label for="new-student-score" class="form-label">Nilai Akhir</label>
                    <input
                      type="number"
                      class="form-control"
                      id="new-student-score"
                      name="nilai"
                      min="0"
                      max="100"
                      step="any"
                      placeholder="cth. 85.5"
                      required
                    />
                  </div>
                  <div class="mb-3">
                    <label for="new-student-photo" class="form-label">Foto Mahasiswa</label>
                    <input
                      type="file"
                      class="form-control"
                      id="new-student-photo"
                      name="foto"
                      accept="image/jpeg,image/png,image/webp"
                    />
                    <div class="form-text">Opsional. Format JPG, PNG, atau WEBP; maksimal 2 MB.</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Batal
                  </button>
                  <button type="submit" class="btn btn-primary">Simpan Mahasiswa</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--end::Add Student Modal-->
        <!--begin::Edit Student Modal-->
        <div
          class="modal fade"
          id="modal-edit-student"
          tabindex="-1"
          aria-labelledby="modal-edit-student-label"
          aria-hidden="true"
        >
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" action="datatable.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_student" />
                <input type="hidden" id="edit-student-nim" name="nim" />
                <div class="modal-header">
                  <h5 class="modal-title" id="modal-edit-student-label">Edit Data Mahasiswa</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label for="edit-student-nim-display" class="form-label">NIM</label>
                    <input type="text" class="form-control" id="edit-student-nim-display" readonly />
                  </div>
                  <div class="mb-3">
                    <label for="edit-student-name" class="form-label">Nama Mahasiswa</label>
                    <input
                      type="text"
                      class="form-control"
                      id="edit-student-name"
                      name="nama"
                      maxlength="30"
                      pattern="[A-Za-z' ]+"
                      required
                    />
                  </div>
                  <div class="mb-3">
                    <label for="edit-student-score" class="form-label">Nilai Akhir</label>
                    <input
                      type="number"
                      class="form-control"
                      id="edit-student-score"
                      name="nilai"
                      min="0"
                      max="100"
                      step="any"
                      required
                    />
                  </div>
                  <div class="mb-3">
                    <label for="edit-student-photo" class="form-label">Ganti Foto Mahasiswa</label>
                    <div class="mb-2">
                      <img
                        id="edit-student-photo-preview"
                        src="../uts_pweb/dist/assets/img/default-150x150.png"
                        alt="Pratinjau foto mahasiswa"
                        class="img-size-64 rounded-circle"
                      />
                    </div>
                    <input
                      type="file"
                      class="form-control"
                      id="edit-student-photo"
                      name="foto"
                      accept="image/jpeg,image/png,image/webp"
                    />
                    <div class="form-text">Opsional. Kosongkan jika tidak ingin mengubah foto. Format JPG, PNG, atau WEBP; maksimal 2 MB.</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--end::Edit Student Modal-->
        <!--begin::Delete Student Modal-->
        <div
          class="modal fade"
          id="modal-delete-student"
          tabindex="-1"
          aria-labelledby="modal-delete-student-label"
          aria-hidden="true"
        >
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" action="datatable.php">
                <input type="hidden" name="action" value="delete_student" />
                <input type="hidden" id="delete-student-nim" name="nim" />
                <div class="modal-header">
                  <h5 class="modal-title" id="modal-delete-student-label">Hapus Mahasiswa</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                  <p class="mb-0">
                    Yakin ingin menghapus data mahasiswa <strong id="delete-student-name"></strong>?
                    Tindakan ini tidak dapat dibatalkan.
                  </p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-danger">Hapus</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--end::Delete Student Modal-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">Anything you want</div>
        <!--end::To the end-->
        <!--begin::Copyright-->
        <strong>
          Copyright &copy; 2014-2026&nbsp;
          <a href="https://adminlte.io" class="text-decoration-none">AdminLTE.io</a>.
        </strong>
        All rights reserved.
        <!--end::Copyright-->
      </footer>
      <!--end::Footer-->
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <script
      src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Third Party Plugin(OverlayScrollbars)--><!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)--><!--begin::Required Plugin(Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Required Plugin(Bootstrap 5)--><!--begin::Required Plugin(AdminLTE)-->
    <script src="../uts_pweb/dist/js/adminlte.js"></script>
    <!--end::Required Plugin(AdminLTE)-->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const tableBody = document.getElementById('student-rows');
        const searchInput = document.getElementById('student-search');
        const gradeFilter = document.getElementById('grade-filter');
        const scoreSort = document.getElementById('score-sort');
        const countLabel = document.getElementById('student-count');
        const pagination = document.getElementById('student-pagination');
        const rows = Array.from(tableBody.querySelectorAll('tr[data-grade]'));
        const rowsPerPage = 10;
        let currentPage = 1;
        const editModal = document.getElementById('modal-edit-student');
        const editPhotoInput = document.getElementById('edit-student-photo');
        const editPhotoPreview = document.getElementById('edit-student-photo-preview');
        let selectedPhotoPreviewUrl = null;
        const deleteModal = document.getElementById('modal-delete-student');
        const actionSuccessAlert = document.getElementById('action-success-alert');

        if (actionSuccessAlert) {
          window.setTimeout(function () {
            actionSuccessAlert.classList.remove('show');
            window.setTimeout(function () {
              actionSuccessAlert.remove();
            }, 200);
          }, 3000);
        }

        editModal.addEventListener('show.bs.modal', function (event) {
          const trigger = event.relatedTarget;
          if (selectedPhotoPreviewUrl) {
            URL.revokeObjectURL(selectedPhotoPreviewUrl);
            selectedPhotoPreviewUrl = null;
          }
          editPhotoInput.value = '';
          editPhotoPreview.src = trigger.dataset.studentPhoto;
          document.getElementById('edit-student-nim').value = trigger.dataset.studentNim;
          document.getElementById('edit-student-nim-display').value = trigger.dataset.studentNim;
          document.getElementById('edit-student-name').value = trigger.dataset.studentName;
          document.getElementById('edit-student-score').value = trigger.dataset.studentScore;
        });

        editPhotoInput.addEventListener('change', function () {
          if (selectedPhotoPreviewUrl) {
            URL.revokeObjectURL(selectedPhotoPreviewUrl);
            selectedPhotoPreviewUrl = null;
          }
          if (editPhotoInput.files && editPhotoInput.files[0]) {
            selectedPhotoPreviewUrl = URL.createObjectURL(editPhotoInput.files[0]);
            editPhotoPreview.src = selectedPhotoPreviewUrl;
          }
        });

        editModal.addEventListener('hidden.bs.modal', function () {
          if (selectedPhotoPreviewUrl) {
            URL.revokeObjectURL(selectedPhotoPreviewUrl);
            selectedPhotoPreviewUrl = null;
          }
        });

        deleteModal.addEventListener('show.bs.modal', function (event) {
          const trigger = event.relatedTarget;
          document.getElementById('delete-student-nim').value = trigger.dataset.studentNim;
          document.getElementById('delete-student-name').textContent = trigger.dataset.studentName;
        });

        function renderRows() {
          const query = searchInput.value.trim().toLocaleLowerCase('id');
          const selectedGrade = gradeFilter.value;
          const sortedRows = rows.slice();

          if (scoreSort.value === 'highest') {
            sortedRows.sort(function (firstRow, secondRow) {
              return Number(secondRow.dataset.score) - Number(firstRow.dataset.score);
            });
          }

          const filteredRows = sortedRows.filter(function (row) {
            const matchesText = row.textContent.toLocaleLowerCase('id').includes(query);
            const matchesGrade = selectedGrade === 'all' || row.dataset.grade === selectedGrade;
            return matchesText && matchesGrade;
          });
          const pageCount = Math.ceil(filteredRows.length / rowsPerPage);
          currentPage = Math.min(currentPage, Math.max(pageCount, 1));
          const firstRowIndex = (currentPage - 1) * rowsPerPage;
          const pageRows = new Set(filteredRows.slice(firstRowIndex, firstRowIndex + rowsPerPage));

          sortedRows.forEach(function (row) {
            row.hidden = !pageRows.has(row);
            tableBody.appendChild(row);
          });

          let emptyRow = document.getElementById('student-empty-row');
          if (rows.length > 0 && filteredRows.length === 0) {
            if (!emptyRow) {
              emptyRow = document.createElement('tr');
              emptyRow.id = 'student-empty-row';
              const cell = document.createElement('td');
              cell.colSpan = 9;
              cell.className = 'text-center text-body-secondary py-4';
              cell.textContent = 'Data mahasiswa tidak ditemukan.';
              emptyRow.appendChild(cell);
              tableBody.appendChild(emptyRow);
            }
          } else if (emptyRow) {
            emptyRow.remove();
          }

          const firstVisibleRow = filteredRows.length === 0 ? 0 : firstRowIndex + 1;
          const lastVisibleRow = Math.min(firstRowIndex + rowsPerPage, filteredRows.length);
          countLabel.textContent = filteredRows.length === 0
            ? 'Menampilkan 0 dari 0 mahasiswa'
            : 'Menampilkan ' + firstVisibleRow + '–' + lastVisibleRow + ' dari ' + filteredRows.length + ' mahasiswa';

          pagination.replaceChildren();
          pagination.parentElement.hidden = pageCount === 0;
          if (pageCount > 0) {
            const pageButtons = [
              { label: 'Sebelumnya', page: currentPage - 1, disabled: currentPage === 1 },
            ];
            for (let page = 1; page <= pageCount; page += 1) {
              pageButtons.push({
                label: String(page),
                page: page,
                disabled: false,
                current: page === currentPage,
              });
            }
            pageButtons.push({
              label: 'Berikutnya',
              page: currentPage + 1,
              disabled: currentPage === pageCount,
            });

            pageButtons.forEach(function (pageButton) {
              const item = document.createElement('li');
              item.className = 'page-item' + (pageButton.disabled ? ' disabled' : '') + (pageButton.current ? ' active' : '');
              const button = document.createElement('button');
              button.type = 'button';
              button.className = 'page-link';
              button.textContent = pageButton.label;
              button.disabled = pageButton.disabled;
              button.setAttribute('aria-label', pageButton.current ? 'Halaman ' + pageButton.page : pageButton.label);
              if (pageButton.current) {
                button.setAttribute('aria-current', 'page');
              }
              button.addEventListener('click', function () {
                currentPage = pageButton.page;
                renderRows();
              });
              item.appendChild(button);
              pagination.appendChild(item);
            });
          }
        }

        searchInput.addEventListener('input', function () {
          currentPage = 1;
          renderRows();
        });
        gradeFilter.addEventListener('change', function () {
          currentPage = 1;
          renderRows();
        });
        scoreSort.addEventListener('change', function () {
          currentPage = 1;
          renderRows();
        });
        renderRows();
      });
    </script>    <!--begin::OverlayScrollbars Configure-->
    <script>
      const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
      const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
      };
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);

        // Disable OverlayScrollbars on mobile devices to prevent touch interference
        const isMobile = window.innerWidth <= 992;

        if (
          sidebarWrapper &&
          OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined &&
          !isMobile
        ) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: {
              theme: Default.scrollbarTheme,
              autoHide: Default.scrollbarAutoHide,
              clickScroll: Default.scrollbarClickScroll,
            },
          });
        }
      });
    </script>
    <!--end::OverlayScrollbars Configure-->

    <!--begin::Color Mode Toggle-->
    <!-- The light/dark/auto switcher ships in adminlte.js as the ColorMode
     module (since 4.1) — no page script needed. Only the no-flash snippet
     in <head> stays inline, because it must run before first paint. -->
    <!--end::Color Mode Toggle-->

    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>