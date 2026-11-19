<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

/**
 * One answer of the assistant, in the shape the panel renders.
 *
 * - questions: the assistant needs to know more first; each question can come with answers to
 *   pick from, one or (with "multiple") several. The panel asks them one step at a time. A
 *   question marked "remember" asks a fact about the website; its answer is kept in the AI
 *   context of the webspace
 * - content can propose new snippets under "snippets", referred to from a snippet selection as
 *   "new:<key>"; the panel creates them when the editor applies the reply
 * - outline: a proposed structure (a template and a list of blocks) for the editor to adjust
 *   before anything is written
 * - content: values to put in the form, plus SEO and excerpt fields
 * - message: everything else - advice, a review, an answer
 * - plan: several new pages or articles, each with a title, a template, the page it goes below
 *   and a brief, for the editor to adjust before each is written and created as a draft. A plan of
 *   articles can hold the one new page they go below (see PlanChecker)
 *
 * Every reply can carry follow-up suggestions the panel offers as one-click prompts.
 *
 * @phpstan-import-type PlanItem from PlanChecker
 */
final readonly class AssistantReply
{
    public const string TYPE_QUESTIONS = 'questions';
    public const string TYPE_OUTLINE = 'outline';
    public const string TYPE_CONTENT = 'content';
    public const string TYPE_MESSAGE = 'message';
    public const string TYPE_PLAN = 'plan';

    public const array TYPES = [self::TYPE_QUESTIONS, self::TYPE_OUTLINE, self::TYPE_CONTENT, self::TYPE_MESSAGE, self::TYPE_PLAN];

    /**
     * @param list<array{question: string, options: list<string>, multiple: bool, remember: bool}> $questions
     * @param null|array{template: null|string, templateTitle: null|string, blocks: list<array{type: string, title: string, purpose: string, snippets: list<array{title: string, uuid: null|string}>}>} $outline
     * @param array<string, mixed> $properties
     * @param array<string, string> $seo
     * @param array<string, string> $excerpt
     * @param list<string> $suggestions
     * @param list<string> $warnings what was dropped while checking the content
     * @param list<array{key: string, template: string, properties: array<string, mixed>}> $snippets new snippets to create on apply
     * @param list<PlanItem> $plan
     */
    public function __construct(
        public string $type,
        public string $message,
        public array $questions = [],
        public ?array $outline = null,
        public ?string $template = null,
        public array $properties = [],
        public array $seo = [],
        public array $excerpt = [],
        public array $suggestions = [],
        public array $warnings = [],
        public array $snippets = [],
        public array $plan = [],
    ) {
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $seo
     * @param array<string, string> $excerpt
     * @param list<string> $warnings
     * @param list<array{key: string, template: string, properties: array<string, mixed>}> $snippets
     */
    public function withContent(array $properties, array $seo, array $excerpt, array $warnings, array $snippets): self
    {
        return new self($this->type, $this->message, $this->questions, $this->outline, $this->template, $properties, $seo, $excerpt, $this->suggestions, $warnings, $snippets, $this->plan);
    }

    /**
     * @param null|array{template: null|string, templateTitle: null|string, blocks: list<array{type: string, title: string, purpose: string, snippets: list<array{title: string, uuid: null|string}>}>} $outline
     */
    public function withOutline(?array $outline): self
    {
        return new self($this->type, $this->message, $this->questions, $outline, $this->template, $this->properties, $this->seo, $this->excerpt, $this->suggestions, $this->warnings, $this->snippets, $this->plan);
    }

    /**
     * @param list<PlanItem> $plan
     * @param list<string> $warnings
     */
    public function withPlan(array $plan, array $warnings): self
    {
        return new self($this->type, $this->message, $this->questions, $this->outline, $this->template, $this->properties, $this->seo, $this->excerpt, $this->suggestions, $warnings, $this->snippets, $plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'questions' => $this->questions,
            'outline' => $this->outline,
            'template' => $this->template,
            'properties' => (object) $this->properties,
            'seo' => (object) $this->seo,
            'excerpt' => (object) $this->excerpt,
            'suggestions' => $this->suggestions,
            'warnings' => $this->warnings,
            'snippets' => array_map(static fn (array $snippet) => ['properties' => (object) $snippet['properties']] + $snippet, $this->snippets),
            'plan' => $this->plan,
        ];
    }
}
