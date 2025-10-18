<?php
// 啟動 Session，必須放在檔案的最頂部
session_start();

// --- 角色主題顏色配置 ---
// 這與 login.php 中的定義保持一致，用於在不同頁面保持一致的顏色
$theme_colors = [
    'customer' => ['main' => 'blue', 'hex' => '#2563EB', 'ring' => 'blue-500', 'bg' => 'blue-600', 'hover' => 'blue-700'],
    'agent' => ['main' => 'green', 'hex' => '#10B981', 'ring' => 'green-500', 'bg' => 'green-600', 'hover' => 'green-700'],
    'lister' => ['main' => 'purple', 'hex' => '#8B5CF6', 'ring' => 'purple-500', 'bg' => 'purple-600', 'hover' => 'purple-700'],
];

// 預設主題（如果沒有登入或 Session 中沒有主題）
$default_theme = $theme_colors['customer']; 
$current_theme = $_SESSION['theme'] ?? $default_theme;

// 根據當前主題設定 CSS 類別，用於動態切換顏色
$ring_class = "focus:ring-{$current_theme['ring']}";
$bg_class = "bg-{$current_theme['bg']}";
$hover_class = "hover:bg-{$current_theme['hover']}";
$text_class = "text-{$current_theme['ring']}";
$hover_text_class = "hover:text-{$current_theme['hover']}";

// --- 檢查是否為 AJAX 請求 ---
$is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax_request) {
    // 如果是 AJAX 請求，立即停止執行 header.php
    return;
}

// --- 角色標籤顯示映射 (可選) ---
$role_display_map = [
    'customer' => '客戶',
    'agent' => '經紀人',
    'lister' => '放盤者',
];

// --- 正常 HTML 輸出 (只有在非 AJAX 模式下才會到達這裡) ---
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hose28 - 專業地產平台</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        :root {
            --color-primary-main: <?= $current_theme['hex'] ?>;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-gray-50">


    <header class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                
                <a href="index.php" class="text-2xl font-bold text-gray-800 hover:<?= $text_class ?> transition-colors">
                    Hose28
                </a>

                <div class="flex items-center space-x-4">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="hidden sm:flex items-center space-x-2">
                            <span class="text-sm text-gray-700">
                                歡迎, 
                                <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['email']); ?></span>
                                <?php if (isset($_SESSION['role'])): ?>
                                    <?php 
                                        $role = $_SESSION['role'];
                                        // 獲取角色對應的主題顏色 (用於標籤)
                                        $label_theme = $theme_colors[$role] ?? $default_theme;
                                        $label_bg_class = "bg-{$label_theme['main']}-100";
                                        $label_text_class = "text-{$label_theme['main']}-800";
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $label_bg_class ?> <?= $label_text_class ?>">
                                        <?php echo htmlspecialchars($role_display_map[$role] ?? $role); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <a href="dashboard.php" class="<?= $bg_class ?> text-white font-semibold py-2 px-4 rounded-md <?= $hover_class ?> transition-colors">
                            我的後台
                        </a>
                        <a href="actions/logout_action.php" class="bg-gray-200 text-gray-800 font-semibold py-2 px-4 rounded-md hover:bg-gray-300 transition-colors">
                            登出
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="text-gray-600 font-semibold hover:text-blue-600 transition-colors">登入</a>
                        <a href="register.php" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 transition-colors">
                            免費註冊
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </header>

    <main>