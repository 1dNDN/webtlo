<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\HighPriority;
use KeepersTeam\Webtlo\Update\Subsections;
use KeepersTeam\Webtlo\Update\TopicsDetails;

/**
 * Запуск обновления списка хранителей строго из планировщика.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[update.php, keepers.php]".
 */

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('update.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    // Проверяем возможность запуска обновления.
    if (!Helper::isScheduleActionEnabled($config, 'update')) {
        $log->notice(
            '[Subsections] Автоматическое обновление сведений о раздачах в хранимых подразделах отключено в настройках.'
        );

        return;
    }

    Timers::start('full_update');

    /**
     * Обновляем дерево подразделов.
     *
     * @var ForumTree $forumTree
     */
    $forumTree = $app->get(ForumTree::class);
    $forumTree->update();

    /**
     * Обновляем раздачи в хранимых подразделах.
     *
     * @var Subsections $updateSubsections
     */
    $updateSubsections = $app->get(Subsections::class);
    $updateSubsections->update(config: $config);

    /**
     * Обновляем список высокоприоритетных раздач.
     *
     * @var HighPriority $highPriority
     */
    $highPriority = $app->get(HighPriority::class);
    $highPriority->update(config: $config);

    /**
     * Обновляем дополнительные сведения о раздачах (названия раздач).
     *
     * @var TopicsDetails $detailsClass
     */
    $detailsClass = $app->get(TopicsDetails::class);
    $detailsClass->update();

    // обновляем списки раздач в торрент-клиентах
    include_once dirname(__FILE__) . '/../php/common/tor_clients.php';

    $log->info('Обновление всех данных завершено за {sec}', ['sec' => Timers::getExecTime('full_update')]);
} catch (RuntimeException $e) {
    if (isset($log)) {
        $log->warning($e->getMessage());
    }
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
} finally {
    if (isset($log)) {
        $log->info('-- DONE --');
    }
}
