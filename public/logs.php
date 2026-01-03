<?php
/**
 * Simple Observer.sh Log Viewer
 * Place at: /var/www/dashboard.chatberry.net/public/logs.php
 * Access: https://dashboard.chatberry.net/logs.php
 */

// Security: Basic authentication
$username = 'admin';
$password = "F'i71dWwHK6Q"; // CHANGE THIS!

session_start();

if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if ($_POST['username'] === $username && $_POST['password'] === $password) {
            $_SESSION['logged_in'] = true;
        } else {
            $error = 'Invalid credentials';
        }
    }
    
    if (!isset($_SESSION['logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Observer Logs - Login</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .login-box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 300px; }
                input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
                button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #0056b3; }
                .error { color: red; text-align: center; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2 style="text-align: center;">Observer Logs</h2>
                <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
                <form method="POST">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: logs.php');
    exit;
}

// Log files
$logDir = '/var/log/observer';
$logs = [
    'latest' => "$logDir/latest.txt",
    'queues' => "$logDir/queues.log",
    'alerts' => "$logDir/alerts.log",
    'metrics' => "$logDir/metrics.log"
];

// Get requested log (default: latest)
$view = isset($_GET['view']) ? $_GET['view'] : 'latest';
$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;

// Auto-refresh interval (seconds)
$refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 0;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Observer Logs - ChatBerry</title>
    <?php if ($refresh > 0): ?>
    <meta http-equiv="refresh" content="<?php echo $refresh; ?>">
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background: #1e1e1e; color: #d4d4d4; }
        
        .header {
            background: #252526;
            padding: 15px 20px;
            border-bottom: 2px solid #007acc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #007acc;
            font-size: 24px;
        }
        
        .nav {
            background: #2d2d30;
            padding: 10px 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .nav a {
            padding: 8px 15px;
            background: #3e3e42;
            color: #d4d4d4;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .nav a:hover, .nav a.active {
            background: #007acc;
            color: white;
        }
        
        .controls {
            background: #2d2d30;
            padding: 10px 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .controls label {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .controls select, .controls input {
            padding: 5px 10px;
            background: #3e3e42;
            color: #d4d4d4;
            border: 1px solid #555;
            border-radius: 4px;
        }
        
        .controls button {
            padding: 5px 15px;
            background: #007acc;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .controls button:hover {
            background: #005a9e;
        }
        
        .content {
            padding: 20px;
            max-width: 100%;
            overflow-x: auto;
        }
        
        pre {
            background: #1e1e1e;
            padding: 20px;
            border-radius: 4px;
            border: 1px solid #3e3e42;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .stat {
            background: #2d2d30;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            border-left: 4px solid #007acc;
        }
        
        .warn { color: #ce9178; }
        .error { color: #f48771; }
        .success { color: #4ec9b0; }
        .info { color: #9cdcfe; }
        
        .footer {
            background: #252526;
            padding: 10px 20px;
            text-align: center;
            color: #858585;
            border-top: 1px solid #3e3e42;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #3e3e42;
        }
        
        th {
            background: #2d2d30;
            color: #007acc;
        }
        
        tr:hover {
            background: #2d2d30;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔍 Observer.sh Logs</h1>
        <div>
            <span class="success">● Live</span> | 
            <span><?php echo date('Y-m-d H:i:s'); ?></span> | 
            <a href="?logout" style="color: #f48771; text-decoration: none;">Logout</a>
        </div>
    </div>
    
    <div class="nav">
        <a href="?view=latest" class="<?php echo $view === 'latest' ? 'active' : ''; ?>">📊 Latest Status</a>
        <a href="?view=queues" class="<?php echo $view === 'queues' ? 'active' : ''; ?>">📋 Queue Details</a>
        <a href="?view=alerts" class="<?php echo $view === 'alerts' ? 'active' : ''; ?>">⚠️ Alerts</a>
        <a href="?view=metrics" class="<?php echo $view === 'metrics' ? 'active' : ''; ?>">📈 Metrics History</a>
    </div>
    
    <div class="controls">
        <label>
            Lines: 
            <select onchange="location.href='?view=<?php echo $view; ?>&lines=' + this.value + '&refresh=<?php echo $refresh; ?>'">
                <option value="50" <?php echo $lines === 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $lines === 100 ? 'selected' : ''; ?>>100</option>
                <option value="200" <?php echo $lines === 200 ? 'selected' : ''; ?>>200</option>
                <option value="500" <?php echo $lines === 500 ? 'selected' : ''; ?>>500</option>
                <option value="1000" <?php echo $lines === 1000 ? 'selected' : ''; ?>>1000</option>
            </select>
        </label>
        
        <label>
            Auto-refresh: 
            <select onchange="location.href='?view=<?php echo $view; ?>&lines=<?php echo $lines; ?>&refresh=' + this.value">
                <option value="0" <?php echo $refresh === 0 ? 'selected' : ''; ?>>Off</option>
                <option value="5" <?php echo $refresh === 5 ? 'selected' : ''; ?>>5s</option>
                <option value="10" <?php echo $refresh === 10 ? 'selected' : ''; ?>>10s</option>
                <option value="30" <?php echo $refresh === 30 ? 'selected' : ''; ?>>30s</option>
                <option value="60" <?php echo $refresh === 60 ? 'selected' : ''; ?>>60s</option>
            </select>
        </label>
        
        <button onclick="location.reload()">🔄 Refresh Now</button>
        <button onclick="document.querySelector('pre').style.fontSize = (parseInt(getComputedStyle(document.querySelector('pre')).fontSize) + 1) + 'px'">A+</button>
        <button onclick="document.querySelector('pre').style.fontSize = (parseInt(getComputedStyle(document.querySelector('pre')).fontSize) - 1) + 'px'">A-</button>
    </div>
    
    <div class="content">
        <?php
        if (isset($logs[$view]) && file_exists($logs[$view])) {
            $content = '';
            
            if ($view === 'latest') {
                // Show full latest.txt
                $content = file_get_contents($logs[$view]);
            } else {
                // Show last N lines
                $file = $logs[$view];
                $cmd = "tail -n $lines " . escapeshellarg($file);
                $content = shell_exec($cmd);
            }
            
            // Add syntax highlighting
            $content = htmlspecialchars($content);
            $content = preg_replace('/\[WARN\]/', '<span class="warn">[WARN]</span>', $content);
            $content = preg_replace('/\[ERROR\]/', '<span class="error">[ERROR]</span>', $content);
            $content = preg_replace('/\[INFO\]/', '<span class="info">[INFO]</span>', $content);
            $content = preg_replace('/(OK|✓|Running)/', '<span class="success">$1</span>', $content);
            
            echo "<pre>$content</pre>";
            
            // Show file info
            $size = filesize($logs[$view]);
            $modified = date('Y-m-d H:i:s', filemtime($logs[$view]));
            echo "<div class='stat'>";
            echo "<strong>File:</strong> {$logs[$view]} | ";
            echo "<strong>Size:</strong> " . number_format($size / 1024, 2) . " KB | ";
            echo "<strong>Modified:</strong> $modified";
            echo "</div>";
            
        } else {
            echo "<div class='stat error'>";
            echo "❌ Log file not found or not accessible: {$logs[$view]}";
            echo "</div>";
        }
        ?>
    </div>
    
    <div class="footer">
        Observer.sh Web Viewer | ChatBerry Dashboard | 
        <?php if ($refresh > 0): ?>
            Next refresh in <?php echo $refresh; ?>s
        <?php else: ?>
            Auto-refresh: Off
        <?php endif; ?>
    </div>
</body>
</html>
