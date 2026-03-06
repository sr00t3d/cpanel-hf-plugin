<?php

# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Installer Wrapper v1.0.0                                ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Call Install High Forensic on cPanel                           ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_to_ui_lang($value)
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        return '';
    }

    $normalized = str_replace('-', '_', $raw);
    if ($normalized === 'en' || strpos($normalized, 'en_') === 0 || strpos($normalized, 'english') === 0) {
        return 'en';
    }
    if ($normalized === 'pt' || strpos($normalized, 'pt_') === 0 || strpos($normalized, 'portugu') === 0) {
        return 'pt';
    }

    return '';
}

function detect_cpanel_account_lang($cpUser)
{
    $candidates = [];

    if (valid_cpanel_user($cpUser)) {
        $userConfig = '/var/cpanel/users/' . $cpUser;
        if (is_readable($userConfig)) {
            $lines = @file($userConfig, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || strpos($line, '=') === false) {
                        continue;
                    }
                    list($key, $value) = explode('=', $line, 2);
                    $key = strtoupper(trim((string) $key));
                    $value = trim((string) $value);
                    if (($key === 'LOCALE' || $key === 'LANG') && $value !== '') {
                        $candidates[] = $value;
                    }
                }
            }
        }

        foreach ([
            '/home/' . $cpUser . '/.cpanel/nvdata/lang',
            '/home/' . $cpUser . '/.cpanel/lang',
        ] as $path) {
            if (is_readable($path)) {
                $value = trim((string) @file_get_contents($path));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }
    }

    // Fallback only: runtime locale hints from cPanel context.
    foreach (['CPANEL_LOCALE', 'CPANEL_LANG'] as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
            $candidates[] = (string) $_SERVER[$key];
        }
        $envValue = getenv($key);
        if (is_string($envValue) && trim($envValue) !== '') {
            $candidates[] = (string) $envValue;
        }
    }

    foreach ($candidates as $candidate) {
        $lang = normalize_to_ui_lang($candidate);
        if ($lang === 'en' || $lang === 'pt') {
            return $lang;
        }
    }

    return '';
}

function detect_runtime_ui_lang()
{
    $candidates = [];

    foreach (['CPANEL_LOCALE', 'CPANEL_LANG'] as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
            $candidates[] = (string) $_SERVER[$key];
        }
        $envValue = getenv($key);
        if (is_string($envValue) && trim($envValue) !== '') {
            $candidates[] = (string) $envValue;
        }
    }

    foreach ($candidates as $candidate) {
        $lang = normalize_to_ui_lang($candidate);
        if ($lang === 'en' || $lang === 'pt') {
            return $lang;
        }
    }

    return '';
}

function detect_ui_lang($cpUser = '')
{
    if (isset($_GET['lang']) && is_string($_GET['lang'])) {
        $requested = normalize_to_ui_lang((string) $_GET['lang']);
        if ($requested === 'en' || $requested === 'pt') {
            return $requested;
        }
    }

    $accountLang = detect_cpanel_account_lang($cpUser);
    if ($accountLang === 'en' || $accountLang === 'pt') {
        return $accountLang;
    }

    $runtimeLang = detect_runtime_ui_lang();
    if ($runtimeLang === 'en' || $runtimeLang === 'pt') {
        return $runtimeLang;
    }

    return 'pt';
}

function t($pt, $en)
{
    global $uiLang;
    return $uiLang === 'en' ? $en : $pt;
}

function ensure_session_started()
{
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (!function_exists('session_start')) {
        return false;
    }
    return @session_start();
}

function generate_csrf_token_value()
{
    if (function_exists('random_bytes')) {
        try {
            $bytes = random_bytes(32);
            if (is_string($bytes) && strlen($bytes) === 32) {
                return bin2hex($bytes);
            }
        } catch (Exception $e) {
            // fallback below
        }
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = @openssl_random_pseudo_bytes(32);
        if (is_string($bytes) && strlen($bytes) === 32) {
            return bin2hex($bytes);
        }
    }

    return hash('sha256', uniqid('hforensic', true) . mt_rand() . microtime(true));
}

function csrf_token_get()
{
    ensure_session_started();

    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return '';
    }

    if (!isset($_SESSION['hforensic_csrf']) || !is_string($_SESSION['hforensic_csrf']) || strlen($_SESSION['hforensic_csrf']) < 32) {
        $_SESSION['hforensic_csrf'] = generate_csrf_token_value();
    }

    return (string) $_SESSION['hforensic_csrf'];
}

function request_csrf_token()
{
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return (string) $_POST['csrf_token'];
    }
    if (isset($_SERVER['HTTP_X_HF_CSRF']) && is_string($_SERVER['HTTP_X_HF_CSRF'])) {
        return (string) $_SERVER['HTTP_X_HF_CSRF'];
    }
    return '';
}

function csrf_token_valid($token)
{
    $sessionToken = csrf_token_get();
    if ($sessionToken === '' || $token === '') {
        return false;
    }
    return function_exists('hash_equals') ? hash_equals($sessionToken, $token) : $sessionToken === $token;
}

function find_binary($candidates)
{
    foreach ((array) $candidates as $path) {
        if (is_string($path) && $path !== '' && is_executable($path)) {
            return $path;
        }
    }
    return '';
}

function to_array($value)
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = to_array($item);
        }
        return $out;
    }

    if (is_object($value)) {
        return to_array(get_object_vars($value));
    }

    return $value;
}

function valid_cpanel_user($value)
{
    return is_string($value) && preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9._-]{0,31}$/', $value);
}

function detect_cpanel_user()
{
    $candidates = [
        isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '',
        getenv('REMOTE_USER') ?: '',
        isset($_SERVER['CPANEL_USER']) ? $_SERVER['CPANEL_USER'] : '',
        getenv('CPANEL_USER') ?: '',
    ];

    foreach ($candidates as $candidate) {
        if (valid_cpanel_user($candidate)) {
            return $candidate;
        }
    }

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['name']) && valid_cpanel_user($pw['name'])) {
            return (string) $pw['name'];
        }
    }

    return '';
}

function sanitize_relative_dir($rawDir)
{
    $dir = trim((string) $rawDir);
    $dir = str_replace('\\', '/', $dir);
    $dir = preg_replace('#/+#', '/', $dir);

    if ($dir === '' || $dir === '.' || $dir === '/') {
        return '';
    }

    $dir = ltrim($dir, '/');
    $parts = explode('/', $dir);
    $clean = [];

    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return false;
        }
        $clean[] = $part;
    }

    return implode('/', $clean);
}

function dir_parent($dir)
{
    $dir = trim((string) $dir, '/');
    if ($dir === '') {
        return '';
    }
    $parent = dirname($dir);
    if ($parent === '.' || $parent === '/') {
        return '';
    }
    return trim($parent, '/');
}

function join_path($left, $right)
{
    $left = rtrim((string) $left, '/');
    $right = ltrim((string) $right, '/');

    if ($left === '') {
        return '/' . $right;
    }
    if ($right === '') {
        return $left;
    }

    return $left . '/' . $right;
}

function normalize_file_input($rawPath, $homeDir)
{
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }

    if (substr($path, 0, 2) === '~/') {
        return $homeDir . substr($path, 1);
    }

    if ($path[0] !== '/') {
        return join_path($homeDir, $path);
    }

    return $path;
}

function human_size($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes < 1024) {
        return (string) ((int) $bytes) . ' B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return number_format($bytes, 1, '.', '') . ' ' . $units[$i];
}

function row_name($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (['file', 'filename', 'name', 'basename'] as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string) $row[$key];
        }
    }
    return '';
}

function row_is_dir($row)
{
    if (!is_array($row)) {
        return false;
    }

    if (isset($row['isdir'])) {
        return (int) $row['isdir'] === 1;
    }

    if (isset($row['type'])) {
        $type = strtolower((string) $row['type']);
        return $type === 'dir' || $type === 'directory' || $type === 'folder';
    }

    if (isset($row['mime_type'])) {
        return strtolower((string) $row['mime_type']) === 'inode/directory';
    }

    return false;
}

function row_size($row)
{
    if (!is_array($row)) {
        return '-';
    }

    if (isset($row['human_size']) && $row['human_size'] !== '') {
        return (string) $row['human_size'];
    }

    if (isset($row['size']) && $row['size'] !== '') {
        return (string) $row['size'];
    }

    return '-';
}

function row_mime($row)
{
    if (!is_array($row)) {
        return '-';
    }

    if (isset($row['mime_type']) && $row['mime_type'] !== '') {
        return (string) $row['mime_type'];
    }

    if (isset($row['mimetype']) && $row['mimetype'] !== '') {
        return (string) $row['mimetype'];
    }

    return '-';
}

function extract_uapi_result($raw)
{
    $raw = to_array($raw);

    $result = [];
    if (isset($raw['result']) && is_array($raw['result'])) {
        $result = $raw['result'];
    } elseif (isset($raw['cpanelresult']) && is_array($raw['cpanelresult'])) {
        $result = $raw['cpanelresult'];
    } else {
        $result = $raw;
    }

    $status = isset($result['status']) ? (int) $result['status'] : (isset($raw['status']) ? (int) $raw['status'] : 0);
    $data = isset($result['data']) ? $result['data'] : (isset($raw['data']) ? $raw['data'] : []);

    $errors = [];
    if (isset($result['errors']) && is_array($result['errors'])) {
        $errors = $result['errors'];
    } elseif (isset($raw['errors']) && is_array($raw['errors'])) {
        $errors = $raw['errors'];
    }

    return [$status === 1, to_array($data), $errors, $raw];
}

function extract_api_errors($raw)
{
    $raw = to_array($raw);
    $errors = [];

    $collect = function ($value) use (&$errors) {
        if (is_array($value)) {
            foreach ($value as $line) {
                if (is_scalar($line)) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $errors[] = $line;
                    }
                }
            }
        } elseif (is_scalar($value)) {
            $line = trim((string) $value);
            if ($line !== '') {
                $errors[] = $line;
            }
        }
    };

    $paths = [
        ['errors'],
        ['error'],
        ['messages'],
        ['warnings'],
        ['statusmsg'],
        ['result', 'errors'],
        ['result', 'messages'],
        ['result', 'warnings'],
        ['result', 'statusmsg'],
        ['cpanelresult', 'error'],
        ['cpanelresult', 'errors'],
        ['cpanelresult', 'event', 'result'],
        ['cpanelresult', 'data'],
    ];

    foreach ($paths as $path) {
        $node = $raw;
        $ok = true;
        foreach ($path as $key) {
            if (is_array($node) && array_key_exists($key, $node)) {
                $node = $node[$key];
            } else {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $collect($node);
        }
    }

    return array_values(array_unique($errors));
}

function extract_file_rows($data)
{
    $data = to_array($data);

    if (isset($data['files']) && is_array($data['files'])) {
        return $data['files'];
    }

    if (isset($data['contents']) && is_array($data['contents'])) {
        return $data['contents'];
    }

    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }

    $rows = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            if (isset($item['file']) || isset($item['filename']) || isset($item['name'])) {
                $rows[] = $item;
            }
        }
    }

    return $rows;
}

function list_local_files($homeDir, $browseDir, &$errorsOut)
{
    $errorsOut = [];
    if ($homeDir === '' || !is_dir($homeDir)) {
        $errorsOut[] = 'Home da conta não encontrada para listagem local.';
        return [];
    }

    $homeReal = realpath($homeDir);
    if ($homeReal === false) {
        $errorsOut[] = 'Não foi possível resolver a home da conta.';
        return [];
    }

    $target = $browseDir === '' ? $homeReal : join_path($homeReal, $browseDir);
    $targetReal = realpath($target);
    if ($targetReal === false || !is_dir($targetReal)) {
        $errorsOut[] = 'Diretório não encontrado: ' . $browseDir;
        return [];
    }

    if ($targetReal !== $homeReal && strpos($targetReal, $homeReal . '/') !== 0) {
        $errorsOut[] = 'Diretorio fora do escopo da conta.';
        return [];
    }

    $items = @scandir($targetReal);
    if (!is_array($items)) {
        $errorsOut[] = 'Falha ao ler o diretório.';
        return [];
    }

    $rows = [];
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $targetReal . '/' . $item;
        $isDir = is_dir($fullPath);

        $size = '-';
        if (!$isDir) {
            $statSize = @filesize($fullPath);
            if ($statSize !== false) {
                $size = human_size($statSize);
            }
        }

        $mime = '-';
        if ($isDir) {
            $mime = 'inode/directory';
        } elseif ($finfo) {
            $mimeVal = @finfo_file($finfo, $fullPath);
            if (is_string($mimeVal) && $mimeVal !== '') {
                $mime = $mimeVal;
            }
        } elseif (function_exists('mime_content_type')) {
            $mimeVal = @mime_content_type($fullPath);
            if (is_string($mimeVal) && $mimeVal !== '') {
                $mime = $mimeVal;
            }
        }

        $rows[] = [
            'file' => $item,
            'isdir' => $isDir ? 1 : 0,
            'human_size' => $size,
            'mime_type' => $mime,
        ];
    }

    if ($finfo) {
        @finfo_close($finfo);
    }

    return $rows;
}

function sort_rows(&$rows)
{
    usort($rows, function ($a, $b) {
        $aDir = row_is_dir($a);
        $bDir = row_is_dir($b);
        if ($aDir && !$bDir) {
            return -1;
        }
        if (!$aDir && $bDir) {
            return 1;
        }
        return strcasecmp(row_name($a), row_name($b));
    });
}

function list_rows($cpanel, $liveApiAvailable, $homeDir, $browseDir)
{
    $errors = [];
    $notices = [];
    $mode = '';
    $rows = [];

    if ($liveApiAvailable && $cpanel && method_exists($cpanel, 'uapi')) {
        $apiDir = $browseDir === '' ? '.' : $browseDir;
        $raw = $cpanel->uapi(
            'Fileman',
            'list_files',
            [
                'dir' => $apiDir,
                'show_hidden' => 0,
                'types' => 'file|dir',
            ]
        );

        list($ok, $data, $apiErrors) = extract_uapi_result($raw);
        if ($ok) {
            $rows = extract_file_rows($data);
            $mode = 'uapi';
        } else {
            $apiErrorList = extract_api_errors($raw);
            if ($apiErrors) {
                foreach ($apiErrors as $line) {
                    $apiErrorList[] = (string) $line;
                }
            }

            $localErrors = [];
            $rows = list_local_files($homeDir, $browseDir, $localErrors);
            if (!$localErrors) {
                $mode = 'local';
            } else {
                $errors[] = 'Não foi possível listar arquivos via UAPI Fileman::list_files.';
                foreach (array_values(array_unique($apiErrorList)) as $line) {
                    $errors[] = (string) $line;
                }
                foreach ($localErrors as $line) {
                    $errors[] = (string) $line;
                }
            }
        }
    } else {
        $localErrors = [];
        $rows = list_local_files($homeDir, $browseDir, $localErrors);
        if (!$localErrors) {
            $mode = 'local';
        } else {
            $errors[] = 'LiveAPI indisponível neste contexto. Verifique se o arquivo .live.php está no frontend Jupiter e aberto via cPanel.';
            foreach ($localErrors as $line) {
                $errors[] = (string) $line;
            }
        }
    }

    if (count($rows) > 400) {
        $rows = array_slice($rows, 0, 400);
        $notices[] = 'Mostrando os primeiros 400 itens do diretório.';
    }

    sort_rows($rows);
    return [$rows, $mode, $errors, $notices];
}

function rows_to_items($rows, $browseDir, $homeDir)
{
    $items = [];
    foreach ($rows as $row) {
        $name = row_name($row);
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }

        $isDir = row_is_dir($row);
        $relative = ($browseDir !== '' ? $browseDir . '/' : '') . $name;
        $nextDir = sanitize_relative_dir($relative);
        if ($nextDir === false) {
            $nextDir = $browseDir;
        }

        $items[] = [
            'name' => $name,
            'is_dir' => $isDir,
            'size' => row_size($row),
            'mime' => row_mime($row),
            'next_dir' => $isDir ? $nextDir : '',
            'file_path' => $isDir ? '' : join_path($homeDir, $relative),
        ];
    }
    return $items;
}

