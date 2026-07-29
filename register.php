<?php

include 'config.php';

$fullname = $_POST['fullname'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

$sql = "INSERT INTO users(fullname,email,password)
VALUES('$fullname','$email','$password')";

if($conn->query($sql)){
    echo "Registration Successful";
} else {
    echo "Error : " . $conn->error;
}

?>