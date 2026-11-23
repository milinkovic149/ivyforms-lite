<?php

namespace IvyForms\ValueObjects\Slack;

/**
 * Slack feed value object.
 */
final class SlackFeed
{
    public ?int $id;
    public int $formId;
    public string $name;
    public ?string $channelId;
    public ?string $channelName;
    /**
     * @var array<int, array{id: string, name: string, type?: string}>
     */
    public array $channels;
    public string $messageTitle;
    /** @var array<int, string> */
    public array $fields;
    /**
     * Smart logic rules ({enabled, match, rules}); evaluated by Pro, stored by Lite.
     *
     * @var array<string, mixed>
     */
    public array $smartLogic;
    public bool $enabled;

    /**
     * @param array<int, array{id: string, name: string, type?: string}> $channels
     * @param array<int, string> $fields
     * @param array<string, mixed> $smartLogic
     */
    public function __construct(
        ?int $id,
        int $formId,
        string $name,
        ?string $channelId,
        ?string $channelName,
        array $channels,
        string $messageTitle,
        array $fields,
        array $smartLogic,
        bool $enabled
    ) {
        $this->id = $id;
        $this->formId = $formId;
        $this->name = $name;
        $this->channelId = $channelId;
        $this->channelName = $channelName;
        $this->channels = $channels;
        $this->messageTitle = $messageTitle;
        $this->fields = $fields;
        $this->smartLogic = $smartLogic;
        $this->enabled = $enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'formId'       => $this->formId,
            'name'         => $this->name,
            'channelId'    => $this->channelId,
            'channelName'  => $this->channelName,
            'channels'     => $this->channels,
            'messageTitle' => $this->messageTitle,
            'fields'       => $this->fields,
            'smartLogic'   => $this->smartLogic,
            'enabled'      => $this->enabled,
        ];
    }
}
