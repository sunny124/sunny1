<?php
// 引入 header 和數據庫連接
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/db_connect.php';

// --- AJAX 模式判斷 ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- 1. 初始化和處理頂層篩選 ---
$listing_type = isset($_GET['listing_type']) && $_GET['listing_type'] == 'rent' ? 'rent' : 'sale';
$current_area_type = htmlspecialchars($_GET['area_type'] ?? 'net'); // 'net' for UFA, 'gross' for floor area
$current_sort_by = htmlspecialchars($_GET['sort_by'] ?? 'latest'); // 新增排序參數，默認為最新

// --- 2. 動態建立 SQL 查詢 ---
$conditions = [];
$params = [];
$param_types = '';

// 確定要用於結果顯示和面積篩選的數據庫欄位
// 顯示時，預設顯示 UFA，如果沒有 UFA 則顯示 floor area
$size_display_column = "COALESCE(p.UFA, p.`floor area`)";
// 篩選時，根據選擇的類型決定使用的欄位
$size_filter_column = ($current_area_type == 'gross') ? "p.`floor area`" : "p.UFA";


// 基礎查詢 (只包含 SELECT FROM JOIN，不含 WHERE)
$base_sql = "
    SELECT 
        p.id, p.title, p.price, {$size_display_column} AS size_sqft, p.district, p.num_bedrooms, p.num_bathrooms, p.listing_type,
        pp.image_url AS cover_image_url
    FROM properties p
    LEFT JOIN property_photos pp ON p.id = pp.property_id AND pp.is_primary = 1
";

// 設置強制條件：狀態必須為 'active'
$conditions[] = "p.status = 'active'";

// 設置強制條件：列表類型 (sale/rent)
$conditions[] = "p.listing_type = ?";
$params[] = $listing_type;
$param_types .= 's';


// --- 3. 根據用戶輸入，逐步添加篩選條件 (所有條件均使用 AND 邏輯) ---

// 關鍵字搜尋 (Keyword Search)
$search_keyword = trim(htmlspecialchars($_GET['search'] ?? ''));
if (!empty($search_keyword)) {
    // 關鍵字搜尋邏輯: 匹配 title, address, community
    $conditions[] = "
        (p.title LIKE ? OR p.address LIKE ? OR p.community LIKE ?)
    ";
    $search_param = '%' . $search_keyword . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}


// 區域 (District) 和 社區 (Community)
$current_district = htmlspecialchars($_GET['district'] ?? '不限');
$current_community_string = htmlspecialchars($_GET['community'] ?? '不限'); 
$current_communities = ($current_community_string != '不限' && $current_community_string != '') ? explode(',', $current_community_string) : ['不限'];
$is_community_multi_select = count($current_communities) > 1 || (count($current_communities) == 1 && $current_communities[0] != '不限' && $current_community_string != '');


if ($current_district != '不限') {
    // 篩選主區域 (p.district) - 嚴格 AND 邏輯
    $conditions[] = "p.district = ?"; 
    $params[] = $current_district;
    $param_types .= 's';

    // 如果選了社區 (p.community)，再 AND 篩選社區 (使用 IN 查詢)
    if ($is_community_multi_select) {
        $in_placeholders = implode(',', array_fill(0, count($current_communities), '?'));
        $conditions[] = "p.community IN ($in_placeholders)";
        foreach ($current_communities as $comm) {
            $params[] = $comm;
            $param_types .= 's';
        }
    }
}


// 類別 (Property Type) 和 子類別 (Sup Property Type)
$current_property_type_db = htmlspecialchars($_GET['property_type'] ?? '不限'); 
$current_sup_property_type_string = htmlspecialchars($_GET['sup_property_type'] ?? '不限'); 
$current_sup_property_types = ($current_sup_property_type_string != '不限' && $current_sup_property_type_string != '') ? explode(',', $current_sup_property_type_string) : ['不限'];
$is_sub_type_multi_select = count($current_sup_property_types) > 1 || (count($current_sup_property_types) == 1 && $current_sup_property_types[0] != '不限' && $current_sup_property_type_string != '');


if ($current_property_type_db != '不限') {
    // 映射 UI 選擇值到數據庫 ENUM 值
    $type_map = [
        'residential' => 'residential', 'parking_space' => 'parking_space', 
        'industrial' => 'industrial', 'shop' => 'shop',
    ];
    
    $db_property_type = $type_map[$current_property_type_db] ?? $current_property_type_db;
    
    if (in_array($db_property_type, ['residential', 'parking_space', 'industrial', 'shop'])) {
        $conditions[] = "p.property_type = ?";
        $params[] = $db_property_type;
        $param_types .= 's';
    }
    
    // 如果選了子類別 (p.sup_property_type)，再 AND 篩選子類別 (使用 IN 查詢)
    if ($is_sub_type_multi_select) {
        $in_placeholders = implode(',', array_fill(0, count($current_sup_property_types), '?'));
        $conditions[] = "p.sup_property_type IN ($in_placeholders)";
        foreach ($current_sup_property_types as $type) {
            $params[] = $type;
            $param_types .= 's';
        }
    }
}


// 價格 (Price)
if (!empty($_GET['price_min']) && is_numeric($_GET['price_min'])) {
    $conditions[] = "p.price >= ?";
    $params[] = (float)$_GET['price_min'];
    $param_types .= 'd';
}
if (!empty($_GET['price_max']) && is_numeric($_GET['price_max'])) {
    $conditions[] = "p.price <= ?";
    $params[] = (float)$_GET['price_max'];
    $param_types .= 'd';
}
if (empty($_GET['price_min']) && empty($_GET['price_max']) && !empty($_GET['price_range']) && $_GET['price_range'] != '不限') {
    $price_ranges = [
        'sale' => [
            'p1' => [0, 2000000], 'p2' => [2000000, 4000000], 'p3' => [4000000, 8000000],
            'p4' => [8000000, 20000000], 'p5' => [20000000, 999999999]
        ],
        'rent' => [
            'p1' => [0, 5000], 'p2' => [5000, 10000], 'p3' => [10000, 15000],
            'p4' => [15000, 20000], 'p5' => [20000, 40000], 'p6' => [40000, 999999999]
        ]
    ];
    
    $selected_price_ranges = explode(',', htmlspecialchars($_GET['price_range'] ?? ''));
    $price_or_conditions = [];
    foreach ($selected_price_ranges as $range_key) {
        if ($range_key !== '不限' && isset($price_ranges[$listing_type][$range_key])) {
            $range = $price_ranges[$listing_type][$range_key];
            $price_or_conditions[] = "p.price BETWEEN ? AND ?";
            $params[] = $range[0];
            $params[] = $range[1];
            $param_types .= 'dd';
        }
    }
    
    if (count($price_or_conditions) > 0) {
        $conditions[] = "(" . implode(" OR ", $price_or_conditions) . ")";
    }
}

// 面積 (Size) - 使用新的欄位名稱 UFA 和 floor area
if (!empty($_GET['size_min']) && is_numeric($_GET['size_min'])) {
    $conditions[] = "{$size_filter_column} >= ?";
    $params[] = (int)$_GET['size_min'];
    $param_types .= 'i';
}
if (!empty($_GET['size_max']) && is_numeric($_GET['size_max'])) {
    $conditions[] = "{$size_filter_column} <= ?";
    $params[] = (int)$_GET['size_max'];
    $param_types .= 'i';
}

if (empty($_GET['size_min']) && empty($_GET['size_max']) && !empty($_GET['size_range']) && $_GET['size_range'] != '不限') {
    $size_ranges = [
        's1' => [0, 300], 's2' => [300, 500], 's3' => [500, 1000],
        's4' => [1000, 2000], 's5' => [2000, 99999]
    ];
    
    $selected_size_ranges = explode(',', htmlspecialchars($_GET['size_range'] ?? ''));
    $size_or_conditions = [];
    foreach ($selected_size_ranges as $range_key) {
        if ($range_key !== '不限' && isset($size_ranges[$range_key])) {
            $range = $size_ranges[$range_key];
            $size_or_conditions[] = "{$size_filter_column} BETWEEN ? AND ?";
            $params[] = $range[0];
            $params[] = $range[1];
            $param_types .= 'ii';
        }
    }
    
    if (count($size_or_conditions) > 0) {
        $conditions[] = "(" . implode(" OR ", $size_or_conditions) . ")";
    }
}

// 房間數量 (Bedrooms)
$current_bedrooms = htmlspecialchars($_GET['bedrooms'] ?? '不限');
if ($current_bedrooms != '不限') {
    $bedrooms = (int)$current_bedrooms;
    if ($bedrooms >= 5) {
        $conditions[] = "p.num_bedrooms >= ?";
        $params[] = 5;
        $param_types .= 'i';
    } else {
        $conditions[] = "p.num_bedrooms = ?";
        $params[] = $bedrooms;
        $param_types .= 'i';
    }
}


