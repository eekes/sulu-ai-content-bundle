<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('get_page', 'Reads the content of one page of this website: its title, template and properties. Use it for example pages and before you link to a page.')]
final class GetPageTool
{
    /**
     * Long pages are cut off: the tool result goes into every following request of the run.
     */
    private const int MAX_LENGTH = 12000;

    public function __construct(
        private readonly PageFinderInterface $pageFinder,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param string $uuid The uuid of the page, as returned by search_pages
     */
    public function __invoke(string $uuid): string
    {
        $page = $this->pageFinder->get($uuid, $this->scope->getLocale());

        if ($page === null) {
            return \sprintf('There is no page with the uuid "%s" in this locale.', $uuid);
        }

        $json = json_encode($page, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return mb_strlen($json) > self::MAX_LENGTH ? mb_substr($json, 0, self::MAX_LENGTH).' [cut off]' : $json;
    }
}
