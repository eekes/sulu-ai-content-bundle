<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Prompt;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSchemaBuilder;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;

/**
 * Writes the editor's turn: what they asked, which action it is and the form as it is right now.
 *
 * Only the current turn carries the form. Earlier turns keep just the editor's words, which keeps
 * a long conversation from sending the same page over and over.
 */
class TurnPromptBuilder
{
    public function __construct(
        private readonly ContentSchemaBuilder $schemaBuilder,
    ) {
    }

    /**
     * @param array<mixed> $content the current form data
     * @param null|array<mixed> $sourceContent the data of the source locale, when translating
     */
    public function build(
        AiAction $action,
        string $request,
        string $formKey,
        TypeDescription $template,
        array $content,
        Catalog $catalog,
        ?int $focusBlock = null,
        ?string $focusProperty = null,
        ?array $sourceContent = null,
        ?string $sourceLocale = null,
        ?string $locale = null,
    ): string {
        $guide = $catalog->template($formKey, $template->key);
        $parts = ['Action: '.$this->describeAction($action, $focusBlock, $focusProperty, $sourceLocale)];

        if ($action === AiAction::Translate && $sourceContent !== null) {
            $parts[] = "Content in the source locale (translate this; keep every \"_ref\"):\n".self::json($this->visible($template->fields, $sourceContent, $catalog, null, $guide));
        } else {
            $current = $this->visible($template->fields, $content, $catalog, null, $guide);
            $parts[] = $current === []
                ? 'The form is empty.'
                : "Current content of the form (template \"{$template->key}\"):\n".self::json($current);
        }

        $seo = self::strings($content['seo'] ?? null);
        $excerpt = self::strings($content['excerpt'] ?? null);

        if ($seo !== [] || $excerpt !== []) {
            $parts[] = "Current SEO and excerpt:\n".self::json(['seo' => $seo, 'excerpt' => $excerpt]);
        }

        if ($locale !== null) {
            $parts[] = \sprintf('Language of the content: %s. Write every text in it, whatever language the editor, the facts or the other pages use.', LanguageName::of($locale));
        }

        $parts[] = 'Request of the editor: '.($request === '' ? '(none - do what the action says)' : $request);

        return implode("\n\n", $parts);
    }

    /**
     * The turn of the planner: no form, just the editor's request - and the page selected in the
     * page tree, when the planner was opened there.
     *
     * @param null|array{uuid: string, title: string} $selectedPage
     */
    public function buildPlan(string $request, ?array $selectedPage = null): string
    {
        $parts = ['Action: plan new pages for the website.'];

        if ($selectedPage !== null) {
            $parts[] = \sprintf('The editor opened the planner on the page "%s" (uuid %s) in the page tree: put the new pages below it, unless the request says otherwise.', $selectedPage['title'], $selectedPage['uuid']);
        }

        $parts[] = 'Request of the editor: '.($request === '' ? '(none - ask what is needed)' : $request);

        return implode("\n\n", $parts);
    }

    private function describeAction(AiAction $action, ?int $focusBlock, ?string $focusProperty, ?string $sourceLocale): string
    {
        return match ($action) {
            AiAction::CreatePage => 'create a new page. Start with questions or an outline unless the editor already gave you everything.',
            AiAction::RewritePage => 'rework this page.',
            AiAction::RewriteBlock => $focusBlock === null
                ? 'rework one block.'
                : \sprintf('rework only the block with "_ref" %d in "%s". Return the complete "%2$s" list, every other block unchanged with its "_ref".', $focusBlock, $focusProperty ?? 'blocks'),
            AiAction::Seo => 'write the SEO title and description and the excerpt title and description for this page, based on its content. Reply with content and change nothing else.',
            AiAction::Translate => \sprintf('translate the page from the locale "%s". Keep the structure, every block and every "_ref"; translate the meaning, not word for word. Reply with the complete content, SEO and excerpt.', $sourceLocale ?? 'unknown'),
            AiAction::WritePlannedPage => 'write this new page in full now. It is part of a plan the editor approved, so reply with content - no questions and no outline - unless a fact you cannot write the page without is missing; then ask for it. The brief of the page and the rest of the plan are below.',
            AiAction::PlanPages => 'plan new pages.',
            AiAction::Chat, AiAction::AnalyzeBlocks => 'answer the editor.',
        };
    }

    /**
     * The form data limited to what the assistant may write, with every block item numbered so
     * the reply can refer to it.
     *
     * @param list<FieldDescription> $fields
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function visible(array $fields, array $values, Catalog $catalog, ?string $blockKey, ?Guide $templateGuide): array
    {
        $visible = [];

        foreach ($fields as $field) {
            if (!\array_key_exists($field->name, $values) || !$this->schemaBuilder->isWritable($field, $catalog, $blockKey, $templateGuide)) {
                continue;
            }

            $value = $values[$field->name];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (!$field->isBlock()) {
                $visible[$field->name] = $value;

                continue;
            }

            $blocks = [];

            foreach (\is_array($value) ? array_values($value) : [] as $index => $item) {
                $type = \is_array($item) && \is_string($item['type'] ?? null) ? $field->findType($item['type']) : null;

                if (!\is_array($item) || $type === null) {
                    continue;
                }

                $blocks[] = ['type' => $type->key, ContentSchemaBuilder::BLOCK_REF => $index] + $this->visible($type->fields, $item, $catalog, $type->key, null);
            }

            $visible[$field->name] = $blocks;
        }

        return $visible;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];

        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($item) && $item !== '') {
                $strings[(string) $key] = $item;
            }
        }

        return $strings;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
