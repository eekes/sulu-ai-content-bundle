<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Prompt;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSchemaBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SnippetFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;

/**
 * Writes the system prompt: how the assistant works, how it replies, what the website is, which
 * templates and blocks exist and the schema of the template being edited.
 *
 * The order is deliberate. The parts that never change come first and the parts that change per
 * page last, so providers with prompt caching (Anthropic, OpenAI) serve most of it from cache on
 * every turn after the first.
 */
class SystemPromptBuilder
{
    private const array CONTEXT_LABELS = [
        'siteDescription' => 'What the website is',
        'audience' => 'Who reads it',
        'toneOfVoice' => 'Tone of voice',
        'writingGuidelines' => 'Writing guidelines',
        'wordsToUse' => 'Words and phrases to use',
        'wordsToAvoid' => 'Words and phrases to avoid',
        'glossary' => 'Glossary',
        'extraInstructions' => 'Further instructions',
    ];

    public function __construct(
        private readonly ContentSchemaBuilder $schemaBuilder,
        private readonly ?string $extraInstructions = null,
        private readonly int $maxPlanItems = 12,
    ) {
    }

    /**
     * @param list<TypeDescription> $templates every template of the form, for switching
     * @param list<array{uuid: string, title: string}> $examplePages
     * @param list<TypeDescription> $snippetTemplates the templates of snippets, for snippet selections
     * @param list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds?: array<string, int>}> $siteStructure
     */
    public function build(string $formKey, TypeDescription $template, array $templates, Catalog $catalog, ?WebspaceContext $context, SchemaContext $schemaContext, array $examplePages = [], array $snippetTemplates = [], array $siteStructure = [], int $siteStructureLimit = 0): string
    {
        $sections = [
            $this->rules($formKey, $schemaContext->locale),
            $this->replyFormat(),
            $this->website($context, $examplePages),
            $this->siteStructure($siteStructure, $siteStructureLimit),
            $this->templates($formKey, $templates, $catalog),
            $this->blocks($template, $catalog),
            $this->snippets($template, $snippetTemplates, $catalog, $schemaContext),
            $this->schema($formKey, $template, $catalog, $schemaContext),
        ];

        return implode("\n\n", array_filter($sections, static fn (string $section) => $section !== ''));
    }

    /**
     * The prompt of the planner: several new pages or articles at once, planned with the editor.
     * No form and no schema - only what is needed to decide which pages, where and with which
     * template. Each page is written later, with the normal prompt of its template.
     *
     * @param list<TypeDescription> $templates
     * @param list<array{uuid: string, title: string}> $examplePages
     * @param list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds?: array<string, int>}> $siteStructure
     * @param list<TypeDescription> $pageTemplates for a plan of articles: the templates of the page they go below, when it has to be created
     */
    public function buildPlanner(string $formKey, array $templates, Catalog $catalog, ?WebspaceContext $context, string $locale, array $examplePages = [], array $siteStructure = [], int $siteStructureLimit = 0, array $pageTemplates = []): string
    {
        // only a plan that is not about pages can hold a page: the one its items go below
        $pageTemplates = $formKey === 'page' ? [] : $pageTemplates;

        $sections = [
            $this->plannerRules($formKey, $locale, $pageTemplates !== []),
            $this->website($context, $examplePages),
            $this->siteStructure($siteStructure, $siteStructureLimit),
            "## Templates\n\nEvery {$formKey} of the plan uses one of these templates.\n\n".self::templateList($formKey, $templates, $catalog),
            $pageTemplates === [] ? '' : "## Page templates\n\nOnly for the one page that holds the {$formKey}s.\n\n".self::templateList('page', $pageTemplates, $catalog),
        ];

        return implode("\n\n", array_filter($sections, static fn (string $section) => $section !== ''));
    }

    /**
     * @param list<TypeDescription> $templates
     */
    private static function templateList(string $formKey, array $templates, Catalog $catalog): string
    {
        $lines = [];

        foreach ($templates as $template) {
            $lines[] = \sprintf('- %s: %s', $template->key, $template->title).self::inline($catalog->template($formKey, $template->key));
        }

        return implode("\n", $lines);
    }

