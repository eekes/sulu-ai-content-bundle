<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Model;

/**
 * What an editor asked for. Every run in the usage log carries one, so the log can answer "what
 * does writing a new page cost us" as well as "what did this month cost".
 */
enum AiAction: string
{
    case CreatePage = 'create_page';
    case RewritePage = 'rewrite_page';
    case RewriteBlock = 'rewrite_block';
    case Seo = 'seo';
    case Translate = 'translate';
    case Chat = 'chat';
    // a plan of several new pages or articles, from the planner of a webspace or an article list
    case PlanPages = 'plan_pages';
    // one page of an approved plan, written in full and created as a draft
    case WritePlannedPage = 'write_planned';
    case AnalyzeBlocks = 'analyze_blocks';

    /**
     * The actions an editor can start from the assistant panel.
     *
     * @return list<self>
     */
    public static function assistantActions(): array
    {
        return [self::CreatePage, self::RewritePage, self::RewriteBlock, self::Seo, self::Translate, self::Chat, self::PlanPages, self::WritePlannedPage];
    }
}