function ensure_rate_limit_dir($cpUser, &$errorOut = '')
{
    $errorOut = '';
    if (!valid_cpanel_user($cpUser)) {
        $errorOut = t('Usuário cPanel inválido.', 'Invalid cPanel user.');
        return '';
    }

    $homeDir = '/home/' . $cpUser;
    if (!is_dir($homeDir) || is_link($homeDir)) {
        $errorOut = t('Diretório HOME da conta é inválido.', 'Account HOME directory is invalid.');
        return '';
    }

    $metaDir = $homeDir . '/.hforensic';
    $stateDir = $metaDir . '/state';

    if (is_link($metaDir) || is_link($stateDir)) {
        $errorOut = t('Falha de segurança no armazenamento de limite de atualização.', 'Security validation failed for refresh rate-limit storage.');
        return '';
    }

    if (!is_dir($metaDir) && !@mkdir($metaDir, 0700, true)) {
        $errorOut = t('Falha ao preparar diretório interno do High Forensic.', 'Failed to prepare internal High Forensic directory.');
        return '';
    }
    @chmod($metaDir, 0700);

    if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true)) {
        $errorOut = t('Falha ao preparar armazenamento de limite de atualização.', 'Failed to prepare refresh rate-limit storage.');
        return '';
    }
    @chmod($stateDir, 0700);

    if (!is_dir($stateDir)) {
        $errorOut = t('Falha ao preparar armazenamento de limite de atualização.', 'Failed to prepare refresh rate-limit storage.');
        return '';
    }

    return $stateDir;
}

function enforce_action_rate_limit($cpUser, $action, $intervalSeconds, &$retryAfter = 0, &$errorOut = '')
{
    $retryAfter = 0;
    $errorOut = '';

    $minInterval = (int) $intervalSeconds;
    if ($minInterval < 1) {
        return true;
    }

    $stateDir = ensure_rate_limit_dir($cpUser, $errorOut);
    if ($stateDir === '') {
        return false;
    }

    $actionKey = strtolower((string) $action);
    $actionKey = preg_replace('/[^a-z0-9_-]+/i', '_', $actionKey);
    $actionKey = trim((string) $actionKey, '_');
    if ($actionKey === '') {
        $actionKey = 'default';
    }

    $stampPath = $stateDir . '/rl_' . $actionKey . '.stamp';
    if (is_link($stampPath)) {
        $errorOut = t('Falha de segurança no armazenamento de limite de atualização.', 'Security validation failed for refresh rate-limit storage.');
        return false;
    }

    $handle = @fopen($stampPath, 'c+');
    if (!is_resource($handle)) {
        $errorOut = t('Falha ao validar limite de atualização.', 'Failed to validate refresh rate limit.');
        return false;
    }

    if (function_exists('flock') && !@flock($handle, LOCK_EX)) {
        @fclose($handle);
        $errorOut = t('Falha ao validar limite de atualização.', 'Failed to validate refresh rate limit.');
        return false;
    }

    $lastRaw = stream_get_contents($handle);
    $lastEpoch = 0;
    if (is_string($lastRaw)) {
        $lastRaw = trim($lastRaw);
        if ($lastRaw !== '' && preg_match('/^[0-9]+$/', $lastRaw)) {
            $lastEpoch = (int) $lastRaw;
        }
    }

    $now = time();
    if ($lastEpoch > 0) {
        $elapsed = $now - $lastEpoch;
        if ($elapsed < $minInterval) {
            $retryAfter = $minInterval - max(0, $elapsed);
            if (function_exists('flock')) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
            return false;
        }
    }

    @rewind($handle);
    if (!@ftruncate($handle, 0)) {
        if (function_exists('flock')) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        $errorOut = t('Falha ao validar limite de atualização.', 'Failed to validate refresh rate limit.');
        return false;
    }

    if (@fwrite($handle, (string) $now) === false) {
        if (function_exists('flock')) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        $errorOut = t('Falha ao validar limite de atualização.', 'Failed to validate refresh rate limit.');
        return false;
    }

    @fflush($handle);
    @chmod($stampPath, 0600);

    if (function_exists('flock')) {
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);

    return true;
}

function run_log_refresh_for_user($cpUser)
{
    if (!valid_cpanel_user($cpUser)) {
        return [false, t('Usuário cPanel inválido.', 'Invalid cPanel user.')];
    }

    $helper = '/usr/local/bin/hf-runweblogs-safe';
    // Soft fallback: when helper is unavailable, skip refresh without noisy UI errors.
    if (!is_executable($helper) || is_link($helper)) {
        return [true, t('Sincronização de logs concluída.', 'Log synchronization completed.')];
    }

    $sudoBinary = find_binary(['/usr/bin/sudo', '/bin/sudo']);
    if ($sudoBinary === '') {
        return [true, t('Sincronização de logs concluída.', 'Log synchronization completed.')];
    }

    $command = escapeshellarg($sudoBinary) . ' -n ' . escapeshellarg($helper) . ' ' . escapeshellarg($cpUser) . ' 2>&1';

    $timeoutBinary = find_binary(['/usr/bin/timeout', '/bin/timeout']);
    if ($timeoutBinary !== '') {
        $command = escapeshellarg($timeoutBinary) . ' 180s ' . $command;
    }

    $lines = [];
    $status = 1;
    exec($command, $lines, $status);
    $output = trim(implode("\n", $lines));

    if ($status === 0) {
        return [true, $output !== '' ? $output : t('Logs atualizados com sucesso.', 'Logs refreshed successfully.')];
    }

    if ($status === 124) {
        return [false, t('Tempo limite ao atualizar logs via runweblogs (180s).', 'Timeout while refreshing logs via runweblogs (180s).')];
    }

    return [false, $output !== '' ? $output : t('Falha ao atualizar logs via runweblogs.', 'Failed to refresh logs via runweblogs.')];
}

function run_audit($cpUser, $runnerScript, $rawPath, $homeDir, $uiLang = '')
{
    $errors = [];
    if (!valid_cpanel_user($cpUser)) {
        $errors[] = 'Usuário cPanel inválido.';
    }

    if (!is_file($runnerScript) || !is_executable($runnerScript)) {
        $errors[] = 'Runner do plugin não encontrado ou sem permissão de execução.';
    }
    if (is_link($runnerScript)) {
        $errors[] = 'Runner do plugin inválido (symlink).';
    }

    $inputPath = (string) $rawPath;
    if (strlen($inputPath) > 4096) {
        $errors[] = 'Caminho muito longo.';
    }

    if (strpos($inputPath, "\0") !== false) {
        $errors[] = 'Caminho inválido.';
    }

    $normalizedPath = normalize_file_input($inputPath, $homeDir);
    if ($normalizedPath === '') {
        $errors[] = 'Informe o caminho do arquivo.';
    }
    if (!$errors && is_link($normalizedPath)) {
        $errors[] = 'Auditoria de symlink não permitida.';
    }

    if ($errors) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'output' => '',
            'message' => implode(' ', $errors),
            'file_path' => $normalizedPath,
            'errors' => $errors,
        ];
    }

    $command = escapeshellarg($runnerScript) . ' ' . escapeshellarg($cpUser) . ' ' . escapeshellarg($normalizedPath) . ' 2>&1';

    $uiLangNormalized = normalize_to_ui_lang($uiLang);
    if ($uiLangNormalized !== '') {
        $envBinary = find_binary(['/usr/bin/env', '/bin/env']);
        if ($envBinary !== '') {
            $command = escapeshellarg($envBinary) . ' HF_UI_LANG=' . escapeshellarg($uiLangNormalized) . ' ' . $command;
        } else {
            $command = 'HF_UI_LANG=' . escapeshellarg($uiLangNormalized) . ' ' . $command;
        }
    }

    $timeoutBinary = find_binary(['/usr/bin/timeout', '/bin/timeout']);
    if ($timeoutBinary !== '') {
        $command = escapeshellarg($timeoutBinary) . ' 60s ' . $command;
    }

    $lines = [];
    $status = 1;
    exec($command, $lines, $status);
    $auditOutput = trim(implode("\n", $lines));

    if ($status === 124) {
        return [
            'ok' => false,
            'exit_code' => 124,
            'output' => $auditOutput,
            'message' => 'Timeout ao executar auditoria (60s).',
            'file_path' => $normalizedPath,
            'errors' => ['Timeout ao executar auditoria (60s).'],
        ];
    }

    if ($status !== 0 && $auditOutput === '') {
        return [
            'ok' => false,
            'exit_code' => $status,
            'output' => '',
            'message' => 'Falha ao executar auditoria.',
            'file_path' => $normalizedPath,
            'errors' => ['Falha ao executar auditoria.'],
        ];
    }

    return [
        'ok' => $status === 0,
        'exit_code' => $status,
        'output' => $auditOutput,
        'message' => $status === 0 ? 'Auditoria concluida com sucesso.' : 'Auditoria executada com alertas.',
        'file_path' => $normalizedPath,
        'errors' => $status === 0 ? [] : ['Auditoria retornou status ' . $status . '.'],
    ];
}

function delete_audit_file($cpUser, $rawPath, $homeDir)
{
    $errors = [];
    if (!valid_cpanel_user($cpUser)) {
        $errors[] = 'Usuário cPanel inválido.';
    }

    $inputPath = (string) $rawPath;
    if (strlen($inputPath) > 4096) {
        $errors[] = 'Caminho muito longo.';
    }

    if (strpos($inputPath, "\0") !== false) {
        $errors[] = 'Caminho inválido.';
    }

    $normalizedPath = normalize_file_input($inputPath, $homeDir);
    if ($normalizedPath === '') {
        $errors[] = 'Informe o caminho do arquivo.';
    }
    if (!$errors && is_link($normalizedPath)) {
        $errors[] = 'Exclusão de symlink não permitida.';
    }

    $resolvedPath = '';
    if (!$errors) {
        $resolvedPath = realpath($normalizedPath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            $errors[] = 'Arquivo não encontrado.';
        }
    }

    if (!$errors) {
        if (!path_within_home($cpUser, $resolvedPath)) {
            $errors[] = 'Arquivo fora do escopo da conta.';
        }
    }

    if ($errors) {
        return [
            'ok' => false,
            'message' => implode(' ', $errors),
            'file_path' => $normalizedPath,
            'errors' => $errors,
        ];
    }

    if (!@unlink($resolvedPath)) {
        $lastError = error_get_last();
        $reason = is_array($lastError) && isset($lastError['message']) ? (string) $lastError['message'] : 'Falha ao excluir arquivo.';
        return [
            'ok' => false,
            'message' => 'Falha ao excluir arquivo.',
            'file_path' => $resolvedPath,
            'errors' => [$reason],
        ];
    }

    // If the deleted file was a quarantined copy, remove stale record from index.
    list($record, $recordKey, $entries, $indexPath) = find_quarantine_record($cpUser, '', $resolvedPath);
    if ($recordKey !== '' && isset($entries[$recordKey])) {
        unset($entries[$recordKey]);
        save_quarantine_index($indexPath, $entries);
    }

    return [
        'ok' => true,
        'message' => 'Arquivo excluido com sucesso.',
        'file_path' => $resolvedPath,
        'errors' => [],
    ];
}

function path_starts_with($path, $prefix)
{
    $path = (string) $path;
    $prefix = rtrim((string) $prefix, '/');
    if ($path === '' || $prefix === '') {
        return false;
    }
    return $path === $prefix || strpos($path, $prefix . '/') === 0;
}

function path_has_traversal($path)
{
    $path = str_replace('\\', '/', (string) $path);
    if ($path === '' || strpos($path, "\0") !== false) {
        return true;
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
        return true;
    }
    if (preg_match('#(^|/)\.(/|$)#', $path)) {
        return true;
    }
    return false;
}

function path_within_home($cpUser, $path)
{
    if (!valid_cpanel_user($cpUser)) {
        return false;
    }
    if (path_has_traversal($path)) {
        return false;
    }
    $homePrefix = '/home/' . $cpUser;
    return path_starts_with((string) $path, $homePrefix);
}

function path_within_quarantine($cpUser, $path)
{
    $path = (string) $path;
    if ($path === '' || path_has_traversal($path) || !path_within_home($cpUser, $path)) {
        return false;
    }

    $qDir = quarantine_dir_path($cpUser);
    if (!path_starts_with($path, $qDir)) {
        return false;
    }

    $realQDir = realpath($qDir);
    $realPath = realpath($path);
    if ($realQDir !== false && $realPath !== false) {
        if (!path_starts_with($realPath, $realQDir)) {
            return false;
        }
    }

    return true;
}

function quarantine_hmac_key($cpUser)
{
    if (!valid_cpanel_user($cpUser)) {
        return '';
    }

    $userConfig = '/var/cpanel/users/' . $cpUser;
    if (!is_readable($userConfig)) {
        return '';
    }

    $content = @file_get_contents($userConfig);
    if (!is_string($content) || $content === '') {
        return '';
    }

    return hash('sha256', 'hforensic-quarantine-v1|' . $cpUser . '|' . hash('sha256', $content));
}