// --- 4. 組合最終的 SQL 查詢並處理排序 ---
$sql = $base_sql;
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// 根據 $current_sort_by 設定 ORDER BY
$order_by = "p.created_at DESC"; // 默認排序

switch ($current_sort_by) {
    case 'price_asc':
        $order_by = "p.price ASC";
        break;
    case 'price_desc':
        $order_by = "p.price DESC";
        break;
    case 'size_gross_asc':
        $order_by = "p.`floor area` ASC";
        break;
    case 'size_gross_desc':
        $order_by = "p.`floor area` DESC";
        break;
    case 'size_net_asc':
        $order_by = "p.UFA ASC";
        break;
    case 'size_net_desc':
        $order_by = "p.UFA DESC";
        break;
    case 'latest':
    default:
        $order_by = "p.created_at DESC";
        break;
}

$sql .= " ORDER BY {$order_by}";

// --- 5. 執行查詢 ---
$stmt = $conn->prepare($sql);
$properties = [];
$final_sql_query = $sql; 

if ($stmt) {
    if (!empty($params)) {
        // 使用一個安全的技巧來處理可變參數數量
        $bind_names = [$param_types];
        for ($i=0; $i<count($params); $i++) {
            $bind_name = 'param'.$i;
            $$bind_name = &$params[$i];
            $bind_names[] = &$$bind_name;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
    $stmt->close();
}
$conn->close();

// --- 輔助函數：渲染樓盤列表 (用於 AJAX 和初次載入) ---
function render_property_list_html($properties, $listing_type, $sql_query) {
    ob_start();
    ?>
    <h3 class="text-xl font-bold mb-4">
        共找到 <?php echo count($properties); ?> 個樓盤
    </h3>
    
    <?php if (!empty($properties)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="property-grid">
            <?php foreach ($properties as $property): ?>
                <a href="property_details.php?id=<?php echo $property['id']; ?>" class="block bg-white rounded-lg shadow-md overflow-hidden hover:shadow-xl transition-shadow duration-300 h-96">
                    <div class="h-48 w-full bg-cover bg-center" style="background-image: url('<?php echo $property['cover_image_url'] ? htmlspecialchars($property['cover_image_url']) : 'https://placehold.co/600x400/e2e8f0/cbd5e0?text=No+Image'; ?>');"></div>
                    <div class="p-4">
                         <div class="flex justify-between items-center mb-1"><span class="text-sm text-gray-500"><?php echo htmlspecialchars($property['district']); ?></span><?php if ($property['listing_type'] == 'sale'): ?><span class="text-xs font-semibold bg-blue-100 text-blue-800 px-2 py-1 rounded-full">出售</span><?php else: ?><span class="text-xs font-semibold bg-green-100 text-green-800 px-2 py-1 rounded-full">出租</span><?php endif; ?></div>
                        <h3 class="text-lg font-bold text-gray-900 truncate mb-2"><?php echo htmlspecialchars($property['title']); ?></h3>
                        <div class="flex items-center text-gray-700 space-x-4 text-sm"><span><i class="fas fa-bed"></i> <?php echo $property['num_bedrooms']; ?> 房</span><span><i class="fas fa-bath"></i> <?php echo $property['num_bathrooms']; ?> 廁</span><span><i class="fas fa-ruler-combined"></i> <?php echo number_format($property['size_sqft']); ?> 呎</span></div>
                        <div class="text-xl font-extrabold text-blue-600 mt-2"><?php if ($property['listing_type'] == 'sale'): ?>$<?php echo number_format($property['price'] / 10000, 0); ?> 萬<?php else: ?>$<?php echo number_format($property['price'], 0); ?> /月<?php endif; ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-center text-gray-500 mt-8 text-lg font-semibold">找不到符合條件的樓盤，請嘗試放寬篩選條件。</p>
    <?php endif; 
    return ob_get_clean();
}

// 如果是 AJAX 請求，則只輸出樓盤列表 HTML 並退出
if ($is_ajax) {
    echo render_property_list_html($properties, $listing_type, $final_sql_query);
    exit;
}

// --- 輔助函數 (保持選中狀態) (邏輯不變) ---
function getActiveFilterValue($param, $default = '不限') {
    if (isset($_GET[$param]) && $_GET[$param] != '') {
        return htmlspecialchars($_GET[$param]);
    }
    return $default;
}

function isActiveLink($param, $value, $is_sub = false) {
    $active_value = getActiveFilterValue($param);
    $active_values_array = explode(',', $active_value); // 處理多選

    if ($param === 'bedrooms' && $value === '5' && is_numeric($active_value) && (int)$active_value >= 5) return ' active';
    
    if ($param === 'district') {
        $current_community = getActiveFilterValue('community');
        if ($current_community == '不限' && $active_value === $value) return ' active';
    } elseif ($param === 'property_type') {
        $current_sup_property_type = getActiveFilterValue('sup_property_type');
        if ($current_sup_property_type == '不限' && $active_value === $value) return ' active';
    } elseif (in_array($value, $active_values_array)) {
        return ' active';
    } elseif ($active_value === $value) {
        return ' active';
    }

    return '';
}

function isActiveSubItem($param, $value, $parent_param, $parent_value) {
    $active_sub_value = getActiveFilterValue($param); 
    $active_parent_value = getActiveFilterValue($parent_param); 
    
    if ($active_parent_value != $parent_value) {
        return ''; 
    }
    
    $active_sub_values_array = explode(',', $active_sub_value);
    
    if ($value == '不限') {
        if ($active_sub_value == '不限' || $active_sub_value == '') {
            return ' active';
        }
    } 
    elseif (in_array($value, $active_sub_values_array)) {
        return ' active';
    }
    
    return '';
}


// 獲取當前選中的值 (用於初始 HTML 渲染)
$current_district = getActiveFilterValue('district');
$current_community_string = getActiveFilterValue('community'); 
$current_property_type_db = getActiveFilterValue('property_type');
$current_sup_property_type_string = getActiveFilterValue('sup_property_type'); 
$is_custom_price = (!empty($_GET['price_min']) || !empty($_GET['price_max'])) && empty($_GET['price_range']);
$is_custom_size = (!empty($_GET['size_min']) || !empty($_GET['size_max'])) && empty($_GET['size_range']);
$current_price_range_string = getActiveFilterValue('price_range');
$current_size_range_string = getActiveFilterValue('size_range');
$current_search_keyword = getActiveFilterValue('search', ''); 
$current_sort_by_value = getActiveFilterValue('sort_by', 'latest'); // 獲取當前排序值
?>

<div class="container mx-auto px-4 py-8">
    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <h2 class="text-2xl font-bold mb-4">
            樓盤篩選 (<?php echo $listing_type == 'sale' ? '放售中' : '放租中'; ?>)
        </h2>
        
        <form action="properties.php" method="GET" id="filter-form">
            <input type="hidden" name="listing_type" value="<?php echo $listing_type; ?>">
            
            <input type="hidden" id="filter-district" name="district" value="<?php echo $current_district; ?>">
            <input type="hidden" id="filter-community" name="community" value="<?php echo $current_community_string; ?>">
            <input type="hidden" id="filter-property_type" name="property_type" value="<?php echo $current_property_type_db; ?>">
            <input type="hidden" id="filter-sup-property-type" name="sup_property_type" value="<?php echo $current_sup_property_type_string; ?>">
            <input type="hidden" id="filter-bedrooms" name="bedrooms" value="<?php echo getActiveFilterValue('bedrooms'); ?>">
            <input type="hidden" id="filter-price-range" name="price_range" value="<?php echo $current_price_range_string; ?>">
            <input type="hidden" id="filter-price-min" name="price_min" value="<?php echo htmlspecialchars($_GET['price_min'] ?? ''); ?>">
            <input type="hidden" id="filter-price-max" name="price_max" value="<?php echo htmlspecialchars($_GET['price_max'] ?? ''); ?>">
            <input type="hidden" id="filter-size-range" name="size_range" value="<?php echo $current_size_range_string; ?>">
            <input type="hidden" id="filter-size-min" name="size_min" value="<?php echo htmlspecialchars($_GET['size_min'] ?? ''); ?>">
            <input type="hidden" id="filter-size-max" name="size_max" value="<?php echo htmlspecialchars($_GET['size_max'] ?? ''); ?>">
            <input type="hidden" id="currentAreaType" name="area_type" value="<?php echo getActiveFilterValue('area_type', 'net'); ?>">
            <input type="hidden" id="filter-sort-by" name="sort_by" value="<?php echo $current_sort_by_value; ?>">


            <div class="filter-grid border p-4 rounded-lg space-y-4">

                <div class="search-bar border-b pb-4">
                    <div class="inline-block w-24 font-bold align-top two-wide-column">搜尋:</div>
                    <div class="inline-block w-3/4">
                        <input 
                            type="text" 
                            id="search-input"
                            name="search"
                            value="<?php echo $current_search_keyword; ?>"
                            placeholder="輸入地區、屋苑或街道..." 
                            onchange="loadProperties()"
                            class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500"
                        >
                    </div>
                </div>

                <div class="location border-b pb-4">
                    <div class="pb-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column">區域:</div>
                        <a href="#" class="item <?php echo $current_district == '不限' ? 'active' : ''; ?>" onclick="selectDistrict(event, '不限')">不限</a>
                        <a href="#" class="item <?php echo isActiveLink('district', '香港島'); ?>" onclick="selectDistrict(event, '香港島')">香港島</a>
                        <a href="#" class="item <?php echo isActiveLink('district', '九龍'); ?>" onclick="selectDistrict(event, '九龍')">九龍</a>
                        <a href="#" class="item <?php echo isActiveLink('district', '新界'); ?>" onclick="selectDistrict(event, '新界')">新界</a>
                        <a href="#" class="item <?php echo isActiveLink('district', '離島'); ?>" onclick="selectDistrict(event, '離島')">離島</a>
                    </div>
                    
                    <div class="香港島_item <?php echo $current_district == '香港島' ? '' : 'hidden'; ?> pt-2 community-list" data-parent="香港島">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '不限', 'district', '香港島'); ?>" data-sub-value="不限" onclick="selectCommunity(event, '不限', '香港島')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '南區', 'district', '香港島'); ?>" data-sub-value="南區" onclick="selectCommunity(event, '南區', '香港島')">南區</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '香港仔', 'district', '香港島'); ?>" data-sub-value="香港仔" onclick="selectCommunity(event, '香港仔', '香港島')">香港仔</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '鴨脷洲', 'district', '香港島'); ?>" data-sub-value="鴨脷洲" onclick="selectCommunity(event, '鴨脷洲', '香港島')">鴨脷洲</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '黃竹坑', 'district', '香港島'); ?>" data-sub-value="黃竹坑" onclick="selectCommunity(event, '黃竹坑', '香港島')">黃竹坑</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '柴灣', 'district', '香港島'); ?>" data-sub-value="柴灣" onclick="selectCommunity(event, '柴灣', '香港島')">柴灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '小西灣', 'district', '香港島'); ?>" data-sub-value="小西灣" onclick="selectCommunity(event, '小西灣', '香港島')">小西灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '石澳', 'district', '香港島'); ?>" data-sub-value="石澳" onclick="selectCommunity(event, '石澳', '香港島')">石澳</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '筲箕灣', 'district', '香港島'); ?>" data-sub-value="筲箕灣" onclick="selectCommunity(event, '筲箕灣', '香港島')">筲箕灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '杏花邨', 'district', '香港島'); ?>" data-sub-value="杏花邨" onclick="selectCommunity(event, '杏花邨', '香港島')">杏花邨</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '西灣河', 'district', '香港島'); ?>" data-sub-value="西灣河" onclick="selectCommunity(event, '西灣河', '香港島')">西灣河</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '鰂魚涌', 'district', '香港島'); ?>" data-sub-value="鰂魚涌" onclick="selectCommunity(event, '鰂魚涌', '香港島')">鰂魚涌</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '北角', 'district', '香港島'); ?>" data-sub-value="北角" onclick="selectCommunity(event, '北角', '香港島')">北角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '炮台山', 'district', '香港島'); ?>" data-sub-value="炮台山" onclick="selectCommunity(event, '炮台山', '香港島')">炮台山</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '天后', 'district', '香港島'); ?>" data-sub-value="天后" onclick="selectCommunity(event, '天后', '香港島')">天后</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大坑', 'district', '香港島'); ?>" data-sub-value="大坑" onclick="selectCommunity(event, '大坑', '香港島')">大坑</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '銅鑼灣', 'district', '香港島'); ?>" data-sub-value="銅鑼灣" onclick="selectCommunity(event, '銅鑼灣', '香港島')">銅鑼灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '跑馬地', 'district', '香港島'); ?>" data-sub-value="跑馬地" onclick="selectCommunity(event, '跑馬地', '香港島')">跑馬地</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '灣仔', 'district', '香港島'); ?>" data-sub-value="灣仔" onclick="selectCommunity(event, '灣仔', '香港島')">灣仔</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '金鐘', 'district', '香港島'); ?>" data-sub-value="金鐘" onclick="selectCommunity(event, '金鐘', '香港島')">金鐘</a>
                    </div>
                    
                    <div class="九龍_item <?php echo $current_district == '九龍' ? '' : 'hidden'; ?> pt-2 community-list" data-parent="九龍">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '不限', 'district', '九龍'); ?>" data-sub-value="不限" onclick="selectCommunity(event, '不限', '九龍')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '紅磡', 'district', '九龍'); ?>" data-sub-value="紅磡" onclick="selectCommunity(event, '紅磡', '九龍')">紅磡</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '尖沙咀', 'district', '九龍'); ?>" data-sub-value="尖沙咀" onclick="selectCommunity(event, '尖沙咀', '九龍')">尖沙咀</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '佐敦', 'district', '九龍'); ?>" data-sub-value="佐敦" onclick="selectCommunity(event, '佐敦', '九龍')">佐敦</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '油麻地', 'district', '九龍'); ?>" data-sub-value="油麻地" onclick="selectCommunity(event, '油麻地', '九龍')">油麻地</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '旺角', 'district', '九龍'); ?>" data-sub-value="旺角" onclick="selectCommunity(event, '旺角', '九龍')">旺角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '太子', 'district', '九龍'); ?>" data-sub-value="太子" onclick="selectCommunity(event, '太子', '九龍')">太子</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大角咀', 'district', '九龍'); ?>" data-sub-value="大角咀" onclick="selectCommunity(event, '大角咀', '九龍')">大角咀</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '奧運', 'district', '九龍'); ?>" data-sub-value="奧運" onclick="selectCommunity(event, '奧運', '九龍')">奧運</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '九龍站', 'district', '九龍'); ?>" data-sub-value="九龍站" onclick="selectCommunity(event, '九龍站', '九龍')">九龍站</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '長沙灣', 'district', '九龍'); ?>" data-sub-value="長沙灣" onclick="selectCommunity(event, '長沙灣', '九龍')">長沙灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '荔枝角', 'district', '九龍'); ?>" data-sub-value="荔枝角" onclick="selectCommunity(event, '荔枝角', '九龍')">荔枝角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '深水埗', 'district', '九龍'); ?>" data-sub-value="深水埗" onclick="selectCommunity(event, '深水埗', '九龍')">深水埗</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '石硤尾', 'district', '九龍'); ?>" data-sub-value="石硤尾" onclick="selectCommunity(event, '石硤尾', '九龍')">石硤尾</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '南昌', 'district', '九龍'); ?>" data-sub-value="南昌" onclick="selectCommunity(event, '南昌', '九龍')">南昌</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '新蒲崗', 'district', '九龍'); ?>" data-sub-value="新蒲崗" onclick="selectCommunity(event, '新蒲崗', '九龍')">新蒲崗</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '黃大仙', 'district', '九龍'); ?>" data-sub-value="黃大仙" onclick="selectCommunity(event, '黃大仙', '九龍')">黃大仙</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '土瓜灣', 'district', '九龍'); ?>" data-sub-value="土瓜灣" onclick="selectCommunity(event, '土瓜灣', '九龍')">土瓜灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '九龍灣', 'district', '九龍'); ?>" data-sub-value="九龍灣" onclick="selectCommunity(event, '九龍灣', '九龍')">九龍灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '觀塘', 'district', '九龍'); ?>" data-sub-value="觀塘" onclick="selectCommunity(event, '觀塘', '九龍')">觀塘</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '牛頭角', 'district', '九龍'); ?>" data-sub-value="牛頭角" onclick="selectCommunity(event, '牛頭角', '九龍')">牛頭角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '油塘', 'district', '九龍'); ?>" data-sub-value="油塘" onclick="selectCommunity(event, '油塘', '九龍')">油塘</a>
                    </div>
                    
                    <div class="新界_item <?php echo $current_district == '新界' ? '' : 'hidden'; ?> pt-2 community-list" data-parent="新界">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '不限', 'district', '新界'); ?>" data-sub-value="不限" onclick="selectCommunity(event, '不限', '新界')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '沙頭角', 'district', '新界'); ?>" data-sub-value="沙頭角" onclick="selectCommunity(event, '沙頭角', '新界')">沙頭角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '青衣', 'district', '新界'); ?>" data-sub-value="青衣" onclick="selectCommunity(event, '青衣', '新界')">青衣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '葵涌', 'district', '新界'); ?>" data-sub-value="葵涌" onclick="selectCommunity(event, '葵涌', '新界')">葵涌</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '葵芳', 'district', '新界'); ?>" data-sub-value="葵芳" onclick="selectCommunity(event, '葵芳', '新界')">葵芳</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '荃灣', 'district', '新界'); ?>" data-sub-value="荃灣" onclick="selectCommunity(event, '荃灣', '新界')">荃灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大窩口', 'district', '新界'); ?>" data-sub-value="大窩口" onclick="selectCommunity(event, '大窩口', '新界')">大窩口</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '屯門', 'district', '新界'); ?>" data-sub-value="屯門" onclick="selectCommunity(event, '屯門', '新界')">屯門</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '天水圍', 'district', '新界'); ?>" data-sub-value="天水圍" onclick="selectCommunity(event, '天水圍', '新界')">天水圍</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '元朗', 'district', '新界'); ?>" data-sub-value="元朗" onclick="selectCommunity(event, '元朗', '新界')">元朗</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '洪水橋', 'district', '新界'); ?>" data-sub-value="洪水橋" onclick="selectCommunity(event, '洪水橋', '新界')">洪水橋</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '上水', 'district', '新界'); ?>" data-sub-value="上水" onclick="selectCommunity(event, '上水', '新界')">上水</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '粉嶺', 'district', '新界'); ?>" data-sub-value="粉嶺" onclick="selectCommunity(event, '粉嶺', '新界')">粉嶺</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大埔', 'district', '新界'); ?>" data-sub-value="大埔" onclick="selectCommunity(event, '大埔', '新界')">大埔</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '太和', 'district', '新界'); ?>" data-sub-value="太和" onclick="selectCommunity(event, '太和', '新界')">太和</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '白石角', 'district', '新界'); ?>" data-sub-value="白石角" onclick="selectCommunity(event, '白石角', '新界')">白石角</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '沙田', 'district', '新界'); ?>" data-sub-value="沙田" onclick="selectCommunity(event, '沙田', '新界')">沙田</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大圍', 'district', '新界'); ?>" data-sub-value="大圍" onclick="selectCommunity(event, '大圍', '新界')">大圍</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '火炭', 'district', '新界'); ?>" data-sub-value="火炭" onclick="selectCommunity(event, '火炭', '新界')">火炭</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '馬鞍山', 'district', '新界'); ?>" data-sub-value="馬鞍山" onclick="selectCommunity(event, '馬鞍山', '新界')">馬鞍山</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '將軍澳區', 'district', '新界'); ?>" data-sub-value="將軍澳區" onclick="selectCommunity(event, '將軍澳區', '新界')">將軍澳區</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '西貢', 'district', '新界'); ?>" data-sub-value="西貢" onclick="selectCommunity(event, '西貢', '新界')">西貢</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '清水灣', 'district', '新界'); ?>" data-sub-value="清水灣" onclick="selectCommunity(event, '清水灣', '新界')">清水灣</a>
                    </div>
                    
                    <div class="離島_item <?php echo $current_district == '離島' ? '' : 'hidden'; ?> pt-2 community-list" data-parent="離島">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '不限', 'district', '離島'); ?>" data-sub-value="不限" onclick="selectCommunity(event, '不限', '離島')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '長洲', 'district', '離島'); ?>" data-sub-value="長洲" onclick="selectCommunity(event, '長洲', '離島')">長洲</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '其他離島', 'district', '離島'); ?>" data-sub-value="其他離島" onclick="selectCommunity(event, '其他離島', '離島')">其他離島</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '南丫島', 'district', '離島'); ?>" data-sub-value="南丫島" onclick="selectCommunity(event, '南丫島', '離島')">南丫島</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '坪洲', 'district', '離島'); ?>" data-sub-value="坪洲" onclick="selectCommunity(event, '坪洲', '離島')">坪洲</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '南大嶼山', 'district', '離島'); ?>" data-sub-value="南大嶼山" onclick="selectCommunity(event, '南大嶼山', '離島')">南大嶼山</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '大澳', 'district', '離島'); ?>" data-sub-value="大澳" onclick="selectCommunity(event, '大澳', '離島')">大澳</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '東涌', 'district', '離島'); ?>" data-sub-value="東涌" onclick="selectCommunity(event, '東涌', '離島')">東涌</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '愉景灣', 'district', '離島'); ?>" data-sub-value="愉景灣" onclick="selectCommunity(event, '愉景灣', '離島')">愉景灣</a>
                        <a href="#" class="item <?php echo isActiveSubItem('community', '馬灣', 'district', '離島'); ?>" data-sub-value="馬灣" onclick="selectCommunity(event, '馬灣', '離島')">馬灣</a>
                    </div>
                </div>

                <div class="category border-b pb-4">
                    <div class="pb-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column">類別:</div>
                        <a href="#" class="item <?php echo $current_property_type_db == '不限' ? 'active' : ''; ?>" onclick="selectCategory(event, '不限')">不限</a>
                        <a href="#" class="item <?php echo isActiveLink('property_type', 'residential'); ?>" data-category-type="residential" onclick="selectCategory(event, 'residential')">住宅</a>
                        <a href="#" class="item <?php echo isActiveLink('property_type', 'parking_space'); ?>" data-category-type="parking_space" onclick="selectCategory(event, 'parking_space')">車位</a>
                        <a href="#" class="item <?php echo isActiveLink('property_type', 'industrial'); ?>" data-category-type="industrial" onclick="selectCategory(event, 'industrial')">工商</a>
                        <a href="#" class="item <?php echo isActiveLink('property_type', 'shop'); ?>" data-category-type="shop" onclick="selectCategory(event, 'shop')">店舖</a>
                    </div>
                    
                    <div class="residential_item <?php echo $current_property_type_db == 'residential' ? '' : 'hidden'; ?> pt-2 sub-category-list" data-parent="residential">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '不限', 'property_type', 'residential'); ?>" data-sub-value="不限" onclick="selectSubCategory(event, '不限', 'residential')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '私人屋苑', 'property_type', 'residential'); ?>" data-sub-value="私人屋苑" onclick="selectSubCategory(event, '私人屋苑', 'residential')">私人屋苑</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '居屋', 'property_type', 'residential'); ?>" data-sub-value="居屋" onclick="selectSubCategory(event, '居屋', 'residential')">居屋</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '村屋', 'property_type', 'residential'); ?>" data-sub-value="村屋" onclick="selectSubCategory(event, '村屋', 'residential')">村屋</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '獨立屋', 'property_type', 'residential'); ?>" data-sub-value="獨立屋" onclick="selectSubCategory(event, '獨立屋', 'residential')">獨立屋</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '公屋', 'property_type', 'residential'); ?>" data-sub-value="公屋" onclick="selectSubCategory(event, '公屋', 'residential')">公屋</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '唐樓', 'property_type', 'residential'); ?>" data-sub-value="唐樓" onclick="selectSubCategory(event, '唐樓', 'residential')">唐樓</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '洋樓', 'property_type', 'residential'); ?>" data-sub-value="洋樓" onclick="selectSubCategory(event, '洋樓', 'residential')">洋樓</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '單幢式大廈', 'property_type', 'residential'); ?>" data-sub-value="單幢式大廈" onclick="selectSubCategory(event, '單幢式大廈', 'residential')">單幢式大廈</a>
                    </div>

                    <div class="parking_space_item <?php echo $current_property_type_db == 'parking_space' ? '' : 'hidden'; ?> pt-2 sub-category-list" data-parent="parking_space">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '不限', 'property_type', 'parking_space'); ?>" data-sub-value="不限" onclick="selectSubCategory(event, '不限', 'parking_space')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '電單車位', 'property_type', 'parking_space'); ?>" data-sub-value="電單車位" onclick="selectSubCategory(event, '電單車位', 'parking_space')">電單車位</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '貨車車位', 'property_type', 'parking_space'); ?>" data-sub-value="貨車車位" onclick="selectSubCategory(event, '貨車車位', 'parking_space')">貨車車位</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '工商車位', 'property_type', 'parking_space'); ?>" data-sub-value="工商車位" onclick="selectSubCategory(event, '工商車位', 'parking_space')">工商車位</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '住宅車位', 'property_type', 'parking_space'); ?>" data-sub-value="住宅車位" onclick="selectSubCategory(event, '住宅車位', 'parking_space')">住宅車位</a>
                    </div>
                    
                    <div class="industrial_item <?php echo $current_property_type_db == 'industrial' ? '' : 'hidden'; ?> pt-2 sub-category-list" data-parent="industrial">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '不限', 'property_type', 'industrial'); ?>" data-sub-value="不限" onclick="selectSubCategory(event, '不限', 'industrial')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '工商大廈', 'property_type', 'industrial'); ?>" data-sub-value="工商大廈" onclick="selectSubCategory(event, '工商大廈', 'industrial')">工商大廈</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '寫字樓', 'property_type', 'industrial'); ?>" data-sub-value="寫字樓" onclick="selectSubCategory(event, '寫字樓', 'industrial')">寫字樓</a>
                    </div>

                    <div class="shop_item <?php echo $current_property_type_db == 'shop' ? '' : 'hidden'; ?> pt-2 sub-category-list" data-parent="shop">
                        <div class="inline-block w-24 font-bold align-top text-xs opacity-0 two-wide-column">...</div>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '不限', 'property_type', 'shop'); ?>" data-sub-value="不限" onclick="selectSubCategory(event, '不限', 'shop')">不限</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '商場舖位', 'property_type', 'shop'); ?>" data-sub-value="商場舖位" onclick="selectSubCategory(event, '商場舖位', 'shop')">商場舖位</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '樓上舖', 'property_type', 'shop'); ?>" data-sub-value="樓上舖" onclick="selectSubCategory(event, '樓上舖', 'shop')">樓上舖</a>
                        <a href="#" class="item <?php echo isActiveSubItem('sup_property_type', '地舖', 'property_type', 'shop'); ?>" data-sub-value="地舖" onclick="selectSubCategory(event, '地舖', 'shop')">地舖</a>
                    </div>
                </div>

                <div class="bedrooms border-b pb-4">
                    <div class="inline-block w-24 font-bold align-top two-wide-column">房間:</div>
                    <a href="#" class="item <?php echo getActiveFilterValue('bedrooms') == '不限' ? 'active' : ''; ?>" data-filter-type="bedrooms" data-filter-value="不限" onclick="selectItem(event, 'bedrooms', '不限')">不限</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '0'); ?>" data-filter-type="bedrooms" data-filter-value="0" onclick="selectItem(event, 'bedrooms', '0')">開放式</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '1'); ?>" data-filter-type="bedrooms" data-filter-value="1" onclick="selectItem(event, 'bedrooms', '1')">1房</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '2'); ?>" data-filter-type="bedrooms" data-filter-value="2" onclick="selectItem(event, 'bedrooms', '2')">2房</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '3'); ?>" data-filter-type="bedrooms" data-filter-value="3" onclick="selectItem(event, 'bedrooms', '3')">3房</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '4'); ?>" data-filter-type="bedrooms" data-filter-value="4" onclick="selectItem(event, 'bedrooms', '4')">4房</a>
                    <a href="#" class="item <?php echo isActiveLink('bedrooms', '5'); ?>" data-filter-type="bedrooms" data-filter-value="5" onclick="selectItem(event, 'bedrooms', '5')">5房以上</a>
                </div>
                
                <div class="area border-b pb-4">
                    <div class="area-type-group pb-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column">面積類型:</div>
                        <a href="#" class="item <?php echo getActiveFilterValue('area_type', 'net') == 'net' ? 'active' : ''; ?>" onclick="selectAreaType(event, 'net')">實用面積</a>
                        <a href="#" class="item <?php echo getActiveFilterValue('area_type') == 'gross' ? 'active' : ''; ?>" onclick="selectAreaType(event, 'gross')">建築面積</a>
                    </div>
                    <div class="area-range-group pt-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column">面積範圍:</div>
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', '不限') && !$is_custom_size ? 'active' : ''; ?>" data-range-type="unlimit" data-range-value="不限" onclick="selectAreaRange(event, '不限')">不限</a>
                        <a href="#" class="item area-range <?php echo $is_custom_size ? 'active' : ''; ?>" data-range-type="custom" data-range-value="custom" onclick="showCustomAreaDialog(event)">自定</a>
                        
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', 's1'); ?>" data-range-type="multi" data-range-value="s1" onclick="selectAreaRange(event, 's1')">300 呎以下</a>
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', 's2'); ?>" data-range-type="multi" data-range-value="s2" onclick="selectAreaRange(event, 's2')">300-500 呎</a>
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', 's3'); ?>" data-range-type="multi" data-range-value="s3" onclick="selectAreaRange(event, 's3')">500-1000 呎</a>
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', 's4'); ?>" data-range-type="multi" data-range-value="s4" onclick="selectAreaRange(event, 's4')">1000-2000 呎</a>
                        <a href="#" class="item area-range <?php echo isActiveLink('size_range', 's5'); ?>" data-range-type="multi" data-range-value="s5" onclick="selectAreaRange(event, 's5')">2000 呎以上</a>
                    </div>
                    <div class="custom_area_display <?php echo $is_custom_size ? '' : 'hidden'; ?> pt-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column"></div>
                        <span id="custom_area_value" class="font-semibold text-gray-700">
                            <?php 
                            $area_type_name = getActiveFilterValue('area_type', 'net') == 'net' ? '實用面積' : '建築面積';
                            if ($is_custom_size) echo "自定({$area_type_name}): ".($_GET['size_min'] ?? '0')." 呎 - ".($_GET['size_max'] ?? 'Max')." 呎"; 
                            ?>
                        </span>
                        <a href="#" onclick="clearCustomArea(event)" class="text-red-500 hover:text-red-700 font-normal"> [清除] </a>
                    </div>
                </div>

                <div class="price border-b pb-4">
                    <div class="price-range-group">
                        <div class="inline-block w-24 font-bold align-top two-wide-column"><?php echo $listing_type == 'sale' ? '售價' : '租價'; ?>:</div>
                        <a href="#" class="item price-range <?php echo isActiveLink('price_range', '不限') && !$is_custom_price ? 'active' : ''; ?>" data-range-type="unlimit" data-range-value="不限" onclick="selectPriceRange(event, '不限')">不限</a>
                        <a href="#" class="item price-range <?php echo $is_custom_price ? 'active' : ''; ?>" data-range-type="custom" data-range-value="custom" onclick="showCustomPriceDialog(event)">自定</a>
                        
                        <?php if ($listing_type == 'sale'): ?>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p1'); ?>" data-range-type="multi" data-range-value="p1" onclick="selectPriceRange(event, 'p1')">200萬以下</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p2'); ?>" data-range-type="multi" data-range-value="p2" onclick="selectPriceRange(event, 'p2')">200-400萬</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p3'); ?>" data-range-type="multi" data-range-value="p3" onclick="selectPriceRange(event, 'p3')">400-800萬</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p4'); ?>" data-range-type="multi" data-range-value="p4" onclick="selectPriceRange(event, 'p4')">800-2000萬</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p5'); ?>" data-range-type="multi" data-range-value="p5" onclick="selectPriceRange(event, 'p5')">2000萬以上</a>
                        <?php else: ?>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p1'); ?>" data-range-type="multi" data-range-value="p1" onclick="selectPriceRange(event, 'p1')">5000元以下</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p2'); ?>" data-range-type="multi" data-range-value="p2" onclick="selectPriceRange(event, 'p2')">5000-10000元</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p3'); ?>" data-range-type="multi" data-range-value="p3" onclick="selectPriceRange(event, 'p3')">10000-15000元</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p4'); ?>" data-range-type="multi" data-range-value="p4" onclick="selectPriceRange(event, 'p4')">15000-20000元</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p5'); ?>" data-range-type="multi" data-range-value="p5" onclick="selectPriceRange(event, 'p5')">20000-40000元</a>
                            <a href="#" class="item price-range <?php echo isActiveLink('price_range', 'p6'); ?>" data-range-type="multi" data-range-value="p6" onclick="selectPriceRange(event, 'p6')">40000元以上</a>
                        <?php endif; ?>
                    </div>
                    <div class="custom_price_display <?php echo $is_custom_price ? '' : 'hidden'; ?> pt-2">
                        <div class="inline-block w-24 font-bold align-top two-wide-column"></div>
                        <span id="custom_price_value" class="font-semibold text-gray-700">
                            <?php 
                            if ($is_custom_price) {
                                $unit = $listing_type == 'sale' ? '萬' : '元';
                                $min_val = $listing_type == 'sale' ? number_format($_GET['price_min'] / 10000, 0) : number_format($_GET['price_min'], 0);
                                $max_val = $listing_type == 'sale' ? number_format($_GET['price_max'] / 10000, 0) : number_format($_GET['price_max'], 0);
                                echo "自定: {$min_val} {$unit} - {$max_val} {$unit}";
                            }
                            ?>
                        </span>
                        <a href="#" onclick="clearCustomPrice(event)" class="text-red-500 hover:text-red-700 font-normal"> [清除] </a>
                    </div>
                </div>

                <div class="sort-order pb-4">
                    <div class="inline-block w-24 font-bold align-top two-wide-column">排序:</div>
                    <select id="sort-select" name="sort_by" onchange="loadProperties()" class="p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
                        <option value="latest" <?php echo $current_sort_by_value == 'latest' ? 'selected' : ''; ?>>最新發布</option>
                        
                        <?php 
                        $price_text = $listing_type == 'rent' ? '租價' : '價錢';
                        ?>
                        <option value="price_asc" <?php echo $current_sort_by_value == 'price_asc' ? 'selected' : ''; ?>><?php echo $price_text; ?>(低至高)</option>
                        <option value="price_desc" <?php echo $current_sort_by_value == 'price_desc' ? 'selected' : ''; ?>><?php echo $price_text; ?>(高至低)</option>
                        
                        <option value="size_gross_asc" <?php echo $current_sort_by_value == 'size_gross_asc' ? 'selected' : ''; ?>>面積 建築(低至高)</option>
                        <option value="size_gross_desc" <?php echo $current_sort_by_value == 'size_gross_desc' ? 'selected' : ''; ?>>面積 建築(高至低)</option>
                        
                        <option value="size_net_asc" <?php echo $current_sort_by_value == 'size_net_asc' ? 'selected' : ''; ?>>面積 實用(低至高)</option>
                        <option value="size_net_desc" <?php echo $current_sort_by_value == 'size_net_desc' ? 'selected' : ''; ?>>面積 實用(高至低)</option>
                    </select>
                </div>
                
            </div>
            
            <div class="mt-4 flex justify-end space-x-4">
                 <a href="properties.php?listing_type=<?php echo $listing_type; ?>" class="px-6 py-2 border rounded text-gray-700 hover:bg-gray-100">重設</a>
            </div>
        </form>
    </div>

    <div id="property-list-container">
        <p id="loading-spinner" class="text-center text-gray-500 mt-8 text-lg font-semibold">
            <i class="fas fa-spinner fa-spin mr-2"></i> 正在載入樓盤列表...
        </p>
    </div>
