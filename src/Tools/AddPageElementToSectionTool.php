<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class AddPageElementToSectionTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'add_page_element_to_section';
    }

    public function getDescription(): string
    {
        return 'Add an existing PageElement to a PageSection\'s Elements relation. '
            . 'PageSection.Elements is a many_many relation: adding an element does NOT remove it from '
            . 'other sections — the same element record is shared across every section that references it. '
            . 'The element is appended at the end of the section\'s sort order. '
            . 'Target a section either directly via "sectionId", or via "parentId"/"parentClass" (single) '
            . 'or "parentIds" (batch) with optional "sectionName" — the section is resolved on the parent '
            . 'and created if it does not exist yet. When a parent is involved, the element type is validated '
            . 'against the parent\'s getAllowedPageElements(sectionName) — disallowed types are rejected. '
            . 'In a batch, all targets are validated before any write, so a batch either fully applies or '
            . 'fully fails. Writes to the draft stage only — call publish_record on the parent page to make '
            . 'the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the PageSection to add the element to. '
                        . 'If omitted, the section is resolved from "parentId"/"parentIds" + "parentClass" + "sectionName".',
                ],
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to add.',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.). '
                        . 'Used to resolve the section when "sectionId" is omitted, and to validate the element type.',
                ],
                'parentIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: batch — array of parent DataObject IDs to add the element to. '
                        . 'Mutually exclusive with "sectionId" and "parentId".',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to resolve/check (e.g. "Main", "FooterNav"). '
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
        $targets = $this->resolveSections($parsed, true, $actingMember);

        // Validate everything first, so a batch either fully applies or fully fails
        foreach ($targets as $target) {
            if (!$this->canEditSection($target['section'], $target['parent'], $actingMember)) {
                throw new ToolException('Permission denied: cannot edit PageSection #' . $target['section']->ID);
            }
            $this->assertElementAllowed($element, $target['parent'], $parsed['sectionName']);
        }

        $results = [];
        foreach ($targets as $target) {
            $section = $target['section'];
            if ($section->Elements()->byID($element->ID)) {
                $results[] = $this->targetResult($target, $element, 'already_exists');
                continue;
            }

            $section->Elements()->add($element);
            $section->writeToStage(Versioned::DRAFT);
            $results[] = $this->targetResult($target, $element, 'added');
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
            'sectionId' => $target['section']->ID,
            'elementId' => $element->ID,
            'elementClass' => $element->ClassName,
            'action' => $action,
            'parentId' => $target['parent'] ? $target['parent']->ID : null,
        ];
    }
}
