<?php

declare(strict_types=1);

session_start();

/**
 * oneFilePHPGallery
 * Compatível com PHP 8.0.
 */

const GALLERY_TITLE = 'oneFilePHPGallery';
const AUTH_USER = 'henrique';
const AUTH_PASS = 'chegue1';
const DEFAULT_PER_PAGE = 24;
const DEFAULT_IMAGES_PER_ROW = 4;
const MIN_IMAGES_PER_ROW = 1;
const MAX_IMAGES_PER_ROW = 8;
const MIN_PER_PAGE = 6;
const MAX_PER_PAGE = 200;

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
$currentDir = __DIR__;
$selfBasename = basename(__FILE__);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectWithMessage(string $message, string $type, array $query = []): never
{
    $query['msg'] = $message;
    $query['type'] = $type;
    header('Location: ?' . http_build_query($query));
    exit;
}

function validateCsrfOrFail(string $token, array $query): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        redirectWithMessage('Token CSRF inválido.', 'error', $query);
    }
}

function isAuthenticated(): bool
{
    return isset($_SESSION['auth_ok']) && $_SESSION['auth_ok'] === true;
}

function isSafeFilename(string $name): bool
{
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }

    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return false;
    }

    return preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1;
}

function hasImageExtension(string $filename, array $allowedExtensions): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions, true);
}

function getGalleryImages(string $dir, array $allowedExtensions, string $selfBasename): array
{
    $images = [];
    $items = scandir($dir);

    if ($items === false) {
        return $images;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === $selfBasename) {
            continue;
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

        if (!is_file($fullPath)) {
            continue;
        }

        if (!hasImageExtension($item, $allowedExtensions)) {
            continue;
        }

        $images[] = [
            'name' => $item,
            'mtime' => filemtime($fullPath) ?: 0,
            'ext' => strtolower(pathinfo($item, PATHINFO_EXTENSION)),
        ];
    }

    return $images;
}

function parseIntInRange(string $value, int $default, int $min, int $max): int
{
    if (!preg_match('/^-?\d+$/', $value)) {
        return $default;
    }

    $int = (int) $value;

    if ($int < $min) {
        return $min;
    }

    if ($int > $max) {
        return $max;
    }

    return $int;
}

$queryConfig = [
    'page' => isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1,
    'cols' => isset($_GET['cols']) ? parseIntInRange((string) $_GET['cols'], DEFAULT_IMAGES_PER_ROW, MIN_IMAGES_PER_ROW, MAX_IMAGES_PER_ROW) : DEFAULT_IMAGES_PER_ROW,
    'per_page' => isset($_GET['per_page']) ? parseIntInRange((string) $_GET['per_page'], DEFAULT_PER_PAGE, MIN_PER_PAGE, MAX_PER_PAGE) : DEFAULT_PER_PAGE,
    'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
    'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'name_asc',
];

$allowedSorts = ['name_asc', 'name_desc', 'date_new', 'date_old', 'type_asc', 'type_desc'];
if (!in_array($queryConfig['sort'], $allowedSorts, true)) {
    $queryConfig['sort'] = 'name_asc';
}

