        </div>
    </div>

    <script src="<?= e(asset($basePath, 'js/main.js')) ?>"></script>
    <?php foreach ($extraScripts ?? [] as $script): ?>
        <script src="<?= e(asset($basePath, $script)) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
