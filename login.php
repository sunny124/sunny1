<?php
// 引入 header
include __DIR__ . '/includes/header.php';
// 可以在這裡加入 session_start(); 如果 header.php 中沒有
// session_start(); 

// 處理來自 login_action.php 的錯誤或訊息
$error_message = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']); // 顯示後清除錯誤訊息
$success_message = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']); 

// 設置 Tailwind CSS 顏色主題
$user_type_color = [
    'customer' => 'bg-blue-600',
    'agent' => 'bg-green-600',
    'owner' => 'bg-red-600',
];

// 預設或從 GET/POST 獲取用戶選擇的類型
$selected_type = $_GET['type'] ?? 'customer'; 
$current_color = $user_type_color[$selected_type] ?? $user_type_color['customer'];

?>

<div class="container mx-auto px-4 py-20">
    <div class="max-w-lg mx-auto bg-white p-8 md:p-10 rounded-xl shadow-2xl border-t-8 border-<?php echo str_replace('bg-', '', $current_color); ?>-600">
        
        <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">會員登入</h2>
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">登入失敗:</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">成功:</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <div class="flex justify-around mb-8 border-b pb-4">
            <a href="?type=customer" class="tab-select-btn <?php echo $selected_type === 'customer' ? 'active-customer' : ''; ?>">客戶</a>
            <a href="?type=agent" class="tab-select-btn <?php echo $selected_type === 'agent' ? 'active-agent' : ''; ?>">經紀</a>
            <a href="?type=owner" class="tab-select-btn <?php echo $selected_type === 'owner' ? 'active-owner' : ''; ?>">業主/放盤</a>
        </div>

        <form action="login_action.php" method="POST" class="space-y-6">
            <input type="hidden" name="user_type" value="<?php echo htmlspecialchars($selected_type); ?>">

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">電子郵件 (Email)</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    required 
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="請輸入您的電子郵件地址"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">密碼</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="請輸入密碼"
                >
            </div>

            <div>
                <button 
                    type="submit" 
                    class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white <?php echo htmlspecialchars($current_color); ?> hover:<?php echo htmlspecialchars(str_replace('600', '700', $current_color)); ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150"
                >
                    登入 (<?php 
                        echo $selected_type === 'customer' ? '客戶' : 
                             ($selected_type === 'agent' ? '經紀' : '業主'); 
                    ?>)
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            還沒有帳號嗎？ 
            <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500">
                立即註冊
            </a>
        </p>

    </div>
</div>

<style>
    .tab-select-btn {
        padding: 8px 15px;
        font-weight: 600;
        border-radius: 9999px;
        color: #6b7280; /* Gray-500 */
        border: 2px solid transparent;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    .tab-select-btn:hover {
        background-color: #f3f4f6; /* Gray-100 */
    }

    .active-customer {
        color: #2563eb; /* Blue-600 */
        border-color: #2563eb; 
    }
    .active-agent {
        color: #10b981; /* Green-600 */
        border-color: #10b981;
    }
    .active-owner {
        color: #ef4444; /* Red-600 */
        border-color: #ef4444;
    }
</style>

<?php
// 引入 footer
include __DIR__ . '/includes/footer.php';
?>