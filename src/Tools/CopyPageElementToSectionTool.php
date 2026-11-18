<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class CopyPageElementToSectionTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'copy_page_element_to_section';
    }

    public function getDescription(): string
    {
        return 'Copy a PageElement: duplicate the element (including any nested child elements) and place '
            . 'the COPY. Unlike add_page_element_to_section (which shares the same element record across '
            . 'sections), the original element is left untouched. Two placement modes: '
            . '(1) SECTION mode — the copy is added to a PageSection\'s Elements relation (appended at the '
            . 'end of the section\'s sort order); the section is targeted via "sectionId", or via '
            . '"parentId"/"parentClass" (single) or "parentIds" (batch) with optional "sectionName" (the '
            . 'section is resolved on the parent and created if it does not exist yet). When a parent is '
            . 'involved, the element type is validated against the parent\'s getAllowedPageElements(sectionName). '
            . '(2) ELEMENT mode — via "parentElementId": the copy is added as a child of that element '
            . '(validated against that element\'s allowed children, appended at the end of its child order). '
            . 'In a section-mode batch, all targets are validated before any write, so a batch either fully '
            . 'applies or fully fails. Writes to the draft stage only — call publish_record on the root '
            . 'page to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the PageSection to add the copy to (section mode). '
                        . 'If omitted, the section is resolved from "parentId"/"parentIds" + "parentClass" + "sectionName".',
                ],
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to copy.',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.) (section mode). '
                        . 'Used to resolve the section when "sectionId" is omitted, and to validate the element type.',
                ],
                'parentIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: batch — array of parent DataObject IDs to add the copy to (section mode). '
                        . 'Mutually exclusive with "sectionId", "parentId" and "parentElementId".',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided (section mode).',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to resolve/check (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main" (section mode).',
                ],
                'parentElementId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent PageElement to add the copy as a child of '
                        . '(element mode). Mutually exclusive with "sectionId"/"parentId"/"parentIds".',
                ],
            ],
            'required' => ['elementId'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $element = $this->getElement($this->requireInt($arguments, 'elementId'));
        $mode = $this->requireSingleTargetMode($arguments);

        if (!singleton($element->ClassName)->canCreate($actingMember)) {
            throw new ToolException("Permission denied: cannot create {$element->ClassName}");
        }

        // ELEMENT mode: duplicate the element and attach the copy as a child
        if ($mode === 'element') {
            $parent = $this->resolveParentElement($arguments, $actingMember);
            if (!$parent->canEdit($actingMember)) {
                throw new ToolException('Permission denied: cannot edit parent element #' . $parent->ID);
            }
            $this->assertChildAllowed($element, $parent);

            // duplicate(false) clones the element (and cascades "Children"), but does not write it
            $copy = $element->duplicate(false);
            $copy->writeToStage(Versioned::DRAFT);

            $parent->Children()->add($copy);
            // Re-write the parent to trigger its counter update (staged classes only)
            if ($parent->hasMethod('writeToStage')) {
                $parent->writeToStage(Versioned::DRAFT);
            }

            return $this->jsonResult([
                'sourceElementId' => $element->ID,
                'newElementId' => $copy->ID,
                'elementClass' => $copy->ClassName,
                'action' => 'copied',
                'parentElementId' => $parent->ID,
            ]);
        }

        // SECTION mode: duplicate the element and add the copy to the section(s)
        $parsed = $this->parseSectionArguments($arguments);
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

            // duplicate(false) clones the element (and cascades "Children"), but does not write it
            $copy = $element->duplicate(false);
            $copy->writeToStage(Versioned::DRAFT);

            $section->Elements()->add($copy);
            $section->writeToStage(Versioned::DRAFT);

            $results[] = [
                'sectionId' => $section->ID,
                'sourceElementId' => $element->ID,
                'newElementId' => $copy->ID,
                'elementClass' => $copy->ClassName,
                'action' => 'copied',
                'parentId' => $target['parent'] ? $target['parent']->ID : null,
            ];
        }

        if (count($results) === 1) {
            return $this->jsonResult($results[0]);
        }
        return $this->jsonResult([
            'sourceElementId' => $element->ID,
            'results' => $results,
        ]);
    }
}