function quarantine_signature_payload($entry)
{
    $payload = [
        'user' => isset($entry['user']) ? (string) $entry['user'] : '',
        'original_path' => isset($entry['original_path']) ? (string) $entry['original_path'] : '',
        'quarantine_path' => isset($entry['quarantine_path']) ? (string) $entry['quarantine_path'] : '',
        'file_name' => isset($entry['file_name']) ? (string) $entry['file_name'] : '',
        'quarantined_at' => isset($entry['quarantined_at']) ? (string) $entry['quarantined_at'] : '',
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function quarantine_signature($entry, $key)
{
    $payload = quarantine_signature_payload($entry);
    if (!is_string($payload) || $payload === '' || (string) $key === '') {
        return '';
    }
    return hash_hmac('sha256', $payload, (string) $key);
}

function quarantine_entry_valid($cpUser, $entry, &$normalizedEntry = null)
{
    if (!is_array($entry)) {
        return false;
    }

    $entryUser = isset($entry['user']) ? (string) $entry['user'] : '';
    $entryOriginal = isset($entry['original_path']) ? (string) $entry['original_path'] : '';
    $entryQPath = isset($entry['quarantine_path']) ? (string) $entry['quarantine_path'] : '';
    $entryFile = isset($entry['file_name']) ? (string) $entry['file_name'] : basename($entryOriginal);
    $entryAt = isset($entry['quarantined_at']) ? (string) $entry['quarantined_at'] : '';

    if ($entryUser !== $cpUser || $entryOriginal === '' || $entryQPath === '') {
        return false;
    }
    if (!path_within_home($cpUser, $entryOriginal)) {
        return false;
    }
    if (!path_within_quarantine($cpUser, $entryQPath)) {
        return false;
    }
    if ($entryOriginal === $entryQPath) {
        return false;
    }

    $normalizedEntry = [
        'user' => $entryUser,
        'original_path' => $entryOriginal,
        'quarantine_path' => $entryQPath,
        'file_name' => $entryFile,
        'quarantined_at' => $entryAt,
    ];

    $key = quarantine_hmac_key($cpUser);
    if ($key !== '') {
        $sig = isset($entry['sig']) ? (string) $entry['sig'] : '';
        if ($sig === '') {
            return false;
        }
        $expected = quarantine_signature($normalizedEntry, $key);
        $isValidSig = function_exists('hash_equals') ? hash_equals($expected, $sig) : $expected === $sig;
        if (!$isValidSig) {
            return false;
        }
        $normalizedEntry['sig'] = $sig;
    } elseif (isset($entry['sig']) && is_string($entry['sig']) && $entry['sig'] !== '') {
        $normalizedEntry['sig'] = (string) $entry['sig'];
    }

    return true;
}

function quarantine_meta_dirs($cpUser)
{
    $baseDir = '/home/' . $cpUser;
    return [$baseDir . '/.hforensic'];
}

function quarantine_meta_dir($cpUser)
{
    $dirs = quarantine_meta_dirs($cpUser);
    return $dirs[0];
}

function quarantine_dir_path($cpUser, $metaDir = '')
{
    $baseDir = $metaDir !== '' ? $metaDir : quarantine_meta_dir($cpUser);
    return rtrim($baseDir, '/') . '/quarantine';
}

function quarantine_index_path($cpUser, $metaDir = '')
{
    $baseDir = $metaDir !== '' ? $metaDir : quarantine_meta_dir($cpUser);
    return rtrim($baseDir, '/') . '/quarantine_index.json';
}

function load_quarantine_index($indexPath)
{
    if (!is_readable($indexPath)) {
        return [];
    }

    $raw = @file_get_contents($indexPath);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $entries = [];
    foreach ($decoded as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entries[(string) $key] = $entry;
    }

    return $entries;
}

function save_quarantine_index($indexPath, $entries)
{
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $ok = @file_put_contents($indexPath, $json, LOCK_EX) !== false;
    if ($ok) {
        @chmod($indexPath, 0600);
    }
    return $ok;
}

function ensure_quarantine_store($cpUser, &$errorsOut)
{
    $errorsOut = [];
    $metaDir = quarantine_meta_dir($cpUser);
    $qDir = quarantine_dir_path($cpUser);
    $indexPath = quarantine_index_path($cpUser);

    if (is_link($metaDir) || is_link($qDir)) {
        $errorsOut[] = 'Diretório de quarentena inválido (symlink).';
        return ['', ''];
    }

    if (!is_dir($metaDir) && !@mkdir($metaDir, 0700, true)) {
        $errorsOut[] = 'Falha ao criar diretório de metadados da quarentena.';
        return ['', ''];
    }
    @chmod($metaDir, 0700);

    if (!is_dir($qDir) && !@mkdir($qDir, 0700, true)) {
        $errorsOut[] = 'Falha ao criar diretório da quarentena.';
        return ['', ''];
    }
    @chmod($qDir, 0700);

    if (!is_dir($metaDir) || !is_dir($qDir)) {
        $errorsOut[] = 'Diretório de quarentena indisponível.';
        return ['', ''];
    }

    return [$qDir, $indexPath];
}

function quarantine_record_key($record)
{
    $quarantinePath = '';
    $originalPath = '';

    if (is_array($record)) {
        if (isset($record['quarantine_path'])) {
            $quarantinePath = (string) $record['quarantine_path'];
        }
        if (isset($record['original_path'])) {
            $originalPath = (string) $record['original_path'];
        }
    }

    return hash('sha256', $quarantinePath . '|' . $originalPath);
}

function find_quarantine_record($cpUser, $originalPath, $preferredQuarantinePath = '')
{
    $defaultIndexPath = quarantine_index_path($cpUser);
    $originalPath = (string) $originalPath;
    $preferredQuarantinePath = (string) $preferredQuarantinePath;

    if ($originalPath === '' && $preferredQuarantinePath === '') {
        return [null, '', [], $defaultIndexPath];
    }

    $bestKey = '';
    $best = null;
    $bestTime = 0;
    $bestEntries = [];
    $bestIndexPath = $defaultIndexPath;

    foreach (quarantine_meta_dirs($cpUser) as $metaDir) {
        $indexPath = quarantine_index_path($cpUser, $metaDir);
        $entries = load_quarantine_index($indexPath);
        if (!$entries) {
            continue;
        }

        foreach ($entries as $key => $entry) {
            $safeEntry = null;
            if (!quarantine_entry_valid($cpUser, $entry, $safeEntry)) {
                continue;
            }

            $entryOriginal = (string) $safeEntry['original_path'];
            $entryQPath = (string) $safeEntry['quarantine_path'];

            if ($preferredQuarantinePath !== '') {
                // When preferred path is provided, only exact quarantine path matches are valid.
                if ($entryQPath !== $preferredQuarantinePath) {
                    continue;
                }
                if ($originalPath !== '' && $entryOriginal !== $originalPath) {
                    continue;
                }
                return [$safeEntry, (string) $key, $entries, $indexPath];
            }

            if ($originalPath !== '' && $entryOriginal !== $originalPath) {
                continue;
            }

            $entryTime = 0;
            if (isset($safeEntry['quarantined_at'])) {
                $entryTime = (int) strtotime((string) $safeEntry['quarantined_at']);
            }

            if ($best === null || $entryTime >= $bestTime) {
                $best = $safeEntry;
                $bestKey = (string) $key;
                $bestTime = $entryTime;
                $bestEntries = $entries;
                $bestIndexPath = $indexPath;
            }
        }
    }

    return [$best, $bestKey, $bestEntries, $bestIndexPath];
}

function quarantine_audit_file($cpUser, $rawPath, $homeDir)
{
    $errors = [];
    if (!valid_cpanel_user($cpUser)) {
        $errors[] = 'Usuário cPanel inválido.';
    }

    $inputPath = (string) $rawPath;
    if (strlen($inputPath) > 4096) {
        $errors[] = 'Caminho muito longo.';
    }
    if (strpos($inputPath, "\0") !== false) {
        $errors[] = 'Caminho inválido.';
    }

    $normalizedPath = normalize_file_input($inputPath, $homeDir);
    if ($normalizedPath === '') {
        $errors[] = 'Informe o caminho do arquivo.';
    }
    if (!$errors && is_link($normalizedPath)) {
        $errors[] = 'Quarentena de symlink não permitida.';
    }

    $resolvedPath = '';
    if (!$errors) {
        $resolvedPath = realpath($normalizedPath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            $errors[] = 'Arquivo não encontrado.';
        }
    }

    if (!$errors) {
        if (!path_within_home($cpUser, $resolvedPath)) {
            $errors[] = 'Arquivo fora do escopo da conta.';
        }
    }

    if (!$errors && path_within_quarantine($cpUser, $resolvedPath)) {
        $errors[] = 'Arquivo já está dentro da quarentena.';
    }

    $storeErrors = [];
    list($qDir, $indexPath) = ensure_quarantine_store($cpUser, $storeErrors);
    if ($storeErrors) {
        foreach ($storeErrors as $line) {
            $errors[] = $line;
        }
    }

    if ($errors) {
        return [
            'ok' => false,
            'message' => implode(' ', $errors),
            'file_path' => $normalizedPath,
            'errors' => $errors,
        ];
    }

    $id = date('Ymd-His') . '-' . substr(hash('sha256', uniqid('hfq', true) . mt_rand()), 0, 8);
    $qPath = $qDir . '/' . $id . '__' . basename($resolvedPath);
    if (!@rename($resolvedPath, $qPath)) {
        $lastError = error_get_last();
        $reason = is_array($lastError) && isset($lastError['message']) ? (string) $lastError['message'] : 'Falha ao mover arquivo para quarentena.';
        return [
            'ok' => false,
            'message' => 'Falha ao mover arquivo para quarentena.',
            'file_path' => $resolvedPath,
            'errors' => [$reason],
        ];
    }

    $entry = [
        'user' => $cpUser,
        'original_path' => $resolvedPath,
        'quarantine_path' => $qPath,
        'file_name' => basename($resolvedPath),
        'quarantined_at' => gmdate('c'),
    ];
    $hmacKey = quarantine_hmac_key($cpUser);
    if ($hmacKey !== '') {
        $entry['sig'] = quarantine_signature($entry, $hmacKey);
    }

    $entries = load_quarantine_index($indexPath);
    $entries[quarantine_record_key($entry)] = $entry;
    if (!save_quarantine_index($indexPath, $entries)) {
        return [
            'ok' => true,
            'message' => 'Arquivo movido para quarentena, mas não foi possível atualizar o índice.',
            'file_path' => $resolvedPath,
            'errors' => [],
            'quarantine' => $entry,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Arquivo movido para quarentena com sucesso.',
        'file_path' => $resolvedPath,
        'errors' => [],
        'quarantine' => $entry,
    ];
}

function restore_audit_file($cpUser, $rawPath, $homeDir, $rawQuarantinePath)
{
    $errors = [];
    if (!valid_cpanel_user($cpUser)) {
        $errors[] = 'Usuário cPanel inválido.';
    }

    $inputPath = (string) $rawPath;
    if (strlen($inputPath) > 4096) {
        $errors[] = 'Caminho muito longo.';
    }
    if (strpos($inputPath, "\0") !== false) {
        $errors[] = 'Caminho inválido.';
    }

    $normalizedPath = normalize_file_input($inputPath, $homeDir);
    if ($normalizedPath === '') {
        $errors[] = 'Informe o caminho original do arquivo.';
    }

    $preferredQuarantinePath = trim((string) $rawQuarantinePath);
    if (strpos($preferredQuarantinePath, "\0") !== false) {
        $errors[] = 'Caminho de quarentena inválido.';
    }

    if (!$errors) {
        if (!path_within_home($cpUser, $normalizedPath)) {
            $errors[] = 'Arquivo fora do escopo da conta.';
        }
    }

    list($record, $recordKey, $entries, $indexPath) = find_quarantine_record($cpUser, $normalizedPath, $preferredQuarantinePath);
    if (!$errors && (!$record || !is_array($record))) {
        $errors[] = 'Não há versão em quarentena para este arquivo.';
    }

    if ($errors) {
        return [
            'ok' => false,
            'message' => implode(' ', $errors),
            'file_path' => $normalizedPath,
            'errors' => $errors,
        ];
    }

    $safeRecord = null;
    if (!$errors && !quarantine_entry_valid($cpUser, $record, $safeRecord)) {
        $errors[] = 'Registro de quarentena inválido ou adulterado.';
    }

    if ($errors) {
        return [
            'ok' => false,
            'message' => implode(' ', $errors),
            'file_path' => $normalizedPath,
            'errors' => $errors,
        ];
    }

    $qPath = isset($safeRecord['quarantine_path']) ? (string) $safeRecord['quarantine_path'] : '';
    $originalPath = isset($safeRecord['original_path']) ? (string) $safeRecord['original_path'] : $normalizedPath;
    if (!path_within_home($cpUser, $originalPath) || path_within_quarantine($cpUser, $originalPath)) {
        return [
            'ok' => false,
            'message' => 'Caminho original inválido para restauração.',
            'file_path' => $normalizedPath,
            'errors' => ['Caminho original inválido para restauração.'],
        ];
    }
    if (!path_within_quarantine($cpUser, $qPath)) {
        return [
            'ok' => false,
            'message' => 'Caminho de quarentena inválido.',
            'file_path' => $normalizedPath,
            'errors' => ['Caminho de quarentena inválido.'],
        ];
    }

    if ($qPath === '' || !is_file($qPath)) {
        return [
            'ok' => false,
            'message' => 'Arquivo em quarentena não encontrado.',
            'file_path' => $originalPath,
            'errors' => ['Arquivo em quarentena não encontrado.'],
        ];
    }

    if (is_link($qPath)) {
        return [
            'ok' => false,
            'message' => 'Restauração de symlink não permitida.',
            'file_path' => $originalPath,
            'errors' => ['Restauração de symlink não permitida.'],
        ];
    }

    $parentDir = dirname($originalPath);
    if (!is_dir($parentDir) && !@mkdir($parentDir, 0750, true)) {
        return [
            'ok' => false,
            'message' => 'Falha ao preparar diretório original.',
            'file_path' => $originalPath,
            'errors' => ['Falha ao preparar diretório original.'],
        ];
    }

    if (file_exists($originalPath)) {
        return [
            'ok' => false,
            'message' => 'Ja existe arquivo no caminho original. Exclua ou renomeie antes de restaurar.',
            'file_path' => $originalPath,
            'errors' => ['Ja existe arquivo no caminho original.'],
        ];
    }

    if (!@rename($qPath, $originalPath)) {
        $lastError = error_get_last();
        $reason = is_array($lastError) && isset($lastError['message']) ? (string) $lastError['message'] : 'Falha ao restaurar arquivo da quarentena.';
        return [
            'ok' => false,
            'message' => 'Falha ao restaurar arquivo da quarentena.',
            'file_path' => $originalPath,
            'errors' => [$reason],
        ];
    }

    if ($recordKey !== '' && isset($entries[$recordKey])) {
        unset($entries[$recordKey]);
        save_quarantine_index($indexPath, $entries);
    }

    return [
        'ok' => true,
        'message' => 'Arquivo restaurado com sucesso da quarentena.',
        'file_path' => $originalPath,
        'errors' => [],
        'restored' => [
            'user' => $cpUser,
            'original_path' => $originalPath,
            'quarantine_path' => $qPath,
            'restored_at' => gmdate('c'),
        ],
    ];
}

function quarantine_status($cpUser, $rawPath, $homeDir)
{
    if (!valid_cpanel_user($cpUser)) {
        return [false, null, t('Usuário cPanel inválido.', 'Invalid cPanel user.')];
    }

    $inputPath = (string) $rawPath;
    if ($inputPath === '' || strlen($inputPath) > 4096 || strpos($inputPath, "\0") !== false) {
        return [false, null, t('Caminho inválido.', 'Invalid path.')];
    }

    $normalizedPath = normalize_file_input($inputPath, $homeDir);
    if ($normalizedPath === '') {
        return [false, null, t('Caminho inválido.', 'Invalid path.')];
    }

    if (!path_within_home($cpUser, $normalizedPath)) {
        return [false, null, t('Arquivo fora do escopo da conta.', 'File outside account scope.')];
    }

    list($record) = find_quarantine_record($cpUser, $normalizedPath);
    if (!$record || !is_array($record)) {
        // Also supports lookups when the audited file path is already inside quarantine.
        list($record) = find_quarantine_record($cpUser, '', $normalizedPath);
    }
    if (!$record || !is_array($record)) {
        return [true, null, t('Sem cópia em quarentena para este arquivo.', 'No quarantine copy found for this file.')];
    }

    $safeRecord = null;
    if (!quarantine_entry_valid($cpUser, $record, $safeRecord)) {
        return [true, null, t('Sem cópia em quarentena para este arquivo.', 'No quarantine copy found for this file.')];
    }

    $qPath = isset($safeRecord['quarantine_path']) ? (string) $safeRecord['quarantine_path'] : '';
    if ($qPath === '' || !path_within_quarantine($cpUser, $qPath) || !is_file($qPath)) {
        return [true, null, t('Sem cópia em quarentena para este arquivo.', 'No quarantine copy found for this file.')];
    }

    return [true, $safeRecord, t('Arquivo possui cópia em quarentena.', 'File has a quarantine copy.')];
}

function hf_script_meta($scriptPath)
{
    $version = 'desconhecida';
    $ftpReady = 'nao';

    if (!is_readable($scriptPath)) {
        return [$version, $ftpReady];
    }

    $content = @file_get_contents($scriptPath);
    if (!is_string($content) || $content === '') {
        return [$version, $ftpReady];
    }

    if (preg_match('/^HF_SCRIPT_VERSION="([^"]+)"/m', $content, $m)) {
        $version = (string) $m[1];
    }

    if (strpos($content, 'HF_USER_MODE_FTP_READY=1') !== false && strpos($content, 'search_user_ftp_logs()') !== false) {
        $ftpReady = 'sim';
    }

    return [$version, $ftpReady];
}

function send_json($payload, $statusCode)
{
    if (function_exists('http_response_code')) {
        http_response_code((int) $statusCode);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cpanel = null;
$liveApiAvailable = false;
$cpanelBootstrap = '/usr/local/cpanel/php/cpanel.php';
if (is_readable($cpanelBootstrap)) {
    require_once $cpanelBootstrap;
    if (class_exists('CPANEL')) {
        try {
            $cpanel = new CPANEL();
            $liveApiAvailable = true;
        } catch (Exception $e) {
            $cpanel = null;
            $liveApiAvailable = false;
        }
    }
}

$cpUser = detect_cpanel_user();
$uiLang = detect_ui_lang($cpUser);
$homeDir = $cpUser !== '' ? '/home/' . $cpUser : '';
$runnerScript = __DIR__ . '/bin/run_hforensic.sh';
$hfScriptPath = '/usr/local/bin/hf.sh';
if (!is_readable($hfScriptPath)) {
    $hfScriptPath = __DIR__ . '/bin/hf.sh';
}
list($hfVersion, $hfUserFtpReady) = hf_script_meta($hfScriptPath);
$hfVersionUi = ($uiLang === 'en' && $hfVersion === 'desconhecida') ? 'unknown' : $hfVersion;
$hfUserFtpReadyUi = $hfUserFtpReady === 'sim' ? ($uiLang === 'en' ? 'YES' : 'SIM') : ($uiLang === 'en' ? 'NO' : 'NÃO');
$csrfToken = csrf_token_get();

$isGet = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET';
$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$autoRefreshEnabled = $isGet && $action === '' && !$ajax;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

$requestedDir = '';
if (isset($_GET['dir'])) {
    $requestedDir = (string) $_GET['dir'];
} elseif (isset($_POST['dir'])) {
    $requestedDir = (string) $_POST['dir'];
} else {
    $requestedDir = 'public_html';
}

$browseDir = sanitize_relative_dir($requestedDir);
if ($browseDir === false) {
    $browseDir = 'public_html';
}

if ($ajax && $action === 'refresh_logs') {
    if (!$isPost) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    if (!csrf_token_valid(request_csrf_token())) {
        send_json(['ok' => false, 'message' => t('Falha de validação CSRF.', 'CSRF validation failed.')], 403);
    }

    $refreshRateWindow = 180;
    $retryAfter = 0;
    $rateError = '';
    if (!enforce_action_rate_limit($cpUser, 'refresh_logs', $refreshRateWindow, $retryAfter, $rateError)) {
        $waitSeconds = $retryAfter > 0 ? $retryAfter : $refreshRateWindow;
        $message = $rateError !== ''
            ? $rateError
            : sprintf(
                t('Aguarde %ds antes de atualizar logs novamente.', 'Please wait %ds before refreshing logs again.'),
                $waitSeconds
            );
        if ($waitSeconds > 0) {
            header('Retry-After: ' . (int) $waitSeconds);
        }
        send_json(
            [
                'ok' => false,
                'message' => $message,
                'retry_after' => $waitSeconds,
                'min_interval' => $refreshRateWindow,
                'user' => $cpUser,
            ],
            429
        );
    }

    list($ok, $message) = run_log_refresh_for_user($cpUser);
    send_json(
        [
            'ok' => $ok,
            'message' => $message,
            'min_interval' => $refreshRateWindow,
            'user' => $cpUser,
        ],
        $ok ? 200 : 500
    );
}

if ($ajax && $action === 'list_files') {
    if (!$isGet) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    list($rows, $mode, $listErrors, $listNotices) = list_rows($cpanel, $liveApiAvailable, $homeDir, $browseDir);
    $items = rows_to_items($rows, $browseDir, $homeDir);

    $ok = count($listErrors) === 0;
    send_json(
        [
            'ok' => $ok,
            'dir' => $browseDir,
            'parent_dir' => dir_parent($browseDir),
            'mode' => $mode,
            'items' => $items,
            'errors' => $listErrors,
            'notices' => $listNotices,
            'user' => $cpUser,
        ],
        $ok ? 200 : 500
    );
}

if ($ajax && $action === 'run_audit') {
    if (!$isPost) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    if (!csrf_token_valid(request_csrf_token())) {
        send_json(['ok' => false, 'message' => t('Falha de validação CSRF.', 'CSRF validation failed.')], 403);
    }
    $filePath = isset($_POST['file_path']) ? (string) $_POST['file_path'] : '';
    $result = run_audit($cpUser, $runnerScript, $filePath, $homeDir, $uiLang);
    send_json($result, $result['ok'] ? 200 : 500);
}

if ($ajax && $action === 'quarantine_file') {
    if (!$isPost) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    if (!csrf_token_valid(request_csrf_token())) {
        send_json(['ok' => false, 'message' => t('Falha de validação CSRF.', 'CSRF validation failed.')], 403);
    }
    $filePath = isset($_POST['file_path']) ? (string) $_POST['file_path'] : '';
    $result = quarantine_audit_file($cpUser, $filePath, $homeDir);
    send_json($result, $result['ok'] ? 200 : 500);
}

if ($ajax && $action === 'restore_file') {
    if (!$isPost) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    if (!csrf_token_valid(request_csrf_token())) {
        send_json(['ok' => false, 'message' => t('Falha de validação CSRF.', 'CSRF validation failed.')], 403);
    }
    $filePath = isset($_POST['file_path']) ? (string) $_POST['file_path'] : '';
    $quarantinePath = isset($_POST['quarantine_path']) ? (string) $_POST['quarantine_path'] : '';
    $result = restore_audit_file($cpUser, $filePath, $homeDir, $quarantinePath);
    send_json($result, $result['ok'] ? 200 : 500);
}

if ($ajax && $action === 'quarantine_status') {
    if (!$isGet) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    $filePath = isset($_GET['file_path']) ? (string) $_GET['file_path'] : '';
    list($ok, $record, $message) = quarantine_status($cpUser, $filePath, $homeDir);
    send_json(
        [
            'ok' => $ok,
            'message' => $message,
            'quarantine' => $record,
        ],
        $ok ? 200 : 500
    );
}

if ($ajax && $action === 'delete_file') {
    if (!$isPost) {
        send_json(['ok' => false, 'message' => t('Método HTTP inválido.', 'Invalid HTTP method.')], 405);
    }
    if (!csrf_token_valid(request_csrf_token())) {
        send_json(['ok' => false, 'message' => t('Falha de validação CSRF.', 'CSRF validation failed.')], 403);
    }
    $filePath = isset($_POST['file_path']) ? (string) $_POST['file_path'] : '';
    $result = delete_audit_file($cpUser, $filePath, $homeDir);
    send_json($result, $result['ok'] ? 200 : 500);
}

if ($ajax && $action !== '') {
    send_json(['ok' => false, 'message' => t('Ação AJAX inválida.', 'Invalid AJAX action.')], 400);
}

$errors = [];
$notices = [];

$inputPath = '';
if (isset($_GET['file']) && $_GET['file'] !== '') {
    $inputPath = (string) $_GET['file'];
}

$auditOutput = '';
$auditExitCode = null;
if ($isPost && !$ajax) {
    if (!csrf_token_valid(request_csrf_token())) {
        $errors[] = t('Falha de validação CSRF.', 'CSRF validation failed.');
    } else {
        $inputPath = isset($_POST['file_path']) ? (string) $_POST['file_path'] : '';
        $result = run_audit($cpUser, $runnerScript, $inputPath, $homeDir, $uiLang);
        $auditOutput = $result['output'];
        $auditExitCode = $result['exit_code'];

        if ($result['ok']) {
            $notices[] = $result['message'];
        } else {
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $line) {
                    $errors[] = (string) $line;
                }
            } else {
                $errors[] = (string) $result['message'];
            }
        }
    }
}

if ($cpUser === '') {
    $errors[] = 'Não foi possível identificar o usuário cPanel.';
}

list($rows, $listingMode, $listErrors, $listNotices) = list_rows($cpanel, $liveApiAvailable, $homeDir, $browseDir);
$items = rows_to_items($rows, $browseDir, $homeDir);
$errors = array_merge($errors, $listErrors);
$notices = array_merge($notices, $listNotices);

$useCpanelChrome = $liveApiAvailable && $cpanel && method_exists($cpanel, 'header') && method_exists($cpanel, 'footer');
if ($useCpanelChrome) {
    print $cpanel->header('High Forensic');
} else {
    ?>
<!doctype html>
<html lang="<?= h($uiLang === 'en' ? 'en-US' : 'pt-BR') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>High Forensic</title>
</head>
<body>
    <?php
}
?>
<style>
    .hf-audit-wrap { max-width: 1240px; }
    .hf-muted { color: #6a768a; }
    .hf-panel { border: 1px solid #d9e0eb; border-radius: 6px; background: #fff; padding: 14px; margin-bottom: 12px; }
    .hf-title { margin: 0 0 6px 0; font-size: 26px; }
    .hf-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
    .hf-field { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #c9d3e0; border-radius: 4px; }
    .hf-btn { border: 1px solid #0d6ea8; background: #157fbd; color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
    .hf-btn:hover { filter: brightness(1.03); }
    .hf-btn-light { border: 1px solid #c5d1df; background: #f3f6fa; color: #29496b; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
    .hf-btn-light:hover { background: #eaf0f7; }
    .hf-btn-success { border: 1px solid #2c8a54; background: #35a365; color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
    .hf-btn-success:hover { filter: brightness(1.03); }
    .hf-btn-warn { border: 1px solid #b98b2d; background: #d8a53a; color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
    .hf-btn-warn:hover { filter: brightness(1.03); }
    .hf-btn-danger { border: 1px solid #b74141; background: #cf4b4b; color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
    .hf-btn-danger:hover { filter: brightness(1.03); }
    .hf-btn-link { border: 1px solid #c5d1df; background: #f3f6fa; color: #29496b; padding: 5px 10px; border-radius: 4px; text-decoration: none; display: inline-block; }
    .hf-note { padding: 10px; border-radius: 4px; margin-bottom: 10px; }
    .hf-note-error { background: #fdeff2; border: 1px solid #f5ccd5; color: #7c1c2d; }
    .hf-note-ok { background: #ebf8ef; border: 1px solid #c4ebd0; color: #14542a; }
    .hf-table { width: 100%; border-collapse: collapse; }
    .hf-table th, .hf-table td { text-align: left; padding: 8px; border-bottom: 1px solid #e6ecf3; vertical-align: middle; }
    .hf-table th { font-weight: 600; color: #4f5d73; background: #f8fbff; }
    .hf-table-tools { display: grid; grid-template-columns: 2fr 1.2fr 1fr auto auto; gap: 8px; align-items: end; margin-bottom: 10px; }
    .hf-table-filter label { display: block; margin-bottom: 4px; font-size: 12px; color: #53657d; }
    .hf-filter-count { border: 1px dashed #c9d3e0; border-radius: 4px; padding: 8px 10px; background: #f8fbff; color: #4f6077; min-width: 160px; text-align: center; }
    .hf-dir { font-weight: 600; }
    .hf-dir-link { border: 0; background: transparent; color: #0b63a3; cursor: pointer; font-weight: 600; padding: 0; }
    .hf-dir-link:hover { text-decoration: underline; }
    .hf-dir-nav { margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .hf-dir-nav-label { color: #53657d; font-size: 12px; margin-right: 4px; }
    .hf-dir-nav-sep { color: #8ca0b9; }
    .hf-inline-actions { display: flex; flex-wrap: wrap; gap: 6px; }
    .hf-pre { margin: 0; padding: 12px; border-radius: 4px; background: #0b1f36; color: #d9ebff; border: 1px solid #1f3958; white-space: pre-wrap; word-break: break-word; max-height: 560px; overflow: auto; font-family: Consolas, Menlo, Monaco, monospace; font-size: 12px; }
    .hf-path { font-family: Consolas, Menlo, Monaco, monospace; }
    .hf-loading-overlay { position: fixed; inset: 0; background: rgba(8, 23, 43, 0.58); display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(1px); }
    .hf-loading-overlay.is-open { display: flex; }
    .hf-loading-card { background: #fff; border: 1px solid #cdd7e7; border-radius: 8px; padding: 14px 18px; min-width: 360px; max-width: 92vw; box-shadow: 0 8px 30px rgba(10, 32, 60, 0.24); }
    .hf-loading-title { margin: 0 0 4px 0; font-size: 18px; font-weight: 600; }
    .hf-loading-text { margin: 0; color: #4a607d; font-size: 14px; }
    .hf-loading-bar { margin-top: 10px; width: 100%; height: 6px; background: #e4ebf5; border-radius: 999px; overflow: hidden; }
    .hf-loading-progress { height: 100%; width: 36%; background: linear-gradient(90deg, #1976b7, #1c9ce5); border-radius: 999px; animation: hfload 1.2s ease-in-out infinite; }
    .hf-loading-msg { margin-top: 8px; font-size: 12px; color: #5a6e89; }
    .hf-modal-backdrop { position: fixed; inset: 0; background: rgba(7, 20, 36, 0.68); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 20px; }
    .hf-modal-backdrop.is-open { display: flex; }
    .hf-modal { width: min(1100px, 96vw); max-height: 92vh; background: #fff; border: 1px solid #d2dbea; border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; }
    .hf-modal-head { padding: 12px 14px; border-bottom: 1px solid #e1e8f3; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
    .hf-modal-title { margin: 0; font-size: 19px; }
    .hf-modal-sub { color: #5f738d; font-size: 13px; }
    .hf-modal-actions { display: flex; flex-wrap: wrap; gap: 6px; }
    .hf-modal-body { padding: 12px 14px 14px; overflow: auto; }
    .hf-risk-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 8px; margin-bottom: 12px; }
    .hf-risk-card { border: 1px solid #d8e1ee; background: #f8fbff; border-radius: 6px; padding: 8px 10px; }
    .hf-risk-k { display: block; color: #597089; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
    .hf-risk-v { display: block; color: #1a3048; font-size: 15px; font-weight: 600; }
    .hf-risk-v.hf-risk-low { color: #1d7e42; }
    .hf-risk-v.hf-risk-medium { color: #9e6a11; }
    .hf-risk-v.hf-risk-high { color: #a11f32; }
    .hf-timeline-wrap { margin-top: 12px; border: 1px solid #dbe4f1; border-radius: 6px; }
    .hf-timeline-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 10px; border-bottom: 1px solid #e6edf7; background: #f8fbff; }
    .hf-timeline-title { margin: 0; font-size: 16px; }
    .hf-timeline-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .hf-timeline-count { color: #5f738d; font-size: 12px; }
    .hf-timeline-select { min-width: 170px; }
    .hf-timeline-table { width: 100%; border-collapse: collapse; }
    .hf-timeline-table th, .hf-timeline-table td { text-align: left; padding: 8px; border-bottom: 1px solid #edf2f9; vertical-align: top; font-size: 12px; }
    .hf-timeline-table th { color: #4f5d73; background: #fbfdff; }
    .hf-chip { display: inline-block; border: 1px solid #c9d5e6; border-radius: 999px; padding: 2px 8px; font-size: 11px; color: #365472; background: #f5f9ff; }
    .hf-confirm-dialog { width: min(540px, 94vw); background: #fff; border: 1px solid #d2dbea; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 30px rgba(8, 23, 43, 0.35); }
    .hf-confirm-body { padding: 14px; color: #273f5b; font-size: 14px; line-height: 1.45; }
    .hf-confirm-actions { padding: 0 14px 14px; display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }

    @keyframes hfload {
        0% { transform: translateX(-120%); }
        100% { transform: translateX(300%); }
    }

    @media (max-width: 960px) {
        .hf-grid { grid-template-columns: 1fr; }
        .hf-table-tools { grid-template-columns: 1fr; }
        .hf-risk-grid { grid-template-columns: 1fr 1fr; }
    }
</style>

<div id="hf-loading-overlay" class="hf-loading-overlay<?= $autoRefreshEnabled ? ' is-open' : '' ?>">
    <div class="hf-loading-card">
        <h3 class="hf-loading-title" id="hf-loading-title"><?= h(t('Atualizando logs da conta', 'Updating account logs')) ?></h3>
        <p class="hf-loading-text" id="hf-loading-text"><?= h(t('Executando runweblogs para refletir eventos mais recentes antes da auditoria.', 'Running runweblogs to reflect the most recent events before auditing.')) ?></p>
        <div class="hf-loading-bar"><div class="hf-loading-progress"></div></div>
        <div id="hf-loading-msg" class="hf-loading-msg"><?= h(t('Aguarde...', 'Please wait...')) ?></div>
    </div>
</div>

<div class="hf-audit-wrap">
    <?php if (!$useCpanelChrome): ?>
        <h1 class="hf-title">High Forensic</h1>
    <?php endif; ?>

    <div class="hf-panel">
        <strong><?= h(t('Conta', 'Account')) ?>:</strong> <span id="hf-meta-user"><?= h($cpUser !== '' ? $cpUser : t('desconhecida', 'unknown')) ?></span><br>
        <strong><?= h(t('Escopo', 'Scope')) ?>:</strong> <span id="hf-meta-scope"><?= h($homeDir !== '' ? $homeDir : '/home/<user>') ?></span><br>
        <strong><?= h(t('Diretório atual', 'Current directory')) ?>:</strong> <span id="hf-meta-dir" class="hf-path"><?= h($browseDir === '' ? t('(início)', '(home)') : $browseDir) ?></span><br>
        <strong><?= h(t('Listagem', 'Listing')) ?>:</strong> <span id="hf-meta-mode"><?= h($listingMode !== '' ? strtoupper($listingMode) : t('indisponível', 'unavailable')) ?></span><br>
        <strong>HF Script:</strong> <span><?= h($hfVersionUi) ?></span> |
        <strong><?= h(t('FTP user-mode pronto', 'FTP user-mode ready')) ?>:</strong> <span><?= h($hfUserFtpReadyUi) ?></span>
    </div>

    <div id="hf-alert-host">
        <?php if ($errors): ?>
            <div class="hf-panel"><div class="hf-note hf-note-error"><?php foreach ($errors as $line): ?><div><?= h((string) $line) ?></div><?php endforeach; ?></div></div>
        <?php endif; ?>
        <?php if ($notices): ?>
            <div class="hf-panel"><div class="hf-note hf-note-ok"><?php foreach ($notices as $line): ?><div><?= h((string) $line) ?></div><?php endforeach; ?></div></div>
        <?php endif; ?>
    </div>

    <div class="hf-grid">
        <div class="hf-panel">
            <h2><?= h(t('Auditar Arquivo', 'Audit File')) ?></h2>
            <form id="hf-audit-form" method="post" action="">
                <input type="hidden" name="dir" id="hf-dir-input" value="<?= h($browseDir) ?>">
                <input type="hidden" name="csrf_token" id="hf-csrf-token" value="<?= h($csrfToken) ?>">
                <label for="file_path"><?= h(t('Caminho do arquivo', 'File path')) ?></label><br>
                <input
                    id="file_path"
                    class="hf-field"
                    type="text"
                    name="file_path"
                    required
                    value="<?= h($inputPath) ?>"
                    placeholder="<?= h($homeDir !== '' ? $homeDir . '/public_html/index.php' : '/home/user/public_html/index.php') ?>"
                >
                <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="hf-btn" type="submit" id="hf-run-audit-btn"><?= h(t('Executar Auditoria', 'Run Audit')) ?></button>
                    <button type="button" class="hf-btn-light" id="hf-refresh-list-btn"><?= h(t('Atualizar arquivos', 'Refresh files')) ?></button>
                    <button type="button" class="hf-btn-light" id="hf-refresh-logs-btn"><?= h(t('Atualizar logs', 'Refresh logs')) ?></button>
                </div>
            </form>
            <p class="hf-muted" style="margin-top:10px;"><?= h(t('Dica: use "Auditar" na tabela para abrir resultado em modal.', 'Tip: use "Audit" in the table to open results in a modal.')) ?></p>
        </div>

        <div class="hf-panel">
            <h2><?= h(t('Navegação', 'Navigation')) ?></h2>
            <p class="hf-muted"><?= h(t('Navegue por diretórios sem recarregar a página.', 'Browse directories without reloading the page.')) ?></p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="hf-btn-light" id="hf-open-public" type="button"><?= h(t('Ir para public_html', 'Go to public_html')) ?></button>
                <button class="hf-btn-light" id="hf-open-quarantine" type="button"><?= h(t('Ir para quarentena', 'Go to quarantine')) ?></button>
                <button class="hf-btn-light" id="hf-exit-quarantine" type="button"><?= h(t('Sair da quarentena', 'Exit quarantine')) ?></button>
                <button class="hf-btn-light" id="hf-open-parent" type="button"><?= h(t('Subir um nível', 'Go up one level')) ?></button>
            </div>
        </div>
    </div>

    <div class="hf-panel">
        <h2><?= h(t('Arquivos do Diretório', 'Directory Files')) ?></h2>
        <div id="hf-dir-nav" class="hf-dir-nav"></div>
        <div class="hf-table-tools">
            <div class="hf-table-filter">
                <label for="hf-filter-name"><?= h(t('Busca por nome', 'Search by name')) ?></label>
                <input type="text" class="hf-field" id="hf-filter-name" placeholder="<?= h(t('Ex.: domain, php, registro', 'Ex.: domain, php, record')) ?>">
            </div>
            <div class="hf-table-filter">
                <label for="hf-filter-ext"><?= h(t('Filtro por extensão', 'Filter by extension')) ?></label>
                <input type="text" class="hf-field" id="hf-filter-ext" list="hf-filter-ext-options" placeholder="Ex.: php, txt, jpg">
                <datalist id="hf-filter-ext-options"></datalist>
            </div>
            <div class="hf-table-filter">
                <label for="hf-results-limit"><?= h(t('Resultados', 'Results')) ?></label>
                <select class="hf-field" id="hf-results-limit">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="all"><?= h(t('Todos', 'All')) ?></option>
                </select>
            </div>
            <div class="hf-table-filter">
                <label for="hf-filter-count"><?= h(t('Resultados', 'Results')) ?></label>
                <div class="hf-filter-count" id="hf-filter-count">0 de 0</div>
            </div>
            <div class="hf-table-filter">
                <label>&nbsp;</label>
                <button type="button" class="hf-btn-light" id="hf-filter-clear-btn"><?= h(t('Limpar filtro', 'Clear filter')) ?></button>
            </div>
        </div>
        <table class="hf-table" id="hf-file-table">
            <thead>
                <tr>
                    <th style="width:38%;"><?= h(t('Nome', 'Name')) ?></th>
                    <th style="width:12%;"><?= h(t('Tipo', 'Type')) ?></th>
                    <th style="width:15%;"><?= h(t('Tamanho', 'Size')) ?></th>
                    <th style="width:20%;">MIME</th>
                    <th style="width:15%;"><?= h(t('Ações', 'Actions')) ?></th>
                </tr>
            </thead>
            <tbody id="hf-file-table-body">
                <?php if (!$items): ?>
                    <tr><td colspan="5" class="hf-muted"><?= h(t('Nenhum item encontrado.', 'No items found.')) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="<?= $item['is_dir'] ? 'hf-dir' : '' ?>">
                                <?php if ($item['is_dir']): ?>
                                    <button type="button" class="hf-dir-link" data-open-dir="<?= h($item['next_dir']) ?>"><?= h($item['name']) ?></button>
                                <?php else: ?>
                                    <?= h($item['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $item['is_dir'] ? h(t('Diretório', 'Directory')) : h(t('Arquivo', 'File')) ?></td>
                            <td><?= h($item['size']) ?></td>
                            <td><?= h($item['mime']) ?></td>
                            <td>
                                <div class="hf-inline-actions">
                                    <?php if ($item['is_dir']): ?>
                                        <button type="button" class="hf-btn-link" data-open-dir="<?= h($item['next_dir']) ?>"><?= h(t('Abrir', 'Open')) ?></button>
                                    <?php else: ?>
                                        <button type="button" class="hf-btn" data-audit-file="<?= h($item['file_path']) ?>"><?= h(t('Auditar', 'Audit')) ?></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($auditOutput !== '' || $auditExitCode !== null): ?>
        <div class="hf-panel">
            <h2><?= h(t('Resultado da Auditoria', 'Audit Result')) ?></h2>
            <pre class="hf-pre"><?= h($auditOutput !== '' ? $auditOutput : t('(sem saída)', '(no output)')) ?></pre>
        </div>
    <?php endif; ?>
</div>

<div id="hf-audit-modal" class="hf-modal-backdrop" aria-hidden="true">
    <div class="hf-modal" role="dialog" aria-modal="true" aria-labelledby="hf-modal-title">
        <div class="hf-modal-head">
            <div>
                <h3 id="hf-modal-title" class="hf-modal-title">Resultado da Auditoria</h3>
                <div id="hf-modal-sub" class="hf-modal-sub"></div>
            </div>
            <div class="hf-modal-actions">
                <button type="button" class="hf-btn-light" id="hf-modal-print"><?= h(t('Imprimir / PDF', 'Print / PDF')) ?></button>
                <button type="button" class="hf-btn-light" id="hf-modal-download-txt"><?= h(t('Baixar TXT', 'Download TXT')) ?></button>
                <button type="button" class="hf-btn-light" id="hf-modal-download-evidence"><?= h(t('Baixar Evidência JSON', 'Download Evidence JSON')) ?></button>
                <button type="button" class="hf-btn-light" id="hf-modal-download-png"><?= h(t('Exportar PNG', 'Export PNG')) ?></button>
                <button type="button" class="hf-btn-warn" id="hf-modal-quarantine-file"><?= h(t('Quarentena', 'Quarantine')) ?></button>
                <button type="button" class="hf-btn-success" id="hf-modal-restore-file"><?= h(t('Restaurar', 'Restore')) ?></button>
                <button type="button" class="hf-btn-danger" id="hf-modal-delete-file"><?= h(t('Excluir Arquivo', 'Delete File')) ?></button>
                <button type="button" class="hf-btn" id="hf-modal-close"><?= h(t('Fechar', 'Close')) ?></button>
            </div>
        </div>
        <div class="hf-modal-body">
            <div id="hf-risk-summary" class="hf-risk-grid">
                <div class="hf-risk-card">
                    <span class="hf-risk-k"><?= h(t('Score de risco', 'Risk score')) ?></span>
                    <span class="hf-risk-v" id="hf-risk-score">-</span>
                </div>
                <div class="hf-risk-card">
                    <span class="hf-risk-k"><?= h(t('Origem provável', 'Likely origin')) ?></span>
                    <span class="hf-risk-v" id="hf-risk-origin">-</span>
                </div>
                <div class="hf-risk-card">
                    <span class="hf-risk-k"><?= h(t('Último evento', 'Last event')) ?></span>
                    <span class="hf-risk-v" id="hf-risk-last-event">-</span>
                </div>
                <div class="hf-risk-card">
                    <span class="hf-risk-k"><?= h(t('IP principal', 'Main IP')) ?></span>
                    <span class="hf-risk-v" id="hf-risk-main-ip">-</span>
                </div>
            </div>
            <pre id="hf-modal-output" class="hf-pre"></pre>
            <div class="hf-timeline-wrap">
                <div class="hf-timeline-head">
                    <h4 class="hf-timeline-title"><?= h(t('Timeline de Evidências', 'Evidence Timeline')) ?></h4>
                    <div class="hf-timeline-tools">
                        <select id="hf-timeline-filter" class="hf-field hf-timeline-select">
                            <option value="all"><?= h(t('Todos os tipos', 'All types')) ?></option>
                            <option value="FTP/SFTP">FTP/SFTP</option>
                            <option value="WEB">WEB</option>
                            <option value="PAINEL">PAINEL</option>
                            <option value="SSH">SSH</option>
                            <option value="OUTROS">OUTROS</option>
                        </select>
                        <span id="hf-timeline-count" class="hf-timeline-count"><?= h(t('0 eventos', '0 events')) ?></span>
                    </div>
                </div>
                <table class="hf-timeline-table">
                    <thead>
                        <tr>
                            <th style="width:16%;"><?= h(t('Data/Hora', 'Date/Time')) ?></th>
                            <th style="width:14%;"><?= h(t('Tipo', 'Type')) ?></th>
                            <th style="width:13%;">IP</th>
                            <th style="width:20%;"><?= h(t('Ação/Alvo', 'Action/Target')) ?></th>
                            <th style="width:37%;"><?= h(t('Log/Detalhe', 'Log/Detail')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="hf-timeline-body">
                        <tr><td colspan="5" class="hf-muted"><?= h(t('Nenhuma evidência processada.', 'No processed evidence.')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="hf-confirm-modal" class="hf-modal-backdrop" aria-hidden="true">
    <div class="hf-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="hf-confirm-title">
        <div class="hf-modal-head">
            <h3 id="hf-confirm-title" class="hf-modal-title"><?= h(t('Confirmar ação', 'Confirm action')) ?></h3>
        </div>
        <div id="hf-confirm-message" class="hf-confirm-body"></div>
        <div class="hf-confirm-actions">
            <button type="button" class="hf-btn-light" id="hf-confirm-cancel"><?= h(t('Cancelar', 'Cancel')) ?></button>
            <button type="button" class="hf-btn-danger" id="hf-confirm-ok"><?= h(t('Confirmar', 'Confirm')) ?></button>
        </div>
    </div>
</div>

<script>
(function () {
    var UI_LANG = <?= json_encode($uiLang, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var I18N = {
        pt: {
            wait: 'Aguarde...',
            confirm_action: 'Confirmar ação',
            confirm: 'Confirmar',
            no_items_found: 'Nenhum item encontrado.',
            directory: 'Diretório',
            file: 'Arquivo',
            open: 'Abrir',
            audit: 'Auditar',
            unavailable: 'indisponível',
            home_label: '(início)',
            status_label: 'status',
            no_evidence: 'Sem evidência',
            risk_low: 'Baixo',
            risk_medium: 'Médio',
            risk_high: 'Alto',
            loading_files_title: 'Atualizando arquivos',
            loading_files_text: 'Recarregando a listagem do diretório atual.',
            loading_logs_title: 'Atualizando logs da conta',
            loading_logs_text: 'Executando runweblogs para refletir eventos recentes.',
            loading_logs_first_text: 'Executando runweblogs para refletir eventos mais recentes antes da auditoria.',
            listing_failed: 'Falha ao carregar listagem.',
            quarantine_empty: 'Quarentena vazia: nenhum arquivo movido até o momento.',
            comm_list_error: 'Erro de comunicação ao atualizar listagem.',
            status_success: 'sucesso',
            status_code: 'código',
            no_output: '(sem saída)',
            no_evidence_selected_filter: 'Nenhuma evidência para o filtro selecionado.',
            events_count: 'evento(s)',
            no_file_to_audit: 'Informe um caminho de arquivo para auditar.',
            invalid_audit_response: 'Resposta inválida da auditoria.',
            audit_ok: 'Auditoria concluída com sucesso.',
            audit_fail: 'Falha na auditoria.',
            comm_audit_error: 'Erro de comunicação ao executar auditoria.',
            no_file_for_quarantine: 'Nenhum arquivo selecionado para quarentena.',
            already_quarantined: 'Este arquivo já está em quarentena. Use "Restaurar" para trazê-lo de volta.',
            quarantine_confirm_title: 'Mover para quarentena',
            quarantine_confirm_btn: 'Mover para quarentena',
            quarantine_confirm_msg_prefix: 'Mover o arquivo ',
            quarantine_confirm_msg_suffix: ' para a quarentena?',
            quarantine_loading_title: 'Movendo para quarentena',
            quarantine_loading_text: 'Arquivo será removido do caminho original e armazenado em área segura.',
            quarantine_ok: 'Arquivo movido para quarentena com sucesso.',
            quarantine_fail: 'Falha ao mover para quarentena.',
            comm_quarantine_error: 'Erro de comunicação ao mover arquivo para quarentena.',
            no_file_restore: 'Nenhum arquivo disponível para restauração.',
            no_quarantine_copy: 'Nenhuma cópia em quarentena disponível para restauração.',
            restore_confirm_title: 'Restaurar da quarentena',
            restore_confirm_btn: 'Restaurar',
            restore_confirm_msg_prefix: 'Restaurar o arquivo para ',
            restore_confirm_msg_suffix: '?',
            restore_loading_title: 'Restaurando arquivo',
            restore_loading_text: 'Movendo arquivo da quarentena para o caminho original.',
            restore_ok: 'Arquivo restaurado com sucesso.',
            restore_fail: 'Falha ao restaurar arquivo.',
            comm_restore_error: 'Erro de comunicação ao restaurar arquivo.',
            no_file_delete: 'Nenhum arquivo selecionado para exclusão.',
            delete_confirm_title: 'Confirmar exclusão',
            delete_confirm_msg_prefix: 'Deseja realmente excluir o arquivo ',
            delete_confirm_msg_suffix: '?',
            continue_label: 'Continuar',
            delete_confirm_final_title: 'Confirmação final',
            delete_confirm_final_msg_prefix: 'Excluir PERMANENTEMENTE o arquivo ',
            delete_confirm_final_msg_suffix: '?',
            delete_confirm_final_btn: 'Excluir permanentemente',
            deleting_file_title: 'Excluindo arquivo',
            deleting_file_text: 'Removendo o arquivo auditado da conta.',
            delete_ok: 'Arquivo excluído com sucesso.',
            delete_fail: 'Falha ao excluir arquivo.',
            comm_delete_error: 'Erro de comunicação ao excluir arquivo.',
            print_window_error: 'Não foi possível abrir janela de impressão.',
            no_result_export: 'Nenhum resultado para exportar.',
            result_label: 'resultado',
            png_truncated: '[saída truncada na exportação PNG]',
            logs_updated: 'Logs atualizados.',
            logs_update_fail: 'Falha ao atualizar logs.',
            comm_logs_error: 'Erro de comunicação ao atualizar logs.',
            logs_refresh_wait_prefix: 'Aguarde ',
            logs_refresh_wait_suffix: 's antes de atualizar logs novamente.',
            navigation_label: 'Navegação:',
            home: 'home',
            showing_items: 'de',
            total: 'total'
        },
        en: {
            wait: 'Please wait...',
            confirm_action: 'Confirm action',
            confirm: 'Confirm',
            no_items_found: 'No items found.',
            directory: 'Directory',
            file: 'File',
            open: 'Open',
            audit: 'Audit',
            unavailable: 'unavailable',
            home_label: '(home)',
            status_label: 'status',
            no_evidence: 'No evidence',
            risk_low: 'Low',
            risk_medium: 'Medium',
            risk_high: 'High',
            loading_files_title: 'Refreshing files',
            loading_files_text: 'Reloading current directory listing.',
            loading_logs_title: 'Refreshing account logs',
            loading_logs_text: 'Running runweblogs to reflect recent events.',
            loading_logs_first_text: 'Running runweblogs to reflect the most recent events before auditing.',
            listing_failed: 'Failed to load listing.',
            quarantine_empty: 'Quarantine is empty: no moved files yet.',
            comm_list_error: 'Communication error while refreshing listing.',
            status_success: 'success',
            status_code: 'code',
            no_output: '(no output)',
            no_evidence_selected_filter: 'No evidence for selected filter.',
            events_count: 'event(s)',
            no_file_to_audit: 'Enter a file path to audit.',
            invalid_audit_response: 'Invalid audit response.',
            audit_ok: 'Audit completed successfully.',
            audit_fail: 'Audit failed.',
            comm_audit_error: 'Communication error while running audit.',
            no_file_for_quarantine: 'No file selected for quarantine.',
            already_quarantined: 'This file is already in quarantine. Use "Restore" to bring it back.',
            quarantine_confirm_title: 'Move to quarantine',
            quarantine_confirm_btn: 'Move to quarantine',
            quarantine_confirm_msg_prefix: 'Move file ',
            quarantine_confirm_msg_suffix: ' to quarantine?',
            quarantine_loading_title: 'Moving to quarantine',
            quarantine_loading_text: 'The file will be removed from its original path and stored in a safe area.',
            quarantine_ok: 'File moved to quarantine successfully.',
            quarantine_fail: 'Failed to move to quarantine.',
            comm_quarantine_error: 'Communication error while moving file to quarantine.',
            no_file_restore: 'No file available for restore.',
            no_quarantine_copy: 'No quarantine copy available for restore.',
            restore_confirm_title: 'Restore from quarantine',
            restore_confirm_btn: 'Restore',
            restore_confirm_msg_prefix: 'Restore file to ',
            restore_confirm_msg_suffix: '?',
            restore_loading_title: 'Restoring file',
            restore_loading_text: 'Moving file from quarantine to original path.',
            restore_ok: 'File restored successfully.',
            restore_fail: 'Failed to restore file.',
            comm_restore_error: 'Communication error while restoring file.',
            no_file_delete: 'No file selected for deletion.',
            delete_confirm_title: 'Confirm deletion',
            delete_confirm_msg_prefix: 'Do you really want to delete file ',
            delete_confirm_msg_suffix: '?',
            continue_label: 'Continue',
            delete_confirm_final_title: 'Final confirmation',
            delete_confirm_final_msg_prefix: 'Permanently delete file ',
            delete_confirm_final_msg_suffix: '?',
            delete_confirm_final_btn: 'Delete permanently',
            deleting_file_title: 'Deleting file',
            deleting_file_text: 'Removing the audited file from the account.',
            delete_ok: 'File deleted successfully.',
            delete_fail: 'Failed to delete file.',
            comm_delete_error: 'Communication error while deleting file.',
            print_window_error: 'Could not open print window.',
            no_result_export: 'No result to export.',
            result_label: 'result',
            png_truncated: '[output truncated in PNG export]',
            logs_updated: 'Logs updated.',
            logs_update_fail: 'Failed to refresh logs.',
            comm_logs_error: 'Communication error while refreshing logs.',
            logs_refresh_wait_prefix: 'Wait ',
            logs_refresh_wait_suffix: 's before refreshing logs again.',
            navigation_label: 'Navigation:',
            home: 'home',
            showing_items: 'of',
            total: 'total'
        }
    };

    function tr(key) {
        var current = I18N[UI_LANG] || I18N.pt;
        if (current && Object.prototype.hasOwnProperty.call(current, key)) {
            return current[key];
        }
        if (I18N.pt && Object.prototype.hasOwnProperty.call(I18N.pt, key)) {
            return I18N.pt[key];
        }
        return key;
    }

    var state = {
        currentDir: <?= json_encode($browseDir, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        parentDir: <?= json_encode(dir_parent($browseDir), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        listingMode: <?= json_encode($listingMode, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        homeDir: <?= json_encode($homeDir, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        lastAuditText: '',
        lastAuditPath: '',
        lastAuditExitCode: null,
        lastEvidence: { matches: [], summary: null },
        lastQuarantine: null,
        lastQuarantineForPath: '',
        quarantineStatusRequestId: 0,
        allItems: <?= json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    };

    var autoRefreshEnabled = <?= $autoRefreshEnabled ? 'true' : 'false' ?>;
    var tableBody = document.getElementById('hf-file-table-body');
    var dirNav = document.getElementById('hf-dir-nav');
    var alertHost = document.getElementById('hf-alert-host');
    var fileInput = document.getElementById('file_path');
    var dirInput = document.getElementById('hf-dir-input');
    var csrfInput = document.getElementById('hf-csrf-token');
    var metaDir = document.getElementById('hf-meta-dir');
    var metaMode = document.getElementById('hf-meta-mode');
    var refreshBtn = document.getElementById('hf-refresh-list-btn');
    var refreshLogsBtn = document.getElementById('hf-refresh-logs-btn');
    var refreshLogsBtnDefaultLabel = refreshLogsBtn ? String(refreshLogsBtn.textContent || '').trim() : '';
    var runAuditBtn = document.getElementById('hf-run-audit-btn');
    var openPublicBtn = document.getElementById('hf-open-public');
    var openQuarantineBtn = document.getElementById('hf-open-quarantine');
    var exitQuarantineBtn = document.getElementById('hf-exit-quarantine');
    var openParentBtn = document.getElementById('hf-open-parent');
    var filterNameInput = document.getElementById('hf-filter-name');
    var filterExtInput = document.getElementById('hf-filter-ext');
    var filterExtOptions = document.getElementById('hf-filter-ext-options');
    var resultsLimitSelect = document.getElementById('hf-results-limit');
    var filterClearBtn = document.getElementById('hf-filter-clear-btn');
    var filterCount = document.getElementById('hf-filter-count');

    var modal = document.getElementById('hf-audit-modal');
    var modalSub = document.getElementById('hf-modal-sub');
    var modalOutput = document.getElementById('hf-modal-output');
    var modalClose = document.getElementById('hf-modal-close');
    var modalPrint = document.getElementById('hf-modal-print');
    var modalDownloadTxt = document.getElementById('hf-modal-download-txt');
    var modalDownloadEvidence = document.getElementById('hf-modal-download-evidence');
    var modalDownloadPng = document.getElementById('hf-modal-download-png');
    var modalQuarantineFile = document.getElementById('hf-modal-quarantine-file');
    var modalRestoreFile = document.getElementById('hf-modal-restore-file');
    var modalDeleteFile = document.getElementById('hf-modal-delete-file');
    var riskScore = document.getElementById('hf-risk-score');
    var riskOrigin = document.getElementById('hf-risk-origin');
    var riskLastEvent = document.getElementById('hf-risk-last-event');
    var riskMainIp = document.getElementById('hf-risk-main-ip');
    var timelineFilter = document.getElementById('hf-timeline-filter');
    var timelineCount = document.getElementById('hf-timeline-count');
    var timelineBody = document.getElementById('hf-timeline-body');

    var loadingOverlay = document.getElementById('hf-loading-overlay');
    var loadingTitle = document.getElementById('hf-loading-title');
    var loadingText = document.getElementById('hf-loading-text');
    var loadingMsg = document.getElementById('hf-loading-msg');
    var confirmModal = document.getElementById('hf-confirm-modal');
    var confirmTitle = document.getElementById('hf-confirm-title');
    var confirmMessage = document.getElementById('hf-confirm-message');
    var confirmCancel = document.getElementById('hf-confirm-cancel');
    var confirmOk = document.getElementById('hf-confirm-ok');
    var confirmResolve = null;
    var quarantineDir = '.hforensic/quarantine';
    var busyState = false;
    var refreshLogsCooldownUntil = 0;
    var refreshLogsCooldownTimer = null;
    var refreshLogsCooldownInterval = null;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildApiUrl(action, dir, extraParams) {
        var url = new URL(window.location.href);
        url.searchParams.delete('action');
        url.searchParams.delete('ajax');
        url.searchParams.delete('file');
        url.searchParams.set('action', action);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('dir', typeof dir === 'string' ? dir : state.currentDir);
        if (extraParams && typeof extraParams === 'object') {
            Object.keys(extraParams).forEach(function (key) {
                if (typeof extraParams[key] !== 'undefined' && extraParams[key] !== null) {
                    url.searchParams.set(key, String(extraParams[key]));
                }
            });
        }
        url.searchParams.set('_ts', String(Date.now()));
        return url.toString();
    }

    function showAlert(type, lines) {
        var msgs = Array.isArray(lines) ? lines.filter(Boolean) : [String(lines || '')];
        if (!msgs.length) {
            return;
        }

        var box = document.createElement('div');
        box.className = 'hf-panel';
        var note = document.createElement('div');
        note.className = 'hf-note ' + (type === 'ok' ? 'hf-note-ok' : 'hf-note-error');

        msgs.forEach(function (line) {
            var div = document.createElement('div');
            div.textContent = line;
            note.appendChild(div);
        });

        box.appendChild(note);
        alertHost.innerHTML = '';
        alertHost.appendChild(box);
    }

    function getRefreshLogsCooldownRemaining() {
        if (!refreshLogsCooldownUntil) {
            return 0;
        }
        var remaining = Math.ceil((refreshLogsCooldownUntil - Date.now()) / 1000);
        return remaining > 0 ? remaining : 0;
    }

    function refreshLogsWaitMessage(seconds) {
        var waitSeconds = parseInt(seconds, 10);
        if (!Number.isFinite(waitSeconds) || waitSeconds < 1) {
            waitSeconds = 1;
        }
        return tr('logs_refresh_wait_prefix') + String(waitSeconds) + tr('logs_refresh_wait_suffix');
    }

    function updateRefreshLogsButtonLabel() {
        if (!refreshLogsBtn) {
            return;
        }

        var remaining = getRefreshLogsCooldownRemaining();
        if (remaining > 0) {
            var baseLabel = refreshLogsBtnDefaultLabel || tr('loading_logs_title');
            refreshLogsBtn.textContent = baseLabel + ' (' + String(remaining) + 's)';
            return;
        }

        refreshLogsBtn.textContent = refreshLogsBtnDefaultLabel || tr('loading_logs_title');
    }

    function setRefreshLogsCooldown(seconds) {
        var waitSeconds = parseInt(seconds, 10);
        if (!Number.isFinite(waitSeconds) || waitSeconds < 1) {
            refreshLogsCooldownUntil = 0;
            if (refreshLogsCooldownTimer) {
                clearTimeout(refreshLogsCooldownTimer);
                refreshLogsCooldownTimer = null;
            }
            if (refreshLogsCooldownInterval) {
                clearInterval(refreshLogsCooldownInterval);
                refreshLogsCooldownInterval = null;
            }
            updateRefreshLogsButtonLabel();
            setBusy(busyState);
            return;
        }

        refreshLogsCooldownUntil = Date.now() + (waitSeconds * 1000);
        if (refreshLogsCooldownTimer) {
            clearTimeout(refreshLogsCooldownTimer);
            refreshLogsCooldownTimer = null;
        }
        if (refreshLogsCooldownInterval) {
            clearInterval(refreshLogsCooldownInterval);
            refreshLogsCooldownInterval = null;
        }

        updateRefreshLogsButtonLabel();

        refreshLogsCooldownTimer = setTimeout(function () {
            refreshLogsCooldownUntil = 0;
            refreshLogsCooldownTimer = null;
            if (refreshLogsCooldownInterval) {
                clearInterval(refreshLogsCooldownInterval);
                refreshLogsCooldownInterval = null;
            }
            updateRefreshLogsButtonLabel();
            setBusy(busyState);
        }, (waitSeconds * 1000) + 100);

        refreshLogsCooldownInterval = setInterval(function () {
            var remaining = getRefreshLogsCooldownRemaining();
            if (remaining <= 0) {
                if (refreshLogsCooldownInterval) {
                    clearInterval(refreshLogsCooldownInterval);
                    refreshLogsCooldownInterval = null;
                }
                updateRefreshLogsButtonLabel();
                setBusy(busyState);
                return;
            }
            updateRefreshLogsButtonLabel();
        }, 1000);

        setBusy(busyState);
    }

    function ensureRefreshLogsAllowed() {
        var remaining = getRefreshLogsCooldownRemaining();
        if (remaining > 0) {
            showAlert('error', [refreshLogsWaitMessage(remaining)]);
            setBusy(busyState);
            return false;
        }
        return true;
    }

    function setBusy(isBusy) {
        busyState = !!isBusy;
        var hasAuditPath = String(state.lastAuditPath || '').trim() !== '';
        var hasQuarantine = isCurrentAuditInQuarantine();
        var inQ = isInQuarantineDir();
        var logsCooldown = getRefreshLogsCooldownRemaining() > 0;
        refreshBtn.disabled = busyState;
        refreshLogsBtn.disabled = busyState || logsCooldown;
        runAuditBtn.disabled = busyState;
        updateRefreshLogsButtonLabel();
        if (modalQuarantineFile) {
            modalQuarantineFile.disabled = busyState || !hasAuditPath || hasQuarantine || inQ;
        }
        if (modalRestoreFile) {
            modalRestoreFile.disabled = busyState || !hasQuarantine || !inQ;
        }
        if (modalDeleteFile) {
            modalDeleteFile.disabled = busyState || !hasAuditPath;
        }
        if (confirmCancel) {
            confirmCancel.disabled = busyState;
        }
        if (confirmOk) {
            confirmOk.disabled = busyState;
        }
    }

    function appendCsrf(body) {
        var token = (csrfInput && csrfInput.value) ? csrfInput.value : state.csrfToken;
        if (token) {
            body.set('csrf_token', token);
        }
    }

    function showLoading(title, text, message) {
        if (!loadingOverlay) {
            return;
        }
        if (loadingTitle && title) {
            loadingTitle.textContent = title;
        }
        if (loadingText && text) {
            loadingText.textContent = text;
        }
        if (loadingMsg) {
            loadingMsg.textContent = message || tr('wait');
        }
        loadingOverlay.classList.add('is-open');
    }

    function hideLoading() {
        if (!loadingOverlay) {
            return;
        }
        loadingOverlay.classList.remove('is-open');
    }

    function closeConfirmDialog(confirmed) {
        if (!confirmModal) {
            return;
        }

        confirmModal.classList.remove('is-open');
        confirmModal.setAttribute('aria-hidden', 'true');

        if (typeof confirmResolve === 'function') {
            var resolver = confirmResolve;
            confirmResolve = null;
            resolver(!!confirmed);
        }
    }

    function openConfirmDialog(title, message, okLabel) {
        if (!confirmModal) {
            return Promise.resolve(false);
        }

        return new Promise(function (resolve) {
            confirmResolve = resolve;
            if (confirmTitle) {
                confirmTitle.textContent = title || tr('confirm_action');
            }
            if (confirmMessage) {
                confirmMessage.textContent = message || '';
            }
            if (confirmOk) {
                confirmOk.textContent = okLabel || tr('confirm');
            }
            confirmModal.classList.add('is-open');
            confirmModal.setAttribute('aria-hidden', 'false');
        });
    }

    function stripAnsi(text) {
        return String(text || '').replace(/\x1B\[[0-9;]*[A-Za-z]/g, '');
    }

    function normalizeCompare(text) {
        return stripAnsi(text || '')
            .toUpperCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function classifyService(service) {
        var normalized = normalizeCompare(service || '');
        if (normalized.indexOf('FTP') !== -1) {
            return 'FTP/SFTP';
        }
        if (normalized.indexOf('HTTP') !== -1 || normalized.indexOf('WEB') !== -1) {
            return 'WEB';
        }
        if (normalized.indexOf('API') !== -1 || normalized.indexOf('CPANEL') !== -1 || normalized.indexOf('PAINEL') !== -1) {
            return 'PAINEL';
        }
        if (normalized.indexOf('SSH') !== -1 || normalized.indexOf('SFTP') !== -1) {
            return 'SSH';
        }
        return 'OUTROS';
    }

    function parseAuditEvidence(output) {
        var text = stripAnsi(output || '');
        var lines = text.split(/\r?\n/);
        var matches = [];
        var current = null;

        lines.forEach(function (rawLine) {
            var line = String(rawLine || '').trim();
            if (!line) {
                return;
            }

            var matchHeader = line.match(/MATCH\s+(?:ENCONTRADO|FOUND)\s*\(([^)]+)\)/i);
            if (matchHeader) {
                if (current) {
                    matches.push(current);
                }
                current = {
                    service: String(matchHeader[1] || '').trim(),
                    source_type: classifyService(matchHeader[1] || ''),
                    ip: '',
                    date: '',
                    action: '',
                    source: '',
                    detail: ''
                };
                return;
            }

            if (!current) {
                return;
            }

            if (/^(IP Origem|Source IP):/i.test(line)) {
                current.ip = line.replace(/^(IP Origem|Source IP):\s*/i, '').trim();
                return;
            }
            if (/^(Data\/Hora|Date\/Time):/i.test(line)) {
                current.date = line.replace(/^(Data\/Hora|Date\/Time):\s*/i, '').trim();
                return;
            }
            if (/^((Ação|Acao)\/Alvo|Action\/Target):/i.test(line)) {
                current.action = line.replace(/^((Ação|Acao)\/Alvo|Action\/Target):\s*/i, '').trim();
                return;
            }
            if (/^(Log Fonte|Log Source):/i.test(line)) {
                current.source = line.replace(/^(Log Fonte|Log Source):\s*/i, '').trim();
                return;
            }
            if (/^(Detalhe|Detail):/i.test(line)) {
                current.detail = line.replace(/^(Detalhe|Detail):\s*/i, '').trim();
            }
        });

        if (current) {
            matches.push(current);
        }

        var normalized = normalizeCompare(text);
        var score = 0;
        if (normalized.indexOf('ALERTA CRITICO') !== -1 || normalized.indexOf('CRITICAL ALERT') !== -1) {
            score += 35;
        }
        if (normalized.indexOf('SPOOFING DETECTADO') !== -1 || normalized.indexOf('SPOOFING DETECTED') !== -1) {
            score += 25;
        }
        if (normalized.indexOf('SINTAXE SUSPEITA') !== -1 || normalized.indexOf('SUSPICIOUS SYNTAX') !== -1) {
            score += 20;
        }
        score += Math.min(matches.length * 4, 24);

        var sourceCounts = { 'FTP/SFTP': 0, WEB: 0, PAINEL: 0, SSH: 0, OUTROS: 0 };
        var ipCounts = {};
        matches.forEach(function (item) {
            var sourceType = classifyService(item.service || '');
            item.source_type = sourceType;
            sourceCounts[sourceType] = (sourceCounts[sourceType] || 0) + 1;

            var ip = String(item.ip || '').trim();
            if (ip && ip !== 'N/A' && ip !== '-') {
                ipCounts[ip] = (ipCounts[ip] || 0) + 1;
            }
        });

        if (sourceCounts['FTP/SFTP'] > 0) {
            score += 12;
        }
        if (sourceCounts.WEB > 0) {
            score += 10;
        }
        if (sourceCounts.PAINEL > 0) {
            score += 10;
        }
        if (sourceCounts.SSH > 0) {
            score += 8;
        }
        if (score > 100) {
            score = 100;
        }

        var sortedOrigins = Object.keys(sourceCounts).sort(function (a, b) {
            return sourceCounts[b] - sourceCounts[a];
        });
        var likelyOrigin = sourceCounts[sortedOrigins[0]] > 0 ? sortedOrigins[0] : tr('no_evidence');

        var topIp = '-';
        var topCount = 0;
        Object.keys(ipCounts).forEach(function (ip) {
            if (ipCounts[ip] > topCount) {
                topCount = ipCounts[ip];
                topIp = ip;
            }
        });

        var lastEvent = '-';
        if (matches.length) {
            var latest = matches[matches.length - 1];
            lastEvent = latest.date || latest.action || latest.service || '-';
        }

        var level = tr('risk_low');
        var levelClass = 'hf-risk-low';
        if (score >= 70) {
            level = tr('risk_high');
            levelClass = 'hf-risk-high';
        } else if (score >= 40) {
            level = tr('risk_medium');
            levelClass = 'hf-risk-medium';
        }

        return {
            raw: text,
            matches: matches,
            summary: {
                score: score,
                level: level,
                levelClass: levelClass,
                likelyOrigin: likelyOrigin,
                lastEvent: lastEvent,
                topIp: topIp,
                sourceCounts: sourceCounts
            }
        };
    }

    function renderRiskSummary(summary) {
        var safe = summary || {
            score: 0,
            level: tr('risk_low'),
            levelClass: 'hf-risk-low',
            likelyOrigin: tr('no_evidence'),
            lastEvent: '-',
            topIp: '-'
        };

        if (riskScore) {
            riskScore.textContent = String(safe.score) + '/100 (' + safe.level + ')';
            riskScore.className = 'hf-risk-v ' + safe.levelClass;
        }
        if (riskOrigin) {
            riskOrigin.textContent = safe.likelyOrigin || '-';
            riskOrigin.className = 'hf-risk-v';
        }
        if (riskLastEvent) {
            riskLastEvent.textContent = safe.lastEvent || '-';
            riskLastEvent.className = 'hf-risk-v';
        }
        if (riskMainIp) {
            riskMainIp.textContent = safe.topIp || '-';
            riskMainIp.className = 'hf-risk-v';
        }
    }

    function renderTimeline() {
        if (!timelineBody) {
            return;
        }

        var selected = timelineFilter ? String(timelineFilter.value || 'all') : 'all';
        var matches = (state.lastEvidence && Array.isArray(state.lastEvidence.matches)) ? state.lastEvidence.matches : [];
        var filtered = matches.filter(function (item) {
            return selected === 'all' || item.source_type === selected;
        });

        if (timelineCount) {
            timelineCount.textContent = String(filtered.length) + ' ' + tr('events_count');
        }

        if (!filtered.length) {
            timelineBody.innerHTML = '<tr><td colspan="5" class="hf-muted">' + escapeHtml(tr('no_evidence_selected_filter')) + '</td></tr>';
            return;
        }

        var html = '';
        filtered.forEach(function (item) {
            var source = (item.source || '-');
            if (item.detail) {
                source += ' | ' + item.detail;
            }
            html += '<tr>';
            html += '<td>' + escapeHtml(item.date || '-') + '</td>';
            html += '<td><span class="hf-chip">' + escapeHtml(item.source_type || 'OUTROS') + '</span></td>';
            html += '<td>' + escapeHtml(item.ip || '-') + '</td>';
            html += '<td>' + escapeHtml(item.action || item.service || '-') + '</td>';
            html += '<td>' + escapeHtml(source) + '</td>';
            html += '</tr>';
        });

        timelineBody.innerHTML = html;
    }

    function isCurrentAuditInQuarantine() {
        var auditPath = String(state.lastAuditPath || '').trim();
        var q = state.lastQuarantine;
        if (!auditPath || !q) {
            return false;
        }

        var originalPath = String(q.original_path || '').trim();
        var quarantinePath = String(q.quarantine_path || '').trim();
        var boundPath = String(state.lastQuarantineForPath || '').trim();

        if (boundPath && boundPath !== auditPath) {
            return false;
        }

        return (originalPath !== '' && originalPath === auditPath) || (quarantinePath !== '' && quarantinePath === auditPath);
    }

    function updateRestoreButtonState() {
        var hasAuditPath = String(state.lastAuditPath || '').trim() !== '';
        var hasQuarantine = isCurrentAuditInQuarantine();
        var inQ = isInQuarantineDir();

        if (modalQuarantineFile) {
            modalQuarantineFile.style.display = inQ ? 'none' : '';
        }
        if (modalRestoreFile) {
            modalRestoreFile.style.display = inQ ? '' : 'none';
        }

        if (modalRestoreFile) {
            modalRestoreFile.disabled = !hasQuarantine || !inQ;
        }
        if (modalQuarantineFile) {
            modalQuarantineFile.disabled = !hasAuditPath || hasQuarantine || inQ;
        }
        if (modalDeleteFile) {
            modalDeleteFile.disabled = !hasAuditPath;
        }
    }

    function refreshQuarantineStatus(path) {
        var targetPath = String(path || '').trim();
        var requestId = ++state.quarantineStatusRequestId;
        if (!targetPath) {
            state.lastQuarantine = null;
            state.lastQuarantineForPath = '';
            updateRestoreButtonState();
            return Promise.resolve();
        }

        return fetch(buildApiUrl('quarantine_status', state.currentDir, { file_path: targetPath }), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (requestId !== state.quarantineStatusRequestId) {
                    return;
                }

                if (data && data.ok && data.quarantine) {
                    var q = data.quarantine || {};
                    var qOriginal = String(q.original_path || '').trim();
                    var qPath = String(q.quarantine_path || '').trim();
                    if ((qOriginal !== '' && qOriginal === targetPath) || (qPath !== '' && qPath === targetPath)) {
                        state.lastQuarantine = q;
                        state.lastQuarantineForPath = targetPath;
                    } else {
                        state.lastQuarantine = null;
                        state.lastQuarantineForPath = '';
                    }
                } else {
                    state.lastQuarantine = null;
                    state.lastQuarantineForPath = '';
                }
                updateRestoreButtonState();
            })
            .catch(function () {
                if (requestId !== state.quarantineStatusRequestId) {
                    return;
                }
                state.lastQuarantine = null;
                state.lastQuarantineForPath = '';
                updateRestoreButtonState();
            });
    }

    function updateMeta(dir, mode, parentDir) {
        state.currentDir = dir || '';
        state.parentDir = parentDir || '';
        state.listingMode = mode || '';

        if (metaDir) {
            metaDir.textContent = state.currentDir === '' ? tr('home_label') : state.currentDir;
        }
        if (metaMode) {
            metaMode.textContent = state.listingMode ? state.listingMode.toUpperCase() : tr('unavailable');
        }
        if (dirInput) {
            dirInput.value = state.currentDir;
        }
        renderDirNav();
        syncQuarantineNavButtons();
        updateRestoreButtonState();
    }

    function isInQuarantineDir() {
        var current = String(state.currentDir || '').replace(/^\/+/, '');
        return current === quarantineDir || current.indexOf(quarantineDir + '/') === 0;
    }

    function syncQuarantineNavButtons() {
        var inQ = isInQuarantineDir();
        if (openQuarantineBtn) {
            openQuarantineBtn.disabled = inQ;
        }
        if (exitQuarantineBtn) {
            exitQuarantineBtn.disabled = !inQ;
        }
    }

    function renderDirNav() {
        if (!dirNav) {
            return;
        }

        var parts = state.currentDir ? state.currentDir.split('/').filter(Boolean) : [];
        var html = '<span class="hf-dir-nav-label">' + escapeHtml(tr('navigation_label')) + '</span>';
        if (state.parentDir) {
            html += '<button type="button" class="hf-btn-link" data-open-dir="' + escapeHtml(state.parentDir) + '">../</button>';
        }
        html += '<button type="button" class="hf-btn-link" data-open-dir="">' + escapeHtml(tr('home')) + '</button>';

        var walk = '';
        parts.forEach(function (part) {
            walk = walk ? (walk + '/' + part) : part;
            html += '<span class="hf-dir-nav-sep">/</span>';
            html += '<button type="button" class="hf-btn-link" data-open-dir="' + escapeHtml(walk) + '">' + escapeHtml(part) + '</button>';
        });

        dirNav.innerHTML = html;
    }

    function itemExt(name) {
        var fileName = String(name || '');
        var dotPos = fileName.lastIndexOf('.');
        if (dotPos <= 0 || dotPos >= fileName.length - 1) {
            return '';
        }
        return fileName.slice(dotPos + 1).toLowerCase();
    }

    function normalizeExtInput(rawExt) {
        var ext = String(rawExt || '').trim().toLowerCase();
        if (ext === '' || ext === '*' || ext === 'all' || ext === 'todas') {
            return '';
        }
        if (ext.charAt(0) === '.') {
            ext = ext.slice(1);
        }
        return ext;
    }

    function updateFilterCount(visibleCount, filteredCount, totalCount) {
        if (!filterCount) {
            return;
        }
        filterCount.textContent = String(visibleCount) + ' ' + tr('showing_items') + ' ' + String(filteredCount) + ' (' + tr('total') + ' ' + String(totalCount) + ')';
    }

    function rebuildExtensionFilterOptions(items) {
        if (!filterExtOptions) {
            return;
        }

        var extMap = {};

        (Array.isArray(items) ? items : []).forEach(function (item) {
            if (!item || item.is_dir) {
                return;
            }
            var ext = itemExt(item.name);
            if (ext !== '') {
                extMap[ext] = true;
            }
        });

        var exts = Object.keys(extMap).sort();
        var options = '';
        exts.forEach(function (ext) {
            options += '<option value="' + escapeHtml(ext) + '"></option>';
        });
        filterExtOptions.innerHTML = options;
    }

    function applyFilters() {
        var allItems = Array.isArray(state.allItems) ? state.allItems : [];
        var nameNeedle = filterNameInput ? String(filterNameInput.value || '').trim().toLowerCase() : '';
        var extNeedle = normalizeExtInput(filterExtInput ? filterExtInput.value : '');
        var limitValue = resultsLimitSelect ? String(resultsLimitSelect.value || '20') : '20';

        var filtered = allItems.filter(function (item) {
            var name = String(item && item.name ? item.name : '');
            if (nameNeedle !== '' && name.toLowerCase().indexOf(nameNeedle) === -1) {
                return false;
            }

            if (extNeedle !== '') {
                if (!item || item.is_dir) {
                    return false;
                }
                var ext = itemExt(name);
                return ext === extNeedle;
            }

            return true;
        });

        var filteredCount = filtered.length;
        var visible = filtered;
        if (limitValue !== 'all') {
            var limit = parseInt(limitValue, 10);
            if (Number.isFinite(limit) && limit > 0) {
                visible = filtered.slice(0, limit);
            }
        }

        renderTable(visible);
        updateFilterCount(visible.length, filteredCount, allItems.length);
    }

    function renderTable(items) {
        if (!Array.isArray(items) || !items.length) {
            tableBody.innerHTML = '<tr><td colspan="5" class="hf-muted">' + escapeHtml(tr('no_items_found')) + '</td></tr>';
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var isDir = !!item.is_dir;
            var name = escapeHtml(item.name || '');
            var size = escapeHtml(item.size || '-');
            var mime = escapeHtml(item.mime || '-');
            var type = isDir ? tr('directory') : tr('file');

            html += '<tr>';
            html += '<td class="' + (isDir ? 'hf-dir' : '') + '">';
            if (isDir) {
                html += '<button type="button" class="hf-dir-link" data-open-dir="' + escapeHtml(item.next_dir || '') + '">' + name + '</button>';
            } else {
                html += name;
            }
            html += '</td>';
            html += '<td>' + type + '</td>';
            html += '<td>' + size + '</td>';
            html += '<td>' + mime + '</td>';
            html += '<td><div class="hf-inline-actions">';

            if (isDir) {
                html += '<button type="button" class="hf-btn-link" data-open-dir="' + escapeHtml(item.next_dir || '') + '">' + escapeHtml(tr('open')) + '</button>';
            } else {
                html += '<button type="button" class="hf-btn" data-audit-file="' + escapeHtml(item.file_path || '') + '">' + escapeHtml(tr('audit')) + '</button>';
            }

            html += '</div></td></tr>';
        });

        tableBody.innerHTML = html;
    }

    function loadListing(dir, options) {
        options = options || {};
        var silent = !!options.silent;
        var noBusy = !!options.noBusy;
        var showOverlay = !!options.showOverlay;
        var requestedDir = typeof dir === 'string' ? dir : state.currentDir;
        if (showOverlay) {
            showLoading(
                options.title || tr('loading_files_title'),
                options.text || tr('loading_files_text'),
                options.message || tr('wait')
            );
        }

        if (!noBusy) {
            setBusy(true);
        }
        return fetch(buildApiUrl('list_files', dir), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    var errs = (data && data.errors && data.errors.length) ? data.errors : [tr('listing_failed')];
                    var requested = String(requestedDir || '').replace(/^\/+/, '');
                    var isQuarantineRequest = requested === quarantineDir || requested.indexOf(quarantineDir + '/') === 0;
                    var notFound = errs.some(function (line) {
                        return /Diret[óo]rio n[aã]o encontrado|not found/i.test(String(line || ''));
                    });

                    if (isQuarantineRequest && notFound) {
                        updateMeta(requested, data && data.mode ? data.mode : 'local', data && data.parent_dir ? data.parent_dir : '.hforensic');
                        state.allItems = [];
                        applyFilters();
                        if (!silent) {
                            showAlert('ok', [tr('quarantine_empty')]);
                        }
                        return;
                    }
                    if (!silent) {
                        showAlert('error', errs);
                    }
                    return;
                }

                updateMeta(data.dir || '', data.mode || '', data.parent_dir || '');
                state.allItems = Array.isArray(data.items) ? data.items : [];
                rebuildExtensionFilterOptions(state.allItems);
                applyFilters();

                if (!silent && data.notices && data.notices.length) {
                    showAlert('ok', data.notices);
                }
            })
            .catch(function () {
                if (!silent) {
                    showAlert('error', [tr('comm_list_error')]);
                }
            })
            .finally(function () {
                if (!noBusy) {
                    setBusy(false);
                }
                if (showOverlay) {
                    hideLoading();
                }
            });
    }

    function openModal(path, output, exitCode) {
        state.lastAuditPath = path || '';
        state.lastAuditText = output || '';
        state.lastAuditExitCode = exitCode;
        state.lastQuarantine = null;
        state.lastQuarantineForPath = '';

        var statusText = Number(exitCode) === 0 ? tr('status_success') : (tr('status_code') + ' ' + String(exitCode));
        modalSub.textContent = (path || '-') + ' | ' + tr('status_label') + ': ' + statusText;
        modalOutput.textContent = output || tr('no_output');
        state.lastEvidence = parseAuditEvidence(output || '');
        if (timelineFilter) {
            timelineFilter.value = 'all';
        }
        renderRiskSummary(state.lastEvidence.summary);
        renderTimeline();
        refreshQuarantineStatus(path || '');
        updateRestoreButtonState();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function runAudit(path) {
        var targetPath = (path || '').trim();
        if (!targetPath) {
            showAlert('error', [tr('no_file_to_audit')]);
            return;
        }

        fileInput.value = targetPath;
        setBusy(true);

        var body = new URLSearchParams();
        body.set('file_path', targetPath);
        body.set('dir', state.currentDir || '');
        appendCsrf(body);

        fetch(buildApiUrl('run_audit', state.currentDir), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data) {
                    showAlert('error', [tr('invalid_audit_response')]);
                    return;
                }

                var exitCode = typeof data.exit_code !== 'undefined' ? data.exit_code : 1;
                var output = data.output || '';

                if (data.ok) {
                    showAlert('ok', [data.message || tr('audit_ok')]);
                } else {
                    var errs = (data.errors && data.errors.length) ? data.errors : [data.message || tr('audit_fail')];
                    showAlert('error', errs);
                }

                openModal(data.file_path || targetPath, output, exitCode);

                // Always refresh table after audit to reflect newly uploaded/changed files.
                loadListing(state.currentDir, { silent: true, noBusy: true });
            })
            .catch(function () {
                showAlert('error', [tr('comm_audit_error')]);
            })
            .finally(function () {
                setBusy(false);
            });
    }

    function quarantineCurrentFile() {
        var targetPath = String(state.lastAuditPath || '').trim();
        var movedToQuarantine = false;
        if (!targetPath) {
            showAlert('error', [tr('no_file_for_quarantine')]);
            return;
        }
        if (isInQuarantineDir()) {
            showAlert('ok', [tr('already_quarantined')]);
            return;
        }
        if (isCurrentAuditInQuarantine()) {
            showAlert('ok', [tr('already_quarantined')]);
            return;
        }

        openConfirmDialog(
            tr('quarantine_confirm_title'),
            tr('quarantine_confirm_msg_prefix') + targetPath + tr('quarantine_confirm_msg_suffix'),
            tr('quarantine_confirm_btn')
        ).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            setBusy(true);
            showLoading(tr('quarantine_loading_title'), tr('quarantine_loading_text'), tr('wait'));

            var body = new URLSearchParams();
            body.set('file_path', targetPath);
            body.set('dir', state.currentDir || '');
            appendCsrf(body);

            fetch(buildApiUrl('quarantine_file', state.currentDir), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        movedToQuarantine = true;
                        state.lastQuarantine = data.quarantine || null;
                        state.lastQuarantineForPath = targetPath;
                        updateRestoreButtonState();
                        closeModal();
                        showAlert('ok', [data.message || tr('quarantine_ok')]);
                        loadListing(state.currentDir, { silent: true, noBusy: true });
                    } else {
                        var errs = (data && data.errors && data.errors.length) ? data.errors : [data && data.message ? data.message : tr('quarantine_fail')];
                        showAlert('error', errs);
                    }
                })
                .catch(function () {
                    showAlert('error', [tr('comm_quarantine_error')]);
                })
                .finally(function () {
                    hideLoading();
                    if (movedToQuarantine) {
                        closeModal();
                    }
                    setBusy(false);
                });
        });
    }

    function restoreCurrentFile() {
        var auditPath = String(state.lastAuditPath || '').trim();
        var restored = false;
        if (!isInQuarantineDir()) {
            showAlert('error', [tr('no_quarantine_copy')]);
            return;
        }
        if (!auditPath) {
            showAlert('error', [tr('no_file_restore')]);
            return;
        }

        var ensureQuarantine = isCurrentAuditInQuarantine() ? Promise.resolve() : refreshQuarantineStatus(auditPath);

        ensureQuarantine.then(function () {
            if (!isCurrentAuditInQuarantine()) {
                showAlert('error', [tr('no_quarantine_copy')]);
                return;
            }

            var quarantine = state.lastQuarantine || {};
            var originalPath = String(quarantine.original_path || auditPath).trim();
            var quarantinePath = String(quarantine.quarantine_path || '').trim();
            if (!originalPath || !quarantinePath) {
                showAlert('error', [tr('no_quarantine_copy')]);
                return;
            }

            openConfirmDialog(
                tr('restore_confirm_title'),
                tr('restore_confirm_msg_prefix') + originalPath + tr('restore_confirm_msg_suffix'),
                tr('restore_confirm_btn')
            ).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            setBusy(true);
            showLoading(tr('restore_loading_title'), tr('restore_loading_text'), tr('wait'));

            var body = new URLSearchParams();
            body.set('file_path', originalPath);
            body.set('quarantine_path', quarantinePath);
            body.set('dir', state.currentDir || '');
            appendCsrf(body);

            fetch(buildApiUrl('restore_file', state.currentDir), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        restored = true;
                        state.lastQuarantine = null;
                        state.lastQuarantineForPath = '';
                        updateRestoreButtonState();
                        closeModal();
                        showAlert('ok', [data.message || tr('restore_ok')]);
                        loadListing(state.currentDir, { silent: true, noBusy: true });
                    } else {
                        var errs = (data && data.errors && data.errors.length) ? data.errors : [data && data.message ? data.message : tr('restore_fail')];
                        showAlert('error', errs);
                    }
                })
                .catch(function () {
                    showAlert('error', [tr('comm_restore_error')]);
                })
                .finally(function () {
                    hideLoading();
                    if (restored) {
                        closeModal();
                    }
                    setBusy(false);
                });
            });
        });
    }

    function deleteCurrentFile() {
        var targetPath = String(state.lastAuditPath || '').trim();
        if (!targetPath) {
            showAlert('error', [tr('no_file_delete')]);
            return;
        }

        openConfirmDialog(
            tr('delete_confirm_title'),
            tr('delete_confirm_msg_prefix') + targetPath + tr('delete_confirm_msg_suffix'),
            tr('continue_label')
        )
            .then(function (firstConfirmed) {
                if (!firstConfirmed) {
                    return false;
                }
                return openConfirmDialog(
                    tr('delete_confirm_final_title'),
                    tr('delete_confirm_final_msg_prefix') + targetPath + tr('delete_confirm_final_msg_suffix'),
                    tr('delete_confirm_final_btn')
                );
            })
            .then(function (secondConfirmed) {
                if (!secondConfirmed) {
                    return;
                }

                setBusy(true);
                showLoading(tr('deleting_file_title'), tr('deleting_file_text'), tr('wait'));

                var body = new URLSearchParams();
                body.set('file_path', targetPath);
                body.set('dir', state.currentDir || '');
                appendCsrf(body);

                fetch(buildApiUrl('delete_file', state.currentDir), {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            showAlert('ok', [data.message || tr('delete_ok')]);
                            closeModal();
                            state.lastAuditPath = '';
                            state.lastAuditText = '';
                            state.lastAuditExitCode = null;
                            state.lastEvidence = { matches: [], summary: null };
                            state.lastQuarantine = null;
                            state.lastQuarantineForPath = '';
                            updateRestoreButtonState();
                            loadListing(state.currentDir, { silent: true, noBusy: true });
                        } else {
                            var errs = (data && data.errors && data.errors.length) ? data.errors : [data && data.message ? data.message : tr('delete_fail')];
                            showAlert('error', errs);
                        }
                    })
                    .catch(function () {
                        showAlert('error', [tr('comm_delete_error')]);
                    })
                    .finally(function () {
                        hideLoading();
                        setBusy(false);
                    });
            });
    }

    function basename(path) {
        var parts = String(path || '').split('/');
        return parts[parts.length - 1] || 'audit';
    }

    function printAudit() {
        var text = state.lastAuditText || '';
        var title = 'High Forensic - ' + (state.lastAuditPath || tr('result_label'));

        var win = window.open('', '_blank');
        if (!win) {
            showAlert('error', [tr('print_window_error')]);
            return;
        }

        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title>');
        win.document.write('<style>body{font-family:Consolas,monospace;padding:20px;}pre{white-space:pre-wrap;word-break:break-word;border:1px solid #ddd;padding:12px;}</style>');
        win.document.write('</head><body><h2>' + escapeHtml(title) + '</h2><pre>' + escapeHtml(text) + '</pre></body></html>');
        win.document.close();
        win.focus();
        win.print();
    }

    function downloadTxt() {
        var text = state.lastAuditText || '';
        var fileName = basename(state.lastAuditPath || 'audit') + '.audit.txt';
        var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function downloadEvidenceJson() {
        var evidence = state.lastEvidence && state.lastEvidence.summary ? state.lastEvidence : parseAuditEvidence(state.lastAuditText || '');
        state.lastEvidence = evidence;

        var payload = {
            generated_at: new Date().toISOString(),
            file_path: state.lastAuditPath || '',
            exit_code: state.lastAuditExitCode,
            summary: evidence.summary || null,
            matches: evidence.matches || [],
            raw_output: state.lastAuditText || ''
        };

        var fileName = basename(state.lastAuditPath || 'audit') + '.evidence.json';
        var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function downloadPng() {
        var text = state.lastAuditText || '';
        if (!text) {
            showAlert('error', [tr('no_result_export')]);
            return;
        }

        var lines = text.split(/\r?\n/);
        var fontSize = 14;
        var lineHeight = 20;
        var padding = 20;

        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        ctx.font = fontSize + 'px Consolas, "Courier New", monospace';

        var maxWidth = 0;
        lines.forEach(function (line) {
            var width = ctx.measureText(line).width;
            if (width > maxWidth) {
                maxWidth = width;
            }
        });

        var maxCanvasWidth = 3600;
        var maxCanvasHeight = 12000;
        var maxLines = Math.max(1, Math.floor((maxCanvasHeight - padding * 2) / lineHeight));
        var drawLines = lines.slice(0, maxLines);
        var truncated = lines.length > maxLines;

        var width = Math.min(maxCanvasWidth, Math.ceil(maxWidth + padding * 2));
        var height = Math.ceil(drawLines.length * lineHeight + padding * 2 + (truncated ? lineHeight : 0));

        canvas.width = width;
        canvas.height = height;

        ctx.fillStyle = '#0b1f36';
        ctx.fillRect(0, 0, width, height);

        ctx.fillStyle = '#d9ebff';
        ctx.font = fontSize + 'px Consolas, "Courier New", monospace';

        var y = padding + lineHeight - 6;
        drawLines.forEach(function (line) {
            ctx.fillText(line, padding, y);
            y += lineHeight;
        });

        if (truncated) {
            ctx.fillStyle = '#f6d365';
            ctx.fillText(tr('png_truncated'), padding, y);
        }

        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = basename(state.lastAuditPath || 'audit') + '.audit.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function refreshLogs(options) {
        options = options || {};
        var body = new URLSearchParams();
        body.set('dir', state.currentDir || '');
        appendCsrf(body);
        if (options.showOverlay) {
            showLoading(
                options.title || tr('loading_logs_title'),
                options.text || tr('loading_logs_text'),
                options.message || tr('wait')
            );
        }
        return fetch(buildApiUrl('refresh_logs', state.currentDir), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var ok = !!(data && data.ok);
                var message = (data && data.message) ? data.message : (ok ? tr('logs_updated') : tr('logs_update_fail'));
                var minInterval = data && data.min_interval ? parseInt(data.min_interval, 10) : 0;
                var retryAfter = data && data.retry_after ? parseInt(data.retry_after, 10) : 0;

                if (Number.isFinite(retryAfter) && retryAfter > 0) {
                    setRefreshLogsCooldown(retryAfter);
                } else if (ok && Number.isFinite(minInterval) && minInterval > 0) {
                    setRefreshLogsCooldown(minInterval);
                }

                if (loadingMsg) {
                    loadingMsg.textContent = message;
                }

                showAlert(ok ? 'ok' : 'error', [message]);
            })
            .catch(function () {
                var message = tr('comm_logs_error');
                if (loadingMsg) {
                    loadingMsg.textContent = message;
                }
                showAlert('error', [message]);
            })
            .finally(function () {
                if (options.showOverlay) {
                    hideLoading();
                }
            });
    }

    function bindEvents() {
        document.getElementById('hf-audit-form').addEventListener('submit', function (event) {
            event.preventDefault();
            runAudit(fileInput.value);
        });

        refreshBtn.addEventListener('click', function () {
            loadListing(state.currentDir, {
                showOverlay: true,
                title: tr('loading_files_title'),
                text: tr('loading_files_text'),
                message: tr('wait')
            });
        });

        refreshLogsBtn.addEventListener('click', function () {
            if (!ensureRefreshLogsAllowed()) {
                return;
            }
            setBusy(true);
            refreshLogs({
                showOverlay: true,
                title: tr('loading_logs_title'),
                text: tr('loading_logs_text'),
                message: tr('wait')
            }).finally(function () {
                setBusy(false);
            });
        });

        openPublicBtn.addEventListener('click', function () {
            loadListing('public_html');
        });

        if (openQuarantineBtn) {
            openQuarantineBtn.addEventListener('click', function () {
                loadListing(quarantineDir);
            });
        }

        if (exitQuarantineBtn) {
            exitQuarantineBtn.addEventListener('click', function () {
                loadListing('public_html');
            });
        }

        openParentBtn.addEventListener('click', function () {
            loadListing(state.parentDir || '');
        });

        if (filterNameInput) {
            filterNameInput.addEventListener('input', function () {
                applyFilters();
            });
        }

        if (filterExtInput) {
            filterExtInput.addEventListener('input', function () {
                applyFilters();
            });
        }

        if (resultsLimitSelect) {
            resultsLimitSelect.addEventListener('change', function () {
                applyFilters();
            });
        }

        if (filterClearBtn) {
            filterClearBtn.addEventListener('click', function () {
                if (filterNameInput) {
                    filterNameInput.value = '';
                }
                if (filterExtInput) {
                    filterExtInput.value = '';
                }
                applyFilters();
            });
        }

        if (dirNav) {
            dirNav.addEventListener('click', function (event) {
                var openBtn = event.target.closest('[data-open-dir]');
                if (openBtn) {
                    loadListing(openBtn.getAttribute('data-open-dir') || '');
                }
            });
        }

        tableBody.addEventListener('click', function (event) {
            var openBtn = event.target.closest('[data-open-dir]');
            if (openBtn) {
                loadListing(openBtn.getAttribute('data-open-dir') || '');
                return;
            }

            var auditBtn = event.target.closest('[data-audit-file]');
            if (auditBtn) {
                runAudit(auditBtn.getAttribute('data-audit-file') || '');
            }
        });

        modalClose.addEventListener('click', closeModal);
        modalPrint.addEventListener('click', printAudit);
        modalDownloadTxt.addEventListener('click', downloadTxt);
        if (modalDownloadEvidence) {
            modalDownloadEvidence.addEventListener('click', downloadEvidenceJson);
        }
        modalDownloadPng.addEventListener('click', downloadPng);
        if (modalQuarantineFile) {
            modalQuarantineFile.addEventListener('click', quarantineCurrentFile);
        }
        if (modalRestoreFile) {
            modalRestoreFile.addEventListener('click', restoreCurrentFile);
        }
        modalDeleteFile.addEventListener('click', deleteCurrentFile);
        if (timelineFilter) {
            timelineFilter.addEventListener('change', function () {
                renderTimeline();
            });
        }
        if (confirmCancel) {
            confirmCancel.addEventListener('click', function () {
                closeConfirmDialog(false);
            });
        }
        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                closeConfirmDialog(true);
            });
        }
        if (confirmModal) {
            confirmModal.addEventListener('click', function (event) {
                if (event.target === confirmModal) {
                    closeConfirmDialog(false);
                }
            });
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (confirmModal && confirmModal.classList.contains('is-open')) {
                    closeConfirmDialog(false);
                    return;
                }
                closeModal();
            }
        });
    }

    bindEvents();
    rebuildExtensionFilterOptions(state.allItems);
    applyFilters();
    renderDirNav();
    syncQuarantineNavButtons();
    renderRiskSummary(null);
    renderTimeline();
    updateRestoreButtonState();

    if (autoRefreshEnabled) {
        showLoading(tr('loading_logs_title'), tr('loading_logs_first_text'), tr('wait'));
        refreshLogs()
            .finally(function () {
                return loadListing(state.currentDir);
            })
            .finally(function () {
                hideLoading();
            });
    }
}());
</script>

<?php
if ($useCpanelChrome) {
    print $cpanel->footer();
    if (method_exists($cpanel, 'end')) {
        $cpanel->end();
    }
} else {
    ?>
</body>
</html>
    <?php
}
?>
