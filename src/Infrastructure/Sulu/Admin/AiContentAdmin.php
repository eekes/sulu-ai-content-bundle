<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Application\Usage\BudgetGuard;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\FormViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\PreviewFormViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

/**
 * Three things in the admin:
 *
 * - an "AI" button in the toolbar of every page, article and snippet form (or whichever forms are
 *   configured), which opens the assistant next to the form
 * - an "AI context" tab per webspace, for what the assistant has to know about the website
 * - "AI usage" under Settings: every run with its tokens and cost
 *
 * It runs after every other admin (the lowest priority), because it adds its button to form
 * views those admins registered.
 */
final class AiContentAdmin extends Admin
{
    public const string ASSISTANT_SECURITY_CONTEXT = 'sulu.ai_content.assistant';
    public const string USAGE_SECURITY_CONTEXT = 'sulu.ai_content.usage';
    public const string SECURITY_CONTEXT_GROUP = 'AI';

    public const string TOOLBAR_ACTION = 'eekes_ai_content.assistant';
    public const string PLAN_TOOLBAR_ACTION = 'eekes_ai_content.plan';
    public const string ARTICLE_LIST_VIEW_PREFIX = 'sulu_article.article.list_';
    public const string CONTEXT_VIEW = 'eekes_ai_content.webspace_context';
    public const string CONTEXT_VIEW_TYPE = 'eekes_ai_content.webspace_context';
    public const string RUNS_LIST_VIEW = 'eekes_ai_content.runs_list';
    public const string RUN_DETAIL_VIEW = 'eekes_ai_content.run_detail';
    public const string RUN_DETAIL_VIEW_TYPE = 'eekes_ai_content.run_detail';
    public const string CONFIG_KEY = 'eekes_ai_content';

