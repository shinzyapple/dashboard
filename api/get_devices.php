<?php
/**
 * SwitchBot デバイス一覧取得スクリプト
 */

require_once 'config.php';

// パスワード認証 (セキュリティ対策)
$inputPassword = isset($_GET['password']) ? $_GET['password'] : '';
if ($inputPassword !== APP_PASSWORD) {
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>アクセス拒否</title><style>body{font-family:sans-serif;padding:40px;background:#f8fafc;color:#1e293b;}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;}</style></head><body>";
    echo "<h1>アクセス拒否</h1>";
    echo "<p>このツールを表示するには、URLの末尾に正しいパスワードパラメータが必要です。</p>";
    echo "<p>例: <code>get_devices.php?password=あなたのパスワード</code></p>";
    echo "</body></html>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');


if (SWITCHBOT_TOKEN === 'YOUR_SWITCHBOT_DEVELOPER_TOKEN' || SWITCHBOT_SECRET === 'YOUR_SWITCHBOT_CLIENT_SECRET') {
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>設定未完了</title><style>body{font-family:sans-serif;padding:40px;background:#f8fafc;color:#1e293b;}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;}</style></head><body>";
    echo "<h1>SwitchBot API設定が未完了です</h1>";
    echo "<p>先に <strong>config.php</strong> に <code>SWITCHBOT_TOKEN</code> と <code>SWITCHBOT_SECRET</code> を設定してください。</p>";
    echo "</body></html>";
    exit;
}

// 署名の作成 (v1.1)
$token = SWITCHBOT_TOKEN;
$secret = SWITCHBOT_SECRET;
$t = round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$data = $token . $t . $nonce;
$sign = hash_hmac('sha256', $data, $secret, true);
$sign = strtoupper(base64_encode($sign));

$headers = [
    "Authorization: {$token}",
    "sign: {$sign}",
    "t: {$t}",
    "nonce: {$nonce}",
    "Content-Type: application/json; charset=utf8"
];

$url = "https://api.switch-bot.com/v1.1/devices";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>通信エラー</title><style>body{font-family:sans-serif;padding:40px;background:#f8fafc;}pre{background:#fee2e2;padding:15px;border-radius:8px;border:1px solid #fca5a5;overflow-x:auto;}</style></head><body>";
    echo "<h1>エラーが発生しました</h1>";
    echo "<p>HTTPステータスコード: {$httpCode}</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    echo "</body></html>";
    exit;
}

$data = json_decode($response, true);
if (!isset($data['body'])) {
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>パースエラー</title><style>body{font-family:sans-serif;padding:40px;background:#f8fafc;}pre{background:#fee2e2;padding:15px;border-radius:8px;border:1px solid #fca5a5;}</style></head><body>";
    echo "<h1>デバイス一覧の取得に失敗しました</h1>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    echo "</body></html>";
    exit;
}

$deviceList = isset($data['body']['deviceList']) ? $data['body']['deviceList'] : [];
$infraRedRemoteList = isset($data['body']['infraredRemoteList']) ? $data['body']['infraredRemoteList'] : [];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>SwitchBot デバイス一覧取得ツール</title>
    <style>
        body { font-family: sans-serif; padding: 40px; background: #f8fafc; color: #1e293b; line-height: 1.6; }
        h1 { border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px; }
        h2 { margin-top: 40px; color: #0f172a; border-left: 5px solid #3b82f6; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border-radius: 8px; overflow: hidden; }
        th, td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f1f5f9; font-weight: 600; color: #475569; }
        tr:last-child td { border-bottom: none; }
        code { background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 1.05em; color: #0f172a; border: 1px solid #e2e8f0; }
        .hint { color: #64748b; font-size: 0.95em; }
    </style>
</head>
<body>
    <h1>SwitchBot デバイス一覧取得ツール</h1>
    <p><code>config.php</code> に設定されたトークン情報を使用して、SwitchBotクラウドに登録されている全てのデバイスを一覧表示するよ。</p>
    <p>エアコンなどの「赤外線リモコンデバイス」は、多くの場合<strong>「2. 赤外線リモコンデバイス一覧」</strong>に表示されるよ。対象のデバイスID（Device ID）をコピーして、<code>config.php</code> の <code>SWITCHBOT_AC_DEVICE_ID</code> に設定してね！</p>

    <h2>1. 物理デバイス一覧 (ハブ、温湿度計、プラグなど)</h2>
    <table>
        <tr>
            <th>デバイス名 (deviceName)</th>
            <th>デバイスID (deviceId)</th>
            <th>種類 (deviceType)</th>
        </tr>
        <?php if (empty($deviceList)): ?>
            <tr><td colspan="3" class="hint">デバイスが見つかりませんでした。</td></tr>
        <?php else: ?>
            <?php foreach ($deviceList as $dev): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($dev['deviceName']); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($dev['deviceId']); ?></code></td>
                    <td><?php echo htmlspecialchars($dev['deviceType']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <h2>2. 赤外線リモコンデバイス一覧 (エアコン、テレビなど)</h2>
    <table>
        <tr>
            <th>デバイス名 (deviceName)</th>
            <th>デバイスID (deviceId)</th>
            <th>種類 (remoteType)</th>
        </tr>
        <?php if (empty($infraRedRemoteList)): ?>
            <tr><td colspan="3" class="hint">赤外線リモコンデバイスが見つかりませんでした。</td></tr>
        <?php else: ?>
            <?php foreach ($infraRedRemoteList as $dev): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($dev['deviceName']); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($dev['deviceId']); ?></code></td>
                    <td><?php echo htmlspecialchars($dev['remoteType']); ?> (ACなどのリモコン)</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</body>
</html>
