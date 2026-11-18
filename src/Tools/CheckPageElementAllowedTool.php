<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\Security\Member;

class CheckPageElementAllowedTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'check_page_element_allowed';
    }

    public function getDescription(): string
    {
        return 'Dry-run check (no writes) for where a PageElement can be placed. Two target modes: '
            . '(1) SECTION mode — via "sectionId", or "parentId"/"parentClass" (+ optional "sectionName"), '
            . 'or "parentIds" for a batch: reports whether the element type is allowed as a ROOT element '
            . 'of that section (the parent\'s getAllowedPageElements(sectionName)), whether the section '
            . 'exists (a missing section would be created by add_page_element_to_section / '
            . 'copy_page_element_to_section), and whether the element is already part of the section; '
            . '(2) ELEMENT mode — via "parentElementId": reports whether the element type is allowed as a '
            . 'CHILD of that element (the parent element\'s getAllowedPageElements()) and whether it is '
            . 'already a child of it. Use this before add/copy/create operations to avoid rejected writes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the PageSection to check (section mode).',
                ],
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to check.',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.) (section mode).',
                ],
                'parentIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: batch — array of parent DataObject IDs to check (section mode). '
                        . 'Mutually exclusive with "sectionId" and "parentId".',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided (section mode).',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to check (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main" (section mode).',
                ],
                'parentElementId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent PageElement to check against (element mode, '
                        . 'checks child placement). Mutually exclusive with "sectionId"/"parentId"/"parentIds".',
                ],
            ],
            'required' => ['elementId'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $element = $this->getElement($this->requireInt($arguments, 'elementId'));
        $mode = $this->requireSingleTargetMode($arguments);

        // ELEMENT mode: can the element be a child of the given parent element?
        if ($mode === 'element') {
            $parent = $this->resolveParentElement($arguments, $actingMember);

            $childAllowed = $parent->hasMethod('getAllowedPageElements')
                && in_array($element->ClassName, $parent->getAllowedPageElements(), true);

            return $this->jsonResult([
                'elementId' => $element->ID,
                'className' => $element->ClassName,
                'mode' => 'element',
                'parentElementId' => $parent->ID,
                'parentElementClass' => $parent->ClassName,
                'childAllowed' => $childAllowed,
                'alreadyChild' => (bool) $parent->Children()->byID($element->ID),
            ]);
        }

        // SECTION mode: can the element be a root element of the given section(s)?
        $parsed = $this->parseSectionArguments($arguments);
        $targets = $this->resolveSections($parsed, false, $actingMember);

        $results = [];
        foreach ($targets as $target) {
            $section = $target['section'];
            $parent = $target['parent'];

            $allowed = null;
            if ($parent !== null) {
                $allowed = $parent->hasMethod('getAllowedPageElements')
                    && in_array($element->ClassName, $parent->getAllowedPageElements($parsed['sectionName']), true);
            } elseif ($section && ($sectionParent = $section->Parent()) && $sectionParent->ID
                && $sectionParent->hasMethod('getAllowedPageElements')) {
                // Bare "sectionId" target: resolve the allowed types through the section's parent
                $allowed = in_array($element->ClassName, $sectionParent->getAllowedPageElements($section->getName()), true);
            }

            $results[] = [
                'parentId' => $parent ? $parent->ID : null,
                'parentClass' => $parent ? $parent->ClassName : null,
                'sectionName' => $parent ? $parsed['sectionName'] : ($section ? $section->getName() : null),
                'sectionId' => $section ? $section->ID : null,
                'sectionExists' => (bool) $section,
                'allowed' => $allowed,
                'alreadyInSection' => $section ? (bool) $section->Elements()->byID($element->ID) : false,
            ];
        }

        if (count($results) === 1) {
            return $this->jsonResult(array_merge(
                ['elementId' => $element->ID, 'className' => $element->ClassName],
                $results[0]
            ));
        }
        return $this->jsonResult([
            'elementId' => $element->ID,
            'className' => $element->ClassName,
            'results' => $results,
        ]);
    }
}
