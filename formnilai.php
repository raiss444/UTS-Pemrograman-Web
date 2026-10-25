<?php

require_once __DIR__ . "/connect.php";

$nim = "";
$nama = "";
$nilai = "";
$nilaiHuruf = "";
$predikat = "";
$pesan = "";

$errNim = "";
$errNama = "";
$errNilai = "";
$errFoto = "";

$sts_nim = "";
$sts_nama = "";
$sts_nilai = "";
$photoTemporaryPath = null;
$photoExtension = null;

// Fungsi sanitasi
function sanitasi($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Cek NIM berisi atau kosong
    if (!empty($_POST["nim"])) {

        $nim = sanitasi($_POST["nim"]);

        // NIM harus tepat 10 digit angka
        if (preg_match("/^[0-9]{10}$/", $nim)) {

            // Cek NIM sudah terdaftar atau belum
            $sqlCekNim = "SELECT nim FROM nilaimahasiswa WHERE nim = ?";
            $stmtCekNim = mysqli_prepare($conn, $sqlCekNim);

            if ($stmtCekNim) {

                mysqli_stmt_bind_param($stmtCekNim, "s", $nim);
                mysqli_stmt_execute($stmtCekNim);
                mysqli_stmt_store_result($stmtCekNim);

                if (mysqli_stmt_num_rows($stmtCekNim) > 0) {
                    $errNim = "NIM sudah terdaftar, gunakan NIM lain!";
                } else {
                    $sts_nim = "valid";
                }

                mysqli_stmt_close($stmtCekNim);

            } else {
                $errNim = "Gagal memeriksa NIM di database!";
            }

        } else {
            $errNim = "NIM harus terdiri dari tepat 10 digit angka!";
        }

    } else {
        $errNim = "NIM wajib diisi!";
    }


    // Cek nama berisi atau kosong
    if (!empty($_POST["nama"])) {

        $nama = sanitasi($_POST["nama"]);

        // Validasi nama hanya boleh berisi huruf, tanda petik, dan spasi
        if (preg_match("/^[a-zA-Z' ]+$/", $nama)) {

            // Cek nama sudah terdaftar atau belum
            $sqlCekNama = "SELECT nim FROM nilaimahasiswa
                           WHERE LOWER(TRIM(namamhsw)) = LOWER(?)";

            $stmtCekNama = mysqli_prepare($conn, $sqlCekNama);

            if ($stmtCekNama) {

                mysqli_stmt_bind_param($stmtCekNama, "s", $nama);
                mysqli_stmt_execute($stmtCekNama);
                mysqli_stmt_store_result($stmtCekNama);

                if (mysqli_stmt_num_rows($stmtCekNama) > 0) {
                    $errNama = "Nama sudah terdaftar, gunakan nama lain!";
                } else {
                    $sts_nama = "valid";
                }

                mysqli_stmt_close($stmtCekNama);

            } else {
                $errNama = "Gagal memeriksa nama di database!";
            }

        } else {
            $errNama = "Nama hanya boleh berisi huruf dan spasi!";
        }

    } else {
        $errNama = "Nama wajib diisi!";
    }


    // Cek nilai berisi atau kosong
    if (isset($_POST["nilai"]) && $_POST["nilai"] !== "") {

        $nilai = sanitasi($_POST["nilai"]);

        // Cek format nilai berupa angka atau desimal
        if (preg_match("/^[0-9]+(\.[0-9]+)?$/", $nilai)) {

            if ($nilai >= 0 && $nilai <= 100) {

                $sts_nilai = "valid";

                // Konversi nilai menjadi huruf dan predikat
                if ($nilai >= 85) {
                    $nilaiHuruf = "A";
                    $predikat = "Sangat Baik";

                } elseif ($nilai >= 75) {
                    $nilaiHuruf = "B";
                    $predikat = "Baik";

                } elseif ($nilai >= 65) {
                    $nilaiHuruf = "C";
                    $predikat = "Cukup";

                } elseif ($nilai >= 50) {
                    $nilaiHuruf = "D";
                    $predikat = "Kurang";

                } else {
                    $nilaiHuruf = "E";
                    $predikat = "Sangat Kurang";
                }

            } else {
                $errNilai = "Nilai harus berada di antara 0 sampai 100!";
            }

        } else {
            $errNilai = "Nilai harus berupa angka!";
        }

    } else {
        $errNilai = "Nilai wajib diisi!";
    }

    $uploadedPhoto = $_FILES["foto"] ?? null;
    if ($uploadedPhoto !== null) {
        if (!is_array($uploadedPhoto) || !isset($uploadedPhoto["error"])) {
            $errFoto = "Foto tidak dapat diproses.";
        } elseif ($uploadedPhoto["error"] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedPhoto["error"] !== UPLOAD_ERR_OK) {
                $errFoto = "Foto gagal diunggah.";
            } elseif (!isset($uploadedPhoto["size"], $uploadedPhoto["tmp_name"]) ||
                !is_int($uploadedPhoto["size"]) ||
                $uploadedPhoto["size"] < 1 ||
                $uploadedPhoto["size"] > 2 * 1024 * 1024 ||
                !is_string($uploadedPhoto["tmp_name"]) ||
                !is_uploaded_file($uploadedPhoto["tmp_name"])) {
                $errFoto = "Foto harus berukuran maksimal 2 MB.";
            } else {
                $photoInfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($photoInfo === false) {
                    error_log("Gagal menginisialisasi pemeriksa tipe foto mahasiswa.");
                    $errFoto = "Foto tidak dapat diperiksa.";
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
                        $errFoto = "Foto harus berupa gambar JPG, PNG, atau WEBP.";
                    } else {
                        $photoTemporaryPath = $uploadedPhoto["tmp_name"];
                        $photoExtension = $allowedPhotoTypes[$photoMimeType];
                    }
                }
            }
        }
    }

    // Simpan data jika semua validasi berhasil
    if (
        $sts_nim == "valid" &&
        $sts_nama == "valid" &&
        $sts_nilai == "valid" &&
        $errFoto === ""
    ) {

        $sql = "INSERT INTO nilaimahasiswa
                (nim, namamhsw, nilaiakhir, nilaihuruf, predikat)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "ssdss",
                $nim,
                $nama,
                $nilai,
                $nilaiHuruf,
                $predikat
            );

            $photoDestination = null;
            $photoMoved = false;
            $canSaveStudent = true;
            if ($photoTemporaryPath !== null && $photoExtension !== null) {
                $photoDirectory = __DIR__ . "/uploads/mahasiswa";
                if (!is_dir($photoDirectory) &&
                    !mkdir($photoDirectory, 0755, true) &&
                    !is_dir($photoDirectory)) {
                    error_log("Gagal membuat direktori penyimpanan foto mahasiswa.");
                    $pesan = "Foto gagal disimpan. Silakan coba lagi.";
                    $canSaveStudent = false;
                } else {
                    $photoDestination = $photoDirectory . "/" . hash("sha256", $nim) . "." . $photoExtension;
                    if (!move_uploaded_file($photoTemporaryPath, $photoDestination)) {
                        error_log("Gagal memindahkan foto mahasiswa ke direktori penyimpanan.");
                        $pesan = "Foto gagal disimpan. Silakan coba lagi.";
                        $canSaveStudent = false;
                    } else {
                        $photoMoved = true;
                    }
                }
            }

            if ($canSaveStudent && mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header("Location: datatable.php?status=added");
                exit;
            } else {
                if ($canSaveStudent) {
                    error_log("Gagal menyimpan mahasiswa: " . mysqli_stmt_error($stmt));
                    $pesan = "Data gagal disimpan.";
                }
                if ($photoMoved && $photoDestination !== null && !unlink($photoDestination)) {
                    error_log("Gagal membersihkan foto mahasiswa yang tidak tersimpan: " . $photoDestination);
                }
                mysqli_stmt_close($stmt);
            }

        } else {
            $pesan = "Gagal menyiapkan query penyimpanan data!";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Nilai Akhir Mahasiswa</title>
</head>

<body>

    <h2>Form Nilai Akhir Mahasiswa</h2>

    <fieldset>

        <legend>Input Data Mahasiswa</legend>

        <form method="POST" action="" enctype="multipart/form-data">

            <p>
                <label for="nim">NIM:</label><br>

                <input
                    type="text"
                    id="nim"
                    name="nim"
                    maxlength="10"
                    value="<?php echo $nim; ?>"
                >

                <br>

                <span style="color:red;">
                    <?php echo $errNim; ?>
                </span>
            </p>

            <p>
                <label for="nama">Nama Mahasiswa:</label><br>

                <input
                    type="text"
                    id="nama"
                    name="nama"
                    value="<?php echo $nama; ?>"
                >

                <br>

                <span style="color:red;">
                    <?php echo $errNama; ?>
                </span>
            </p>

            <p>
                <label for="nilai">Nilai Akhir:</label><br>

                <input
                    type="text"
                    id="nilai"
                    name="nilai"
                    value="<?php echo $nilai; ?>"
                >

                <br>

                <span style="color:red;">
                    <?php echo $errNilai; ?>
                </span>
            </p>

            <p>
                <label for="foto">Foto Mahasiswa (opsional):</label><br>
                <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
                <br>
                <small>Format JPG, PNG, atau WEBP; maksimal 2 MB.</small><br>
                <span style="color:red;">
                    <?php echo htmlspecialchars($errFoto, ENT_QUOTES, "UTF-8"); ?>
                </span>
            </p>

            <?php if ($pesan !== ""): ?>
                <p style="color:red;"><?php echo htmlspecialchars($pesan, ENT_QUOTES, "UTF-8"); ?></p>
            <?php endif; ?>

            <button type="submit">SAVE</button>

        </form>

    </fieldset>

    <p><a href="datatable.php"><button>Lihat Data Nilai Mahasiswa</button></a></p>

    <br>

</body>
</html>