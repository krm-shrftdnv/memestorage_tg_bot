<?php

use JetBrains\PhpStorm\Pure;
use Longman\TelegramBot\Entities\InlineQuery\InlineQueryResultPhoto;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\InputMedia\InputMediaVideo;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

require_once "vendor/autoload.php";

class TelegramBot
{
    private Telegram $telegram;
    private string $adminId;

    public function __construct()
    {
        $this->adminId = getenv('ADMIN_ID');

        try {
            $this->telegram = new Telegram(getenv('TELEGRAM_TOKEN'), getenv('BOT_USERNAME'));
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            $this->sendExceptionMessage($e);
        }
    }

    #[Pure] public function getToken(): string
    {
        return $this->telegram->getApiKey();
    }

    public function sendMessage(string $channelId, string $text): ?ServerResponse
    {
        try {
            return Request::sendMessage([
                'chat_id' => $channelId,
                'text' => $text,
            ]);
        } catch (TelegramException $e) {
            $this->sendExceptionMessage($e);
        }
        return null;
    }

    public function sendExceptionMessage(Exception $e)
    {
        $this->sendMessageToAdmin(printExceptionLogs($e));
    }

    public function sendMessageToAdmin($message)
    {
        $this->sendMessage($this->adminId, $message);
    }

    public function sendImage(string $channelId, string $photoUrl, string $text = null): ?ServerResponse
    {
        $sendingPhoto = [
            'chat_id' => $channelId,
            'photo' => $photoUrl,
            'disable_notification' => 1,
        ];
        if ($text !== null) {
            $sendingPhoto['text'] = $text;
        }
        try {
            return Request::sendPhoto($sendingPhoto);
        } catch (Exception $e) {
            $this->sendMessage($channelId, $photoUrl);
            $this->sendExceptionMessage($e);
            return null;
        }
    }

    public function sendDocument($channelId, $documentUrl): ?ServerResponse
    {
        try {
            return Request::sendDocument([
                'chat_id' => $channelId,
                'document' => $documentUrl,
                'disable_notification' => 1,
            ]);
        } catch (TelegramException $e) {
            $this->sendExceptionMessage($e);
        }
        return null;
    }

    public function sendMediaGroup($channelId, $mediaGroup): bool|ServerResponse
    {
        $media = [];
        foreach ($mediaGroup as $mediaPart) {
            if ($mediaPart['type'] === 'photo') {
                $media[] = new InputMediaPhoto(['media' => $mediaPart['media']]);
            }
            if ($mediaPart['type'] === 'video') {
                $media[] = new InputMediaVideo(['media' => $mediaPart['media']]);
            }
        }
        try {
            $response = Request::sendMediaGroup([
                'chat_id' => $channelId,
                'media' => $media,
            ]);
            if (!$response->getOk()) {
                foreach ($mediaGroup as $mediaPart) {
                    if ($mediaPart['type'] === 'photo') {
                        $response = $this->sendImage($channelId, $mediaPart['media']);
                    }
                    if ($mediaPart['type'] === 'video') {
                        $response = $this->sendMessage($channelId, $mediaPart['media']);
                    }
                }
            }
            return $response;
        } catch (TelegramException $e) {
            $this->sendExceptionMessage($e);
        }
        return false;
    }

    public function deleteMessage($channelId, $messageId): bool|ServerResponse
    {
        return Request::deleteMessage([
            'chat_id' => $channelId,
            'message_id' => $messageId,
        ]);
    }

    public function answerInline($queryId, array $results): ServerResponse
    {
        $data = [
            'inline_query_id' => (string)$queryId,
            'results' => $results,
        ];
        return Request::answerInlineQuery($data);
    }

    public function getPhotoPath(string $fileId): string
    {
        $result = Request::getFile(['file_id' => $fileId]);
        return json_decode($result, true)['result']['file_path'];
    }
}

class UserNotConnectedException extends Exception {}

function printExceptionLogs(Exception $e): string
{
    $date = new DateTime();
    return '[' . $date->format('c') . ']: ' . $e->getMessage() . ' in ' . $e->getTraceAsString() . '\n';
}

