<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * Brings the catalog in line with the templates and blocks of the project.
 *
 * - new: described (or added empty with $describe = false)
 * - unchanged since it was described: left alone
 * - changed, text not edited by a person: described again
 * - changed, text edited by a person: kept, and reported so someone can check it - unless $force
 * - gone from the project: reported, and removed with $prune
 *
 * Excluded fields a person configured always survive a new description.
 */
class CatalogAnalyzer
{
    public const string ADDED = 'added';
    public const string DESCRIBED = 'described';
    public const string REDESCRIBED = 'redescribed';
    public const string UNCHANGED = 'unchanged';
    public const string KEPT_EDITED = 'kept_edited';
    public const string STALE = 'stale';
    public const string REMOVED = 'removed';
    public const string FAILED = 'failed';

    public function __construct(
        private readonly TemplateInspectorInterface $templateInspector,
        private readonly CatalogFile $catalogFile,
        private readonly CatalogDescriber $describer,
    ) {
    }

    /**
     * @param list<string> $formKeys
     * @param null|(callable(string $kind, string $key, string $status, null|string $detail): void) $onEntry
     *
     * @return list<array{kind: string, key: string, status: string, detail: null|string}>
     */
    public function analyze(
        array $formKeys,
        string $locale,
        ?string $webspaceKey = null,
        bool $describe = true,
        bool $force = false,
        bool $prune = false,
        ?string $siteDescription = null,
        ?callable $onEntry = null,
        bool $dryRun = false,
    ): array {
        $catalog = $this->catalogFile->load();
        $report = [];
        $note = static function (string $kind, string $key, string $status, ?string $detail = null) use (&$report, $onEntry): void {
            $report[] = ['kind' => $kind, 'key' => $key, 'status' => $status, 'detail' => $detail];

            if ($onEntry !== null) {
                $onEntry($kind, $key, $status, $detail);
            }
        };

        /** @var array<string, TypeDescription> $blocks */
        $blocks = [];
        /** @var array<string, list<string>> $blockUsage */
        $blockUsage = [];
        $seenTemplates = [];

        foreach ($formKeys as $formKey) {
            foreach ($this->templateInspector->getTemplates($formKey, $locale, $webspaceKey) as $template) {
                $seenTemplates[$formKey][$template->key] = true;
                $this->collectBlocks($template->fields, $formKey.'/'.$template->key, $blocks, $blockUsage);

                $catalog = $this->sync(
                    $catalog,
                    $catalog->template($formKey, $template->key),
                    $template,
                    $formKey === 'snippet' ? 'snippet template' : 'template',
                    $formKey.'/'.$template->key,
                    [],
                    static fn (Catalog $catalog, Guide $guide) => $catalog->withTemplate($formKey, $template->key, $guide),
                    $describe && !$dryRun,
                    $force,
                    $siteDescription,
                    $note,
                );
            }
        }

        ksort($blocks);

        foreach ($blocks as $key => $type) {
            $catalog = $this->sync(
                $catalog,
                $catalog->block($key),
                $type,
                'block',
                $key,
                $blockUsage[$key] ?? [],
                static fn (Catalog $catalog, Guide $guide) => $catalog->withBlock($key, $guide),
                $describe && !$dryRun,
                $force,
                $siteDescription,
                $note,
            );
        }

        foreach (array_keys($catalog->blocks) as $key) {
            if (!isset($blocks[$key])) {
                $note('block', $key, $prune ? self::REMOVED : self::STALE);
                $catalog = $prune ? $catalog->withoutBlock($key) : $catalog;
            }
        }

        foreach ($catalog->templates as $formKey => $templates) {
            if (!\in_array($formKey, $formKeys, true)) {
                continue;
            }

            foreach (array_keys($templates) as $templateKey) {
                if (!isset($seenTemplates[$formKey][$templateKey])) {
                    $note('template', $formKey.'/'.$templateKey, $prune ? self::REMOVED : self::STALE);
                    $catalog = $prune ? $catalog->withoutTemplate($formKey, $templateKey) : $catalog;
                }
            }
        }

        if (!$dryRun) {
            $this->catalogFile->save($catalog);
        }

        return $report;
    }

    /**
     * @param list<string> $usedIn
     * @param \Closure(Catalog, Guide): Catalog $store
     * @param callable(string, string, string, null|string): void $note
     */
    private function sync(Catalog $catalog, ?Guide $existing, TypeDescription $type, string $kind, string $label, array $usedIn, \Closure $store, bool $describe, bool $force, ?string $siteDescription, callable $note): Catalog
    {
        $fingerprint = $type->hash();

        if ($existing !== null && $existing->fingerprint === $fingerprint && !$existing->isEmpty() && !$force) {
            $note($kind, $label, self::UNCHANGED, null);

            return $catalog;
        }

        if ($existing !== null && !$existing->isEmpty() && $existing->isEdited() && !$force) {
            $changed = $existing->fingerprint !== null && $existing->fingerprint !== $fingerprint;
            $note($kind, $label, self::KEPT_EDITED, $changed ? 'its fields changed since the text was written; check it, or run with --force' : null);

            return $changed ? $catalog : $store($catalog, $existing->withFingerprint($fingerprint));
        }

        $base = $existing ?? new Guide();

        if (!$describe) {
            $note($kind, $label, $existing === null ? self::ADDED : self::UNCHANGED, null);

            return $existing === null ? $store($catalog, $base->withFingerprint($fingerprint)) : $catalog;
        }

        try {
            $text = $this->describer->describe($kind, $type, $usedIn, $siteDescription);
        } catch (\Throwable $exception) {
            $note($kind, $label, self::FAILED, $exception->getMessage());

            return $existing === null ? $store($catalog, $base) : $catalog;
        }

        $note($kind, $label, $existing === null || $existing->isEmpty() ? self::DESCRIBED : self::REDESCRIBED, null);

        return $store($catalog, $base->withGeneratedText($text, $fingerprint));
    }

    /**
     * @param list<FieldDescription> $fields
     * @param array<string, TypeDescription> $blocks
     * @param array<string, list<string>> $usage
     */
    private function collectBlocks(array $fields, string $template, array &$blocks, array &$usage): void
    {
        foreach ($fields as $field) {
            foreach ($field->types as $type) {
                if (!\in_array($template, $usage[$type->key] ?? [], true)) {
                    $usage[$type->key][] = $template;
                }

                if (!isset($blocks[$type->key])) {
                    $blocks[$type->key] = $type;
                    $this->collectBlocks($type->fields, $template, $blocks, $usage);
                }
            }
        }
    }
}
