<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AgentFactory;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SmartContentFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SnippetFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Usage\CostCalculator;
use Eekes\Sulu\AiContentBundle\Application\Usage\UsageRecorder;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Asks the model what a template or a block is for, from its structure alone: field names,
 * labels, types, choices and nested blocks. Every description is one run in the usage log.
 */
class CatalogDescriber
{
    private const string SYSTEM = <<<'PROMPT'
        You document the building blocks of a website built with Sulu CMS, for an AI writing assistant that will later compose pages from them.

        You get the structure of one template, one block or one snippet template: its fields with their labels, types, choices and nested block types. A field with "shows_snippets_of_template" selects reusable snippets of that snippet template (for example FAQ items or testimonials); say so in the description. A field with "lists_automatically" is smart content: a list of those items (pages, articles, ...) that fills itself from filters; say what such a list is good for. A snippet template is one reusable item that blocks select. Work out what it is for and describe it in English, concretely and briefly, for a writer who cannot see the design:

        - description: what it is and what it shows, in one or two sentences
        - use_when: the situations and page types it suits
        - avoid_when: when another block does the job better, or when it should not be used
        - tips: how to fill it in well - lengths, number of items, tone, what makes it work

        Base everything on the structure. Do not invent features the fields do not support. When the purpose is unclear, say what is likely and keep it short.

        Reply with one JSON object and nothing else: {"description": "...", "use_when": "...", "avoid_when": "...", "tips": "..."}
        PROMPT;

    public function __construct(
        private readonly AgentFactory $agentFactory,
        private readonly ModelRegistry $modelRegistry,
        private readonly UsageRecorder $usageRecorder,
        private readonly CostCalculator $costCalculator,
    ) {
    }

    /**
     * @param string $kind "template" or "block"
     * @param list<string> $usedIn the templates a block appears in
     */
    public function describe(string $kind, TypeDescription $type, array $usedIn = [], ?string $siteDescription = null): Guide
    {
        $model = $this->modelRegistry->getForCatalog();
        $run = new AiRun(AiAction::AnalyzeBlocks, $model->key, $model->model, $this->costCalculator->getCurrency());
        $run->prompt = \sprintf('Describe the %s "%s"', $kind, $type->key);
        $this->usageRecorder->start($run, 'describing:'.$type->key);

        $structure = [
            'kind' => $kind,
            'key' => $type->key,
            'title' => $type->title,
            'fields' => array_map(self::field(...), $type->fields),
        ];

        if ($usedIn !== []) {
            $structure['used_in_templates'] = $usedIn;
        }

        $user = 'Structure:'."\n".json_encode($structure, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        if ($siteDescription !== null && $siteDescription !== '') {
            $user = "The website: {$siteDescription}\n\n".$user;
        }

        try {
            $result = $this->agentFactory->create($model, false)->call(new MessageBag(Message::forSystem(self::SYSTEM), Message::ofUser($user)), $model->options)->getResult();
            $this->usageRecorder->addUsage($run, $result);
            $content = $result->getContent();
            $guide = self::parse(\is_string($content) ? $content : '');
            $this->usageRecorder->transcript($run, [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $user], ['role' => 'assistant', 'content' => \is_string($content) ? $content : '']]);
            $this->usageRecorder->succeed($run, $guide->toArray());
        } catch (\Throwable $exception) {
            $this->usageRecorder->fail($run, $exception);

            throw $exception;
        }

        return $guide;
    }

    private static function parse(string $text): Guide
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false) {
            throw new \RuntimeException('The model did not reply with JSON.');
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);

        return Guide::fromArray(\is_array($data) ? $data : []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(FieldDescription $field): array
    {
        $data = ['name' => $field->name, 'label' => $field->label, 'type' => $field->type];

        if ($field->description !== null && $field->description !== '') {
            $data['help'] = $field->description;
        }

        if ($field->required) {
            $data['required'] = true;
        }

        if ($field->type === 'snippet_selection' || $field->type === 'single_snippet_selection') {
            $templates = SnippetFieldSchema::templateKeys($field);
            $data['shows_snippets_of_template'] = $templates === [] ? 'any' : $templates;
        }

        if ($field->type === 'smart_content') {
            $data['lists_automatically'] = SmartContentFieldSchema::provider($field);
        }

        if ($field->choices !== []) {
            $data['choices'] = array_column($field->choices, 'label', 'value');
        }

        if ($field->types !== []) {
            $data['block_types'] = array_map(static fn (TypeDescription $type) => ['key' => $type->key, 'title' => $type->title, 'fields' => array_map(self::field(...), $type->fields)], $field->types);
        }

        return $data;
    }
}