$msg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$msgType = isset($_GET['type']) ? (string) $_GET['type'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawAction = (string) ($_POST['action'] ?? '');

    if ($rawAction === 'login') {
        $user = trim((string) ($_POST['login_user'] ?? ''));
        $pass = (string) ($_POST['login_pass'] ?? '');

        if ($user === AUTH_USER && $pass === AUTH_PASS) {
            $_SESSION['auth_ok'] = true;
            header('Location: ?' . http_build_query([
                'page' => $queryConfig['page'],
                'cols' => $queryConfig['cols'],
                'per_page' => $queryConfig['per_page'],
                'q' => $queryConfig['q'],
                'sort' => $queryConfig['sort'],
                'msg' => 'Login efetuado com sucesso.',
                'type' => 'success',
            ]));
            exit;
        }

        redirectWithMessage('Usuário ou senha inválidos.', 'error', [
            'page' => 1,
            'cols' => $queryConfig['cols'],
            'per_page' => $queryConfig['per_page'],
            'q' => $queryConfig['q'],
            'sort' => $queryConfig['sort'],
        ]);
    }

    if (!isAuthenticated()) {
        redirectWithMessage('Faça login para acessar a galeria.', 'error');
    }

    $queryCarry = [
        'page' => $queryConfig['page'],
        'cols' => $queryConfig['cols'],
        'per_page' => $queryConfig['per_page'],
        'q' => $queryConfig['q'],
        'sort' => $queryConfig['sort'],
    ];

    validateCsrfOrFail((string) ($_POST['csrf_token'] ?? ''), $queryCarry);

    $action = $rawAction;

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        redirectWithMessage('Logout realizado com sucesso.', 'success');
    }

    if ($action === 'delete_one') {
        $filename = (string) ($_POST['filename'] ?? '');

        if (!isSafeFilename($filename)) {
            redirectWithMessage('Nome de arquivo inválido para exclusão.', 'error', $queryCarry);
        }

        $path = $currentDir . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path) || !hasImageExtension($filename, $allowedExtensions)) {
            redirectWithMessage('Arquivo não encontrado ou não permitido.', 'error', $queryCarry);
        }

        if (@unlink($path)) {
            redirectWithMessage("Arquivo '{$filename}' excluído com sucesso.", 'success', $queryCarry);
        }

        redirectWithMessage("Falha ao excluir '{$filename}'.", 'error', $queryCarry);
    }

    if ($action === 'rename_one') {
        $oldName = (string) ($_POST['old_name'] ?? '');
        $newName = trim((string) ($_POST['new_name'] ?? ''));

        if (!isSafeFilename($oldName) || !isSafeFilename($newName)) {
            redirectWithMessage('Nome de arquivo inválido para renomear.', 'error', $queryCarry);
        }

        if (!hasImageExtension($oldName, $allowedExtensions) || !hasImageExtension($newName, $allowedExtensions)) {
            redirectWithMessage('Extensão inválida. Renomeie mantendo uma extensão de imagem permitida.', 'error', $queryCarry);
        }

        $oldPath = $currentDir . DIRECTORY_SEPARATOR . $oldName;
        $newPath = $currentDir . DIRECTORY_SEPARATOR . $newName;

        if (!is_file($oldPath)) {
            redirectWithMessage('Arquivo original não existe.', 'error', $queryCarry);
        }

        if (is_file($newPath)) {
            redirectWithMessage('Já existe um arquivo com este novo nome.', 'error', $queryCarry);
        }

        if (@rename($oldPath, $newPath)) {
            redirectWithMessage("Arquivo renomeado para '{$newName}'.", 'success', $queryCarry);
        }

        redirectWithMessage('Não foi possível renomear o arquivo.', 'error', $queryCarry);
    }

    if ($action === 'bulk') {
        $selected = $_POST['selected'] ?? [];

        if (!is_array($selected) || count($selected) === 0) {
            redirectWithMessage('Selecione ao menos uma imagem para ação em massa.', 'error', $queryCarry);
        }

        $validFiles = [];
        foreach ($selected as $item) {
            $filename = (string) $item;
            if (!isSafeFilename($filename) || !hasImageExtension($filename, $allowedExtensions)) {
                continue;
            }
            if (is_file($currentDir . DIRECTORY_SEPARATOR . $filename)) {
                $validFiles[] = $filename;
            }
        }

        if (count($validFiles) === 0) {
            redirectWithMessage('Nenhum arquivo válido foi selecionado.', 'error', $queryCarry);
        }

        $bulkAction = (string) ($_POST['bulk_action'] ?? '');

        if ($bulkAction === 'delete') {
            $deleted = 0;
            foreach ($validFiles as $filename) {
                if (@unlink($currentDir . DIRECTORY_SEPARATOR . $filename)) {
                    $deleted++;
                }
            }
            redirectWithMessage("Exclusão em massa concluída: {$deleted}/" . count($validFiles) . ' arquivo(s).', 'success', $queryCarry);
        }

        if ($bulkAction === 'move_to_folder') {
            $folderName = trim((string) ($_POST['bulk_folder'] ?? ''));

            if (!isSafeFilename($folderName)) {
                redirectWithMessage('Nome de pasta inválido para mover arquivos.', 'error', $queryCarry);
            }

            $targetDir = $currentDir . DIRECTORY_SEPARATOR . $folderName;

            if (is_file($targetDir)) {
                redirectWithMessage('Já existe um arquivo com o nome da pasta de destino.', 'error', $queryCarry);
            }

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
                redirectWithMessage('Não foi possível criar a pasta de destino.', 'error', $queryCarry);
            }

            $moved = 0;
            $skipped = 0;

            foreach ($validFiles as $filename) {
                $sourcePath = $currentDir . DIRECTORY_SEPARATOR . $filename;
                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

                if (is_file($targetPath)) {
                    $skipped++;
                    continue;
                }

                if (@rename($sourcePath, $targetPath)) {
                    $moved++;
                } else {
                    $skipped++;
                }
            }

            redirectWithMessage("Movimento em massa concluído para '{$folderName}': {$moved} movido(s), {$skipped} ignorado(s).", 'success', $queryCarry);
        }

        if ($bulkAction === 'prefix' || $bulkAction === 'suffix') {
            $text = trim((string) ($_POST['bulk_text'] ?? ''));

            if ($text === '') {
                redirectWithMessage('Informe um texto para prefixo/sufixo.', 'error', $queryCarry);
            }

            if (str_contains($text, '/') || str_contains($text, '\\')) {
                redirectWithMessage('Prefixo/sufixo contém caracteres inválidos.', 'error', $queryCarry);
            }

            $renamed = 0;
            $skipped = 0;

            foreach ($validFiles as $oldName) {
                $extension = pathinfo($oldName, PATHINFO_EXTENSION);
                $baseName = pathinfo($oldName, PATHINFO_FILENAME);

                if ($bulkAction === 'prefix') {
                    $newBase = $text . $baseName;
                } else {
                    $newBase = $baseName . $text;
                }

                $newName = $newBase . ($extension !== '' ? '.' . $extension : '');

                if (!isSafeFilename($newName)) {
                    $skipped++;
                    continue;
                }

                $oldPath = $currentDir . DIRECTORY_SEPARATOR . $oldName;
                $newPath = $currentDir . DIRECTORY_SEPARATOR . $newName;

                if (is_file($newPath)) {
                    $skipped++;
                    continue;
                }

                if (@rename($oldPath, $newPath)) {
                    $renamed++;
                } else {
                    $skipped++;
                }
            }

            redirectWithMessage("Renomeação em massa concluída: {$renamed} renomeado(s), {$skipped} ignorado(s).", 'success', $queryCarry);
        }

        redirectWithMessage('Ação em massa inválida.', 'error', $queryCarry);
    }

    redirectWithMessage('Ação inválida.', 'error', $queryCarry);
}

