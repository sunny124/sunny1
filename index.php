<?php
// 引入 header 和數據庫連接
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/db_connect.php';

// --- START: 修改後的查詢邏輯 ---

// 1. 根據 URL 參數決定要查詢的 listing_type
// 默認為 'sale' (買樓)
$listingTypeFilter = "p.listing_type = 'sale'";
$currentListingType = 'sale';

if (isset($_GET['listing']) && $_GET['listing'] === 'rent') {
    $listingTypeFilter = "p.listing_type = 'rent'";
    $currentListingType = 'rent';
}

// 確定顯示的面積欄位：使用 UFA，如果 UFA 為空則使用 floor area
// 注意：MySQL 欄位名稱如果包含空格，需要使用反引號 (`) 括起來。
$sizeDisplayColumn = "COALESCE(p.UFA, p.`floor area`)";

// 2. 構建包含動態條件的 SQL 查詢
$sql = "
    SELECT 
        p.id, 
        p.title, 
        p.price, 
        {$sizeDisplayColumn} AS size_sqft, 
        p.district, 
        p.num_bedrooms, 
        p.num_bathrooms,
        p.listing_type,
        pp.image_url AS cover_image_url
    FROM 
        properties p
    LEFT JOIN 
        property_photos pp ON p.id = pp.property_id AND pp.is_primary = 1
    WHERE 
        p.status = 'active' AND $listingTypeFilter
    ORDER BY 
        p.created_at DESC 
    LIMIT 9
";
// --- END: 修改後的查詢邏輯 ---

$result = $conn->query($sql);
$properties = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}
$conn->close();
?>

