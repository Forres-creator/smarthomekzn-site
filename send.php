<?php
// Приём заявок с сайта: форма консультации (type=lead) и корзина каталога (type=order).
// Заявка сохраняется в data/orders.jsonl и отправляется письмом на почту из config.php.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok' => false, 'error' => 'method']);

$cfg = require __DIR__ . '/config.php';
$raw = file_get_contents('php://input', false, null, 0, 64 * 1024);
$in = json_decode((string)$raw, true);
if (!is_array($in)) reply(400, ['ok' => false, 'error' => 'bad_json']);

// бот заполнил скрытое поле — делаем вид, что всё хорошо
if (!empty($in['website'])) reply(200, ['ok' => true, 'id' => '']);

$clean = fn($v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max);

$type    = ($in['type'] ?? '') === 'order' ? 'order' : 'lead';
$name    = $clean($in['name'] ?? '', 80);
$phone   = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
$comment = $clean($in['comment'] ?? '', 1000);
$consent = ($in['consent'] ?? false) === true;

if ($name === '') reply(422, ['ok' => false, 'error' => 'name']);
if (strlen($phone) === 11 && $phone[0] === '8') $phone = '7' . substr($phone, 1);
if (!preg_match('/^7\d{10}$/', $phone)) reply(422, ['ok' => false, 'error' => 'phone']);
if (!$consent) reply(422, ['ok' => false, 'error' => 'consent']);

$items = [];
$total = 0;
if ($type === 'order') {
    foreach (array_slice((array)($in['items'] ?? []), 0, 100) as $it) {
        $qty = max(1, min(999, (int)($it['qty'] ?? 1)));
        $price = max(0, (int)($it['price'] ?? 0));
        $items[] = ['name' => $clean($it['name'] ?? '', 200), 'qty' => $qty, 'price' => $price];
        $total += $qty * $price;
    }
    if (!$items) reply(422, ['ok' => false, 'error' => 'empty_cart']);
}
$extra = [
    'object'  => $clean($in['object'] ?? '', 100),
    'systems' => array_map(fn($s) => $clean($s, 50), array_slice((array)($in['systems'] ?? []), 0, 20)),
];

// хранилище закрыто от браузера через data/.htaccess
$dir = __DIR__ . '/data';
if (!is_dir($dir)) mkdir($dir, 0750, true);
if (!file_exists("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");

// не больше N заявок с одного IP за 10 минут
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rlFile = "$dir/ratelimit.json";
$rl = json_decode((string)@file_get_contents($rlFile), true) ?: [];
$now = time();
$key = hash('sha256', $ip);
$rl = array_filter($rl, fn($t) => is_array($t) && $t && max($t) > $now - 600);
$rl[$key] = array_values(array_filter($rl[$key] ?? [], fn($t) => $t > $now - 600));
if (count($rl[$key]) >= (int)$cfg['rate_limit']) reply(429, ['ok' => false, 'error' => 'rate']);
$rl[$key][] = $now;
file_put_contents($rlFile, json_encode($rl), LOCK_EX);

// номер вида 1511-0427: день и месяц + случайная часть
$id = date('dm') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$record = [
    'id' => $id, 'time' => date('c'), 'type' => $type,
    'name' => $name, 'phone' => '+' . $phone, 'comment' => $comment,
    'items' => $items, 'total' => $total, 'extra' => $extra,
    'ip' => $ip, 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
];
file_put_contents("$dir/orders.jsonl", json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// текст письма
$fmtPhone = sprintf('+7 (%s) %s-%s-%s', substr($phone, 1, 3), substr($phone, 4, 3), substr($phone, 7, 2), substr($phone, 9, 2));
$money = fn(int $n) => number_format($n, 0, ',', ' ') . ' ₽';
$lines = [];
$lines[] = $type === 'order' ? "Новая заявка из корзины №$id" : "Новая заявка на консультацию №$id";
$lines[] = '';
$lines[] = "Имя: $name";
$lines[] = "Телефон: $fmtPhone";
if ($comment !== '') $lines[] = "Комментарий: $comment";
if ($extra['object'] !== '') $lines[] = "Объект: {$extra['object']}";
if ($extra['systems']) $lines[] = 'Интересует: ' . implode(', ', $extra['systems']);
if ($items) {
    $lines[] = '';
    $lines[] = 'Состав корзины:';
    foreach ($items as $i => $it) {
        $lines[] = sprintf('%d. %s — %d шт. × %s', $i + 1, $it['name'], $it['qty'], $it['price'] ? $money($it['price']) : 'цена по запросу');
    }
    $lines[] = '';
    $lines[] = 'Итого по каталогу (ориентировочно): ' . $money($total);
}
$lines[] = '';
$lines[] = 'Оплата не производилась — клиент ждёт звонка.';
$lines[] = 'Отправлено: ' . date('d.m.Y H:i');
$text = implode("\n", $lines);

$subject = ($type === 'order' ? 'Заявка из корзины' : 'Заявка на консультацию') . " №$id — $name, $fmtPhone";
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: Smart Home <' . $cfg['mail_from'] . '>',
];
$mailed = @mail($cfg['mail_to'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers));

$sent = !empty($cfg['telegram_enabled']) && $cfg['telegram_token'] ? telegram($cfg, $dir, $text) : false;

// заявка уже сохранена на сервере, поэтому даже без письма клиенту отвечаем «принято»
reply(200, ['ok' => true, 'id' => $id, 'mailed' => $mailed, 'telegram' => $sent]);

function tg(string $token, string $method, array $params): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header' => 'Content-Type: application/json',
        'content' => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]]);
    $res = @file_get_contents("https://api.telegram.org/bot$token/$method", false, $ctx);
    return json_decode((string)$res, true) ?: [];
}

// Отправляет заявку в Telegram. chat id берётся из конфига, из кэша или ищется
// по нику получателя среди тех, кто нажал «Старт» в боте.
function telegram(array $cfg, string $dir, string $text): bool {
    $token = $cfg['telegram_token'];
    $cache = "$dir/telegram_chat.txt";
    $user = strtolower(ltrim((string)($cfg['telegram_username'] ?? ''), '@'));
    $chat = $cfg['telegram_chat_id'] ?: trim((string)@file_get_contents($cache));

    if ($chat === '' && $user !== '') {
        foreach (tg($token, 'getUpdates', ['limit' => 100])['result'] ?? [] as $u) {
            $c = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null;
            if ($c && strtolower((string)($c['username'] ?? '')) === $user) { $chat = (string)$c['id']; break; }
        }
        if ($chat !== '') file_put_contents($cache, $chat, LOCK_EX);
    }
    if ($chat === '' && $user !== '') $chat = '@' . $user; // сработает, если это публичный канал

    if ($chat === '') return false;
    $ok = (bool)(tg($token, 'sendMessage', ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000)])['ok'] ?? false);
    if (!$ok && is_file($cache)) @unlink($cache);
    return $ok;
}
