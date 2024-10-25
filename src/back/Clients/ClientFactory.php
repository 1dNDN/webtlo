<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\Torrents;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ClientFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Topics          $topics,
        private readonly Torrents        $torrents,
    ) {}

    public function getClient(TorrentClientOptions $clientOptions): ClientInterface
    {
        // Параметры для основных клиентов.
        $params = [$this->logger, $clientOptions];

        // Расширенные параметры для поиска раздач в локальной БД.
        $extended = [...$params, $this->topics, $this->torrents];

        return match ($clientOptions->type) {
            ClientType::Qbittorrent  => new Qbittorrent(...$extended),
            ClientType::Deluge       => new Deluge(...$params),
            ClientType::Flood        => new Flood(...$params),
            ClientType::Rtorrent     => new Rtorrent(...$params),
            ClientType::Transmission => new Transmission(...$params),
            ClientType::Utorrent     => new Utorrent(...$extended),
        };
    }

    /**
     * Коннект с клиентом, из параметров клиента в конфиге.
     *
     * @param array<string, mixed> $options
     */
    public function fromConfigProperties(array $options): ClientInterface
    {
        $type = ClientType::tryFrom((string) $options['cl']);

        if (null === $type) {
            $this->logger->error('Unknown client type.', $options);

            throw new RuntimeException('Unknown client type');
        }

        return $this->getClient(TorrentClientOptions::fromConfigProperties($options));
    }

    /**
     * Коннект с клиентом, из параметров клиента из UI.
     *
     * @param array<string, mixed> $options
     */
    public function fromFrontProperties(array $options): ClientInterface
    {
        $type = ClientType::tryFrom((string) $options['type']);

        if (null === $type) {
            $this->logger->error('Unknown client type.', $options);

            throw new RuntimeException('Unknown client type');
        }

        return $this->getClient(TorrentClientOptions::fromFrontProperties($options));
    }
}