    private function plannerRules(string $formKey, string $locale, bool $canPlanPage): string
    {
        $language = LanguageName::of($locale);
        $parent = $formKey === 'page'
            ? 'the page it goes below in the page tree'
            : "the page its URL sits below: the page that already holds {$formKey}s like it (see the pages of the website) or a known fact that names it; when neither tells you, ask with \"remember\": true, offering the likely pages by title";
        $an = \in_array($formKey[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
        // the project's own rules (the "instructions" option) count when planning too
        $instructions = $this->extraInstructions === null || trim($this->extraInstructions) === '' ? '' : "\n\n".trim($this->extraInstructions);
        $overview = !$canPlanPage ? '' : <<<PROMPT

            - Every {$formKey} sits below a page that lists them, such as a blog or news overview. That overview is always a page, never {$an} {$formKey}. Never plan it with {$an} {$formKey} template. When the page the {$formKey}s should go below does not exist yet, ask the editor first whether to create it and where it goes in the page tree: on the first level of the website, next to the other main pages (below the homepage), or below another page - offer the likely pages by title. When more than one page template fits, ask which one too.
            - Once the editor agreed, add that page to the plan as an item of its own: {"kind": "page", "key": "blog", "title": "...", "template": "<page template>", "parent": "<page uuid>", "brief": "..."}, with a template of "Page templates" below, and give the {$formKey}s below it "parent": "new:blog". Plan at most one page; it is created first, as an unpublished draft like the rest.
            PROMPT;

        return <<<PROMPT
            You are the writing assistant inside Sulu, a content management system. Together with an editor you plan several new {$formKey}s of a website at once.

            Once the editor approves your plan, every {$formKey} of it is written in full, one at a time, and created as an unpublished draft. The editor reviews and publishes each one. You do not write their content now.

            ## How you work

            - Never ask the editor what you can find out yourself: the pages of the website (listed below, or search_pages), their content (get_page) and everything in "The website" below.
            - Ask before you guess. When the request leaves out something that matters - the goal, who it is for, which subjects - reply with questions first: at most four at a time, each with two to five likely answers as options. Mark a question with "remember": true when its answer is a fact about the organisation or the website that holds for every page.
            - Do not plan what already exists: check the pages of the website. Propose at most {$this->maxPlanItems} {$formKey}s.
            - For each {$formKey}: a title in {$language}, the template that fits best, {$parent}, and a brief: two to four sentences on its goal, its reader, the points it makes and the blocks it could use, with the facts you know. The brief is what the writer gets; never write placeholders in it.
            - Talk to the editor in the language of the editor's latest message - your message, questions and their options, the outline and suggestions - even when the website, its pages, the known facts or the guidelines are in another language.{$overview}{$instructions}

            ## Reply format

            Reply with exactly one JSON object and nothing else.

            - Questions: {"type": "questions", "message": "...", "questions": [{"question": "...", "options": ["...", "..."], "multiple": false, "remember": false}]}
            - A plan: {"type": "plan", "message": "what you propose and why, in one or two sentences", "plan": {"items": [{"title": "...", "template": "<template key>", "parent": "<page uuid>", "brief": "..."}]}}
            - Anything else: {"type": "message", "message": "..."}

            Every reply may add "suggestions": at most three short follow-up requests the editor is likely to want next.
            PROMPT;
    }

    private function rules(string $formKey, string $locale): string
    {
        $language = LanguageName::of($locale);
        $rules = <<<PROMPT
            You are the writing assistant inside Sulu, a content management system. You help an editor write and rework the {$formKey}s of a website.

            You never save or publish anything. What you propose is put into the editor's form; the editor reviews it, sees it in the preview and decides whether to save it.

            ## How you work

            - Never ask the editor what you can find out yourself: the pages of the website (listed below, or search_pages), their content (get_page), snippets (search_snippets), images (search_media), the current form and everything in "The website" below. Look it up first, then ask only what is not in the system.
            - Ask before you guess. When a request leaves out something that matters - the goal of the page, who it is for, the key message, facts such as prices, dates, names or opening hours - reply with questions first. Ask at most four at a time, each about one thing, and offer two to five likely answers as short options. The editor answers them one by one and can always type an answer of their own, so do not add an "other" option.
            - Mark a question with "remember": true when its answer is a fact about the organisation or the website that holds for every page - the company name, the year it was founded, the address, opening hours, prices, the names of products or people. Those answers are kept and given to you on every request from then on. Questions about this page alone are not remembered.
            - For a new page, or when the structure of a page changes, propose an outline first and wait for the editor to approve or adjust it. Write the full content when the editor asks for it, approves an outline, or the request is small and clear (one block, the SEO fields, a shorter text).
            - Never invent facts, figures, quotes, testimonials, prices, people or contact details, and never write placeholders such as [company name] or {year}. When the page needs a fact you do not have in the known facts below, ask for it with a question first; if the editor skips it, write the text without it.
            - Write the content in {$language}, also when the editor, the facts or the other pages use another language: translate what you take from them.
            - Talk to the editor in the language of the editor's latest message - your message, questions and their options, the outline and suggestions - even when the website, its pages, the known facts or the guidelines are in another language.
            - Use every block for what it is meant for (see the block catalog) and vary the blocks; do not stack the same block type without a reason.
            - Link to existing pages of the website where that helps the reader. Find them with the search_pages tool, read them with get_page, and only use uuids those tools return. A link points to such a page or to an absolute external URL - never to an anchor like "#contact" or a path you made up. When no fitting page exists, leave the link out and mention it in your message.
            - Use images only from the media library, found with the search_media tool. When nothing fits, leave the image out and say so.
            - Some blocks show snippets: reusable items such as FAQ entries or testimonials, selected by uuid. For such a block, first look for existing snippets with search_snippets and reuse what fits; propose new snippets only for what is missing. In an outline, list per block which existing snippets you reuse and which new ones you would create, so the editor can agree before anything is made.
            - An article's URL (a page_tree_route field) sits below a page, such as a news or blog page; you choose that page, the rest of the URL is made from the title. For a new article, take the page that already holds articles like it (see the pages of the website) or a known fact that names it. When neither tells you, ask which page with "remember": true, offering the likely pages by title. Keep the page of an existing article unless the editor asks to move it.
            - Do only what the fields can. Plain text fields (text_line, text_area) cannot hold links, bold text or lists; the block catalog says per block where links and images are possible. When the editor asks for something a block cannot do, do not write it anyway and never claim you did: say in your message that it is not possible in that block, and suggest a block or field that can.
            - Some fields are smart content: a list that fills itself on the website - the latest articles, the pages below a page - from filters. You set the filters, never the items. Take a data source page from the pages of the website, categories from search_categories and tags from search_tags; leave out filters that do not help. In an outline, say what such a list will show, for example "the six newest articles tagged Recipes".
            - When you talk to the editor - in "message", in questions, in the purpose of an outline block - call templates, blocks and fields by their name (the title in brackets in the catalog, the label in the schema), never by their key. Keys are only for the JSON fields "type" and "template".
            - Keep everything the editor did not ask you to change exactly as it is.
            - Write for people first and search engines second: clear headings, short paragraphs, concrete words.
            PROMPT;

        if ($this->extraInstructions !== null && trim($this->extraInstructions) !== '') {
            $rules .= "\n\n".trim($this->extraInstructions);
        }

        return $rules;
    }

    private function replyFormat(): string
    {
        return <<<'PROMPT'
            ## Reply format

            Reply with exactly one JSON object and nothing else - no text before or after it.

            - Questions: {"type": "questions", "message": "...", "questions": [{"question": "...", "options": ["...", "..."], "multiple": false}]} - set "multiple" to true when more than one option can apply
            - An outline: {"type": "outline", "message": "...", "outline": {"template": "<template key>", "blocks": [{"type": "<block type>", "purpose": "what this block says", "snippets": [{"title": "...", "uuid": "<uuid of an existing snippet>"}, {"title": "... (a new one: no uuid)"}]}]}} - "snippets" only for blocks with a snippet selection
            - Content: {"type": "content", "message": "what you changed and why, in one or two sentences", "content": {"template": "<template key, only when it has to change>", "properties": {...}, "seo": {"title": "...", "description": "..."}, "excerpt": {"title": "...", "description": "..."}}}
            - Anything else: {"type": "message", "message": "..."}

            Every reply may add "suggestions": at most three short follow-up requests the editor is likely to want next, worded as the editor would ask them.

            Rules for "content":
            - "properties" follows the JSON schema of the template at the end of this prompt. Only include the properties you change.
            - Every field marked "(required)" must be filled in every new block and every new snippet; a block you keep ("_ref") keeps its filled fields. When you cannot fill a required field properly - a button needs a page to link to and there is none - ask the editor with a question first, or choose another block.
            - A block property is replaced as a whole: send every block that should remain, in the new order. For a block that already exists - changed or not - set "_ref" to its "_ref" in the current content, so its settings are kept. A new block has no "_ref".
            - New snippets go under "content.snippets": [{"key": "faq-1", "template": "<snippet template>", "properties": {...}}], following the schema of that snippet template. Select them as "new:faq-1"; existing snippets by their uuid. They are created when the editor applies your reply.
            - Only include "seo" and "excerpt" when they should change. The SEO title has at most 60 characters, the SEO description at most 155.
            PROMPT;
    }

    /**
     * @param list<array{uuid: string, title: string}> $examplePages
     */
    private function website(?WebspaceContext $context, array $examplePages): string
    {
        $lines = [];

        if ($context !== null) {
            foreach (WebspaceContext::TEXT_FIELDS as $field) {
                $value = $context->getText($field);

                if ($value !== null) {
                    $lines[] = '### '.self::CONTEXT_LABELS[$field]."\n\n".$value;
                }
            }
        }

        $facts = $context?->getFacts() ?? [];

        if ($facts !== []) {
            $known = array_map(static fn (array $fact) => \sprintf('- %s %s', $fact['question'], $fact['answer']), $facts);
            $lines[] = "### Known facts\n\nWhat the editors told you earlier. Use these and never ask for them again.\n\n".implode("\n", $known);
        }

        if ($examplePages !== []) {
            $pages = array_map(static fn (array $page) => \sprintf('- %s (uuid %s)', $page['title'], $page['uuid']), $examplePages);
            $lines[] = "### Example pages\n\nThese pages show how the website should read. Read them with get_page when you write new content.\n\n".implode("\n", $pages);
        }

        if ($lines === []) {
            return "## The website\n\nNothing has been described about this website yet. Ask the editor what you need to know.";
        }

        return "## The website\n\n".implode("\n\n", $lines);
    }

    /**
     * The pages that exist, so the assistant never has to ask which pages there are.
     *
     * @param list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds?: array<string, int>}> $pages
     */
    private function siteStructure(array $pages, int $limit): string
    {
        if ($pages === []) {
            return '';
        }

        $lines = array_map(static fn (array $page) => \sprintf(
            '%s- %s (%s%s, uuid %s)%s',
            str_repeat('  ', max(0, $page['depth'])),
            $page['title'] === '' ? '(untitled)' : $page['title'],
            $page['url'] ?? 'no URL yet',
            $page['template'] === null ? '' : ', template '.$page['template'],
            $page['uuid'],
            self::holds($page['holds'] ?? []),
        ), $pages);

        $intro = 'Every page of this website in this language, as a tree. Use it to know what exists, to link to pages and to avoid writing what another page already covers.';

        if (\count($pages) >= $limit) {
            $intro .= \sprintf(' Only the first %d pages are listed; find others with search_pages.', $limit);
        }

        return "## Pages of this website\n\n".$intro."\n\n".implode("\n", $lines);
    }

    /**
     * What a block can hold, from its own fields: whether it can link and show images. Asked for
     * something it cannot do, the model must say so instead of pretending.
     */
    private static function capabilities(TypeDescription $type): string
    {
        $links = [];
        $images = [];

        foreach ($type->fields as $field) {
            if ($field->type === 'text_editor') {
                $links[] = $field->name.' (links in the text)';
            } elseif (\in_array($field->type, ['link', 'single_page_selection', 'page_selection'], true)) {
                $links[] = $field->name;
            } elseif (\in_array($field->type, ['single_media_selection', 'media_selection'], true)) {
                $images[] = $field->name;
            }
        }

        return \sprintf(
            'Links: %s. Images: %s.',
            $links === [] ? 'not possible, its texts are plain text' : implode(', ', $links),
            $images === [] ? 'none' : implode(', ', $images),
        );
    }

    /**
     * @param array<string, int> $holds
     */
    private static function holds(array $holds): string
    {
        if ($holds === []) {
            return '';
        }

        $parts = [];

        foreach ($holds as $resourceKey => $count) {
            $parts[] = \sprintf('%d %s', $count, $resourceKey);
        }

        return ' - the URLs of '.implode(', ', $parts).' sit below it';
    }

    /**
     * @param list<TypeDescription> $templates
     */
    private function templates(string $formKey, array $templates, Catalog $catalog): string
    {
        if (\count($templates) < 2) {
            return '';
        }

        $lines = [];

        foreach ($templates as $template) {
            $lines[] = \sprintf('- %s: %s', $template->key, $template->title).self::inline($catalog->template($formKey, $template->key));
        }

        return "## Templates\n\nA {$formKey} uses one of these templates. Propose another one only when it clearly fits better.\n\n".implode("\n", $lines);
    }

    private function blocks(TypeDescription $template, Catalog $catalog): string
    {
        $types = [];
        $this->collectBlockTypes($template->fields, $types);

        if ($types === []) {
            return '';
        }

        $lines = [];

        foreach ($types as $type) {
            $guide = $catalog->block($type->key);
            $entry = \sprintf('### %s (%s)', $type->key, $type->title)."\n".self::capabilities($type);

            if ($guide !== null && !$guide->isEmpty()) {
                foreach (['' => $guide->description, 'Use it when: ' => $guide->useWhen, 'Avoid it when: ' => $guide->avoidWhen, 'Tips: ' => $guide->tips] as $label => $text) {
                    if ($text !== '') {
                        $entry .= "\n".$label.$text;
                    }
                }
            }

            $lines[] = $entry;
        }

        return "## Block catalog\n\n".implode("\n\n", $lines);
    }

    /**
     * The snippet templates the snippet selections of this template accept, with what the catalog
     * says about them and the schema to write a new one.
     *
     * @param list<TypeDescription> $snippetTemplates
     */
    private function snippets(TypeDescription $template, array $snippetTemplates, Catalog $catalog, SchemaContext $context): string
    {
        $wanted = [];
        $everything = false;
        $this->collectSnippetTemplates($template->fields, $wanted, $everything);

        if ($wanted === [] && !$everything) {
            return '';
        }

        $lines = [];

        foreach ($snippetTemplates as $snippetTemplate) {
            if (!$everything && !isset($wanted[$snippetTemplate->key])) {
                continue;
            }

            $entry = \sprintf('### %s (%s)', $snippetTemplate->key, $snippetTemplate->title).self::inline($catalog->template('snippet', $snippetTemplate->key));
            $entry .= "\nJSON schema of its \"properties\":\n".json_encode(
                $this->schemaBuilder->build('snippet', $snippetTemplate, $catalog, $context),
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
            $lines[] = $entry;
        }

        if ($lines === []) {
            return '';
        }

        return "## Snippets\n\nThe snippet templates the snippet selections on this page take. Find existing snippets with search_snippets; write new ones against these schemas.\n\n".implode("\n\n", $lines);
    }

    /**
     * @param list<FieldDescription> $fields
     * @param array<string, true> $wanted
     */
    private function collectSnippetTemplates(array $fields, array &$wanted, bool &$everything): void
    {
        foreach ($fields as $field) {
            if ($field->type === 'snippet_selection' || $field->type === 'single_snippet_selection') {
                $keys = SnippetFieldSchema::templateKeys($field);
                $everything = $everything || $keys === [];

                foreach ($keys as $key) {
                    $wanted[$key] = true;
                }
            }

            foreach ($field->types as $type) {
                $this->collectSnippetTemplates($type->fields, $wanted, $everything);
            }
        }
    }

    private function schema(string $formKey, TypeDescription $template, Catalog $catalog, SchemaContext $context): string
    {
        $schema = $this->schemaBuilder->build($formKey, $template, $catalog, $context);

        return \sprintf(
            "## The template being edited: %s (%s)\n\nJSON schema of \"properties\":\n\n%s",
            $template->key,
            $template->title,
            json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<FieldDescription> $fields
     * @param array<string, TypeDescription> $types
     */
    private function collectBlockTypes(array $fields, array &$types): void
    {
        foreach ($fields as $field) {
            foreach ($field->types as $type) {
                if (!isset($types[$type->key])) {
                    $types[$type->key] = $type;
                    $this->collectBlockTypes($type->fields, $types);
                }
            }
        }
    }

    private static function inline(?Guide $guide): string
    {
        if ($guide === null || $guide->isEmpty()) {
            return '';
        }

        return ' - '.implode(' ', array_filter([$guide->description, $guide->useWhen === '' ? '' : 'Use it when: '.$guide->useWhen]));
    }
}
