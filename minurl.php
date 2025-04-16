<?php
// CONFIGURATION START
$config = ['db' => null, 'admin_password_hash' => null];
// CONFIGURATION END
session_start();
function tp($table)
{
    global $config;
    return $config['table_prefix'] . $table;
}
function save_config($newConf)
{
    $lines = file(__FILE__);
    $before = array_slice($lines, 0, array_search("// CONFIGURATION START", array_map("trim", $lines)) + 1);
    $after = array_slice($lines, array_search("// CONFIGURATION END", array_map("trim", $lines)));
    $newValues = '$config = ' . var_export($newConf, true) . ";\n";
    $newFile = array_merge($before, [$newValues], $after);
    file_put_contents(__FILE__, implode("", $newFile));
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
function pdo_connect()
{
    global $config;
    try {
        if ($config['db_type'] === 'sqlite') {
            return new PDO("sqlite:" . $config['db']);
        } else {
            return new PDO("mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['name'], $config['db']['user'], $config['db']['pass']);
        }
    } catch (Exception $e) {
        return null;
    }
}
function init_db($pdo)
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $is_sqlite = $driver === 'sqlite';
    $tp_links = tp("links");
    $tp_clicks = tp("clicks");
    if ($is_sqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $tp_links (id INTEGER PRIMARY KEY AUTOINCREMENT, shortcode TEXT UNIQUE, url TEXT, created_at TEXT, last_click TEXT, hits INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS $tp_clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id INTEGER, ip TEXT, server_data TEXT, clicked_at TEXT, FOREIGN KEY (link_id) REFERENCES $tp_links(id))");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $tp_links (id INT AUTO_INCREMENT PRIMARY KEY, shortcode VARCHAR(6) UNIQUE, url TEXT, created_at DATETIME, last_click DATETIME, hits INT DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS $tp_clicks (id INT AUTO_INCREMENT PRIMARY KEY, link_id INT, ip TEXT, server_data TEXT, clicked_at DATETIME, FOREIGN KEY (link_id) REFERENCES $tp_links(id))");
    }
}
function is_logged_in()
{
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}
function handle_click($pdo, $code)
{
    $stmt = $pdo->prepare("SELECT * FROM " . tp("links") . " WHERE shortcode = ?");
    $stmt->execute([$code]);
    if ($link = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $filtered_server = array_filter($_SERVER, function ($key) {
            return (substr($key, 0, 5) === 'HTTP_') || ($key === 'REQUEST_URI');
        }, ARRAY_FILTER_USE_KEY);
        $pdo->prepare("UPDATE " . tp("links") . " SET hits = hits + 1, last_click = CURRENT_TIMESTAMP WHERE id = ?")->execute([$link['id']]);
        $pdo->prepare("INSERT INTO " . tp("clicks") . " (link_id, ip, server_data, clicked_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)")->execute([$link['id'], $_SERVER['REMOTE_ADDR'], json_encode($filtered_server)]);
        header("Location: " . $link['url']);
        exit;
    } else {
        http_response_code(404);
        echo "Link not found.";
        exit;
    }
}
function footer()
{ ?>
    <footer class="text-center bg-dark py-3 text-white">Made with <span style="color:red">♥</span> by <a
            href="https://cirociampaglia.it" class="text-white" rel="noopener noreferrer" target="_blank">Ciro
            Ciampaglia</a></footer>
<?php }
if (isset($_GET['c'])) {
    $pdo = pdo_connect();
    if ($pdo)
        handle_click($pdo, $_GET['c']);
    else
        die("Database not configured.");
}

