<?php
include '../includes/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $email, $password_hash, $role);

    if ($stmt->execute()) {
        echo "註冊成功！正在跳轉到登入頁面...";
        header("refresh:3;url=../login.php");
    } else {
        echo "註冊失敗: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>