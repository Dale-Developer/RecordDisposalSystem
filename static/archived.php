<?php
require_once '../session.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../styles/archived.css">
  <title>Archived</title>
</head>


<body>
  <nav class="sidebar">
    <?php include 'sidebar.php'; ?>
  </nav>
  <main>
    <div class="container">

      <div class="header">
        <h2>ARCHIVED</h2>
        <button class="filter-btn">Filter ⬇</button>
      </div>

      <div class="folder-grid">

        <!-- Selected folder example -->
        <div class="folder selected">
          <i class='bx bxs-folder folder-icon'></i>
          <p>Employee files</p>
        </div>

        <div class="folder">
          <i class='bx bxs-folder folder-icon'></i>
          <p>CCS files</p>
        </div>

        <div class="folder">
          <i class='bx bxs-folder folder-icon'></i>
          <p>CHMT files</p>
        </div>

        <div class="folder">
          <i class='bx bxs-folder folder-icon'></i>
          <p>COF files</p>
        </div>

        <div class="folder">
          <i class='bx bxs-folder folder-icon'></i>
          <p>CAS files</p>
        </div>

        <!-- Month folders -->
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2020</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2021</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2022</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2023</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2024</p>
        </div>

        <!-- Duplicate row -->
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2020</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2021</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2022</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2023</p>
        </div>
        <div class="folder"><i class='bx bxs-folder folder-icon'></i>
          <p>January 2024</p>
        </div>

      </div>
      </div>
  </main>
</body>

</html>