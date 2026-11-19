<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;

/**
 * What the assistant has to know about a website before it writes a single page: what the site
 * is, who reads it and how it sounds. One row per webspace and locale, because tone, word choice
 * and examples belong to a language.
 *
 * It lives in this bundle and is edited in an "AI context" tab of the webspace, so a project
 * needs nothing else to use it.
 */
#[ORM\Entity(repositoryClass: WebspaceContextRepository::class)]
#[ORM\Table(name: 'eekes_ai_webspace_context')]
#[ORM\UniqueConstraint(name: 'uniq_eekes_ai_webspace_context', columns: ['webspace_key', 'locale'])]
class WebspaceContext
{
    public const string RESOURCE_KEY = 'eekes_ai_content_contexts';
    public const string FORM_KEY = 'eekes_ai_content_webspace_context';

    /**
     * The free-text fields, in the order the prompt lists them.
     */
    public const array TEXT_FIELDS = [
        'siteDescription',
        'audience',
        'toneOfVoice',
        'writingGuidelines',
        'wordsToUse',
        'wordsToAvoid',
        'glossary',
        'extraInstructions',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(name: 'webspace_key', length: 64)]
    public string $webspaceKey;

    #[ORM\Column(length: 16)]
    public string $locale;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $siteDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $audience = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $toneOfVoice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $writingGuidelines = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $wordsToUse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $wordsToAvoid = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $glossary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $extraInstructions = null;

    /**
     * Pages that show how the website should read. The assistant reads them on request.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $examplePages = [];

    /**
     * Facts the assistant asked for and was told, such as the name of the company or the year it
     * was founded. They go along with every request, so nobody is asked twice.
     *
     * @var list<array{question: string, answer: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $facts = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $changedAt;

    public function __construct(string $webspaceKey, string $locale)
    {
        $this->webspaceKey = $webspaceKey;
        $this->locale = $locale;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getText(string $field): ?string
    {
        return match ($field) {
            'siteDescription' => $this->siteDescription,
            'audience' => $this->audience,
            'toneOfVoice' => $this->toneOfVoice,
            'writingGuidelines' => $this->writingGuidelines,
            'wordsToUse' => $this->wordsToUse,
            'wordsToAvoid' => $this->wordsToAvoid,
            'glossary' => $this->glossary,
            'extraInstructions' => $this->extraInstructions,
            default => throw new \InvalidArgumentException(\sprintf('Unknown context field "%s".', $field)),
        };
    }

    public function setText(string $field, ?string $value): void
    {
        $value = $value === null || trim($value) === '' ? null : trim($value);

        match ($field) {
            'siteDescription' => $this->siteDescription = $value,
            'audience' => $this->audience = $value,
            'toneOfVoice' => $this->toneOfVoice = $value,
            'writingGuidelines' => $this->writingGuidelines = $value,
            'wordsToUse' => $this->wordsToUse = $value,
            'wordsToAvoid' => $this->wordsToAvoid = $value,
            'glossary' => $this->glossary = $value,
            'extraInstructions' => $this->extraInstructions = $value,
            default => throw new \InvalidArgumentException(\sprintf('Unknown context field "%s".', $field)),
        };

        $this->changedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function getFacts(): array
    {
        return $this->facts;
    }

    /**
     * Keeps the answer to a question, replacing the answer to the same question asked before.
     */
    public function rememberFact(string $question, string $answer): void
    {
        $question = trim($question);
        $answer = trim($answer);

        if ($question === '' || $answer === '') {
            return;
        }

        $key = self::factKey($question);
        $facts = array_values(array_filter($this->facts, static fn (array $fact) => self::factKey($fact['question']) !== $key));
        $facts[] = ['question' => $question, 'answer' => $answer];

        $this->facts = $facts;
        $this->changedAt = new \DateTimeImmutable();
    }

    /**
     * Replaces every fact, as the AI context tab saves them.
     *
     * @param list<array{question: string, answer: string}> $facts
     */
    public function setFacts(array $facts): void
    {
        $this->facts = [];

        foreach ($facts as $fact) {
            $this->rememberFact($fact['question'], $fact['answer']);
        }
    }

    private static function factKey(string $question): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question)));
    }

    public function isEmpty(): bool
    {
        foreach (self::TEXT_FIELDS as $field) {
            if ($this->getText($field) !== null) {
                return false;
            }
        }

        return $this->examplePages === [] && $this->facts === [];
    }
}