    /**
     * @param list<string> $formKeys
     */
    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly ModelRegistry $modelRegistry,
        private readonly BudgetGuard $budgetGuard,
        private readonly CatalogFile $catalogFile,
        private readonly array $formKeys,
        private readonly bool $publishNewSnippets = true,
    ) {
    }

    public static function getPriority(): int
    {
        return -1024;
    }

    public static function getContextSecurityContext(string $webspaceKey): string
    {
        return PageAdmin::SECURITY_CONTEXT_PREFIX.$webspaceKey.'.ai_context';
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->securityChecker->hasPermission(self::USAGE_SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $item = new NavigationItem('eekes_ai_content.usage');
        $item->setPosition(60);
        $item->setView(self::RUNS_LIST_VIEW);

        $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($item);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if ($this->securityChecker->hasPermission(self::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW) && $this->modelRegistry->isConfigured()) {
            $this->addAssistantButton($viewCollection);
            $this->addPlanner($viewCollection);
        }

        if ($viewCollection->has(PageAdmin::WEBSPACE_TABS_VIEW) && $this->hasSomeContextPermission()) {
            // A form view with a type of its own: the Form view of sulu-admin-bundle needs a parent
            // ResourceTabs view for its store, and the webspace tabs are not one.
            $viewCollection->add(
                $this->viewBuilderFactory
                    ->createFormViewBuilder(self::CONTEXT_VIEW, '/ai-context/:locale')
                    ->setResourceKey(WebspaceContext::RESOURCE_KEY)
                    ->setFormKey(WebspaceContext::FORM_KEY)
                    ->setTabTitle('eekes_ai_content.ai_context')
                    ->setTabOrder(4096)
                    ->addLocales($this->allLocales())
                    ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                    ->setType(self::CONTEXT_VIEW_TYPE)
                    ->setParent(PageAdmin::WEBSPACE_TABS_VIEW)
                    ->addRerenderAttribute('webspace'),
            );
        }

        if ($this->securityChecker->hasPermission(self::USAGE_SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            $viewCollection->add(
                $this->viewBuilderFactory->createListViewBuilder(self::RUNS_LIST_VIEW, '/ai-usage')
                    ->setResourceKey(AiRun::RESOURCE_KEY)
                    ->setListKey(AiRun::LIST_KEY)
                    ->setTitle('eekes_ai_content.usage')
                    ->addListAdapters(['table'])
                    ->setEditView(self::RUN_DETAIL_VIEW)
                    ->addToolbarActions([new ToolbarAction('sulu_admin.export')])
                    ->disableSearching(),
            );

            // a page of text, not a form: a run is a log entry, there is nothing to edit
            $viewCollection->add(
                $this->viewBuilderFactory
                    ->createViewBuilder(self::RUN_DETAIL_VIEW, '/ai-usage/:id', self::RUN_DETAIL_VIEW_TYPE)
                    ->setOption('backView', self::RUNS_LIST_VIEW)
                    ->setOption('resourceKey', AiRun::RESOURCE_KEY),
            );
        }
    }

    /**
     * The planner, for several new articles at once: a button in every article list. The page
     * tree gets its button in the admin JavaScript (pageTreePlanner.js), as Sulu's page tree has
     * no place for toolbar actions of other bundles.
     */
    private function addPlanner(ViewCollection $viewCollection): void
    {
        foreach ($viewCollection->all() as $name => $builder) {
            if (!str_starts_with((string) $name, self::ARTICLE_LIST_VIEW_PREFIX) || !$builder instanceof ListViewBuilderInterface) {
                continue;
            }

            $requestParameters = $builder->getView()->getOption('requestParameters');
            $templateKeys = \is_array($requestParameters) && \is_string($requestParameters['templateKeys'] ?? null) ? $requestParameters['templateKeys'] : '';

            $builder->addToolbarActions([new ToolbarAction(self::PLAN_TOOLBAR_ACTION, [
                'formKey' => 'article',
                'templateKeys' => $templateKeys,
                // where a created article opens: the edit view of the same template group
                'editView' => 'sulu_article.article.edit_tabs_'.substr((string) $name, \strlen(self::ARTICLE_LIST_VIEW_PREFIX)),
            ])]);
        }
    }

    /**
     * @return array<string, array<string, array<string, list<string>>>>
     */
    public function getSecurityContexts(): array
    {
        $webspaceContexts = [];

        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            $webspaceContexts[self::getContextSecurityContext($webspace->getKey())] = [PermissionTypes::VIEW, PermissionTypes::EDIT];
        }

        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                self::SECURITY_CONTEXT_GROUP => [
                    self::ASSISTANT_SECURITY_CONTEXT => [PermissionTypes::VIEW],
                    self::USAGE_SECURITY_CONTEXT => [PermissionTypes::VIEW],
                ],
                PageAdmin::SECURITY_CONTEXT_GROUP => $webspaceContexts,
            ],
        ];
    }

    /**
     * @return array<string, array<string, array<string, list<string>>>>
     */
    public function getSecurityContextsWithPlaceholder(): array
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                self::SECURITY_CONTEXT_GROUP => [
                    self::ASSISTANT_SECURITY_CONTEXT => [PermissionTypes::VIEW],
                    self::USAGE_SECURITY_CONTEXT => [PermissionTypes::VIEW],
                ],
                PageAdmin::SECURITY_CONTEXT_GROUP => [
                    self::getContextSecurityContext('#webspace#') => [PermissionTypes::VIEW, PermissionTypes::EDIT],
                ],
            ],
        ];
    }

    public function getConfigKey(): string
    {
        return self::CONFIG_KEY;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        // "enabled" is what every AI button goes by, also the ones the admin JavaScript adds itself
        if (!$this->modelRegistry->isConfigured() || !$this->securityChecker->hasPermission(self::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return ['enabled' => false, 'models' => [], 'actions' => [], 'catalog' => false];
        }

        $budget = $this->budgetGuard->status();

        return [
            'enabled' => true,
            'models' => array_map(static fn ($model) => ['key' => $model->key, 'label' => $model->label], $this->modelRegistry->all()),
            'defaultModel' => $this->modelRegistry->get()->key,
            'actions' => array_map(static fn (AiAction $action) => $action->value, AiAction::assistantActions()),
            'formKeys' => $this->formKeys,
            'catalog' => $this->catalogFile->exists(),
            'budget' => $budget['limit'] === null ? null : ['spent' => round($budget['spent'], 2), 'limit' => $budget['limit']],
            'publishNewSnippets' => $this->publishNewSnippets,
            'canViewUsage' => $this->securityChecker->hasPermission(self::USAGE_SECURITY_CONTEXT, PermissionTypes::VIEW),
        ];
    }

    private function addAssistantButton(ViewCollection $viewCollection): void
    {
        foreach ($viewCollection->all() as $viewBuilder) {
            if (!$viewBuilder instanceof FormViewBuilderInterface && !$viewBuilder instanceof PreviewFormViewBuilderInterface) {
                continue;
            }

            $view = $viewBuilder->getView();

            if (!\in_array($view->getOption('formKey'), $this->formKeys, true)) {
                continue;
            }

            $viewBuilder->addToolbarActions([new ToolbarAction(self::TOOLBAR_ACTION, ['formKey' => $view->getOption('formKey')])]);
        }
    }

    /**
     * Every locale of every webspace: a form view needs its locales up front, and the view
     * itself narrows them down to the webspace that is open.
     *
     * @return list<string>
     */
    private function allLocales(): array
    {
        $locales = [];

        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            foreach ($webspace->getAllLocalizations() as $localization) {
                $locales[] = $localization->getLocale();
            }
        }

        return array_values(array_unique($locales));
    }

    private function hasSomeContextPermission(): bool
    {
        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            if ($this->securityChecker->hasPermission(self::getContextSecurityContext($webspace->getKey()), PermissionTypes::VIEW)) {
                return true;
            }
        }

        return false;
    }
}
