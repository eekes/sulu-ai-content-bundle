<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

/**
 * Reads the JSON reply out of whatever the model wrote around it.
 *
 * The protocol is plain JSON in the answer rather than a provider's structured output, because
 * not every provider supports a schema as deep as a page with blocks, and every provider can
 * write JSON. When the model wrote no JSON at all, its text becomes a plain message.
 *
 * @phpstan-import-type PlanItem from PlanChecker
 */
final class ReplyParser
{
    public function parse(string $text): AssistantReply
    {
        $data = self::decode($text);

        if ($data === null) {
            return new AssistantReply(AssistantReply::TYPE_MESSAGE, trim($text));
        }

        $type = \is_string($data['type'] ?? null) && \in_array($data['type'], AssistantReply::TYPES, true) ? $data['type'] : AssistantReply::TYPE_MESSAGE;
        $message = \is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $content = \is_array($data['content'] ?? null) ? $data['content'] : [];
        $template = $content['template'] ?? $data['template'] ?? null;

        return new AssistantReply(
            type: $type,
            message: $message,
            questions: self::questions($data['questions'] ?? null),
            outline: self::outline($data['outline'] ?? null),
            template: \is_string($template) && $template !== '' ? $template : null,
            properties: \is_array($content['properties'] ?? null) ? self::stringKeys($content['properties']) : [],
            seo: self::strings($content['seo'] ?? null, ['title', 'description', 'keywords']),
            excerpt: self::strings($content['excerpt'] ?? null, ['title', 'description']),
            suggestions: self::list($data['suggestions'] ?? null),
            snippets: self::snippets($content['snippets'] ?? null),
            plan: self::plan($data['plan'] ?? null),
        );
    }

    /**
     * @return null|array<mixed>
     */
    private static function decode(string $text): ?array
    {
        $text = trim($text);

        // a fenced code block, the most common wrapping
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $matches) === 1) {
            $text = $matches[1];
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            $data = json_decode(substr($text, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * @return list<array{question: string, options: list<string>, multiple: bool, remember: bool}>
     */
    private static function questions(mixed $value): array
    {
        $questions = [];

        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_string($item)) {
                $questions[] = ['question' => $item, 'options' => [], 'multiple' => false, 'remember' => false];
            } elseif (\is_array($item) && \is_string($item['question'] ?? null)) {
                $questions[] = ['question' => $item['question'], 'options' => self::list($item['options'] ?? null), 'multiple' => ($item['multiple'] ?? false) === true, 'remember' => ($item['remember'] ?? false) === true];
            }
        }

        return $questions;
    }

    /**
     * @return null|array{template: null|string, templateTitle: null|string, blocks: list<array{type: string, title: string, purpose: string, snippets: list<array{title: string, uuid: null|string}>}>}
     */
    private static function outline(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $blocks = [];

        foreach (\is_array($value['blocks'] ?? null) ? $value['blocks'] : [] as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null)) {
                continue;
            }

            $snippets = [];

            foreach (\is_array($block['snippets'] ?? null) ? $block['snippets'] : [] as $snippet) {
                if (\is_array($snippet) && \is_string($snippet['title'] ?? null)) {
                    $snippets[] = ['title' => $snippet['title'], 'uuid' => \is_string($snippet['uuid'] ?? null) && $snippet['uuid'] !== '' ? $snippet['uuid'] : null];
                }
            }

            $blocks[] = ['type' => $block['type'], 'title' => $block['type'], 'purpose' => \is_string($block['purpose'] ?? null) ? $block['purpose'] : '', 'snippets' => $snippets];
        }

        $template = \is_string($value['template'] ?? null) ? $value['template'] : null;

        // the names editors know are added by the runner, from the template metadata
        return ['template' => $template, 'templateTitle' => $template, 'blocks' => $blocks];
    }

    /**
     * The pages of a plan, as {"plan": {"items": [...]}} or {"plan": [...]}. Template and parent
     * titles are added by the runner, which checks both (PlanChecker). An item with "kind":
     * "page" is the new page the others go below, by its "key".
     *
     * @return list<PlanItem>
     */
    private static function plan(mixed $value): array
    {
        $items = \is_array($value) && \is_array($value['items'] ?? null) ? $value['items'] : $value;
        $plan = [];

        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item) || !\is_string($item['title'] ?? null) || trim($item['title']) === '') {
                continue;
            }

            $template = \is_string($item['template'] ?? null) ? $item['template'] : '';
            $parent = \is_string($item['parent'] ?? null) && $item['parent'] !== '' ? $item['parent'] : null;

            $plan[] = [
                'title' => trim($item['title']),
                'template' => $template,
                'templateTitle' => $template,
                'parent' => $parent,
                'parentTitle' => null,
                'brief' => \is_string($item['brief'] ?? null) ? trim($item['brief']) : '',
                'kind' => ($item['kind'] ?? null) === PlanChecker::KIND_PAGE ? PlanChecker::KIND_PAGE : null,
                'key' => \is_string($item['key'] ?? null) && trim($item['key']) !== '' ? trim($item['key']) : null,
            ];
        }

        return $plan;
    }

    /**
     * @return list<array{key: string, template: string, properties: array<string, mixed>}>
     */
    private static function snippets(mixed $value): array
    {
        $snippets = [];

        foreach (\is_array($value) ? $value : [] as $item) {
            // the key ends up in "new:<key>" references: letters, digits, dots, dashes, underscores
            if (\is_array($item) && \is_string($item['key'] ?? null) && preg_match('/^[\w.-]+$/', $item['key']) === 1 && \is_string($item['template'] ?? null) && \is_array($item['properties'] ?? null)) {
                $snippets[] = ['key' => $item['key'], 'template' => $item['template'], 'properties' => self::stringKeys($item['properties'])];
            }
        }

        return $snippets;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function strings(mixed $value, array $keys): array
    {
        $strings = [];

        foreach ($keys as $key) {
            if (\is_array($value) && \is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                $strings[$key] = trim(strip_tags($value[$key]));
            }
        }

        return $strings;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, static fn (mixed $item) => \is_string($item) && $item !== '')) : [];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