<div class="bg-slate-800 text-white">
    <div class="container mx-auto px-4 py-24 text-center">
        <h1 class="text-4xl md:text-6xl font-bold mb-4">尋找您的理想家園</h1>
        <p class="text-lg md:text-xl text-slate-300 mb-8">在 Hose28，我們為您搜羅全港最優質的樓盤</p>

        <form action="properties.php" method="GET" class="max-w-2xl mx-auto">
            <div class="flex items-center bg-white rounded-full shadow-lg p-2">
                <input 
                    type="hidden" 
                    name="listing_type" 
                    value="<?php echo $currentListingType; ?>"
                >
                <input 
                    type="text" 
                    name="search" 
                    placeholder="輸入地區、屋苑或街道..." 
                    class="w-full bg-transparent text-gray-800 border-none focus:outline-none focus:ring-0 px-4 py-2"
                >
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-full transition duration-300 flex items-center justify-center">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="bg-gray-50">
    <div class="container mx-auto px-4 py-16">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-10">最新精選樓盤</h2>
        
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-md">
            
            <div class="flex justify-center border-b mb-4">
                <a href="index.php?listing=sale" class="tab-btn <?php if ($currentListingType === 'sale') echo 'active'; ?>">買樓</a>
                <a href="index.php?listing=rent" class="tab-btn <?php if ($currentListingType === 'rent') echo 'active'; ?>">租樓</a>
                <button id="btn-owner" class="tab-btn" onclick="showTab('owner')">我是業主</button>
            </div>

            <div id="tab-content-wrapper">
                <div id="tab-buy" class="tab-content" style="<?php echo $currentListingType === 'sale' ? 'display: flex;' : 'display: none;'; ?>">
                    <a href="/properties.php?listing_type=sale&property_type=residential" class="sub-btn">住宅</a>
                    <a href="/properties.php?listing_type=sale&property_type=industrial" class="sub-btn">工商</a>
                    <a href="/properties.php?listing_type=sale&property_type=shop" class="sub-btn">店鋪</a>
                    <a href="/properties.php?listing_type=sale&property_type=parking_space" class="sub-btn">車位</a>
                </div>
                <div id="tab-rent" class="tab-content" style="<?php echo $currentListingType === 'rent' ? 'display: flex;' : 'display: none;'; ?>">
                    <a href="/properties.php?listing_type=rent&property_type=residential" class="sub-btn">住宅</a>
                    <a href="/properties.php?listing_type=rent&property_type=industrial" class="sub-btn">工商</a>
                    <a href="/properties.php?listing_type=rent&property_type=shop" class="sub-btn">店鋪</a>
                    <a href="/properties.php?listing_type=rent&property_type=parking_space" class="sub-btn">車位</a>
                </div>
                <div id="tab-owner" class="tab-content" style="display: none;">
                    <a href="/pricing" class="sub-btn">收費標準</a>
                    <a href="/submit-property" class="sub-btn">立即放盤</a>
                    <a href="/manage-listings" class="sub-btn">管理樓盤</a>
                    <a href="/member-center" class="sub-btn">會員中心</a>
                </div>
            </div>
            <?php if (!empty($properties)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-10">
                    <?php foreach ($properties as $property): ?>
                        <a href="property_details.php?id=<?php echo $property['id']; ?>" class="block bg-white rounded-lg shadow-md overflow-hidden hover:shadow-xl transition-shadow duration-300 border">
                            <div class="h-56 bg-cover bg-center" style="background-image: url('<?php echo htmlspecialchars($property['cover_image_url'] ?? 'https://placehold.co/600x400/e2e8f0/cbd5e0?text=No+Image'); ?>');"></div>
                            <div class="p-5">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm text-gray-500"><?php echo htmlspecialchars($property['district']); ?></span>
                                    <?php if ($property['listing_type'] == 'sale'): ?>
                                        <span class="text-xs font-semibold bg-blue-100 text-blue-800 px-2 py-1 rounded-full">出售</span>
                                    <?php else: ?>
                                        <span class="text-xs font-semibold bg-green-100 text-green-800 px-2 py-1 rounded-full">出租</span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 truncate mb-3"><?php echo htmlspecialchars($property['title']); ?></h3>
                                <div class="flex items-center text-gray-700 space-x-4 mb-4">
                                    <span><i class="fas fa-bed"></i> <?php echo $property['num_bedrooms']; ?> 房</span>
                                    <span><i class="fas fa-bath"></i> <?php echo $property['num_bathrooms']; ?> 廁</span>
                                    <span><i class="fas fa-ruler-combined"></i> <?php echo number_format($property['size_sqft']); ?> 呎</span>
                                </div>
                                <div class="text-2xl font-extrabold text-blue-600">
                                    <?php if ($property['listing_type'] == 'sale'): ?>
                                        $<?php echo number_format($property['price'] / 10000, 0); ?> 萬
                                    <?php else: ?>
                                        $<?php echo number_format($property['price'], 0); ?> /月
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-500 mt-10">暫時沒有符合條件的樓盤提供。</p>
            <?php endif; ?>

        </div>
    </div>
</div>

<style>
    .tab-btn {
        padding: 10px 20px;
        cursor: pointer;
        border: none;
        background-color: transparent;
        font-size: 1.1rem;
        font-weight: 600;
        color: #6b7280;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
        margin-bottom: -2px; 
        text-decoration: none; /* For <a> tags */
    }
    .tab-btn.active {
        color: #2563eb;
        border-bottom-color: #2563eb;
    }
    .tab-content {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 12px;
        padding-top: 10px;
    }
    .sub-btn {
        background-color: #f3f4f6;
        color: #374151;
        padding: 8px 16px;
        border-radius: 9999px;
        text-decoration: none;
        font-size: 0.9rem;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    .sub-btn:hover {
        background-color: #e5e7eb;
        color: #1f2937;
    }
</style>

<script>
    function showTab(tabName) {
        // This function is now only for the 'owner' tab which doesn't reload the page.
        
        // Hide all tab contents first
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.style.display = 'none';
        });

        // Deactivate the 'buy' and 'rent' links visually
        document.querySelector('a.tab-btn[href*="sale"]').classList.remove('active');
        document.querySelector('a.tab-btn[href*="rent"]').classList.remove('active');

        // Show the 'owner' tab content and activate its button
        document.getElementById('tab-owner').style.display = 'flex';
        document.getElementById('btn-owner').classList.add('active');
    }
</script>
<?php
// 引入 footer
include __DIR__ . '/includes/footer.php';
?>