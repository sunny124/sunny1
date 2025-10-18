document.addEventListener('DOMContentLoaded', function() {
    const favoriteButtons = document.querySelectorAll('.favorite-btn');

    favoriteButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            
            const propertyId = this.dataset.propertyId;
            
            // 使用 Fetch API 發送請求到後端
            fetch('/actions/favorite_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ property_id: propertyId })
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    // 成功後改變按鈕樣式
                    this.classList.toggle('favorited'); 
                    alert('操作成功！');
                } else {
                    alert('操作失敗: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    });
});