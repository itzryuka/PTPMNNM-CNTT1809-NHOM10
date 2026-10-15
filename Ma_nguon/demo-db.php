<?php
require __DIR__ . '/src/RegistrationRepository.php';
try {
    $repo = new RegistrationRepository(db());
    echo $repo->save(1, "O'Neil Demo", 'demo@example.test') . "\n";
    echo json_encode($repo->listForEvent(1), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database demo failed. Check lab configuration.\n');
    exit(1);
}
