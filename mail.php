<?php
require './phpmailer/src/Exception.php';
require './phpmailer/src/PHPMailer.php';
require './phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

if(isset($_POST['send'])){
$mail = new PHPMailer(true); // Enable exceptions

$email_address = "" // CHANGE THIS TO REGISTRAR'S OFFICE ACCOUNT!!!
$password = "" // CHANGE THIS TO REGISTRAR'S OFFICE ACCOUNT!!!

// SMTP Configuration
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com'; // Your SMTP server
$mail->SMTPAuth = true;
$mail->Username = $email_address; 
$mail->Password = $password; 
$mail->SMTPSecure = 'ssl';
$mail->Port = 465;

// Sender and recipient settings
$mail->setFrom($email_address, 'Registrar');
$mail->addAddress($_POST['email']);

// Sending plain text email
$mail->isHTML(false); // Set email format to plain text
$mail->Subject = "Registrar Request Status Update";
$mail->Body    =
"
Dear Student,

Your request has been successfully submitted and is now being processed.

Status: Request Submitted

Your requested document will be available for claiming at a specific date so stay updated.

Please bring a valid ID when claiming your document. If you have any questions, feel free to contact the University Registrar's Office.

Thank you.

Facebook Page: CvSU Naic Registrar's Office
Contact No.: 0976 592 7310
Email: registrar@cvsu-naic.edu.ph
"
;

// Send the email
if(!$mail->send()){
    echo 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
} else {
    echo 'Message has been sent';
}
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emailing Test</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
</head>
<body>
    <form method="POST">
    
    <label for="name">Name:</label>
    <input type="text" id="name" name="name" required>

    <label for="email">Email:</label>
    <input type="email" id="email" name="email" required>

    <button type="submit" name="send">Send</button>
</form>
</body>
</html>
