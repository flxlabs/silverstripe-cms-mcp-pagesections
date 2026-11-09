<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\PageSections\PageElement;
use FLXLabs\PageSections\PageSection;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class AddPageElementToSectionTool extends AbstractTool
{
    public function getName(): string
    {
        return 'add_page_element_to_section';
    }

    public function getDescription(): string
    {
        return 'Add an existing PageElement to a PageSection\'s Elements relation. '
            . 'Pass the PageSection ID, PageElement ID, and optionally the parent object ID and class. '
            . 'If "parentId" (and optionally "parentClass") is provided, the element type is validated '
            . 'against the parent DataObject\'s getAllowedPageElements(sectionName) — disallowed types '
            . 'are rejected. The element will be added at the end of the section. Writes to the draft '
            . 'stage only — call publish_record on the parent page to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageSection to add the element to',
                ],
                'elementId' => [
                    'type' => 'integer',
                    'description' => 'ID of the PageElement to add',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.).',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to check against (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main".',
                ],
            ],
            'required' => ['sectionId', 'elementId'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $sectionId = $this->requireInt($arguments, 'sectionId');
        $elementId = $this->requireInt($arguments, 'elementId');
        $parentId = $arguments['parentId'] ?? null;
        $parentClass = $arguments['parentClass'] ?? SiteTree::class;
        $sectionName = $arguments['sectionName'] ?? 'Main';

        // Validate the section
        $section = PageSection::get()->byID($sectionId);
        if (!$section) {
            throw new ToolException("No PageSection found with id $sectionId");
        }

        if (!$section->canEdit($actingMember)) {
            throw new ToolException("Permission denied: cannot edit this PageSection");
        }

        // Validate the element
        $element = PageElement::get()->byID($elementId);
        if (!$element) {
            throw new ToolException("No PageElement found with id $elementId");
        }

        // Validate element is allowed on the page (if parentId provided)
        if ($parentId) {
            $parentObject = $parentClass::get()->byID((int) $parentId);
            if (!$parentObject) {
                throw new ToolException("No {$parentClass} record found with id $parentId");
            }
            if (!method_exists($parentObject, 'getAllowedPageElements')) {
                throw new ToolException(
                    "Class \"{$parentObject->ClassName}\" does not have getAllowedPageElements() method."
                );
            }
            $allowed = $parentObject->getAllowedPageElements($sectionName);
            if (!in_array($element->ClassName, $allowed, true)) {
                throw new ToolException(
                    "\"{$element->ClassName}\" is not allowed in section \"$sectionName\" of this object. "
                        . 'Allowed element types: ' . implode(', ', $allowed)
                );
            }
        }

        // Check if already added
        if ($section->Elements()->byID($elementId)) {
            return $this->jsonResult([
                'sectionId' => $sectionId,
                'elementId' => $elementId,
                'action' => 'already_exists',
            ]);
        }

        // Add element to section
        $section->Elements()->add($element);
        $section->writeToStage(Versioned::DRAFT);

        return $this->jsonResult([
            'sectionId' => $sectionId,
            'elementId' => $elementId,
            'elementClass' => $element->ClassName,
            'action' => 'added',
        ]);
    }
}
