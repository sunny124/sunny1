<?php include 'includes/header.php'; ?>

<div class="container">
    <h2>用戶註冊</h2>

    <form id="register-form">
        <label for="username">用戶名:</label>
        <input type="text" id="username" name="username" required>

        <label for="email">電郵:</label>
        <input type="email" id="email" name="email" required>

        <label for="password">密碼:</label>
        <input type="password" id="password" name="password" required>

        <label for="phone">手機號 (格式: +85291234567):</label>
        <input type="tel" id="phone" name="phone" placeholder="+<國家代碼><號碼>" required>
        
        <button type="submit">獲取 SMS 驗證碼</button>
    </form>

    <form id="verify-form" style="display:none;">
        <p>我們已發送一個 6 位數驗證碼到您的手機。</p>
        <label for="code">驗證碼:</label>
        <input type="text" id="code" name="code" required>
        <input type="hidden" id="phone_to_verify" name="phone">
        <button type="submit">驗證並完成註冊</button>
    </form>
    
    <p id="message"></p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const registerForm = document.getElementById('register-form');
    const verifyForm = document.getElementById('verify-form');
    const messageEl = document.getElementById('message');

    registerForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        messageEl.textContent = '正在發送驗證碼...';

        fetch('actions/send_sms_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                messageEl.textContent = '';
                registerForm.style.display = 'none';
                verifyForm.style.display = 'block';
                document.getElementById('phone_to_verify').value = formData.get('phone');
            } else {
                messageEl.textContent = '發送失敗: ' + data.message;
            }
        });
    });

    verifyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        messageEl.textContent = '正在驗證...';

        fetch('actions/verify_code_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                messageEl.textContent = '註冊成功！正在跳轉...';
                window.location.href = 'login.php';
            } else {
                messageEl.textContent = '驗證失敗: ' + data.message;
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>