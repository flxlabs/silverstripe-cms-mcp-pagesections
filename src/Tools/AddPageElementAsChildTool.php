<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class AddPageElementAsChildTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'add_page_element_as_child';
    }

    public function getDescription(): string
    {
        return 'Add an existing PageElement as a nested child of another PageElement (e.g. a TextElement '
            . 'inside a JumboTeaserChapter). PageElement.Children is a many_many relation, so the element '
            . 'record is SHARED: adding it does not remove it from other parent elements or from any '
            . 'PageSections — it will be rendered in every place it is referenced. The element is appended '
            . 'at the end of the parent\'s child order. The element type is validated against the parent '
            . 'element\'s getAllowedPageElements() — disallowed types are rejected. Adding an element as a '
            . 'child of itself or of one of its own descendants is rejected (cycle protection). '
            . 'Writes to the draft stage only — call publish_record on the root page to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to add as a child.',
                ],
                'parentElementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the parent PageElement to attach the element under.',
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
        $this->assertChildAllowed($element, $parent);
        if ($this->wouldCreateCycle($parent, $element)) {
            throw new ToolException(
                "Adding {$element->ClassName} #{$element->ID} as a child of {$parent->ClassName} #{$parent->ID} "
                . 'would create a cycle (the parent is the element itself or one of its descendants).'
            );
        }

        if ($parent->Children()->byID($element->ID)) {
            return $this->jsonResult([
                'elementId' => $element->ID,
                'elementClass' => $element->ClassName,
                'parentElementId' => $parent->ID,
                'action' => 'already_child',
            ]);
        }

        $parent->Children()->add($element);
        // Re-write the parent to trigger its counter update (staged classes only)
        if ($parent->hasMethod('writeToStage')) {
            $parent->writeToStage(Versioned::DRAFT);
        }

        return $this->jsonResult([
            'elementId' => $element->ID,
            'elementClass' => $element->ClassName,
            'parentElementId' => $parent->ID,
            'action' => 'added',
        ]);
    }
}
