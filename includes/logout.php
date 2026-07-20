<?php
session_start();

if (isset($_SESSION['user_id'])) 
    require_once 'config.php';
    
   

session_destroy();
header('Location: ../index.php');
exit();
?>
