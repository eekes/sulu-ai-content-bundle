<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * Reads the templates of a Sulu form ("page", "article", "snippet") and the blocks in them.
 *
 * Structure only - field names, types, choices, block types. What a block is for comes from the
 * catalog file, never from here: guessing is the model's job, and only in the analyze command.
 */
interface TemplateInspectorInterface
{
    /**
     * @return list<TypeDescription>
     */
    public function getTemplates(string $formKey, string $locale, ?string $webspaceKey = null): array;

    public function getTemplate(string $formKey, string $templateKey, string $locale, ?string $webspaceKey = null): ?TypeDescription;
}
