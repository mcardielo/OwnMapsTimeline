<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0<?= $noScale ?? '' ?>">
    <title><?= View::esc($title ?? 'OwnTracks') ?></title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <?= $headExtra ?? '' ?>
    <style>
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; <?= $bodyStyle ?? '' ?> }
    </style>
</head>
<body class="<?= $bodyClass ?? 'bg-gray-100 min-h-screen' ?>">
    <?php if (($showNav ?? true) && !($fullScreen ?? false)): ?>
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/" class="text-xl font-bold">🗺️ OwnTracks</a>
                <?php if ($pageTitle ?? false): ?>
                    <span class="text-gray-400">/</span>
                    <span class="text-gray-600"><?= View::esc($pageTitle) ?></span>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-gray-500"><?= View::esc($username) ?></span>
                <?php foreach (($navLinks ?? []) as $link): if (!$link) continue; ?>
                    <a href="<?= View::esc($link['url']) ?>" class="text-blue-600 hover:underline"><?= View::esc($link['label']) ?></a>
                <?php endforeach; ?>
                <form method="POST" action="/logout" class="inline">
                    <button class="text-red-500 hover:underline">Logout</button>
                </form>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    <?= $content ?>
</body>
</html>
