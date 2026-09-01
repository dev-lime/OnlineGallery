<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pixel Garden — галерея</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="#">Pixel Garden</a>
    <div id="account" class="account"></div>
</header>
<main class="page">
    <section class="hero">
        <p class="eyebrow">СООБЩЕСТВО ВИЗУАЛЬНЫХ ИСТОРИЙ</p>
        <h1>Сохраняйте то,<br>что вдохновляет.</h1>
        <p>Загружайте любимые кадры, оставляйте реакции и обсуждайте их вместе.</p>
    </section>
    <section id="upload-area" class="upload hidden">
        <h2>Новая публикация</h2>
        <form id="upload-form">
            <label>Изображение <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
            <label>Подпись <textarea name="caption" maxlength="500" placeholder="Расскажите о кадре" required></textarea></label>
            <button>Опубликовать</button>
        </form>
    </section>
    <section><div id="gallery" class="gallery" aria-live="polite"></div><button id="more" class="more">Показать ещё</button></section>
</main>
<dialog id="post-dialog" class="post-dialog"><button class="close" aria-label="Закрыть">×</button><div id="post-content"></div></dialog>
<dialog id="auth-dialog" class="auth-dialog"><button class="close" aria-label="Закрыть">×</button><div id="auth-content"></div></dialog>
<div id="toast" class="toast" role="status"></div>
<script src="assets/app.js"></script>
</body>
</html>
