<?php
// Mẫu cấu hình Trợ lý AI (beta). Sao chép thành config/ai.local.php rồi điền API key VÀO FILE ĐÓ.
// File này được đưa lên git: KHÔNG điền API key thật ở đây.
// Trên server (Render, Railway...) có thể đặt biến môi trường AI_PROVIDER, GEMINI_API_KEY thay cho file.
return [
    'provider'       => 'gemini',                 // 'gemini' (miễn phí) hoặc 'claude' (trả phí, cần cài SDK)

    'gemini_api_key' => '',                       // lấy tại https://aistudio.google.com -> Get API key
    'gemini_model'   => 'gemini-3.5-flash-lite',  // nhanh, có trong gói miễn phí

    'api_key'        => '',                       // Claude: https://console.anthropic.com -> API Keys
    'model'          => 'claude-opus-5-5',
];
