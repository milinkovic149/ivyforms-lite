<?php

namespace IvyForms\Entity\Slack;

use IvyForms\ValueObjects\Slack\SlackFeed as SlackFeedValueObject;

class SlackFeed
{
    private SlackFeedValueObject $feed;

    public function __construct(SlackFeedValueObject $feed)
    {
        $this->feed = $feed;
    }

    public function getId(): ?int
    {
        return $this->feed->id;
    }

    public function setId(int $id): void
    {
        $this->feed->id = $id;
    }

    public function getFormId(): int
    {
        return $this->feed->formId;
    }

    public function getChannelId(): ?string
    {
        return $this->feed->channelId;
    }

    public function getChannelName(): ?string
    {
        return $this->feed->channelName;
    }

    /**
     * @return array<int, array{id: string, name: string, type?: string}>
     */
    public function getChannels(): array
    {
        return $this->feed->channels;
    }

    public function getMessageTitle(): string
    {
        return $this->feed->messageTitle;
    }

    /**
     * @return array<int, string>
     */
    public function getFields(): array
    {
        return $this->feed->fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSmartLogic(): array
    {
        return $this->feed->smartLogic;
    }

    public function isEnabled(): bool
    {
        return $this->feed->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->feed->toArray();
    }
}
