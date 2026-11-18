<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class RemovePageElementFromParentTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'remove_page_element_from_parent';
    }

    public function getDescription(): string
    {
        return 'Remove a PageElement from another PageElement\'s Children relation (the inverse of '
            . 'add_page_element_as_child, and of create_page_element called with "parentElementId"). '
            . 'PageElement.Children is a many_many relation, so the element record itself is NOT deleted '
            . '— it keeps belonging to any other parent elements or PageSections that reference it. '
            . 'Removing an element that is not a child of the given parent is a no-op ("not_child"), '
            . 'so calls are idempotent. Writes to the draft stage only — call publish_record on the root '
            . 'page to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to remove.',
                ],
                'parentElementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the parent PageElement to remove the element from.',
                ],
            ],
            'required' => ['elementId', 'parentElementId'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $element = $this->getElement($this->requireInt($arguments, 'elementId'));
        $parent = $this->resolveParentElement($arguments, $actingMember);

        if (!$parent->canEdit($actingMember)) {
            throw new ToolException('Permission denied: cannot edit parent element #' . $parent->ID);
        }

        $result = [
            'elementId' => $element->ID,
            'elementClass' => $element->ClassName,
            'parentElementId' => $parent->ID,
        ];

        if (!$parent->Children()->byID($element->ID)) {
            return $this->jsonResult($result + ['action' => 'not_child']);
        }

        $parent->Children()->remove($element);
        // Re-write the parent to trigger its counter update (staged classes only)
        if ($parent->hasMethod('writeToStage')) {
            $parent->writeToStage(Versioned::DRAFT);
        }

        return $this->jsonResult($result + ['action' => 'removed']);
    }
}