if (!isAuthenticated()) {
    ?><!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e(GALLERY_TITLE) ?> - Login</title>
        <style>
            body { font-family: system-ui, sans-serif; background:#10131a; color:#ecf0f8; margin:0; min-height:100vh; display:grid; place-items:center; }
            .card { width:min(420px, 92vw); background:#1a2230; border:1px solid #2b364a; border-radius:14px; padding:20px; }
            h1 { margin-top:0; font-size:1.3rem; }
            label { display:block; margin-bottom:10px; color:#9fb0cc; font-size:0.92rem; }
            input { width:100%; padding:10px; border-radius:8px; border:1px solid #2b364a; background:#0f1723; color:#ecf0f8; margin-top:4px; }
            button { margin-top:8px; width:100%; padding:10px; border:0; border-radius:8px; background:#4e9cff; color:#fff; cursor:pointer; }
            .flash { margin-bottom:12px; padding:10px; border-radius:8px; background:rgba(255,92,111,0.2); border:1px solid rgba(255,92,111,0.6); }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Acesso restrito</h1>
            <?php if ($msg !== ''): ?>
                <div class="flash"><?= e($msg) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label>Usuário
                    <input type="text" name="login_user" required>
                </label>
                <label>Senha
                    <input type="password" name="login_pass" required>
                </label>
                <button type="submit">Entrar</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$images = getGalleryImages($currentDir, $allowedExtensions, $selfBasename);

if ($queryConfig['q'] !== '') {
    $needle = strtolower($queryConfig['q']);
    $images = array_values(array_filter($images, static function (array $image) use ($needle): bool {
        return strpos(strtolower($image['name']), $needle) !== false;
    }));
}

usort($images, static function (array $a, array $b) use ($queryConfig): int {
    switch ($queryConfig['sort']) {
        case 'name_desc':
            return strnatcasecmp($b['name'], $a['name']);
        case 'date_new':
            return $b['mtime'] <=> $a['mtime'];
        case 'date_old':
            return $a['mtime'] <=> $b['mtime'];
        case 'type_asc':
            $cmpType = strcmp($a['ext'], $b['ext']);
            return $cmpType !== 0 ? $cmpType : strnatcasecmp($a['name'], $b['name']);
        case 'type_desc':
            $cmpType = strcmp($b['ext'], $a['ext']);
            return $cmpType !== 0 ? $cmpType : strnatcasecmp($a['name'], $b['name']);
        case 'name_asc':
        default:
            return strnatcasecmp($a['name'], $b['name']);
    }
});

$totalImages = count($images);
$perPage = $queryConfig['per_page'];
$totalPages = max(1, (int) ceil($totalImages / $perPage));
$currentPage = min($queryConfig['page'], $totalPages);
$offset = ($currentPage - 1) * $perPage;
$pagedImages = array_slice($images, $offset, $perPage);

function buildPageUrl(int $page, int $cols, int $perPage, string $q, string $sort): string
{
    return '?' . http_build_query([
        'page' => $page,
        'cols' => $cols,
        'per_page' => $perPage,
        'q' => $q,
        'sort' => $sort,
    ]);
}

?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(GALLERY_TITLE) ?></title>
    <style>
        :root {
            --bg: #10131a;
            --card: #1a2230;
            --text: #ecf0f8;
            --muted: #9fb0cc;
            --accent: #4e9cff;
            --danger: #ff5c6f;
            --success: #28c57d;
            --border: #2b364a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
        }

        h1 {
            margin-top: 0;
            font-size: 1.5rem;
        }

        .topbar,
        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 16px;
        }

        .topbar form,
        .bulk-form-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        label {
            font-size: 0.92rem;
            color: var(--muted);
        }

        select,
        input[type="text"] {
            background: #0f1723;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 8px;
        }

        button {
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 8px 10px;
            cursor: pointer;
            color: #fff;
            background: var(--accent);
        }

        button.danger { background: var(--danger); }
        button.secondary {
            background: #3a4861;
            border-color: var(--border);
        }

        .flash {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
        }

        .flash.success {
            background: rgba(40, 197, 125, 0.18);
            border: 1px solid rgba(40, 197, 125, 0.6);
        }

        .flash.error {
            background: rgba(255, 92, 111, 0.18);
            border: 1px solid rgba(255, 92, 111, 0.6);
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(<?= (int) $queryConfig['cols'] ?>, minmax(0, 1fr));
            gap: 12px;
        }

        .item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .thumb-wrap {
            aspect-ratio: 4 / 3;
            background: #0d121c;
        }

        .thumb-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .item-body {
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filename {
            font-size: 0.85rem;
            word-break: break-all;
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .actions input[type="text"] {
            width: 100%;
        }

        .pagination {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text);
            text-decoration: none;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.9rem;
        }

        .muted {
            color: var(--muted);
            font-size: 0.9rem;
        }

        @media (max-width: 900px) {
            .gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 560px) {
            .gallery { grid-template-columns: repeat(1, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h1><?= e(GALLERY_TITLE) ?></h1>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="secondary">Sair</button>
        </form>
    </div>

    <div class="topbar">
        <form method="get">
            <label>
                Filtrar por nome
                <input type="text" name="q" value="<?= e($queryConfig['q']) ?>" placeholder="Ex.: ferias">
            </label>

            <label>
                Ordenar por
                <select name="sort">
                    <option value="name_asc" <?= $queryConfig['sort'] === 'name_asc' ? 'selected' : '' ?>>Nome (A-Z)</option>
                    <option value="name_desc" <?= $queryConfig['sort'] === 'name_desc' ? 'selected' : '' ?>>Nome (Z-A)</option>
                    <option value="date_new" <?= $queryConfig['sort'] === 'date_new' ? 'selected' : '' ?>>Data (mais nova)</option>
                    <option value="date_old" <?= $queryConfig['sort'] === 'date_old' ? 'selected' : '' ?>>Data (mais antiga)</option>
                    <option value="type_asc" <?= $queryConfig['sort'] === 'type_asc' ? 'selected' : '' ?>>Tipo (A-Z)</option>
                    <option value="type_desc" <?= $queryConfig['sort'] === 'type_desc' ? 'selected' : '' ?>>Tipo (Z-A)</option>
                </select>
            </label>

            <label>
                Imagens por linha
                <select name="cols">
                    <?php for ($i = MIN_IMAGES_PER_ROW; $i <= MAX_IMAGES_PER_ROW; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === (int) $queryConfig['cols'] ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>

            <label>
                Imagens por página
                <select name="per_page">
                    <?php foreach ([6, 12, 24, 36, 48, 72, 96, 120, 180, 200] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $opt === (int) $queryConfig['per_page'] ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <input type="hidden" name="page" value="1">
            <button type="submit">Aplicar</button>
            <span class="muted">Total de imagens: <?= $totalImages ?></span>
        </form>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="flash <?= $msgType === 'success' ? 'success' : 'error' ?>">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="post" id="bulk-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="bulk">

            <div class="bulk-form-controls">
                <label>
                    Ação em massa
                    <select name="bulk_action">
                        <option value="delete">Excluir</option>
                        <option value="prefix">Adicionar prefixo</option>
                        <option value="suffix">Adicionar sufixo</option>
                        <option value="move_to_folder">Mover para pasta</option>
                    </select>
                </label>

                <label>
                    Texto (prefixo/sufixo)
                    <input type="text" name="bulk_text" placeholder="Ex.: viagem_ ou _2026">
                </label>

                <label>
                    Pasta destino (mover)
                    <input type="text" name="bulk_folder" placeholder="Ex.: selecionadas_2026">
                </label>

                <button type="button" class="secondary" onclick="toggleSelectAll(true)">Selecionar tudo</button>
                <button type="button" class="secondary" onclick="toggleSelectAll(false)">Limpar seleção</button>
                <button type="submit">Executar em selecionados</button>
            </div>

        </form>

        <?php if ($totalImages === 0): ?>
            <p class="muted">Nenhuma imagem encontrada no diretório atual.</p>
        <?php else: ?>
            <div class="gallery">
                <?php foreach ($pagedImages as $image): ?>
                    <article class="item">
                        <div class="thumb-wrap">
                            <img src="<?= e(rawurlencode($image['name'])) ?>" alt="<?= e($image['name']) ?>" loading="lazy">
                        </div>
                        <div class="item-body">
                            <label>
                                <input type="checkbox" name="selected[]" value="<?= e($image['name']) ?>" class="select-item" form="bulk-form">
                                Selecionar
                            </label>

                            <div class="filename">
                                <?= e($image['name']) ?><br>
                                <small>Tipo: <?= e($image['ext']) ?> | Data: <?= date('Y-m-d H:i:s', (int) $image['mtime']) ?></small>
                            </div>

                            <div class="actions">
                                <form method="post" style="display:flex;flex-wrap:wrap;gap:6px;width:100%;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="rename_one">
                                    <input type="hidden" name="old_name" value="<?= e($image['name']) ?>">
                                    <input type="text" name="new_name" value="<?= e($image['name']) ?>" required>
                                    <button type="submit">Renomear</button>
                                </form>

                                <form method="post" onsubmit="return confirm('Excluir <?= e($image['name']) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_one">
                                    <input type="hidden" name="filename" value="<?= e($image['name']) ?>">
                                    <button type="submit" class="danger">Excluir</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="pagination">
        <?php if ($currentPage > 1): ?>
            <a href="<?= e(buildPageUrl($currentPage - 1, (int) $queryConfig['cols'], (int) $queryConfig['per_page'], (string) $queryConfig['q'], (string) $queryConfig['sort'])) ?>">&laquo; Anterior</a>
        <?php endif; ?>

        <span>Página <?= $currentPage ?> de <?= $totalPages ?></span>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= e(buildPageUrl($currentPage + 1, (int) $queryConfig['cols'], (int) $queryConfig['per_page'], (string) $queryConfig['q'], (string) $queryConfig['sort'])) ?>">Próxima &raquo;</a>
        <?php endif; ?>
    </div>

    <script>
        function toggleSelectAll(state) {
            const items = document.querySelectorAll('.select-item');
            items.forEach((item) => {
                item.checked = state;
            });
        }
    </script>
</body>
</html>
