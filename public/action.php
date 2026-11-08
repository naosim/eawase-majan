<?php
declare(strict_types=1);

require_once __DIR__ . '/../game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(['error' => 'POST requests only.'], 405);
}

$rawInput = file_get_contents('php://input');
if (!is_string($rawInput) || strlen($rawInput) > 16384 || !json_validate($rawInput)) {
    jsonResponse(['error' => 'Invalid JSON input.'], 400);
}

try {
    $input = json_decode($rawInput, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new GameError('A JSON object is required.');
    }

    $db = openGameDatabase();
    $action = $input['action'] ?? null;
    if (!is_string($action)) {
        throw new GameError('An action is required.');
    }
    if ($action === 'create_room') {
        $result = createRoom($db, $input);
    } elseif ($action === 'join_room') {
        $result = joinRoom($db, $input);
    } else {
        $result = performGameAction($db, $input);
    }
    jsonResponse(['success' => true] + $result);
} catch (GameError $error) {
    jsonResponse(['error' => $error->getMessage()], $error->httpStatus);
} catch (JsonException $error) {
    jsonResponse(['error' => 'Invalid JSON input.'], 400);
} catch (Throwable $error) {
    error_log('Game action failed: ' . $error->getMessage());
    jsonResponse(['error' => 'サーバーでエラーが発生しました。ログを確認してください。'], 500);
}
