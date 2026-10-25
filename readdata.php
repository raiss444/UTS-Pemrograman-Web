<?php

require_once __DIR__ . "/connect.php";

$sql = "SELECT * FROM nilaimahasiswa";

// Execute the SQL query
$result = mysqli_query($conn, $sql);

echo "<div style='display: table; margin: 0 auto; text-align: center;'>";

// Process the result set

// Process the result set
if (mysqli_num_rows($result) > 0) {
  // Output data of each row
  echo "<table border=1 cellpadding=10>";
  echo "<caption><h3>Data Nilai Mahasiswa</h3></caption>";
  echo "<tr>
            <th>NIM</th>
            <th>Nama Mahasiswa</th>
            <th>Nilai Akhir</th>
            <th>Nilai Huruf</th>
            <th>Predikat</th>
            <th>Dibuat pada Tgl</th>
            <th>Diubah pada Tgl</th>
            <th>Aksi</th>
        </tr>";

  while($row = mysqli_fetch_assoc($result)) {
    echo "<tr>
            <td>" . $row["nim"] . "</td>
            <td>" . $row["namamhsw"] . "</td>
            <td>" . $row["nilaiakhir"] . "</td>
            <td>" . $row["nilaihuruf"] . "</td>
            <td>" . $row["predikat"] . "</td>
            <td>" . $row["created_date"] . "</td>
            <td>" . $row["modified_date"] . "</td>
            <td><a href=#>Edit</a> | <a href=#>Delete</a></td>
        </tr>";
  }

 echo "</table>";

} else {
  echo "0 results";
}

echo "<div style='margin-top: 20px;'>";
echo "<a href='formnilai.php'><button>Tambah Data</button></a>";
echo "</div>";
echo "</div>";

mysqli_close($conn);

?>