$error = null;
if ($config['db'] === null || $config['admin_password_hash'] === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newConf = ['db' => null, 'admin_password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT), 'db_type' => $_POST['dbtype'], 'table_prefix' => $_POST['prefix'] ? $_POST['prefix'] : '',];
        if ($_POST['dbtype'] === 'sqlite') {
            $sqlitePath = $_POST['sqlite_path'];
            if (@file_put_contents($sqlitePath, '') !== false) {
                try {
                    $db = new PDO('sqlite:' . $sqlitePath);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    init_db($db);
                } catch (PDOException $e) {
                    echo "Database error: " . $e->getMessage();
                    die();
                }
                $newConf['db'] = $_POST['sqlite_path'];
                save_config($newConf);
            } else {
                //echo '<div class="alert alert-danger">Failed to write SQLite file.</div>';
                $error = 'Failed to write SQLite file.';
            }
        } else {
            try {
                $pdo = new PDO("mysql:host={$_POST['host']};dbname={$_POST['name']}", $_POST['user'], $_POST['pass']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $newConf['db'] = ['host' => $_POST['host'], 'name' => $_POST['name'], 'user' => $_POST['user'], 'pass' => $_POST['pass']];
                save_config($newConf);
            } catch (Exception $e) {
                //echo '<div class="alert alert-danger">MySQL connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
                $error = 'MySQL connection failed: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width,initial-scale=1" name="viewport">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>URL Shortener</title>
</head>

<body class="bg-light d-flex flex-column min-vh-100">
    <nav class="bg-primary navbar navbar-dark">
        <div class="container-fluid"><span class="navbar-brand">MinUrl</span><?php if (is_logged_in()) { ?>
                <form><input name="logout" type="hidden" value="1"> <button class="btn btn-light"
                        type="submit">Logout</button></form><?php } ?>
        </div>
    </nav>
    <div class="container flex-grow-1 py-4"><?php if ($config['db'] === null || $config['admin_password_hash'] === null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) { ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php } ?>
            <h2>Initial Configuration</h2>
            <form method="post" class="mb-3"><label class="form-label">Admin Password</label> <input name="password"
                    class="form-control" type="password" required> <label class="form-label mt-3">Database Type</label>
                <select class="form-control" name="dbtype"
                    onchange='document.getElementById("sqlite").style.display="sqlite"===this.value?"block":"none",document.getElementById("mysql").style.display="mysql"===this.value?"block":"none"'
                    required>
                    <option value="">Choose...</option>
                    <option value="sqlite">SQLite</option>
                    <option value="mysql">MySQL</option>
                </select>
                <div class="mt-3" id="sqlite" style="display:none"><label class="form-label">SQLite File Path</label> <input
                        name="sqlite_path" class="form-control"></div>
                <div class="mt-3" id="mysql" style="display:none"><label class="form-label">MySQL Host</label> <input
                        name="host" class="form-control"> <label class="form-label">MySQL DB Name</label> <input name="name"
                        class="form-control"> <label class="form-label">MySQL User</label> <input name="user"
                        class="form-control"> <label class="form-label">MySQL Password</label> <input name="pass"
                        class="form-control" type="password"> <label class="form-label">Table Prefix</label> <input
                        name="prefix" class="form-control"></div><button class="btn btn-primary mt-3">Save
                    Configuration</button>
            </form><?php exit;
    }
    $pdo = pdo_connect();
    init_db($pdo);
    if (!is_logged_in()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && password_verify($_POST['password'], $config['admin_password_hash'])) {
            $_SESSION['admin'] = true;
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } ?>
            <h2>Login</h2>
            <form method="post"><input name="password" class="form-control mb-2" type="password" required
                    placeholder="Admin Password"> <button class="btn btn-primary mb-2">Login</button>
                <?php if (isset($_POST['password'])) { ?>
                    <div class="alert alert-danger">Invalid password.</div><?php } ?>
            </form>
        </div><?php footer();
        exit;
    }
    if (isset($_GET['logout'])) {
        session_destroy();
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if (isset($_GET['delete'])) {
        $pdo->prepare("DELETE FROM " . tp("clicks") . " WHERE link_id = ?")->execute([$_GET['delete']]);
        $pdo->prepare("DELETE FROM " . tp("links") . " WHERE id = ?")->execute([$_GET['delete']]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if (isset($_GET['details'])) {
        $stmt = $pdo->prepare("SELECT * FROM " . tp("clicks") . " WHERE link_id = ?");
        $stmt->execute([$_GET['details']]);
        $clicks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $allKeys = [];
        foreach ($clicks as $click) {
            $serverData = json_decode($click['server_data'], true);
            if (is_array($serverData)) {
                $allKeys = array_unique(array_merge($allKeys, array_keys($serverData)));
            }
        }
        sort($allKeys); ?>
        <h2>Click Details</h2><a href="?" class="btn btn-secondary mb-3">« Back</a>
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Clicked At</th>
                        <th>IP</th><?php foreach ($allKeys as $key): ?>
                            <th><?= htmlspecialchars($key) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody><?php if (empty($clicks)): ?>
                        <tr>
                            <td class="text-center text-muted" colspan="<?= 3 + count($allKeys) ?>">No hits yet for this link.
                            </td>
                        </tr>
                    <?php else: ?>        <?php foreach ($clicks as $i => $click): ?>            <?php $serverData = json_decode($click['server_data'], true) ?: []; ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($click['clicked_at']) ?></td>
                                <td><?= htmlspecialchars($click['ip']) ?></td><?php foreach ($allKeys as $key): ?>
                                    <td><?= isset($serverData[$key]) ? htmlspecialchars($serverData[$key]) : '-' ?></td>
                                <?php endforeach; ?>
                            </tr><?php endforeach; ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        </div><?php footer();
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_url'])) {
        $code = $_POST['code'] ?: substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 6)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO " . tp("links") . " (shortcode, url, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            echo '<div class="alert alert-danger">SQL Prepare failed: ' . htmlspecialchars($errorInfo[2]) . '</div>';
        } else {
            if (!$stmt->execute([$code, $_POST['new_url']])) {
                echo '<div class="alert alert-danger">Failed to create link (duplicate code?).</div>';
            } else {
                echo '<div class="alert alert-success">Link created successfully! Shortcode: <strong>' . htmlspecialchars($code) . '</strong></div>';
            }
        }
    }
    $stmt = $pdo->prepare("SELECT * FROM " . tp("links") . " ORDER BY created_at DESC");
    $stmt->execute();
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC); ?>
    <h2>Create a Short URL</h2>
    <form method="post" class="mb-3 row">
        <div class="mb-3 col-md-8"><label class="form-label" for="new_url">Enter URL</label> <input name="new_url"
                class="form-control" type="url" required placeholder="https://example.com" id="new_url"></div>
        <div class="mb-3 col-md-4"><label class="form-label" for="code">Custom Shortcode (optional)</label> <input
                name="code" class="form-control" id="code" placeholder="abc123" maxlength="6"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Create Short URL</button></div>
    </form>
    <h2>All Links</h2>
    <table class="table table-bordered table-hover">
        <thead>
            <tr>
                <th>Shortcode</th>
                <th>URL</th>
                <th>Created At</th>
                <th>Last click</th>
                <th>Hits</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody><?php if (empty($links)): ?>
                <tr>
                    <td class="text-center text-muted" colspan="6">No shortened links yet.</td>
                </tr><?php else: ?><?php foreach ($links as $link): ?>
                    <tr>
                        <td><a href="?c=<?= htmlspecialchars($link['shortcode']) ?>"
                                target="_blank"><?= htmlspecialchars($link['shortcode']) ?></a></td>
                        <td><a href="<?= htmlspecialchars($link['url']) ?>"
                                target="_blank"><?= htmlspecialchars($link['url']) ?></a></td>
                        <td><?= htmlspecialchars($link['created_at']) ?></td>
                        <td><?= htmlspecialchars($link['last_click'] ? $link['last_click'] : '-') ?></td>
                        <td><?= htmlspecialchars($link['hits'] ? $link['hits'] : 0) ?></td>
                        <td><a href="?details=<?= $link['id'] ?>" class="btn btn-sm btn-info">Details</a> <button
                                class="btn btn-danger btn-sm" data-bs-target="#deleteModal" data-bs-toggle="modal"
                                data-code="<?= htmlspecialchars($link['shortcode']) ?>"
                                data-id="<?= $link['id'] ?>">Delete</button></td>
                    </tr><?php endforeach; ?><?php endif; ?>
        </tbody>
    </table>
    <div class="fade modal" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form>
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deletion</h5><button class="btn-close" type="button"
                            data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the link: <strong id="shortcodePreview"></strong>?</p><input
                            name="delete" type="hidden" id="deleteLinkId">
                    </div>
                    <div class="modal-footer"><button class="btn btn-danger" type="submit">Yes, delete it</button>
                        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button></div>
                </form>
            </div>
        </div>
    </div>
    </div><?php footer(); ?>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const deleteModal = document.getElementById('deleteModal');
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const code = button.getAttribute('data-code');
                deleteModal.querySelector('#deleteLinkId').value = id;
                deleteModal.querySelector('#shortcodePreview').textContent = code;
            });
        });
    </script>
</body>

</html>