</div>

<style>
/* 包含您的 CSS 樣式，並結合 Tailwind 的簡化*/
.item {
    margin-right: 15px;
    text-decoration: none;
    color: #333;
    white-space: nowrap;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background-color 0.15s;
}
.item:hover {
    background-color: #f0f0f0;
}
.active {
    color: #2563eb; /* Tailwind blue-600 */
    font-weight: bold;
    background-color: #dbeafe; /* Tailwind blue-100 */
}
.two-wide-column {
    display: inline-block;
    width: 80px;
    font-weight: bold;
    vertical-align: top;
}
</style>

<dialog id="customRentDialog" class="p-6 rounded-lg shadow-xl backdrop:bg-gray-900/50">
    <h3 class="text-lg font-bold mb-4">請輸入自定租金範圍 (元):</h3>
    <div class="space-y-3">
        <label for="minRent" class="block font-medium">最低租金:</label>
        <input type="number" id="minRent" value="<?php echo htmlspecialchars(number_format($_GET['price_min'] ?? '5000', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
        
        <label for="maxRent" class="block font-medium">最高租金:</label>
        <input type="number" id="maxRent" value="<?php echo htmlspecialchars(number_format($_GET['price_max'] ?? '20000', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
    </div>
    <div class="mt-6 flex justify-end space-x-3">
        <button onclick="closeCustomPriceDialog()" type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">取消</button>
        <button onclick="applyCustomPrice()" type="button" class="px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700">確定</button>
    </div>
