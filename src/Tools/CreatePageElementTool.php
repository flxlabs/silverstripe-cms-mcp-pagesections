<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class CreatePageElementTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'create_page_element';
    }

    public function getDescription(): string
    {
        return 'Create a new PageElement (e.g. JumboTeaserChapter, TextElement, TeaserElement) and place it: '
            . 'as a CHILD of an existing element via "parentElementId" (validated against that parent '
            . 'element\'s getAllowedPageElements() and attached to its Children relation), or as a ROOT '
            . 'element of a parent DataObject\'s section via "parentId" + "parentClass" + optional '
            . '"sectionName" (validated against the parent\'s getAllowedPageElements(sectionName) and added '
            . 'to the section, which is resolved or created on the parent). With neither parent the element '
            . 'is created UNPLACED — no section, no parent; use add_page_element_to_section or '
            . 'add_page_element_as_child to place it afterwards. Writes to the draft stage only — call '
            . 'publish_record on the root page to make the change live. For Fluent-localized classes, pass '
            . '"locale" to create in a specific locale.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'PageElement subclass to create, e.g. JumboTeaserChapter'],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Map of field name to value (Caption, Text, Color, etc.)',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.). The element is '
                        . 'created and added as a root element of that parent\'s section (section resolved via '
                        . '"parentClass" + "sectionName", created if missing). Mutually exclusive with "parentElementId".',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section on the parent DataObject (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main". Only used when parentId is provided without parentElementId.',
                ],
                'parentElementId' => ['type' => 'integer', 'description' => 'Optional: ID of the parent PageElement to add the new element as a child of'],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Optional locale code for Fluent-localized classes (e.g. "fr_FR"). '
                        . 'If omitted, uses the current locale.',
                ],
                'subsiteId' => [
                    'type' => 'integer',
                    'description' => 'Optional subsite ID to query within. If omitted, uses the current subsite.',
                ],
            ],
            'required' => ['class', 'fields'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $elementClass = $this->requireExposableClass($arguments);
        $locale = $arguments['locale'] ?? null;
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        // Verify it's a PageElement subclass
        if (!is_subclass_of($elementClass, PageElement::class)) {
            throw new ToolException("Class \"$elementClass\" is not a PageElement subclass.");
        }

        // Validate element is allowed (if parentId or parentElementId provided)
        $parentId = $arguments['parentId'] ?? null;
        $parentClass = $arguments['parentClass'] ?? SiteTree::class;
        $sectionName = $arguments['sectionName'] ?? 'Main';
        $parentElementId = $arguments['parentElementId'] ?? null;

        if ($parentId && $parentElementId) {
            throw new ToolException(
                'Provide either "parentElementId" (child of an element) or "parentId" (root of a section), not both'
            );
        }

        if ($parentElementId) {
            // Check against the parent element's allowed children
            $parent = PageElement::get()->byID($parentElementId);
            if (!$parent) {
                throw new ToolException("No PageElement found with id $parentElementId");
            }
            if (!$parent->canView($actingMember)) {
                throw new ToolException('Permission denied: cannot view parent element #' . $parentElementId);
            }
            if (!$parent->canEdit($actingMember)) {
                throw new ToolException('Permission denied: cannot edit parent element #' . $parentElementId);
            }
            $allowed = $parent->getAllowedPageElements();
            if (!in_array($elementClass, $allowed, true)) {
                throw new ToolException(
                    "\"$elementClass\" is not allowed as a child of this element. "
                        . 'Allowed element types: ' . implode(', ', $allowed)
                );
            }
        } elseif ($parentId) {
            // Check against the parent DataObject's allowed elements for the section
            $parentObject = $parentClass::get()->byID((int) $parentId);
            if (!$parentObject) {
                throw new ToolException("No {$parentClass} record found with id $parentId");
            }
            if (!$parentObject->canView($actingMember)) {
                throw new ToolException("Permission denied: cannot view {$parentClass} #{$parentId}");
            }
            if (!$parentObject->hasMethod('getAllowedPageElements')) {
                throw new ToolException(
                    "Class \"{$parentObject->ClassName}\" does not have getAllowedPageElements() method."
                );
            }
            $allowed = $parentObject->getAllowedPageElements($sectionName);
            if (!in_array($elementClass, $allowed, true)) {
                throw new ToolException(
                    "\"$elementClass\" is not allowed in section \"$sectionName\" of this object. "
                        . 'Allowed element types: ' . implode(', ', $allowed)
                );
            }
        }

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $elementClass, $locale, $parentId, $parentClass, $sectionName) {
            return $this->withLocale($elementClass, $locale, function () use ($arguments, $actingMember, $elementClass, $parentId, $parentClass, $sectionName) {
                if (!singleton($elementClass)->canCreate($actingMember)) {
                    throw new ToolException("Permission denied: cannot create $elementClass");
                }

                if (!isset($arguments['fields']) || !is_array($arguments['fields'])) {
                    throw new ToolException('Missing required argument "fields" (an object of fieldName => value)');
                }

                $editable = FieldIntrospector::singleton()->describeEditableFields($elementClass);
                $invalid = array_diff(array_keys($arguments['fields']), array_keys($editable));
                if ($invalid) {
                    throw new ToolException(
                        'Not editable: ' . implode(', ', $invalid)
                            . '. Call list_fields to see valid fields.'
                    );
                }

                // Create the element
                $element = $elementClass::create();

                foreach ($arguments['fields'] as $name => $value) {
                    $element->setField($name, $this->castValue($editable[$name]['type'], $value));
                }

                $element->writeToStage(Versioned::DRAFT);

                $parentElementId = $arguments['parentElementId'] ?? null;
                $sectionId = null;

                // Optionally attach as a child of a parent element
                if ($parentElementId) {
                    $parent = PageElement::get()->byID($parentElementId);
                    if (!$parent) {
                        throw new ToolException("No PageElement found with id $parentElementId");
                    }
                    $parent->Children()->Add($element);
                    // Re-write parent to trigger counter update (staged classes only)
                    if ($parent->hasMethod('writeToStage')) {
                        $parent->writeToStage(Versioned::DRAFT);
                    }
                } elseif ($parentId) {
                    // Place the new element as a root element of the parent's section
                    $targets = $this->resolveSections([
                        'elementId' => 0,
                        'sectionId' => null,
                        'parentIds' => [(int) $parentId],
                        'parentClass' => $parentClass,
                        'sectionName' => $sectionName,
                    ], true, $actingMember);
                    $section = $targets[0]['section'];
                    if (!$section->canEdit($actingMember)) {
                        throw new ToolException('Permission denied: cannot edit PageSection #' . $section->ID);
                    }

                    $section->Elements()->add($element);
                    $section->writeToStage(Versioned::DRAFT);
                    $sectionId = $section->ID;
                }

                return $this->jsonResult([
                    'class' => $elementClass,
                    'id' => $element->ID,
                    'fields' => array_keys($arguments['fields']),
                    'parentElementId' => $parentElementId ? (int) $parentElementId : null,
                    'parentId' => $parentId ? (int) $parentId : null,
                    'sectionId' => $sectionId,
                    'action' => $parentElementId ? 'created_as_child' : ($sectionId ? 'created_in_section' : 'created_unplaced'),
                ]);
            });
        });
    }

    private function castValue(string $type, $value)
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }
}
