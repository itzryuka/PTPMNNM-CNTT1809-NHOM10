<?php
// Mẫu cấu hình Trợ lý AI (beta). Sao chép thành config/ai.local.php rồi điền API key.
// config/ai.local.php đã nằm trong .gitignore: KHÔNG đưa API key lên git.
// Trên server (Render, Railway...) có thể đặt biến môi trường ANTHROPIC_API_KEY thay cho file này.
return [
    'api_key' => '',                  // lấy tại https://console.anthropic.com -> API Keys
    'model'   => 'claude-opus-5-5',
];