</dialog>

<dialog id="customSaleDialog" class="p-6 rounded-lg shadow-xl backdrop:bg-gray-900/50">
    <h3 class="text-lg font-bold mb-4">請輸入自定售價範圍 (萬):</h3>
    <div class="space-y-3">
        <label for="minSale" class="block font-medium">最低售價:</label>
        <input type="number" id="minSale" value="<?php echo htmlspecialchars(number_format($_GET['price_min'] / 10000 ?? '500', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
        
        <label for="maxSale" class="block font-medium">最高售價:</label>
        <input type="number" id="maxSale" value="<?php echo htmlspecialchars(number_format($_GET['price_max'] / 10000 ?? '1500', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
    </div>
    <div class="mt-6 flex justify-end space-x-3">
        <button onclick="closeCustomPriceDialog()" type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">取消</button>
        <button onclick="applyCustomPrice()" type="button" class="px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700">確定</button>
    </div>
</dialog>

<dialog id="customAreaDialog" class="p-6 rounded-lg shadow-xl backdrop:bg-gray-900/50">
    <h3 class="text-lg font-bold mb-4">請輸入自定面積範圍 (呎):</h3>
    <p class="text-sm text-gray-600 mb-3">(當前篩選類型: <span id="dialog_area_type_name">實用面積</span>)</p>
    <div class="space-y-3">
        <label for="minArea" class="block font-medium">最低面積:</label>
        <input type="number" id="minArea" value="<?php echo htmlspecialchars(number_format($_GET['size_min'] ?? '500', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
        
        <label for="maxArea" class="block font-medium">最高面積:</label>
        <input type="number" id="maxArea" value="<?php echo htmlspecialchars(number_format($_GET['size_max'] ?? '1500', 0, '.', '')); ?>" min="0" class="w-full p-2 border border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500">
    </div>
    <div class="mt-6 flex justify-end space-x-3">
        <button onclick="closeCustomAreaDialog()" type="button" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">取消</button>
        <button onclick="applyCustomArea()" type="button" class="px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700">確定</button>
    </div>
