<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';

function postExists(int $id): void {
    $statement = db()->prepare('SELECT id FROM posts WHERE id = ?');
    $statement->execute([$id]);
    if (!$statement->fetch()) fail('Публикация не найдена.', 404);
}

function postList(int $page): array {
    $page = max(1, $page);
    $limit = 12;
    $offset = ($page - 1) * $limit;
    $viewer = visitorKey();
    $sql = 'SELECT p.id, p.image_path, p.caption, p.created_at, u.id AS author_id, u.username,
        (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS likes_count,
        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comments_count,
        EXISTS(SELECT 1 FROM likes own WHERE own.post_id = p.id AND own.visitor_key = ?) AS liked
        FROM posts p JOIN users u ON u.id = p.author_id ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?';
    $statement = db()->prepare($sql);
    $statement->bindValue(1, $viewer);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->bindValue(3, $offset, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

switch ($action) {
    case 'session':
        jsonResponse(['ok' => true, 'csrf' => csrfToken(), 'user' => currentUser()]);

    case 'user':
        $id = (int) ($_GET['id'] ?? 0);
        $statement = db()->prepare('SELECT id, username, created_at,
            (SELECT COUNT(*) FROM posts WHERE author_id = ?) AS posts_count
            FROM users WHERE id = ?');
        $statement->execute([$id, $id]);
        $user = $statement->fetch();
        if (!$user) fail('Пользователь не найден.', 404);
        $posts = db()->prepare('SELECT p.id, p.image_path, p.caption, p.created_at,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS likes_count
            FROM posts p WHERE p.author_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT 20');
        $posts->execute([$id]);
        jsonResponse(['ok' => true, 'user' => $user, 'posts' => $posts->fetchAll()]);

    case 'posts':
        jsonResponse(['ok' => true, 'posts' => postList((int) ($_GET['page'] ?? 1))]);

    case 'post':
        $id = (int) ($_GET['id'] ?? 0);
        $viewer = visitorKey();
        $statement = db()->prepare('SELECT p.id, p.image_path, p.caption, p.created_at, u.id AS author_id, u.username,
          (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS likes_count,
          EXISTS(SELECT 1 FROM likes own WHERE own.post_id = p.id AND own.visitor_key = ?) AS liked
          FROM posts p JOIN users u ON u.id=p.author_id WHERE p.id=?');
        $statement->execute([$viewer, $id]);
        $post = $statement->fetch();
        if (!$post) fail('Публикация не найдена.', 404);
        $comments = db()->prepare("SELECT c.id, c.body, c.created_at, c.user_id, COALESCE(u.username, 'Гость') AS author
            FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.post_id=? ORDER BY c.created_at ASC, c.id ASC");
        $comments->execute([$id]);
        jsonResponse(['ok' => true, 'post' => $post, 'comments' => $comments->fetchAll(), 'user' => currentUser()]);

    case 'register':
        requireCsrf(); $data = jsonInput();
        $username = cleanText($data['username'] ?? '', 32, 'Имя пользователя');
        if (!preg_match('/^[\p{L}\p{N}_-]{3,32}$/u', $username)) fail('Имя: 3–32 буквы, цифры, _ или -.');
        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($password) < 6 || mb_strlen($password) > 72) fail('Пароль должен содержать от 6 до 72 символов.');
        try {
            db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $exception) { fail('Это имя пользователя уже занято.', 409); }
        $id = (int) db()->lastInsertId();
        $_SESSION['user'] = ['id' => $id, 'username' => $username, 'role' => 'user'];
        jsonResponse(['ok' => true, 'user' => currentUser()]);

    case 'login':
        requireCsrf(); $data = jsonInput();
        $username = cleanText($data['username'] ?? '', 32, 'Имя пользователя');
        $password = (string) ($data['password'] ?? '');
        $statement = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $statement->execute([$username]); $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) fail('Неверное имя пользователя или пароль.', 401);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']];
        jsonResponse(['ok' => true, 'user' => currentUser()]);

    case 'logout':
        requireCsrf(); unset($_SESSION['user']); jsonResponse(['ok' => true]);

    case 'create_post':
        requireCsrf(); $user = requireLogin();
        $caption = trim((string) ($_POST['caption'] ?? ''));
        if (mb_strlen($caption) > 120) fail('Заголовок должен содержать не более 120 символов.');
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 25 * 1024 * 1024) fail('Выберите изображение размером до 25 МБ.');
        $file = $_FILES['image'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || !getimagesize($file['tmp_name'])) fail('Поддерживаются только JPG, PNG и WebP.');
        $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $relativePath = 'uploads/' . $name;
        if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relativePath)) fail('Не удалось сохранить изображение.', 500);
        db()->prepare('INSERT INTO posts (author_id, image_path, caption) VALUES (?, ?, ?)')->execute([$user['id'], $relativePath, $caption]);
        jsonResponse(['ok' => true, 'id' => (int)db()->lastInsertId()]);

    case 'like':
        requireCsrf(); $data = jsonInput(); $id = (int)($data['post_id'] ?? 0); postExists($id); $key = visitorKey();
        $check = db()->prepare('SELECT 1 FROM likes WHERE post_id=? AND visitor_key=?'); $check->execute([$id, $key]);
        $wasLiked = (bool) $check->fetch();
        if ($wasLiked) db()->prepare('DELETE FROM likes WHERE post_id=? AND visitor_key=?')->execute([$id, $key]);
        else db()->prepare('INSERT INTO likes (post_id, visitor_key) VALUES (?, ?)')->execute([$id, $key]);
        $count = db()->prepare('SELECT COUNT(*) FROM likes WHERE post_id=?'); $count->execute([$id]);
        jsonResponse(['ok' => true, 'liked' => !$wasLiked, 'likes_count' => (int)$count->fetchColumn()]);

    case 'comment':
        requireCsrf(); $data = jsonInput(); $postId = (int)($data['post_id'] ?? 0); postExists($postId);
        $body = cleanText($data['body'] ?? '', 300, 'Комментарий'); $user = currentUser();
        db()->prepare('INSERT INTO comments (post_id, user_id, body) VALUES (?, ?, ?)')->execute([$postId, $user['id'] ?? null, $body]);
        $id = (int)db()->lastInsertId();
        jsonResponse(['ok' => true, 'comment' => ['id' => $id, 'body' => $body, 'author' => $user['username'] ?? 'Гость', 'user_id' => $user['id'] ?? null, 'created_at' => date('Y-m-d H:i:s')]]);

    case 'delete_comment':
        requireCsrf(); $user = requireLogin(); $data = jsonInput(); $id = (int)($data['comment_id'] ?? 0);
        $statement = db()->prepare('SELECT user_id FROM comments WHERE id=?'); $statement->execute([$id]); $comment = $statement->fetch();
        if (!$comment) fail('Комментарий не найден.', 404);
        $moderator = in_array($user['role'], ['moderator', 'admin'], true);
        if (!$moderator && (!$comment['user_id'] || (int)$comment['user_id'] !== $user['id'])) fail('Недостаточно прав.', 403);
        db()->prepare('DELETE FROM comments WHERE id=?')->execute([$id]); jsonResponse(['ok' => true]);

    case 'delete_post':
        requireCsrf(); $user = requireLogin(); $data = jsonInput(); $id = (int)($data['post_id'] ?? 0);
        $statement = db()->prepare('SELECT author_id, image_path FROM posts WHERE id=?'); $statement->execute([$id]); $post = $statement->fetch();
        if (!$post) fail('Публикация не найдена.', 404);
        if ((int)$post['author_id'] !== $user['id']) fail('Удалять можно только собственные публикации.', 403);
        db()->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
        $file = __DIR__ . '/uploads/' . basename($post['image_path']); if (is_file($file)) unlink($file);
        jsonResponse(['ok' => true]);

    default: fail('Неизвестное действие.', 404);
}
