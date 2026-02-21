<?php
session_start();
session_destroy(); // iznīcina visu sesiju
header('Location: landing.php');
exit;
?>