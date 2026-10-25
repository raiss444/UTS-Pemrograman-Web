<?php
// definisi nilai parameter server
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "db5a";

// Connect to MySQL first so the database can be created if it does not exist yet.
$conn = mysqli_connect($servername, $username, $password);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// Create the database before selecting it.
$createDatabaseSql = "CREATE DATABASE IF NOT EXISTS `$dbname`";
if (!mysqli_query($conn, $createDatabaseSql)) {
    die("Pembuatan database gagal: " . mysqli_error($conn));
}

if (!mysqli_select_db($conn, $dbname)) {
    die("Tidak dapat memilih database: " . mysqli_error($conn));
}

// Create the table if it does not exist yet.
$sql = "CREATE TABLE IF NOT EXISTS nilaimahasiswa (
    nim VARCHAR(10) PRIMARY KEY,
    namamhsw VARCHAR(30) NOT NULL,
    nilaiakhir FLOAT(5) NOT NULL,
    nilaihuruf VARCHAR(5),
    predikat VARCHAR(20),
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// menjalankan fungsi mysqli_query
if (mysqli_query($conn, $sql)) {
  echo "Table nilaimahasiswa Berhasil dibuat";
} else {
  echo "Pembuatan Tabel Gagal: " . mysqli_error($conn);
}

// menutup koneksi ke db
mysqli_close($conn);

?>  