function handleEvent(array $eventData, TelegramBot $telegramBot): void
{
    if (isset($eventData['inline_query'])) {
        handleInline($eventData, $telegramBot);
        $telegramBot->sendMessageToAdmin(
            json_encode([
                'from' => [
                    'username' => $eventData['inline_query']['from']['username'],
                    'query' => $eventData['inline_query']['query'],
                ]
            ], JSON_UNESCAPED_UNICODE)
        );
        return;
    }
    if (isset($eventData['message'])) {
        handleMessage($eventData, $telegramBot);
        $telegramBot->sendMessageToAdmin(
            json_encode([
                'from' => [
                    'username' => $eventData['message']['from']['username'],
                    'text' => $eventData['message']['text'] ?? $eventData['message']['caption'],
                ]
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}

function handleInline(array $eventData, TelegramBot $telegramBot): void
{
    $description = trim($eventData['inline_query']['query']);
    $fromId = $eventData['inline_query']['from']['id'];

    $descriptionPersonalMemes = [];
    $descriptionPublicMemes = [];

    if (strlen($description) > 0) {
        $query = [
            'telegram_id' => $fromId,
            'description' => $description,
        ];
        /* todo: personal storage search */
        try {
            $pathDescriptionPersonal = '/oauth/telegram/user/search';
            $descriptionPersonalMemes = getMemesByRequest($pathDescriptionPersonal, $query,
                $telegramBot);
        } catch (UserNotConnectedException $e) {

        }

        $pathDescriptionPublic = '/oauth/telegram/storage/search/description';
        $descriptionPublicMemes = getMemesByRequest($pathDescriptionPublic, $query, $telegramBot);

        $memes = array_merge($descriptionPersonalMemes, $descriptionPublicMemes);

        $memes = removeMemesDuplicates($memes);

        if (sizeof($memes) > 0) {
            $results = [];
            foreach ($memes as $index => $mem) {
                $data = [
                    'type' => 'photo',
                    'photo_url' => $mem['url'],
                    'id' => $index.'_public',
                    'thumb_url' => $mem['url'],
                ];
                $photo = new InlineQueryResultPhoto($data);
                $results[] = $photo;
            }

            $response = $telegramBot->answerInline($eventData['inline_query']['id'], $results);
        }
    }
}

function handleMessage(array $eventData, TelegramBot $telegramBot): void
{
    $message = $eventData['message'];
    $chatId = $message['chat']['id'];
    $fromId = $message['from']['id'];

    if (isset($message['photo'])) {
        try {
            $path = '/oauth/telegram/user/add';
            $tags = findTags($message['caption']);
            $description = trim(removeTags($message['caption'], $tags));
            $imagePath = $telegramBot->getPhotoPath($message['photo'][sizeof($message['photo']) - 1]['file_id']);
            $imageUrl = "https://api.telegram.org/file/bot{$telegramBot->getToken()}/$imagePath";
            $body = [
                'telegram_id' => (string) $fromId,
                'description' => $description,
                'image_url' => $imageUrl,
                'tags' => $tags,
                'image_name' => $imagePath,
            ];
            $response = postMeme($path, $body, $telegramBot);
            if ($response === 200) {
                $telegramBot->sendMessage($fromId, 'Your meme was successfully uploaded. memestorage.tk/storage');
            } else {
                $telegramBot->sendMessage($fromId, 'Oops, something went wrong. We\'ll fix it.');
            }
        } catch (UserNotConnectedException) {
            $telegramBot->sendMessage($chatId,
                "You haven't connected telegram with your memestorage account yet.");
        }
    } else {
        $commands = findCommands($message['text']);

        if (sizeof($commands) > 0) {
            switch ($commands[0]) {
                case 'add':
                {
                    $path = 'oauth/telegram/user';
                    $query = [
                        'telegram_id' => $fromId,
                    ];
                    try {
                        getMemesByRequest($path, $query, $telegramBot);
                        $telegramBot->sendMessage($chatId, 'Send me photo with your specific description and #tags.');
                    } catch (UserNotConnectedException $e) {
                        $telegramBot->sendMessage($chatId, "You haven't connected telegram with your memestorage account yet.");
                    }
                    break;
                }
                case 'search':
                {
                    $memes = [];
                    $tagsMemes = [];
                    $descriptionPublicMemes = [];
                    $descriptionPersonalMemes = [];

                    $searchingStartedMessage = $telegramBot->sendMessage($chatId, 'searching...');
                    $description = trim(str_replace('/' . $commands[0], '', $message['text']));

                    if (strlen($description) > 0) {
                        $description = trim($description);
                        try {
                            if (strlen($description) > 0) {
                                $query = [
                                    'telegram_id' => $fromId,
                                    'description' => $description,
                                ];
                                $pathDescriptionPersonal = '/oauth/telegram/user/search';
                                $descriptionPersonalMemes = getMemesByRequest($pathDescriptionPersonal, $query,
                                    $telegramBot);

                                $pathDescriptionPublic = '/api/storage/search/description';
                                $descriptionPublicMemes = getMemesByRequest($pathDescriptionPublic, $query,
                                    $telegramBot);
                                $memes = array_merge($descriptionPersonalMemes, $descriptionPublicMemes);
                                $memes = removeMemesDuplicates($memes);

                                if (sizeof($memes) > 0) {
                                    $mediaPart = [];
                                    foreach ($memes as $mem) {
                                        $mediaPart[] = [
                                            'type' => 'photo',
                                            'media' => $mem['url'],
                                        ];
                                    }
                                    $response = $telegramBot->sendMediaGroup($chatId, $mediaPart);
                                    if (!$response->getOk()) {
                                        $response = $telegramBot->sendDocument($chatId, $mem['url']);
                                        if (!$response->getOk()) {
                                            $telegramBot->sendMessage($chatId, $mem['url']);
                                        }
                                    }
                                } else {
                                    $telegramBot->sendMessage($chatId, "No memes found on your request.");
                                }
                            } else {
                                $telegramBot->sendMessage($chatId, 'Type description after /search command.');
                            }

                        } catch (UserNotConnectedException $e) {
                            $telegramBot->sendMessage($chatId,
                                "You haven't connected telegram with your memestorage account yet.");
                        }
                        break;
                    }
                }
                case 'register':
                {
                    $telegramBot->sendMessage($chatId, 'Go to www.memestorage.tk/register.');
                    break;
                }
                case 'login':
                {
                    $telegramBot->sendMessage($chatId,
                        "Go to www.memestorage.tk/auth and set your telegram id ($chatId) in settings.");
                    break;
                }
                case 'start':
                {
                    break;
                }
            }
        }
    }

}

function findCommands(string $messageText): array
{
    $words = explode(' ', $messageText);
    $commands = [];
    foreach ($words as $word) {
        $isCommand = false;
        foreach (str_split($word) as $index => $letter) {
            if ($index === 0) {
                $isCommand = $letter === '/';
                if (!$isCommand) {
                    continue 2;
                }
            } else {
                $isCommand = $letter !== '/';
            }
        }
        if ($isCommand) {
            $commands[] = $word;
        }
    }
    return array_map(
        function ($command) {
            return substr($command, 1);
        },
        $commands);
}

function removeMemesDuplicates (array $memes): array
{
    $uniqueMemes = [];
    $uniqueIds = [];
    foreach ($memes as $meme) {
        if (!in_array($meme['id'], $uniqueIds)) {
            $uniqueMemes[] = $meme;
        }
    }
    return $uniqueMemes;
}

function findTags(string $description): array
{
    $words = explode(' ', $description);
    $tags = [];
    foreach ($words as $word) {
        $isTag = false;
        foreach (str_split($word) as $index => $letter) {
            if ($index === 0) {
                $isTag = $letter === '#';
                if (!$isTag) {
                    continue 2;
                }
            } else {
                $isTag = $letter !== '#';
            }
        }
        if ($isTag) {
            $tags[] = $word;
        }
    }
    return array_map(
        function ($tag) {
            return substr($tag, 1);
        },
        $tags);
}

function removeTags(string $description, array $tags): string
{
    foreach ($tags as $tag) {
        $pos = strpos($description, "#$tag");
        if ($pos) {
            $description = substr($description, 0, $pos) . substr($description, $pos + strlen($tag) + 1,
                    strlen($description) - ($pos + strlen($tag) + 1));
        }
    }
    return $description;
}

/** @throws UserNotConnectedException */
function getMemesByRequest(string $path, array $query, TelegramBot $telegramBot): array
{
    $headers = [];
    $hostname = 'https://www.memestorage.tk';
    $url = $hostname . $path . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resultJson = curl_exec($ch);
    $responseInfo = curl_getinfo($ch);
    curl_close($ch);
    if ($responseInfo['http_code'] === 404) {
        throw new UserNotConnectedException(json_decode($resultJson, true)['message']);
    }

    if (!$resultJson) {
        $telegramBot->sendMessageToAdmin(curl_error($ch));
        return [];
    }
    $result = json_decode($resultJson, true);

    if (isset($result['memes']) && sizeof($result['memes']) > 0) {
        return $result['memes'];
    } else {
        return [];
    }
}

/** @throws UserNotConnectedException */
function postMeme(string $path, array $postBody, TelegramBot $telegramBot): int
{
    $headers = [];

    $hostname = 'https://www.memestorage.tk';
    $url = $hostname . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postBody));
    curl_setopt( $ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resultJson = curl_exec($ch);
    $responseInfo = curl_getinfo($ch);
    curl_close($ch);
    if ($responseInfo['http_code'] !== 200) {
        $telegramBot->sendMessageToAdmin(json_encode($responseInfo));
    }
    if ($responseInfo['http_code'] === 404) {
        throw new UserNotConnectedException(json_decode($resultJson, true)['message']);
    }
    return $responseInfo['http_code'];
}

$bot = new TelegramBot();

$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);
handleEvent($data, $bot);