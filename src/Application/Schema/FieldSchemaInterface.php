<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;

/**
 * Teaches the assistant one kind of Sulu property: what a value looks like (as a JSON schema the
 * model writes against) and how to check and clean a value the model sent back.
 *
 * Implement it for a content type of your own and it is picked up through autoconfiguration.
 * A property no implementation supports is left alone: the assistant never sees it and never
 * changes it.
 */
interface FieldSchemaInterface
{
    public const string TAG = 'eekes_sulu_ai_content.field_schema';

    public function supports(FieldDescription $field): bool;

    /**
     * The JSON schema of one value. Its "description" is where format rules for the model go.
     *
     * @return array<string, mixed>
     */
    public function schema(FieldDescription $field, SchemaContext $context): array;

    /**
     * The value to store, cleaned up, or an exception that tells the model what was wrong.
     *
     * @throws InvalidValueException
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): mixed;
}
