<?php

// koneksi ke database
require_once __DIR__ . "/connect.php";

// cek apakah data dikirim menggunakan POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ambil data dari form
    $nim = trim($_POST['nim'] ?? '');
    $namamhsw = trim($_POST['namamhsw'] ?? '');
    $nilaiakhir = trim($_POST['nilaiakhir'] ?? '');

    // validasi data
    if (
        $nim !== '' &&
        preg_match("/^[0-9]+$/", $nim) &&
        $namamhsw !== '' &&
        preg_match("/^[a-zA-Z' ]+$/", $namamhsw) &&
        $nilaiakhir !== '' &&
        is_numeric($nilaiakhir) &&
        $nilaiakhir >= 0 &&
        $nilaiakhir <= 100
    ) {

        // query menyimpan data mahasiswa
        $sql = "INSERT INTO nilaimahasiswa
                (nim, namamhsw, nilaiakhir)
                VALUES (?, ?, ?)";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            $nilaiakhir = (float) $nilaiakhir;

            mysqli_stmt_bind_param(
                $stmt,
                "ssd",
                $nim,
                $namamhsw,
                $nilaiakhir
            );

            if (mysqli_stmt_execute($stmt)) {
                echo "Data mahasiswa berhasil disimpan!";
            } else {
                echo "Gagal menyimpan data.";
            }

            mysqli_stmt_close($stmt);

        } else {
            echo "Gagal menyiapkan query database.";
        }

    } else {
        echo "Data tidak valid. Periksa kembali NIM, nama, dan nilai akhir.";
    }

} else {
    echo "Silakan kirim data melalui form.";
}

header("Location: readdata.php");
// tutup koneksi database
//mysqli_close($conn);

?>