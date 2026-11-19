<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;

/**
 * One turn of the editor: what they typed or clicked, and the form as it was at that moment.
 */
final readonly class AssistantRequest
{
    /**
     * @param array<mixed> $content the data of the form
     * @param null|array<mixed> $sourceContent the data in the source locale, to translate from
     * @param list<array{question: string, answer: string, remember: bool}> $answers the answers of a question round
     * @param list<string> $templateKeys the templates a plan may use (an article list of one template group); empty for all
     * @param null|string $selectedPage the page selected in the page tree when the planner was opened there
     */
    public function __construct(
        public AiAction $action,
        public string $message,
        public string $templateKey,
        public array $content = [],
        public ?string $modelKey = null,
        public ?int $focusBlock = null,
        public ?string $focusProperty = null,
        public ?array $sourceContent = null,
        public ?string $sourceLocale = null,
        public array $answers = [],
        public array $templateKeys = [],
        public ?string $selectedPage = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $action = AiAction::tryFrom(\is_string($data['action'] ?? null) ? $data['action'] : '') ?? AiAction::Chat;

        if (!\in_array($action, AiAction::assistantActions(), true)) {
            $action = AiAction::Chat;
        }

        return new self(
            action: $action,
            message: \is_string($data['message'] ?? null) ? trim($data['message']) : '',
            templateKey: \is_string($data['template'] ?? null) ? $data['template'] : '',
            content: \is_array($data['content'] ?? null) ? $data['content'] : [],
            modelKey: \is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : null,
            focusBlock: \is_int($data['focusBlock'] ?? null) ? $data['focusBlock'] : null,
            focusProperty: \is_string($data['focusProperty'] ?? null) && $data['focusProperty'] !== '' ? $data['focusProperty'] : null,
            sourceContent: \is_array($data['sourceContent'] ?? null) ? $data['sourceContent'] : null,
            sourceLocale: \is_string($data['sourceLocale'] ?? null) && $data['sourceLocale'] !== '' ? $data['sourceLocale'] : null,
            answers: self::answers($data['answers'] ?? null),
            templateKeys: \is_array($data['templateKeys'] ?? null) ? array_values(array_filter($data['templateKeys'], static fn (mixed $key) => \is_string($key) && $key !== '')) : [],
            selectedPage: \is_string($data['selectedPage'] ?? null) && $data['selectedPage'] !== '' ? $data['selectedPage'] : null,
        );
    }

    /**
     * @return list<array{question: string, answer: string, remember: bool}>
     */
    private static function answers(mixed $value): array
    {
        $answers = [];

        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_array($item) && \is_string($item['question'] ?? null) && \is_string($item['answer'] ?? null)) {
                $answers[] = ['question' => $item['question'], 'answer' => $item['answer'], 'remember' => ($item['remember'] ?? false) === true];
            }
        }

        return $answers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'message' => $this->message,
            'template' => $this->templateKey,
            'content' => $this->content,
            'model' => $this->modelKey,
            'focusBlock' => $this->focusBlock,
            'focusProperty' => $this->focusProperty,
            'sourceContent' => $this->sourceContent,
            'sourceLocale' => $this->sourceLocale,
            'answers' => $this->answers,
            'templateKeys' => $this->templateKeys,
            'selectedPage' => $this->selectedPage,
        ];
    }
}
