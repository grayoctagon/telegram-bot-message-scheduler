<?php
date_default_timezone_set('Europe/Vienna');

require_once 'cronSecrets.php';

const JSON_FILE = 'messages.json';
const LOCK_FILE = 'messages.lock';

function getMessages() {
	if (!file_exists(JSON_FILE)) {
		createSampleMessages();
	}
	return json_decode(file_get_contents(JSON_FILE), true) ?? [];
}

function createSampleMessages() {
	$now = new DateTime();
	$messages = [
		[
			"sendDateTime" => $now->modify("-1 minute")->format(DateTime::RFC3339),
			"message-text" => "This message should have been sent 1 minute ago.",
			"chat_id" => "-<your target chat ID here>",
			"wasSend" => false
		],
		[
			"sendDateTime" => (new DateTime())->modify("+10 minutes")->format(DateTime::RFC3339),
			"message-text" => "This message is due in 10 minutes.",
			"chat_id" => "-<your target chat ID here>",
			"wasSend" => false
		],
		[
			"sendDateTime" => (new DateTime())->modify("+1 month 5 minutes")->format(DateTime::RFC3339),
			"message-text" => "This message is due in 1 month and 5 minutes.",
			"chat_id" => "-<your target chat ID here>",
			"wasSend" => false
		]
	];
	file_put_contents(JSON_FILE, json_encode($messages, JSON_PRETTY_PRINT));
}

function saveMessages($messages) {
	file_put_contents(JSON_FILE, json_encode($messages, JSON_PRETTY_PRINT));
}

function sendMessage($chatId, $messageText) {
	try {
		$botToken = getBotToken();
		$url = "https://api.telegram.org/bot$botToken/sendMessage";
		
		$data = [
			'chat_id' => $chatId,
			'text' => escapeTelegramText($messageText, 'HTML'),
			'parse_mode' => 'HTML'
		];
		
		$options = [
			'http' => [
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
			],
		];
		
		$context  = stream_context_create($options);
		$response = @file_get_contents($url, false, $context);
		
		if ($response === false) {
			throw new Exception("Failed to send message.");
		}
		
		$result = json_decode($response, true);
		if (!$result['ok']) {
			throw new Exception("Telegram API error: " . $result['description']);
		}
	} catch (Exception $e) {
		error_log("Error sending message: " . $e->getMessage());
	}
}
function escapeTelegramText($text, $mode = 'Markdown') {
	if ($mode === 'Markdown') {
		// Escape Telegram Markdown special characters
		$escape_chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
		foreach ($escape_chars as $char) {
			$text = str_replace($char, "\\" . $char, $text);
		}
	} elseif ($mode === 'HTML') {
		// Escape Telegram HTML special characters
		$text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
	
	return $text;
}

function getTimeDelta($fromDateTime, $toDateTime = 'now') {
	$from = new DateTime($fromDateTime);
	$to = new DateTime($toDateTime);
	$interval = $from->diff($to);
	$format = "";
	
	foreach (["y" => "year", "m" => "month", "d" => "day", "h" => "hour", "i" => "minute", "s" => "second"] as $key => $label) {
		if ($interval->$key) {
			$format .= "{$interval->$key} {$label}" . ($interval->$key > 1 ? "s " : " ");
		}
	}
	
	return trim($format);
}

function processMessages() {
	$lock = fopen(LOCK_FILE, 'w');
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		die("Another instance is running.");
	}
	
	$messages = getMessages();
	$now = (new DateTime())->modify("+30 seconds");//weil es nur alle 5 minuten läuft
	
	foreach ($messages as &$message) {
		if(!isset($message['wasSend'])){
			$message['wasSend']=false;
		}
		if ((!$message['wasSend']) && new DateTime($message['sendDateTime']) <= $now) {
			sendMessage($message['chat_id'], $message['message-text']);
			$message['wasSend'] = $now->format(DateTime::RFC3339);
		}
	}
	
	saveMessages($messages);
	flock($lock, LOCK_UN);
	fclose($lock);
}

function displayMessages() {
	$messages = getMessages();
	usort($messages, fn($a, $b) => strcmp($a['sendDateTime'], $b['sendDateTime']));
	
	echo "<table border='1'>";
	echo '<tr><th style="width: 200px;">Send DateTime</th><th>Time Delta</th><th>Message</th><th>Chat ID</th><th>Sent At</th><th>Send Delay</th></tr>';
	
	foreach ($messages as $message) {
		$timeDelta = getTimeDelta($message['sendDateTime']);
		$sendDelta = $message['wasSend'] ? getTimeDelta($message['sendDateTime'], $message['wasSend']) : "-";
		$isPast = new DateTime($message['sendDateTime']) < new DateTime();
		$color = $isPast ? 'red' : 'green';
		echo "<tr>";
		echo "<td>" . htmlspecialchars($message['sendDateTime']) . "</td>";
		echo "<td style='color: $color;'>$timeDelta</td>";
		echo "<td>" . str_replace("\n","<br>",htmlspecialchars($message['message-text'])) . "</td>";
		echo "<td>" . htmlspecialchars($message['chat_id']) . "</td>";
		echo "<td>" . ($message['wasSend'] ? htmlspecialchars($message['wasSend']) : "No") . "</td>";
		echo "<td>$sendDelta</td>";
		echo "</tr>";
	}
	echo "</table>";
}

//sendMessage("-<your target chat ID here>", "Test message from bot");


processMessages();
displayMessages();

?>
