<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class RemovePageElementFromSectionTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'remove_page_element_from_section';
    }

    public function getDescription(): string
    {
        return 'Remove a PageElement from a PageSection\'s Elements relation (the inverse of '
            . 'add_page_element_to_section). PageSection.Elements is a many_many relation, so the element '
            . 'record itself is NOT deleted — it stays available and keeps belonging to any other '
            . 'sections or parent elements that reference it. The section can be targeted directly via '
            . '"sectionId", or via "parentId"/"parentClass" (single) or "parentIds" (batch) with optional '
            . '"sectionName". Removing an element that is not part of the section is a no-op '
            . '("not_in_section"), so calls are idempotent. In a batch, all targets are validated before '
            . 'any write, so a batch either fully applies or fully fails. Writes to the draft stage only '
            . '— call publish_record on the parent page to make the change live.';
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
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the PageSection to remove the element from. '
                        . 'If omitted, the section is resolved from "parentId"/"parentIds" + "parentClass" + "sectionName".',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.). '
                        . 'Used to resolve the section when "sectionId" is omitted.',
                ],
                'parentIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: batch — array of parent DataObject IDs to remove the element from. '
                        . 'Mutually exclusive with "sectionId" and "parentId".',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to remove the element from (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main".',
                ],
            ],
            'required' => ['elementId'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $parsed = $this->parseSectionArguments($arguments);
        $element = $this->getElement($parsed['elementId']);
        // createIfMissing=false: removing from a section that does not exist yet is a no-op
        $targets = $this->resolveSections($parsed, false, $actingMember);

        // Validate everything first, so a batch either fully applies or fully fails
        foreach ($targets as $target) {
            if ($target['section'] && !$this->canEditSection($target['section'], $target['parent'], $actingMember)) {
                throw new ToolException('Permission denied: cannot edit PageSection #' . $target['section']->ID);
            }
        }

        $results = [];
        foreach ($targets as $target) {
            $section = $target['section'];
            if (!$section || !$section->Elements()->byID($element->ID)) {
                $results[] = $this->targetResult($target, $element, 'not_in_section');
                continue;
            }

            $section->Elements()->remove($element);
            $section->writeToStage(Versioned::DRAFT);
            $results[] = $this->targetResult($target, $element, 'removed');
        }

        if (count($results) === 1) {
            return $this->jsonResult($results[0]);
        }
        return $this->jsonResult([
            'elementId' => $element->ID,
            'results' => $results,
        ]);
    }

    private function targetResult(array $target, PageElement $element, string $action): array
    {
        return [
            'sectionId' => $target['section'] ? $target['section']->ID : null,
            'elementId' => $element->ID,
            'elementClass' => $element->ClassName,
            'action' => $action,
            'parentId' => $target['parent'] ? $target['parent']->ID : null,
        ];
    }
}
