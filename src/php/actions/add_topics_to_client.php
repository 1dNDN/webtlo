<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    $result = '';
    $starttime = microtime(true);

    include_once dirname(__FILE__) . '/../classes/download.php';

    // список ID раздач
    if (empty($_POST['topic_hashes'])) {
        $result = 'Выберите раздачи';
        throw new Exception();
    }
    parse_str($_POST['topic_hashes'], $topicHashes);

    $app = AppContainer::create();

    // получение настроек
    $cfg = $app->getLegacyConfig();

    if (empty($cfg['subsections'])) {
        $result = 'В настройках не найдены хранимые подразделы';
        throw new Exception();
    }
    if (empty($cfg['clients'])) {
        $result = 'В настройках не найдены торрент-клиенты';
        throw new Exception();
    }
    if (empty($cfg['api_key'])) {
        $result = 'В настройках не указан хранительский ключ API';
        throw new Exception();
    }
    if (empty($cfg['user_id'])) {
        $result = 'В настройках не указан хранительский ключ ID';
        throw new Exception();
    }

    Log::append('Запущен процесс добавления раздач в торрент-клиенты...');
    // получение ID раздач с привязкой к подразделу
    $topicHashesByForums = [];
    $topicHashes = array_chunk($topicHashes['topic_hashes'], 999);
    foreach ($topicHashes as $topicHashes) {
        $placeholders = str_repeat('?,', count($topicHashes) - 1) . '?';
        $data = Db::query_database(
            'SELECT forum_id, info_hash FROM Topics WHERE info_hash IN (' . $placeholders . ')',
            $topicHashes,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($placeholders);
        foreach ($data as $forumID => $forumTopicHashes) {
            if (isset($topicHashesByForums[$forumID])) {
                $topicHashesByForums[$forumID] = array_merge(
                    $topicHashesByForums[$forumID],
                    $forumTopicHashes
                );
            } else {
                $topicHashesByForums[$forumID] = $forumTopicHashes;
            }
        }
        unset($forumTopicHashes);
        unset($data);
    }
    unset($topicHashes);
    if (empty($topicHashesByForums)) {
        $result = 'Не получены идентификаторы раздач с привязкой к подразделу';
        throw new Exception();
    }
    // полный путь до каталога для сохранения торрент-файлов
    $localPath = Helper::getStorageDir() . DIRECTORY_SEPARATOR . 'tfiles';
    // очищаем каталог от старых торрент-файлов
    Helper::removeDirRecursive($localPath);
    // создаём каталог для торрент-файлов
    Helper::checkDirRecursive($localPath);
    // шаблон для сохранения
    $formatPathTorrentFile = $localPath . DIRECTORY_SEPARATOR . '[webtlo].h%s.torrent';
    if (PHP_OS == 'WINNT') {
        $formatPathTorrentFile = mb_convert_encoding($formatPathTorrentFile, 'Windows-1251', 'UTF-8');
    }

    // скачивание торрент-файлов
    $download = new TorrentDownload($cfg['forum_address']);
    // применяем таймауты
    $download->setUserConnectionOptions($cfg['curl_setopt']['forum']);

    $clientFactory = $app->getClientFactory();

    $totalTorrentFilesAdded = 0;
    $usedTorrentClientsIDs = [];
    foreach ($topicHashesByForums as $forumID => $topicHashes) {
        if (empty($topicHashes)) {
            continue;
        }
        if (!isset($cfg['subsections'][$forumID])) {
            Log::append('В настройках нет данных о подразделе с идентификатором "' . $forumID . '"');
            continue;
        }
        // данные текущего подраздела
        $forumData = $cfg['subsections'][$forumID];

        if (empty($forumData['cl'])) {
            Log::append('К подразделу "' . $forumID . '" не привязан торрент-клиент');
            continue;
        }
        // идентификатор торрент-клиента
        $torrentClientID = $forumData['cl'];
        if (empty($cfg['clients'][$torrentClientID])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $torrentClientID . '"');
            continue;
        }
        // данные текущего торрент-клиента
        $torrentClient = $cfg['clients'][$torrentClientID];

        foreach ($topicHashes as $topicHash) {
            $torrentFile = $download->getTorrentFile($cfg['api_key'], $cfg['user_id'], $topicHash, $cfg['retracker']);
            if ($torrentFile === false) {
                Log::append('Error: Не удалось скачать торрент-файл (' . $topicHash . ')');
                continue;
            }
            // сохранить в каталог
            $response = file_put_contents(
                sprintf($formatPathTorrentFile, $topicHash),
                $torrentFile
            );
            if ($response === false) {
                Log::append('Error: Произошла ошибка при сохранении торрент-файла (' . $topicHash . ')');
                continue;
            }
            $downloadedTorrentFiles[] = $topicHash;
        }
        if (empty($downloadedTorrentFiles)) {
            Log::append('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }

        $numberDownloadedTorrentFiles = count($downloadedTorrentFiles);
        // подключаемся к торрент-клиенту
        try {
            $client = $clientFactory->fromConfigProperties($torrentClient);

            // проверяем доступность торрент-клиента
            if (!$client->isOnline()) {
                Log::append('Error: торрент-клиент "' . $torrentClient['cm'] . '" в данный момент недоступен');
                continue;
            }
        } catch (RuntimeException $e) {
            Log::append('Error: торрент-клиент "' . $torrentClient['cm'] . '" в данный момент недоступен '. $e->getMessage());
            continue;
        }

        $clientAddingSleep = $client->getTorrentAddingSleep();

        // убираем последний слэш в пути каталога для данных
        if (preg_match('/(\/|\\\\)$/', $forumData['df'])) {
            $forumData['df'] = substr($forumData['df'], 0, -1);
        }
        // определяем направление слэша в пути каталога для данных
        $delimiter = !str_contains($forumData['df'], '/') ? '\\' : '/';

        $forumLabel = $forumData['lb'] ?? '';
        // добавление раздач
        $downloadedTorrentFiles = array_chunk($downloadedTorrentFiles, 999);
        foreach ($downloadedTorrentFiles as $downloadedTorrentFilesChunk) {
            // получаем идентификаторы раздач
            $placeholders = str_repeat('?,', count($downloadedTorrentFilesChunk) - 1) . '?';
            $topicIDsByHash = Db::query_database(
                'SELECT info_hash, id FROM Topics WHERE info_hash IN (' . $placeholders . ')',
                $downloadedTorrentFilesChunk,
                true,
                PDO::FETCH_KEY_PAIR
            );
            unset($placeholders);

            foreach ($downloadedTorrentFilesChunk as $topicHash) {
                $savePath = '';
                if (!empty($forumData['df'])) {
                    $savePath = $forumData['df'];
                    // подкаталог для данных
                    if ($forumData['sub_folder']) {
                        if ($forumData['sub_folder'] == 1) {
                            $subdirectory = $topicIDsByHash[$topicHash];
                        } elseif ($forumData['sub_folder'] == 2) {
                            $subdirectory = $topicHash;
                        } else {
                            $subdirectory = '';
                        }
                        $savePath .= $delimiter . $subdirectory;
                    }
                }
                // путь до торрент-файла на сервере
                $torrentFilePath = sprintf($formatPathTorrentFile, $topicHash);

                // Добавляем раздачу в торрент-клиент.
                $response = $client->addTorrent($torrentFilePath, $savePath, $forumLabel);
                if ($response !== false) {
                    $addedTorrentFiles[] = $topicHash;
                }

                // Пауза между добавлениями раздач, в зависимости от клиента (0.5 сек по умолчанию)
                usleep($clientAddingSleep);
            }
            unset($downloadedTorrentFilesChunk, $topicIDsByHash);
        }
        unset($downloadedTorrentFiles);

        if (empty($addedTorrentFiles)) {
            Log::append('Не удалось добавить раздачи в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }
        $numberAddedTorrentFiles = count($addedTorrentFiles);

        // Указываем раздачам метку, если она не выставлена при добавлении раздач.
        if ($forumLabel !== '' && !$client->isLabelAddingAllowed()) {
            // ждём добавления раздач, чтобы проставить метку
            sleep(round(count($addedTorrentFiles) / 20) + 1);

            // устанавливаем метку
            $response = $client->setLabel($addedTorrentFiles, $forumLabel);
            if ($response === false) {
                Log::append('Error: Возникли проблемы при отправке запроса на установку метки');
            }
        }

        // помечаем в базе добавленные раздачи
        $addedTorrentFiles = array_chunk($addedTorrentFiles, 998);
        foreach ($addedTorrentFiles as $addedTorrentFiles) {
            $placeholders = str_repeat('?,', count($addedTorrentFiles) - 1) . '?';
            Db::query_database(
                'INSERT INTO Torrents (
                    info_hash,
                    client_id,
                    topic_id,
                    name,
                    total_size
                )
                SELECT
                    Topics.info_hash,
                    ?,
                    Topics.id,
                    Topics.name,
                    Topics.size
                FROM Topics
                WHERE info_hash IN (' . $placeholders . ')',
                array_merge([$torrentClientID], $addedTorrentFiles)
            );
            unset($placeholders);
        }
        unset($addedTorrentFiles);

        Log::append('Добавлено раздач в торрент-клиент "' . $torrentClient['cm'] . '": ' . $numberAddedTorrentFiles . ' шт.');

        if (!in_array($torrentClientID, $usedTorrentClientsIDs)) {
            $usedTorrentClientsIDs[] = $torrentClientID;
        }
        $totalTorrentFilesAdded += $numberAddedTorrentFiles;
        unset($torrentClient);
        unset($forumData);
        unset($client);
    }
    $totalTorrentClients = count($usedTorrentClientsIDs);
    $result = 'Задействовано торрент-клиентов — ' . $totalTorrentClients . ', добавлено раздач всего — ' . $totalTorrentFilesAdded . ' шт.';
    $endtime = microtime(true);

    Log::append('Процесс добавления раздач в торрент-клиенты завершён за ' . Helper::convertSeconds($endtime - $starttime));
} catch (Exception $e) {
    Log::append($e->getMessage());
}

echo json_encode([
    'log'    => Log::get(),
    'result' => $result,
]);