</dialog>


<script>
    const LISTING_TYPE = '<?php echo $listing_type; ?>';
    const FILTER_FORM = document.getElementById('filter-form');
    const PROPERTY_CONTAINER = document.getElementById('property-list-container');
    const LOADING_HTML = '<p id="loading-spinner" class="text-center text-gray-500 mt-8 text-lg font-semibold"><i class="fas fa-spinner fa-spin mr-2"></i> 正在載入樓盤列表...</p>';

    /**
     * 從隱藏欄位收集所有篩選數據，並發送 AJAX 請求來更新列表。
     * @param {boolean} [pushState=true] - 是否更新瀏覽器歷史記錄。
     */
    function loadProperties(pushState = true) {
        // 1. 顯示載入中的提示
        PROPERTY_CONTAINER.innerHTML = LOADING_HTML;

        // 2. 收集篩選數據
        const formData = new FormData(FILTER_FORM);
        const urlParams = new URLSearchParams(formData).toString();
        
        // 3. 更新 URL
        if (pushState) {
            history.pushState(null, '', `properties.php?${urlParams}`);
        }

        // 4. 發送 AJAX 請求
        fetch(`properties.php?${urlParams}`, {
            method: 'GET',
            headers: {
                // 傳送特殊 Header 讓 PHP 知道這是 AJAX 請求
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            // 5. 更新 DOM 內容
            PROPERTY_CONTAINER.innerHTML = html;
        })
        .catch(error => {
            PROPERTY_CONTAINER.innerHTML = `<p class="text-center text-red-500 mt-8">載入列表失敗: ${error.message}</p>`;
            console.error('Error fetching properties:', error);
        });
    }

    /**
     * 處理關鍵字輸入，並觸發篩選 (已併入 onchange 事件，此處留空但保留)
     */
    function selectSearch(value) {
        loadProperties();
    }


    /**
     * 點擊篩選選項後，更新對應的隱藏欄位，並呼叫 loadProperties()。 (單選)
     */
    function selectItem(event, type, value) {
        event.preventDefault();
        const container = event.target.closest(`.${type}`);
        const allItems = container.querySelectorAll('a.item');

        allItems.forEach(link => link.classList.remove('active'));
        event.target.classList.add('active');

        // 更新隱藏欄位
        document.getElementById(`filter-${type}`).value = value;
        loadProperties();
    }


    // --- 區域/社群 相關函式 (觸發 AJAX) ---

    function hideAllCommunityLists() {
        document.querySelectorAll('.community-list').forEach(item => {
            item.classList.add('hidden');
        });
    }

    function selectDistrict(event, district) {
        event.preventDefault();

        document.querySelectorAll('.location > div:first-child > a.item').forEach(link => {
            link.classList.remove('active');
        });
        event.target.classList.add('active');

        hideAllCommunityLists();
        
        // 清除社區篩選
        document.getElementById('filter-community').value = '不限';
        
        if (district !== '不限') {
            const targetItem = document.querySelector(`.${district}_item`);
            if (targetItem) {
                targetItem.classList.remove('hidden');
                targetItem.querySelector('a[data-sub-value="不限"]').classList.add('active'); 
            }
            document.getElementById('filter-district').value = district;
        } else {
             document.getElementById('filter-district').value = '不限';
        }
        loadProperties();
    }

    /**
     * 社區多選邏輯 (與 hi.html 中的 selectSubItem 相似)
     */
    function selectCommunity(event, community, parentDistrict) {
        event.preventDefault();
        
        const parentContainer = event.target.closest('.community-list');
        const unlimitLink = parentContainer.querySelector('a[data-sub-value="不限"]');
        const targetLink = event.target;
        
        if (community === '不限') {
            // 1. 如果點擊「不限」，取消所有其他選項，並選中「不限」
            parentContainer.querySelectorAll('a.item').forEach(link => link.classList.remove('active'));
            targetLink.classList.add('active');
            document.getElementById('filter-community').value = '不限';
        } else {
            // 2. 如果點擊其他選項，切換其狀態
            targetLink.classList.toggle('active');
            
            // 3. 取消「不限」的狀態
            if (unlimitLink && targetLink.classList.contains('active')) {
                unlimitLink.classList.remove('active');
            }
            
            // 4. 檢查是否有其他選項被選中
            const activeSubItems = Array.from(parentContainer.querySelectorAll('a.item:not([data-sub-value="不限"])')).filter(link => link.classList.contains('active'));
            
            if (activeSubItems.length === 0) {
                // 如果沒有任何選項被選中，則選中「不限」
                unlimitLink.classList.add('active');
                document.getElementById('filter-community').value = '不限';
            } else {
                // 否則，更新隱藏欄位為逗號分隔的列表
                const selectedValues = activeSubItems.map(link => link.getAttribute('data-sub-value'));
                document.getElementById('filter-community').value = selectedValues.join(',');
            }
        }
        
        // 確保主區域是選中狀態 (如果子分類不是「不限」)
        document.querySelectorAll('.location > div:first-child > a.item').forEach(link => {
            if (link.textContent.trim() === parentDistrict) {
                 if (document.getElementById('filter-community').value !== '不限') {
                    link.classList.remove('active'); // 有子選，主區域不 active
                } else {
                    link.classList.add('active'); // 沒子選 (或選了不限)，主區域 active
                }
            } else if (link.textContent.trim() === '不限') {
                link.classList.remove('active');
            }
        });
        document.getElementById('filter-district').value = parentDistrict;

        loadProperties();
    }


    // --- 類別/子類別 相關函式 (觸發 AJAX) ---

    function hideAllSubCategoryLists() {
        document.querySelectorAll('.sub-category-list').forEach(item => {
            item.classList.add('hidden');
        });
    }
    
    function selectCategory(event, category) {
        event.preventDefault();
        
        document.querySelectorAll('.category > div:first-child > a.item').forEach(link => {
            link.classList.remove('active');
        });
        event.target.classList.add('active');

        hideAllSubCategoryLists();
        // 修正: 更新 ID 為 filter-sup-property-type
        document.getElementById('filter-sup-property-type').value = '不限';
        
        if (category !== '不限') {
            const targetItem = document.querySelector(`.${category}_item`);
            if (targetItem) {
                targetItem.classList.remove('hidden');
                targetItem.querySelector('a[data-sub-value="不限"]').classList.add('active'); 
            }
            document.getElementById('filter-property_type').value = category;
        } else {
             document.getElementById('filter-property_type').value = '不限';
        }
        loadProperties();
    }
    
    /**
     * 子類別多選邏輯 (與 selectCommunity 邏輯一致)
     */
    function selectSubCategory(event, subType, parentCategory) {
        event.preventDefault();
        
        const parentContainer = event.target.closest('.sub-category-list');
        const unlimitLink = parentContainer.querySelector('a[data-sub-value="不限"]');
        const targetLink = event.target;
        
        if (subType === '不限') {
            // 1. 如果點擊「不限」，取消所有其他選項，並選中「不限」
            parentContainer.querySelectorAll('a.item').forEach(link => link.classList.remove('active'));
            targetLink.classList.add('active');
            // 修正: 更新 ID 為 filter-sup-property-type
            document.getElementById('filter-sup-property-type').value = '不限';
        } else {
            // 2. 如果點擊其他選項，切換其狀態
            targetLink.classList.toggle('active');
            
            // 3. 取消「不限」的狀態
            if (unlimitLink && targetLink.classList.contains('active')) {
                unlimitLink.classList.remove('active');
            }
            
            // 4. 檢查是否有其他選項被選中
            const activeSubItems = Array.from(parentContainer.querySelectorAll('a.item:not([data-sub-value="不限"])')).filter(link => link.classList.contains('active'));
            
            if (activeSubItems.length === 0) {
                // 如果沒有任何選項被選中，則選中「不限」
                unlimitLink.classList.add('active');
                // 修正: 更新 ID 為 filter-sup-property-type
                document.getElementById('filter-sup-property-type').value = '不限';
            } else {
                // 否則，更新隱藏欄位為逗號分隔的列表
                const selectedValues = activeSubItems.map(link => link.getAttribute('data-sub-value'));
                // 修正: 更新 ID 為 filter-sup-property-type
                document.getElementById('filter-sup-property-type').value = selectedValues.join(',');
            }
        }
        
        // 確保主類別是選中狀態
        document.querySelectorAll('.category > div:first-child > a.item').forEach(link => {
             if (link.getAttribute('data-category-type') === parentCategory) {
                link.classList.add('active');
            } else if (link.textContent.trim() === '不限') {
                link.classList.remove('active');
            }
        });
        document.getElementById('filter-property_type').value = parentCategory;
        
        loadProperties();
    }
    
    // --- 價格/面積 範圍選擇 (多選邏輯) (觸發 AJAX) ---
    function selectAreaType(event, type) {
        event.preventDefault();
        document.querySelectorAll('.area a[onclick*="selectAreaType"]').forEach(link => {
            link.classList.remove('active');
        });
        event.target.classList.add('active');
        document.getElementById('currentAreaType').value = type;
        
        // 更新自定對話框中的面積類型名稱
        const areaTypeName = type === 'net' ? '實用面積' : '建築面積';
        document.getElementById('dialog_area_type_name').textContent = areaTypeName;
        
        // 重新設置面積範圍篩選，並重新載入列表
        resetAreaRange();
        loadProperties();
    }
    
    function selectAreaRange(event, areaRange) {
        event.preventDefault();
        const target = event.target;
        const rangeType = target.getAttribute('data-range-type');
        const allRanges = document.querySelectorAll('.area-range-group a.area-range');
        const unlimitLink = document.querySelector('.area a[data-range-type="unlimit"]');
        const customLink = document.querySelector('.area a[data-range-type="custom"]');
        
        // 1. 隱藏自定顯示，並清除自定值
        document.querySelector('.custom_area_display').classList.add('hidden');
        document.getElementById('filter-size-min').value = '';
        document.getElementById('filter-size-max').value = '';

        if (rangeType === 'unlimit' || rangeType === 'custom') {
            // 2. 如果選擇 '不限' 或 '自定'，則取消所有其他範圍的 active 狀態
            allRanges.forEach(link => {
                link.classList.remove('active');
            });
            target.classList.add('active');
        } else if (rangeType === 'multi') {
            // 3. 如果選擇多選項，則取消 '不限' 和 '自定' 的 active 狀態
            unlimitLink.classList.remove('active');
            customLink.classList.remove('active');
            
            // 切換當前多選項的 active 狀態
            target.classList.toggle('active');

            // 4. 檢查是否所有多選項都被取消，如果是，則重新選擇 '不限'
            const activeMultiRanges = document.querySelectorAll('.area a[data-range-type="multi"].active');
            if (activeMultiRanges.length === 0) {
                unlimitLink.classList.add('active');
                document.getElementById('filter-size-range').value = '不限';
                loadProperties();
                return;
            }
        }
        
        // 5. 更新隱藏欄位 (多選值以逗號分隔)
        const activeRangeLinks = Array.from(document.querySelectorAll('.area a[data-range-type]:not([data-range-type="custom"]):not([data-range-type="unlimit"])')).filter(link => link.classList.contains('active'));
        
        if (rangeType === 'unlimit') {
             document.getElementById('filter-size-range').value = '不限';
        } else if (rangeType === 'custom') {
             document.getElementById('filter-size-range').value = 'custom'; // 暫時設為 custom
        } else {
             const selectedValues = activeRangeLinks.map(link => link.getAttribute('data-range-value'));
             document.getElementById('filter-size-range').value = selectedValues.join(',');
        }

        if (rangeType !== 'custom') { // 自訂需點擊對話框內的確定
            loadProperties();
        }
    }


    function selectPriceRange(event, priceRange) {
        event.preventDefault();
        const target = event.target;
        const rangeType = target.getAttribute('data-range-type');
        const allRanges = document.querySelectorAll('.price-range-group a.price-range');
        const unlimitLink = document.querySelector('.price a[data-range-type="unlimit"]');
        const customLink = document.querySelector('.price a[data-range-type="custom"]');
        
        // 1. 隱藏自定顯示，並清除自定值
        document.querySelector('.custom_price_display').classList.add('hidden');
        document.getElementById('filter-price-min').value = '';
        document.getElementById('filter-price-max').value = '';

        if (rangeType === 'unlimit' || rangeType === 'custom') {
            // 2. 如果選擇 '不限' 或 '自定'，則取消所有其他範圍的 active 狀態
            allRanges.forEach(link => {
                link.classList.remove('active');
            });
            target.classList.add('active');
        } else if (rangeType === 'multi') {
            // 3. 如果選擇多選項，則取消 '不限' 和 '自定' 的 active 狀態
            unlimitLink.classList.remove('active');
            customLink.classList.remove('active');
            
            // 切換當前多選項的 active 狀態
            target.classList.toggle('active');

            // 4. 檢查是否所有多選項都被取消，如果是，則重新選擇 '不限'
            const activeMultiRanges = document.querySelectorAll('.price a[data-range-type="multi"].active');
            if (activeMultiRanges.length === 0) {
                unlimitLink.classList.add('active');
                document.getElementById('filter-price-range').value = '不限';
                loadProperties();
                return;
            }
        }
        
        // 5. 更新隱藏欄位 (多選值以逗號分隔)
        const activeRangeLinks = Array.from(document.querySelectorAll('.price a[data-range-type]:not([data-range-type="custom"]):not([data-range-type="unlimit"])')).filter(link => link.classList.contains('active'));
        
        if (rangeType === 'unlimit') {
             document.getElementById('filter-price-range').value = '不限';
        } else if (rangeType === 'custom') {
             document.getElementById('filter-price-range').value = 'custom'; // 暫時設為 custom
        } else {
             const selectedValues = activeRangeLinks.map(link => link.getAttribute('data-range-value'));
             document.getElementById('filter-price-range').value = selectedValues.join(',');
        }

        if (priceRange !== 'custom') { // 自訂需點擊對話框內的確定
            loadProperties();
        }
    }
    
    // --- 清除自訂欄位 (觸發 AJAX) (保持不變) ---
    function clearCustomArea(event) {
        event.preventDefault();
        document.querySelector('.custom_area_display').classList.add('hidden');
        document.getElementById('filter-size-min').value = '';
        document.getElementById('filter-size-max').value = '';
        document.getElementById('filter-size-range').value = '不限';
        document.querySelectorAll('.area a[data-range-type]').forEach(link => link.classList.remove('active'));
        document.querySelector('.area a[data-range-type="unlimit"]').classList.add('active');
        loadProperties();
    }
    
    function clearCustomPrice(event) {
        event.preventDefault();
        document.querySelector('.custom_price_display').classList.add('hidden');
        document.getElementById('filter-price-min').value = '';
        document.getElementById('filter-price-max').value = '';
        document.getElementById('filter-price-range').value = '不限';
        document.querySelectorAll('.price a[data-range-type]').forEach(link => link.classList.remove('active'));
        document.querySelector('.price a[data-range-type="unlimit"]').classList.add('active');
        loadProperties();
    }
    
    // --- 自訂對話框操作 (應用時需更新顯示的面積類型名稱) ---
    
    function applyCustomArea() {
        const minArea = document.getElementById('minArea').value;
        const maxArea = document.getElementById('maxArea').value;
        const customValueSpan = document.getElementById('custom_area_value');

        if (minArea === '' || maxArea === '' || parseInt(minArea) > parseInt(maxArea) || parseInt(minArea) < 0 || parseInt(maxArea) < 0) {
            alert('請輸入有效的面積範圍。');
            return;
        }

        const areaType = document.getElementById('currentAreaType').value;
        const areaTypeName = areaType === 'net' ? '實用面積' : '建築面積';
        customValueSpan.textContent = `自定(${areaTypeName}): ${minArea} 呎 - ${maxArea} 呎`;
        document.querySelector('.custom_area_display').classList.remove('hidden');
        
        document.getElementById('filter-size-min').value = minArea;
        document.getElementById('filter-size-max').value = maxArea;
        document.getElementById('filter-size-range').value = ''; // 清除範圍多選
        
        // 清除範圍選項的 active 狀態
        document.querySelectorAll('.area a[data-range-type="multi"]').forEach(link => link.classList.remove('active'));
        
        closeCustomAreaDialog();
        loadProperties(); // 應用自訂後提交
    }
    
    function applyCustomPrice() {
        const isSale = LISTING_TYPE === 'sale';
        const minPriceInput = isSale ? document.getElementById('minSale') : document.getElementById('minRent');
        const maxPriceInput = isSale ? document.getElementById('maxSale') : document.getElementById('maxRent');
        const minPrice = minPriceInput.value;
        const maxPrice = maxPriceInput.value;

        if (minPrice === '' || maxPrice === '' || parseFloat(minPrice) > parseFloat(maxPrice) || parseFloat(minPrice) < 0 || parseFloat(maxPrice) < 0) {
            alert('請輸入有效的價格範圍。');
            return;
        }
        
        const minVal = isSale ? minPrice * 10000 : minPrice;
        const maxVal = isSale ? maxPrice * 10000 : maxPrice;
        const unit = isSale ? '萬' : '元';

        document.getElementById('custom_price_value').textContent = `自定: ${minPrice} ${unit} - ${maxPrice} ${unit}`;
        document.querySelector('.custom_price_display').classList.remove('hidden');
        
        document.getElementById('filter-price-min').value = minVal;
        document.getElementById('filter-price-max').value = maxVal;
        document.getElementById('filter-price-range').value = ''; // 清除範圍多選
        
        // 清除範圍選項的 active 狀態
        document.querySelectorAll('.price a[data-range-type="multi"]').forEach(link => link.classList.remove('active'));
        
        closeCustomPriceDialog();
        loadProperties(); // 應用自訂後提交
    }
    
    // --- 頁面載入時處理 (首次載入列表，已移動到 window.onload) ---
    window.addEventListener('load', () => {
        
        // 1. 執行首次載入
        loadProperties(false); 

        // 2. 恢復子列表的顯示狀態
        const currentDistrict = '<?php echo $current_district; ?>';
        const currentPropertyType = '<?php echo $current_property_type_db; ?>';
        
        if (currentDistrict !== '不限' && currentDistrict !== '') {
            const districtElement = document.querySelector(`.${currentDistrict}_item`);
            if (districtElement) districtElement.classList.remove('hidden');
        }
        
        if (currentPropertyType !== '不限' && currentPropertyType !== '') {
            const categoryElement = document.querySelector(`.${currentPropertyType}_item`);
            if (categoryElement) categoryElement.classList.remove('hidden');
        }
    });

    // --- 處理瀏覽器「後退」按鈕 (Back/Forward Navigation) ---
    window.addEventListener('popstate', (event) => {
        // Popstate 時，所有篩選值已在 URL 中，只需要重新載入列表
        loadProperties(false); 
    });
    
    // --- (其他輔助函式 for Dialogs, 保持不變) ---
    function showCustomAreaDialog(event) {
        event.preventDefault();
        
        // 確保對話框中的面積類型名稱正確
        const currentAreaType = document.getElementById('currentAreaType').value;
        const areaTypeName = currentAreaType === 'net' ? '實用面積' : '建築面積';
        document.getElementById('dialog_area_type_name').textContent = areaTypeName;
        
        document.querySelectorAll('.area a.area-range').forEach(link => link.classList.remove('active'));
        document.querySelector('.area a[data-range-type="custom"]').classList.add('active');
        document.getElementById('customAreaDialog').showModal();
    }
    
    function closeCustomAreaDialog() {
        document.getElementById('customAreaDialog').close();
        const customDisplay = document.querySelector('.custom_area_display');
        const customLink = document.querySelector('.area a[data-range-type="custom"]');
        const unlimitLink = document.querySelector('.area a[data-range-type="unlimit"]');

        if (customDisplay.classList.contains('hidden') && customLink.classList.contains('active')) {
            customLink.classList.remove('active');
            if(unlimitLink) unlimitLink.classList.add('active');
        }
    }
    
    function resetAreaRange() {
        document.querySelectorAll('.area a[data-range-type]').forEach(link => link.classList.remove('active'));
        document.querySelector('.custom_area_display').classList.add('hidden');
        document.querySelector('.area a[data-range-type="unlimit"]').classList.add('active');
        document.getElementById('filter-size-min').value = '';
        document.getElementById('filter-size-max').value = '';
        document.getElementById('filter-size-range').value = '不限';
    }


    function showCustomPriceDialog(event) {
        event.preventDefault();
        document.querySelectorAll('.price a.price-range').forEach(link => link.classList.remove('active'));
        document.querySelector('.price a[data-range-type="custom"]').classList.add('active');
        
        if (LISTING_TYPE === 'sale') {
            document.getElementById('customSaleDialog').showModal();
        } else {
            document.getElementById('customRentDialog').showModal();
        }
    }
    
    function closeCustomPriceDialog() {
        const dialogId = LISTING_TYPE === 'sale' ? 'customSaleDialog' : 'customRentDialog';
        document.getElementById(dialogId).close();
        
        const customDisplay = document.querySelector('.custom_price_display');
        const customLink = document.querySelector('.price a[data-range-type="custom"]');
        const unlimitLink = document.querySelector('.price a[data-range-type="unlimit"]');

        if (customDisplay.classList.contains('hidden') && customLink.classList.contains('active')) {
            customLink.classList.remove('active');
            if(unlimitLink) unlimitLink.classList.add('active');
        }
    }

</script>

<?php 
// 引入 footer
include __DIR__ . '/includes/footer.php'; 
?>